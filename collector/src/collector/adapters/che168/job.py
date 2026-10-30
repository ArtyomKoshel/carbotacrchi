"""A che168 parse_jobs run: the bounded API collection, its lots into the ingest buffer.

Che168's API is synchronous (curl_cffi): each session runs its requests in a thread of the run,
so the service stays responsive to SIGTERM. The list pages come from one session, the cards from
several at once (CHE168_WORKERS), each with its own proxy exit and its own pause, as Encar's
collector runs many requests over its proxy pool. The checkpoint (parse_jobs.progress.checkpoint)
holds the next page and the listed cars not yet in the buffer; lots already in the buffer for this
run are never fetched again on resume (sink.resume).

A che168 run never counts as a full run: the list is not a proven snapshot of the site, so
lots:lifecycle must never delist che168 lots from it.
"""

from __future__ import annotations

import asyncio
from collections.abc import Callable, Sequence
from concurrent.futures import ThreadPoolExecutor
from dataclasses import asdict, dataclass, field
from datetime import datetime
from typing import Any, Protocol, TypeVar

from collector import COLLECTOR_VERSION
from collector.adapters.che168.api import CollectionError, SearchPage
from collector.adapters.che168.mapping import to_lot
from collector.domain.carlot import CarLotV1
from collector.ports import Clock, IngestSink, utc_now
from collector.runlog import RunLog
from collector.storage import Job, JobQueue

# A car whose card is refused (sold between the list and the card, or one the mapping cannot
# read) is skipped, not the run; this many in a row means the site changed and the run stops.
CARD_ERRORS = (
    "api_rejected",
    "detail_id_mismatch",
    "invalid_vehicle_fields",
    "invalid_price_or_mileage",
    "photo_schema_changed",
    "invalid_vehicle_id",
)
MAX_CARD_FAILURES_IN_A_ROW = 5
BATCH = 10  # cards per buffer write and checkpoint

T = TypeVar("T")


class Api(Protocol):
    requests: int
    rotations: int
    challenge_rotations: int
    response_bytes: int
    request_seconds: float
    retries_used: int

    def search(self, page: int, pagesize: int) -> SearchPage: ...

    def detail(self, infoid: str) -> dict[str, Any]: ...


@dataclass
class Che168State:
    started_at: str
    next_page: int = 1
    exhausted: bool = False
    pending: list[str] = field(default_factory=list)
    pages: int = 0
    written: int = 0
    skipped: int = 0
    api_total: int = 0

    @classmethod
    def from_json(cls, data: dict[str, Any]) -> Che168State:
        return cls(**{k: data[k] for k in cls.__dataclass_fields__ if k in data})

    def to_json(self) -> dict[str, Any]:
        return {"engine": "che168", **asdict(self)}


@dataclass
class Che168Run:
    job: Job
    queue: JobQueue
    sink: IngestSink
    api: Api  # the list pages
    max_pages: int
    max_cars: int
    pagesize: int = 20
    should_stop: Callable[[], bool] = lambda: False
    clock: Clock = utc_now
    runlog: RunLog | None = None
    # the cards, one worker per session (its own proxy exit and pause); none = `api` alone
    card_apis: Sequence[Api] = ()

    @property
    def apis(self) -> list[Api]:
        return [self.api, *(a for a in self.card_apis if a is not self.api)]


def _limit(filters: dict[str, Any], key: str, default: int) -> int:
    value = filters.get(key)
    return int(value) if isinstance(value, int | str) and str(value).isdigit() and int(value) > 0 else default


def _traffic(run: Che168Run) -> dict[str, Any]:
    """Requests, proxy bytes and answer time over every session of the run."""
    apis = run.apis
    requests = sum(a.requests for a in apis)
    return {
        "requests": requests,
        "proxy_bytes": sum(a.response_bytes for a in apis),
        "avg_request_s": round(sum(a.request_seconds for a in apis) / requests, 2) if requests else None,
        "proxy_rotations": sum(a.rotations for a in apis),
        "challenges": sum(a.challenge_rotations for a in apis),
        "retries": sum(a.retries_used for a in apis),
        "workers": len(run.card_apis) or 1,
    }


def _elapsed(run: Che168Run, state: Che168State) -> float:
    return round(max(0.0, (run.clock() - datetime.fromisoformat(state.started_at)).total_seconds()), 1)


def _progress(run: Che168Run, state: Che168State, max_pages: int, message: str) -> dict[str, Any]:
    pct = round(min(state.pages / max_pages, 1.0) * 100, 1) if max_pages else 0.0
    return {
        "status": f"{pct}%",
        "pct": pct,
        "phase": "search",
        "phase_progress": round(pct / 100, 3),
        "total_progress": round(pct / 100, 3),
        "found_total": state.written,
        "api_total": state.api_total,
        "total": state.written,
        "rejected": state.skipped,
        "page": state.pages,
        "message": message,
        "elapsed_s": _elapsed(run, state),
        **_traffic(run),
        "checkpoint": state.to_json(),
        "collector": COLLECTOR_VERSION,
    }


async def run_che168(run: Che168Run) -> str:
    """Returns done | interrupted | error."""
    return await _Collection(run).run()


class _Collection:
    """One lister reads the pages into a queue, the card workers empty it, each with its own session.

    The lister keeps about one page ahead of the workers (like Encar's pipeline_depth). The
    checkpoint's `pending` is every listed car not yet in the buffer, queued or in flight: a
    resume fetches them again unless the buffer already has them (sink.resume).
    """

    def __init__(self, run: Che168Run) -> None:
        self.run_ = run
        job = run.job
        self.state = (
            Che168State.from_json(job.checkpoint)
            if job.checkpoint
            else Che168State(started_at=run.clock().isoformat(timespec="seconds"))
        )
        self.max_pages = _limit(job.filters, "max_pages", run.max_pages)
        self.max_cars = _limit(job.filters, "max_cars", run.max_cars)
        self.workers = list(run.card_apis) or [run.api]
        self.done: set[str] = set()
        self.batch: list[CarLotV1] = []
        self.batch_ids: list[str] = []
        self.outstanding: dict[str, None] = {}  # listed, not yet in the buffer, in list order
        self.ids: asyncio.Queue[str | None] = asyncio.Queue()
        self.claimed = 0  # cars written or in flight, against max_cars
        self.failures = 0
        self.error: CollectionError | None = None
        self.car_limit = False
        self.flushing = asyncio.Lock()
        self.sessions: dict[int, asyncio.Lock] = {}  # without card_apis the list and the cards share `api`
        self.threads = ThreadPoolExecutor(max_workers=len(self.workers) + 1, thread_name_prefix="che168")

    async def run(self) -> str:
        run, state = self.run_, self.state
        if run.job.checkpoint:
            self.done = await run.sink.resume(run.job.id)
        n = len(self.workers)
        await self._log(
            f"{'resumed' if run.job.checkpoint else 'started'}: {n} card session{'s' if n > 1 else ''}, "
            f"up to {self.max_pages} pages / {self.max_cars} cars"
        )
        try:
            reason = await self._collect()
        except CollectionError as e:
            await self._write()
            await self._log(f"stopped: {e.kind} · {self._totals()}", "error")
            await run.queue.finish(run.job.id, "error", {**_result(run, state, e.kind), "error": e.kind})
            return "error"
        finally:
            self.threads.shutdown(wait=False, cancel_futures=True)
        await self._write()  # a limit can end the run mid-batch
        if reason == "interrupted":
            await run.queue.interrupt(run.job.id, self._checkpoint())
            await self._log(f"stopped after {state.written} cars; resumes from the checkpoint", "warning")
            return "interrupted"
        progress = {**_progress(run, state, self.max_pages, "done"), "status": "done"}
        await run.queue.finish(run.job.id, "done", _result(run, state, reason), progress=progress)
        await self._log(f"done ({reason}): {self._totals()}")
        return "done"

    async def _collect(self) -> str:
        """The reason the run ended; raises the first error any session met."""
        for infoid in self.state.pending:
            if infoid not in self.done and infoid not in self.outstanding:
                self.outstanding[infoid] = None
                self.ids.put_nowait(infoid)
        tasks = [asyncio.create_task(self._card_worker(api)) for api in self.workers]
        try:
            reason = await self._lister()
        finally:
            for _ in tasks:
                self.ids.put_nowait(None)  # after what is queued: the workers finish the listed cars
        await asyncio.gather(*tasks)
        if self.error is not None:
            raise self.error
        if self.run_.should_stop():
            return "interrupted"
        return "car_limit" if self.car_limit else reason

    async def _lister(self) -> str:
        state, run = self.state, self.run_
        while True:
            # one page ahead of the workers, not the whole site
            while self.ids.qsize() >= run.pagesize and not self._halted() and not self.car_limit:
                await asyncio.sleep(0.05)
            if self._halted():
                return "interrupted"
            if self.car_limit or self.claimed >= self.max_cars:
                return "car_limit"
            if state.exhausted:
                return "api_end_reached"
            if state.next_page > self.max_pages:
                return "page_limit"
            try:
                page = await self._call(run.api, run.api.search, state.next_page, run.pagesize)
            except CollectionError as e:
                self.error = self.error or e
                return "error"
            state.api_total = page.total
            state.pages = page.number
            state.next_page = page.number + 1
            state.exhausted = page.number >= page.page_count
            fresh = [i for i in dict.fromkeys(page.ids) if i not in self.done and i not in self.outstanding]
            for infoid in fresh:
                self.outstanding[infoid] = None
                self.ids.put_nowait(infoid)
            await self._flush(f"page {page.number}: {len(page.ids)} cars listed")
            listed = f"page {page.number}/{self.max_pages}: {len(page.ids)} listed, {len(fresh)} new"
            await self._log(f"{listed} · {self._totals()}")

    async def _card_worker(self, api: Api) -> None:
        while True:
            infoid = await self.ids.get()
            if infoid is None or self._halted():
                return
            if infoid in self.done:
                self.outstanding.pop(infoid, None)
                continue
            if self.claimed >= self.max_cars:
                self.car_limit = True
                return
            self.claimed += 1
            try:
                data = await self._call(api, api.detail, infoid)
                lot = to_lot(data, self.run_.clock()).model_copy(update={"run_id": self.run_.job.id})
            except CollectionError as e:
                self.claimed -= 1
                self.failures += 1
                if e.kind not in CARD_ERRORS or self.failures >= MAX_CARD_FAILURES_IN_A_ROW:
                    self.error = self.error or e
                    return
                self.state.skipped += 1
                self.done.add(infoid)
                self.outstanding.pop(infoid, None)
                await self._log(f"car {infoid} skipped: {e.kind}", "warning")
                continue
            self.failures = 0
            self.done.add(infoid)
            self.batch.append(lot)
            self.batch_ids.append(infoid)
            self.state.written += 1
            if len(self.batch) >= BATCH:
                await self._flush(f"page {self.state.pages}: {self.state.written} cars")

    def _halted(self) -> bool:
        return self.error is not None or self.run_.should_stop()

    async def _call(self, api: Api, fn: Callable[..., T], *args: Any) -> T:
        """A synchronous curl_cffi request in this run's own threads; one request at a time per session."""
        async with self.sessions.setdefault(id(api), asyncio.Lock()):
            return await asyncio.get_running_loop().run_in_executor(self.threads, fn, *args)

    async def _write(self) -> None:
        """The cars gathered so far into the buffer; only then they leave the checkpoint's pending."""
        if not self.batch:
            return
        batch, ids = self.batch, self.batch_ids
        self.batch, self.batch_ids = [], []
        await self.run_.sink.write(batch)
        for infoid in ids:
            self.outstanding.pop(infoid, None)

    def _checkpoint(self) -> dict[str, Any]:
        self.state.pending = list(self.outstanding)
        return self.state.to_json()

    async def _flush(self, message: str) -> None:
        """Lots into the buffer, then the checkpoint: a resume never loses a written card."""
        async with self.flushing:
            await self._write()
            self._checkpoint()
            progress = _progress(self.run_, self.state, self.max_pages, message)
            await self.run_.queue.progress(self.run_.job.id, progress)

    def _totals(self) -> str:
        """«97 cars, 10.8 cars/min, 0 skipped · 102 requests, 2.3 s per answer, 4 sessions · proxy …»."""
        t, state = _traffic(self.run_), self.state
        elapsed = _elapsed(self.run_, state)
        per_min = f", {state.written / elapsed * 60:.1f} cars/min" if elapsed > 0 else ""
        avg = f"{t['avg_request_s']:.1f} s per answer" if t["avg_request_s"] is not None else "no answers yet"
        sessions = f"{t['workers']} session{'s' if t['workers'] > 1 else ''}"
        return (
            f"{state.written} cars{per_min}, {state.skipped} skipped"
            f" · {t['requests']} requests, {avg}, {sessions}"
            f" · proxy {t['proxy_bytes'] / 1024**2:.1f} MB"
            f" · {t['proxy_rotations']} rotations, {t['challenges']} challenges, {t['retries']} retries"
        )

    async def _log(self, message: str, level: str = "info") -> None:
        if self.run_.runlog:
            await getattr(self.run_.runlog, level)(message)


def _result(run: Che168Run, state: Che168State, reason: str) -> dict[str, Any]:
    started = datetime.fromisoformat(state.started_at)
    return {
        "engine": "che168",
        "stop_reason": reason,
        "total": state.written,
        "api_total": state.api_total,
        "pages": state.pages,
        "rejected": state.skipped,
        # never a full run: lots:lifecycle must not delist che168 lots by it
        "full_run": False,
        "coverage_pct": 0.0,
        "run_started_at": started.strftime("%Y-%m-%d %H:%M:%S"),
        "ingest_last_id": getattr(run.sink, "last_id", None),
        **_traffic(run),
        "elapsed_s": _elapsed(run, state),
        "collector": COLLECTOR_VERSION,
    }
