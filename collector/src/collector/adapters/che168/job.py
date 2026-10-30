"""A che168 parse_jobs run: the bounded API collection, its lots into the ingest buffer.

Che168's API is synchronous (curl_cffi); each request runs in a thread so the service stays
responsive to SIGTERM. The checkpoint (parse_jobs.progress.checkpoint) holds the next page and
the ids of the current page still to fetch; lots already in the buffer for this run are never
fetched again on resume (sink.resume).

A che168 run never counts as a full run: the list is not a proven snapshot of the site, so
lots:lifecycle must never delist che168 lots from it.
"""

from __future__ import annotations

import asyncio
from collections.abc import Callable
from dataclasses import asdict, dataclass, field
from datetime import datetime
from typing import Any, Protocol

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


class Api(Protocol):
    requests: int
    rotations: int

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
    api: Api
    max_pages: int
    max_cars: int
    pagesize: int = 20
    should_stop: Callable[[], bool] = lambda: False
    clock: Clock = utc_now
    runlog: RunLog | None = None


def _limit(filters: dict[str, Any], key: str, default: int) -> int:
    value = filters.get(key)
    return int(value) if isinstance(value, int | str) and str(value).isdigit() and int(value) > 0 else default


def _progress(state: Che168State, max_pages: int, message: str) -> dict[str, Any]:
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
        "checkpoint": state.to_json(),
        "collector": COLLECTOR_VERSION,
    }


async def run_che168(run: Che168Run) -> str:
    """Returns done | interrupted | error."""
    return await _Collection(run).run()


class _Collection:
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
        self.done: set[str] = set()
        self.batch: list[CarLotV1] = []
        self.failures = 0

    async def run(self) -> str:
        run, state = self.run_, self.state
        if run.job.checkpoint:
            self.done = await run.sink.resume(run.job.id)
        try:
            reason = await self._collect()
        except CollectionError as e:
            await self._write()
            await self._log(f"stopped: {e.kind}", "error")
            await run.queue.finish(run.job.id, "error", {**_result(run, state, e.kind), "error": e.kind})
            return "error"
        await self._write()  # a limit can end the run mid-batch
        if reason == "interrupted":
            await run.queue.interrupt(run.job.id, state.to_json())
            await self._log(f"stopped after {state.written} cars; resumes from the checkpoint", "warning")
            return "interrupted"
        progress = {**_progress(state, self.max_pages, "done"), "status": "done"}
        await run.queue.finish(run.job.id, "done", _result(run, state, reason), progress=progress)
        await self._log(
            f"done ({reason}): {state.written} cars from {state.pages} pages, {state.skipped} skipped"
        )
        return "done"

    async def _collect(self) -> str:
        """Cards of the current page first, then the next page; the reason the run ended."""
        state = self.state
        while True:
            if self.run_.should_stop():
                await self._flush("stopping")
                return "interrupted"
            if state.written >= self.max_cars:
                return "car_limit"
            if state.pending:
                await self._card(state.pending[0])
                state.pending.pop(0)
                if len(self.batch) >= BATCH:
                    await self._flush(f"page {state.pages}: {state.written} cars")
                continue
            await self._flush(f"page {state.pages}: {state.written} cars")
            if state.exhausted:
                return "api_end_reached"
            if state.next_page > self.max_pages:
                return "page_limit"
            await self._page()

    async def _card(self, infoid: str) -> None:
        if infoid in self.done:
            return
        run = self.run_
        try:
            data = await asyncio.to_thread(run.api.detail, infoid)
            self.batch.append(to_lot(data, run.clock()).model_copy(update={"run_id": run.job.id}))
            self.state.written += 1
            self.failures = 0
        except CollectionError as e:
            self.failures += 1
            if e.kind not in CARD_ERRORS or self.failures >= MAX_CARD_FAILURES_IN_A_ROW:
                raise
            self.state.skipped += 1
            await self._log(f"car {infoid} skipped: {e.kind}", "warning")
        self.done.add(infoid)

    async def _page(self) -> None:
        state = self.state
        page = await asyncio.to_thread(self.run_.api.search, state.next_page, self.run_.pagesize)
        state.api_total = page.total
        state.pending = [i for i in dict.fromkeys(page.ids) if i not in self.done]
        state.pages = page.number
        state.next_page = page.number + 1
        state.exhausted = page.number >= page.page_count
        await self._flush(f"page {page.number}: {len(page.ids)} cars listed")

    async def _write(self) -> None:
        if self.batch:
            await self.run_.sink.write(self.batch)
            self.batch.clear()

    async def _flush(self, message: str) -> None:
        """Lots into the buffer, then the checkpoint: a resume never loses a written card."""
        await self._write()
        await self.run_.queue.progress(self.run_.job.id, _progress(self.state, self.max_pages, message))

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
        "requests": run.api.requests,
        "proxy_rotations": run.api.rotations,
        "elapsed_s": round(max(0.0, (run.clock() - started).total_seconds()), 1),
        "collector": COLLECTOR_VERSION,
    }
