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
import uuid
from collections.abc import Callable, Coroutine
from typing import Any

import httpx
from sqlalchemy.ext.asyncio import AsyncEngine

from collector import storage
from collector.adapters.che168.api import Che168Api, CurlTransport, RequestBudget, RotationPolicy
from collector.adapters.che168.job import Che168Run, che168_rules, run_che168
from collector.adapters.che168.signing import Signer
from collector.adapters.encar.api import HEADERS, EncarApi
from collector.config import Settings
from collector.http import Fetcher, ProxyBudgetError, ProxyPool
from collector.ports import Clock, utc_now
from collector.runlog import RunLog
from collector.runner import (
    RunContext,
    build_pipeline,
    history_cutoff,
    known_makers,
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
        if job.source == "che168":
            return await self._run_che168(job, sink, runlog)
        rules = await load_rules(self.db, self.settings.core_schema)
        pipeline = build_pipeline(
            EncarApi(fetcher), rules, MySqlHistoryStore(self.db), sink,
            history_concurrency=self.settings.history_concurrency,
            raw=MySqlRawStore(self.db),
        )  # fmt: skip
        pipeline.clock = self.clock
        now = self.clock()
        cutoff = await history_cutoff(
            self.db, job.source, self.settings.history_every_runs, now, self.settings.history_max_age_hours
        )
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
            pipeline_depth=self.settings.pipeline_depth,
        )
        if job.type == "reparse":
            return await run_reparse(ctx, MySqlRawStore(self.db))
        ctx.known_makers = await known_makers(self.db, self.settings.core_schema, job.source, exclude=job.id)
        return await run_full(ctx, history_cutoff_at=cutoff, current_year=now.year)

    async def _run_che168(self, job: Job, sink: MySqlIngestSink, runlog: RunLog) -> str:
        """Che168 has its own API client (curl_cffi, CN proxy sessions) instead of the Encar fetcher.

        One session reads the list pages, CHE168_WORKERS sessions fetch the cards at once, each
        with its own proxy exit, cookies and pause; the request limit is shared by all of them.
        """
        if job.type != "full":
            await self.queue.finish(job.id, "error", {"error": f"{job.type} is not supported for che168"})
            return "error"
        s = self.settings
        max_pages = max(1, s.che168_max_pages)
        max_cars = max(1, s.che168_max_cars)
        # each car is a card and its options, each page one more; retries and challenges on top
        budget = RequestBudget(3 * max_cars + 2 * max_pages + 50 * (1 + s.che168_workers))
        # planned rotations are bounded by the request limit; a session gives up after 3 challenges
        unbounded = budget.limit
        rules = che168_rules(await load_rules(self.db, s.core_schema))
        transports: list[CurlTransport] = []

        def session(rotation: RotationPolicy) -> Che168Api:
            transport = CurlTransport("che" + uuid.uuid4().hex[:16])
            transports.append(transport)
            api = Che168Api(
                transport.request,
                Signer.bundled(),
                delay=s.che168_delay,
                retries=2,  # a stuck exit is retried on a fresh session, twice at most
                max_seconds=s.che168_max_hours * 3600,
                rotate=transport.rotate,
                rotation=rotation,
            )
            api.budget = budget
            return api

        try:
            # list sessions read a wave of pages at once; each rotates after two of its own pages
            listers = [
                session(RotationPolicy(every_pages=0, every_searches=2, max_rotations=unbounded))
                for _ in range(max(1, s.che168_list_workers))
            ]
            cards = [
                session(RotationPolicy(every_pages=0, every_cards=40, max_rotations=unbounded))
                for _ in range(max(1, s.che168_workers))
            ]
            run = Che168Run(
                job=job, queue=self.queue, sink=sink, api=listers[0], list_apis=listers, card_apis=cards,
                max_pages=max_pages, max_cars=max_cars, should_stop=self.stop.is_set, clock=self.clock,
                runlog=runlog, history=MySqlHistoryStore(self.db), rules=rules,
                card_max_age_s=s.che168_card_max_age_hours * 3600,
            )  # fmt: skip
            return await run_che168(run)
        finally:
            for transport in transports:
                transport.close()

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
