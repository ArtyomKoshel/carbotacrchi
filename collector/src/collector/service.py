"""The collector process: migrate, then take jobs from the core's parse_jobs until stopped.

SIGTERM / SIGINT (a Railway redeploy) asks the running sweep to stop after the
current page; the job is handed back as `interrupted` with its checkpoint and
the next process resumes it (docs/refactor stage 4). A page that runs past
stop_grace_seconds is cancelled and the job resumes from the checkpoint of its
last progress. If the process is killed before either, the next one takes the
job back once it has reported nothing for stale_minutes.
"""

from __future__ import annotations

import asyncio
import contextlib
import logging
import signal
import traceback
from collections.abc import Callable, Coroutine
from typing import Any

import httpx
from sqlalchemy.ext.asyncio import AsyncEngine

from collector import storage
from collector.adapters.encar.api import HEADERS, EncarApi
from collector.config import Settings
from collector.http import Fetcher, ProxyBudgetError, ProxyPool
from collector.ports import Clock, utc_now
from collector.runlog import RunLog
from collector.runner import (
    RunContext,
    build_pipeline,
    history_cutoff,
    load_rules,
    qualified,
    run_full,
    run_reparse,
)
from collector.storage import (
    Job,
    JobCancelledError,
    JobQueue,
    MySqlHistoryStore,
    MySqlIngestSink,
    MySqlRawStore,
)

log = logging.getLogger("collector")

ClientFactory = Callable[[str | None], httpx.AsyncClient]


class Service:
    def __init__(
        self,
        settings: Settings,
        db: AsyncEngine,
        *,
        tagged_only: bool = True,
        client_factory: ClientFactory | None = None,
        clock: Clock = utc_now,
        jobs_table: str | None = None,
    ) -> None:
        self.settings = settings
        self.db = db
        self.tagged_only = tagged_only
        self.client_factory = client_factory
        self.clock = clock
        self.queue = JobQueue(db, jobs_table or qualified(settings.core_schema, "parse_jobs"))
        self.stop = asyncio.Event()
        self._busy: set[str] = set()  # one process runs one job at a time: nothing is busy in between

    def install_signal_handlers(self) -> None:
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGTERM, signal.SIGINT):
            with contextlib.suppress(NotImplementedError):  # Windows: no loop signal handlers
                loop.add_signal_handler(sig, self._on_signal, sig)

    def _on_signal(self, sig: signal.Signals) -> None:
        log.info("%s received: stopping", sig.name)
        self.stop.set()

    async def serve(self) -> None:
        applied = await storage.migrate(self.db)
        if applied:
            log.info("migrations applied: %s", ", ".join(applied))
        while not self.stop.is_set():
            if not await self.run_once():
                with contextlib.suppress(TimeoutError):
                    await asyncio.wait_for(self.stop.wait(), timeout=self.settings.poll_seconds)

    async def run_once(self) -> bool:
        """Claim and run one job; False when there was none."""
        job = await self.queue.claim(
            self.settings.sources,
            busy=self._busy,
            types=("full", "reparse"),
            tagged_only=self.tagged_only,
            stale_minutes=self.settings.stale_minutes,
        )
        if job is None:
            return False
        log.info(
            "job %s (%s %s) claimed%s%s",
            job.id,
            job.source,
            job.type,
            " — left running by a stopped collector" if job.recovered else "",
            " — resuming" if job.checkpoint else "",
        )
        await self.execute(job)
        return True

    def _fetcher(self) -> Fetcher:
        s = self.settings
        pool = (
            ProxyPool.floppydata(s.proxy_username, s.proxy_password, s.proxy_host, s.proxy_sessions)
            if s.has_proxy
            else ProxyPool([])
        )
        return Fetcher(
            pool,
            headers=HEADERS,
            concurrency=s.concurrency,
            timeout=s.http_timeout,
            client_factory=self.client_factory,
        )

    async def execute(self, job: Job) -> str:
        fetcher = self._fetcher()
        sink = MySqlIngestSink(self.db)
        runlog = RunLog(self.db, job.id, clock=self.clock)
        await runlog.info(
            f"{job.type} run of {job.source} started" + (" (resumed)" if job.checkpoint else "")
        )
        try:
            status = await self._until_stopped(self._run(job, fetcher, sink, runlog), job, runlog)
        except JobCancelledError:
            await runlog.warning("cancelled by the admin")
            status = "cancelled"
        except ProxyBudgetError as e:
            await runlog.error(f"proxy budget exhausted: {e}")
            await self.queue.finish(job.id, "error", {"error": f"proxy budget exhausted: {e}"})
            status = "error"
        except Exception as e:
            log.exception("job %s failed", job.id)
            await runlog.error(f"{type(e).__name__}: {e}")
            await self.queue.finish(
                job.id,
                "error",
                {"error": f"{type(e).__name__}: {e}", "trace": traceback.format_exc()[-2000:]},
            )
            status = "error"
        finally:
            await fetcher.aclose()
            await runlog.flush()
        log.info("job %s → %s", job.id, status)
        return status

    async def _run(self, job: Job, fetcher: Fetcher, sink: MySqlIngestSink, runlog: RunLog) -> str:
        rules = await load_rules(self.db, self.settings.core_schema)
        pipeline = build_pipeline(
            EncarApi(fetcher), rules, MySqlHistoryStore(self.db), sink,
            history_concurrency=self.settings.history_concurrency,
            raw=MySqlRawStore(self.db),
        )  # fmt: skip
        pipeline.clock = self.clock
        now = self.clock()
        cutoff = await history_cutoff(self.db, job.source, self.settings.history_every_runs, now)
        ctx = RunContext(
            job=job,
            queue=self.queue,
            pipeline=pipeline,
            sink=sink,
            min_coverage_pct=self.settings.min_coverage_pct,
            should_stop=self.stop.is_set,
            clock=self.clock,
            proxy_bytes=lambda: fetcher.stats.proxy_bytes,
            runlog=runlog,
        )
        if job.type == "reparse":
            return await run_reparse(ctx, MySqlRawStore(self.db))
        return await run_full(ctx, history_cutoff_at=cutoff, current_year=now.year)

    async def _until_stopped(self, work: Coroutine[Any, Any, str], job: Job, runlog: RunLog) -> str:
        """Runs the job; after a stop request the current page gets stop_grace_seconds, then is cancelled."""
        task = asyncio.ensure_future(work)
        stopping = asyncio.ensure_future(self.stop.wait())
        try:
            await asyncio.wait({task, stopping}, return_when=asyncio.FIRST_COMPLETED)
            if task.done():
                return task.result()
            grace = self.settings.stop_grace_seconds
            await runlog.warning(f"stop requested: finishing the current page (up to {grace:g} s)")
            done, _ = await asyncio.wait({task}, timeout=grace)
            if done:
                return task.result()
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task
            await self.queue.interrupt_at_last_progress(job.id)
            await runlog.warning("stopped mid-page; resumes from the last checkpoint")
            return "interrupted"
        finally:
            stopping.cancel()
