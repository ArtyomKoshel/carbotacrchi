"""Full sweep of Encar search results, in resumable segments.

Encar's search stops at ~10 000 results per query, so the old parser split the
catalogue: a global pass (which also discovers makers and models), then one
query per maker, split by year above the cap, and by model inside a year above
the cap. Same plan here, with these changes:

- Progress is checkpointed after every page (segment and next page), so a run
  stopped by a deploy resumes where it stopped; the lots a run already wrote are
  not counted again (the old run() ignored its checkpoint).
- Pages are pipelined: the next page's list and details are fetched while the
  history of earlier pages is still loading, so a page no longer waits for its
  slowest request. What a page did is committed (seen, cursor, progress) strictly
  in page order, so the checkpoint never runs ahead of the work.
- The results move while a run goes: Encar sorts by modification date, a lot
  that changes jumps to the top and a delisted one shifts the rest up. Pages are
  requested with an overlap, a resumed segment starts one page back, and a full
  run ends with a catch-up pass from the top that takes the lots which moved
  into the part already swept.
- Makers do not come from the global pass alone: its unfiltered results are
  unstable (run 19 stopped after 24 pages and 14 makers were never swept), so
  the makers of Encar's navigation and of the earlier runs are swept as well.
- A segment a failure cut short (a page or a Count that kept failing) is not
  marked done: it stays in `incomplete`, is retried once at the end of the run
  from its cursor, and a run with an incomplete segment is not a full run.
"""

from __future__ import annotations

import asyncio
import contextlib
from collections import deque
from collections.abc import Awaitable, Callable, Iterable
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Final

from collector.adapters.encar.api import DEFAULT_QUERY, MAX_SAFE_OFFSET, PAGE_SIZE, EncarApi
from collector.adapters.encar.pipeline import EncarPipeline, PageReport
from collector.http import Failed, Missing, Ok

MAX_PAGES_PER_SEGMENT: Final = 100
YEAR_FROM: Final = 1990
# Lots a page re-reads from the end of the previous one: covers the delistings between two
# requests (each shifts the rest up by one); a repeated lot costs nothing, it is already seen.
PAGE_OVERLAP: Final = 10
CATCH_UP: Final = "catchup"


def year_query(maker: str, year: int, model: str | None = None) -> str:
    q = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99)."
    return q + (f"_.Model.{model}.)" if model else ")")


def maker_query(maker: str) -> str:
    return f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)"


@dataclass
class SweepState:
    """Checkpoint of a run: everything needed to resume it."""

    started_at: str  # ISO, UTC: the run's start on the parsed_at clock, kept across resumes
    api_total: int = 0
    done_segments: list[str] = field(default_factory=list)
    makers: dict[str, list[str]] = field(default_factory=dict)  # discovered in the global pass
    seen: int = 0
    pages: int = 0
    errors: int = 0
    error_log: list[str] = field(default_factory=list)
    report: dict[str, int] = field(default_factory=dict)
    cursor: dict[str, int] = field(default_factory=dict)  # segment -> next page, for unfinished segments
    proxy_bytes: int = 0  # traffic of the run so far, earlier processes included
    incomplete: list[str] = field(default_factory=list)  # segments, makers, years a failure cut short
    counts: dict[str, int] = field(default_factory=dict)  # Encar's Count per segment, maker and year

    def to_json(self) -> dict[str, Any]:
        return self.__dict__.copy()

    @classmethod
    def from_json(cls, data: dict[str, Any]) -> SweepState:
        known = {k: v for k, v in data.items() if k in cls.__dataclass_fields__}
        return cls(**known)


Progress = Callable[[SweepState, str], Awaitable[None]]
ErrorSink = Callable[[str], Awaitable[None]]


@dataclass
class _Pending:
    """A page in flight (task set) or a segment finished behind it (task None)."""

    key: str
    page: int = 0
    fresh: int = 0
    task: asyncio.Task[PageReport] | None = None


class Sweeper:
    def __init__(
        self,
        pipeline: EncarPipeline,
        *,
        run_id: int,
        history_cutoff: datetime | None,
        on_progress: Progress,
        on_error: ErrorSink | None = None,
        should_stop: Callable[[], bool] = lambda: False,
        current_year: int,
        seen: set[str] | None = None,
        depth: int = 3,
        known_makers: Iterable[str] = (),
    ) -> None:
        self.pipeline = pipeline
        self.api: EncarApi = pipeline.api
        self.run_id = run_id
        self.cutoff = history_cutoff
        self.on_progress = on_progress
        self.on_error = on_error
        self.should_stop = should_stop
        self.current_year = current_year
        self._seen: set[str] = set(seen or ())
        self.known_makers = tuple(known_makers)  # makers of earlier runs
        self.depth = max(1, depth)  # pages in flight
        self._pending: deque[_Pending] = deque()
        self.stopped = False

    async def run(
        self, state: SweepState, *, maker: str | None = None, max_pages: int | None = None
    ) -> SweepState:
        """Sweep (or resume) a run. A maker or max_pages makes it a partial run."""
        try:
            await self._plan(state, maker, max_pages)
            await self._drain(state)
        finally:
            await self._cancel_pending()
        return state

    async def _plan(self, state: SweepState, maker: str | None, max_pages: int | None) -> None:
        if maker or max_pages:
            await self._segment(
                state,
                f"q:{maker or 'all'}",
                maker_query(maker) if maker else DEFAULT_QUERY,
                max_pages,
                total=True,
            )
            return
        await self._segment(state, "global", DEFAULT_QUERY, MAX_PAGES_PER_SEGMENT, discover=True, total=True)
        if self.stopped:
            return
        await self._discover(state)
        for mk in sorted(state.makers):
            if self.stopped:
                return
            await self._maker(state, mk)
        # Lots changed or listed while the run went float to the top: take those it passed.
        await self._segment(state, CATCH_UP, DEFAULT_QUERY, MAX_PAGES_PER_SEGMENT)
        await self._retry(state)

    async def _discover(self, state: SweepState) -> None:
        """Makers the global pass did not reach: Encar's navigation, and the makers of earlier runs."""
        for mk in self.known_makers:
            state.makers.setdefault(mk, [])
        outcome = await self.api.makers()
        if isinstance(outcome, Ok):
            state.counts["nav:makers"] = len(outcome.data)
            for mk in outcome.data:
                state.makers.setdefault(mk, [])

    async def _retry(self, state: SweepState) -> None:
        """Once more for what a failure cut short, from where it stopped; failing again, it stays cut."""
        for key in list(state.incomplete):
            if self.stopped:
                return
            kind, _, rest = key.partition(":")
            if key == "global":
                await self._segment(
                    state, key, DEFAULT_QUERY, MAX_PAGES_PER_SEGMENT, discover=True, total=True
                )
            elif key == CATCH_UP:
                await self._segment(state, key, DEFAULT_QUERY, MAX_PAGES_PER_SEGMENT)
            elif kind == "maker":
                await self._maker(state, rest)
            elif kind == "year":
                maker, _, year = rest.rpartition(":")
                await self._year(state, maker, int(year))
            elif kind == "model":
                maker, year, model = rest.split(":", 2)
                await self._segment(state, key, year_query(maker, int(year), model), MAX_PAGES_PER_SEGMENT)

    async def _count(self, query: str) -> int | None:
        outcome = await self.api.search(query, 0, 1)
        return int(outcome.data.get("Count") or 0) if isinstance(outcome, Ok) else None

    async def _maker(self, state: SweepState, maker: str) -> None:
        key = f"maker:{maker}"
        if key in state.done_segments:
            return
        total = await self._count(maker_query(maker))
        if total is None:
            await self._cut(state, key, f"[{maker}] count failed")
            return
        state.counts[key] = total
        if total <= MAX_SAFE_OFFSET:
            await self._segment(state, key, maker_query(maker), MAX_PAGES_PER_SEGMENT)
            return
        for year in range(YEAR_FROM, self.current_year + 2):
            if self.stopped:
                return
            await self._year(state, maker, year)
        cut = any(k.startswith((f"year:{maker}:", f"model:{maker}:")) for k in state.incomplete)
        if not self.stopped and not cut:
            await self._done(state, key)

    async def _year(self, state: SweepState, maker: str, year: int) -> None:
        key = f"year:{maker}:{year}"
        if key in state.done_segments:
            return
        total = await self._count(year_query(maker, year))
        if total is None:
            await self._cut(state, key, f"[{maker}/{year}] count failed")
            return
        if not total:
            await self._done(state, key)
            return
        state.counts[key] = total
        if total <= MAX_SAFE_OFFSET:
            await self._segment(state, key, year_query(maker, year), MAX_PAGES_PER_SEGMENT)
            return
        for model in sorted(state.makers.get(maker, [])):
            if self.stopped:
                return
            mkey = f"model:{maker}:{year}:{model}"
            if mkey in state.done_segments:
                continue
            n = await self._count(year_query(maker, year, model))
            if n is None:
                await self._cut(state, mkey, f"[{maker}/{year}/{model}] count failed")
            elif n:
                await self._segment(state, mkey, year_query(maker, year, model), MAX_PAGES_PER_SEGMENT)
        # Models the global pass did not see: the year query itself, up to the cap.
        await self._segment(state, key, year_query(maker, year), MAX_PAGES_PER_SEGMENT)

    async def _segment(
        self,
        state: SweepState,
        key: str,
        query: str,
        max_pages: int | None,
        *,
        discover: bool = False,
        total: bool = False,
    ) -> None:
        """discover: the global pass (records makers); total: its first page sets api_total."""
        if key in state.done_segments or self.stopped:
            return
        segment_seen: set[str] = set()
        overlap_pages = 0
        # Resumed: one page back, for what moved up during the pause.
        first = max(0, state.cursor[key] - 1) if key in state.cursor else 0
        for page in range(first, max_pages or MAX_PAGES_PER_SEGMENT):
            if self.should_stop():
                self.stopped = True
                return  # not marked done: the resume goes on from the cursor
            start = page * PAGE_SIZE
            if start > MAX_SAFE_OFFSET:
                break
            offset = max(0, start - PAGE_OVERLAP) if page else 0
            size = PAGE_SIZE + (start - offset)
            outcome = await self.api.search(query, offset, size)
            if isinstance(outcome, Failed | Missing):
                # Not the end of the results: the segment stays open from its cursor, for _retry.
                await self._cut(state, key, f"{key} p.{page + 1}: {getattr(outcome, 'reason', 'missing')}")
                return
            data = outcome.data
            count = int(data.get("Count") or 0)
            if page == 0:
                state.counts[key] = count
                if total:
                    state.api_total = count
            items = data.get("SearchResults") or []
            if not items:
                break
            fresh = self._take_new(items, segment_seen, state.makers if discover else None)
            if not fresh:
                # Everything already seen: the API cycles, or an earlier segment covered it.
                if all(str(i.get("Id", "")) in segment_seen for i in items):
                    break
                overlap_pages += 1
                if overlap_pages >= 5 or start + PAGE_SIZE >= count:
                    break
                continue
            overlap_pages = 0
            await self._launch(state, key, page, fresh)
            if start + PAGE_SIZE >= count:
                break
        await self._done(state, key)

    # ── pipeline: pages in flight, committed in order ──────────────────────

    async def _launch(self, state: SweepState, key: str, page: int, fresh: list[dict[str, Any]]) -> None:
        task = asyncio.ensure_future(
            self.pipeline.process(fresh, run_id=self.run_id, history_cutoff=self.cutoff)
        )
        self._pending.append(_Pending(key, page, len(fresh), task))
        # depth = pages in flight at most; 1 = one page at a time, committed right after it
        while sum(1 for p in self._pending if p.task is not None) >= self.depth:
            await self._commit_next(state)

    async def _done(self, state: SweepState, key: str) -> None:
        """A segment is done once every page before it is committed."""
        self._pending.append(_Pending(key))
        while self._pending and self._pending[0].task is None:
            await self._commit_next(state)

    async def _drain(self, state: SweepState) -> None:
        while self._pending:
            await self._commit_next(state)

    async def _commit_next(self, state: SweepState) -> None:
        p = self._pending[0]
        if p.task is None:
            self._pending.popleft()
            state.cursor.pop(p.key, None)
            if p.key not in state.done_segments:
                state.done_segments.append(p.key)
            if p.key in state.incomplete:
                state.incomplete.remove(p.key)  # a retry finished it
            return
        report = await p.task
        self._pending.popleft()
        state.seen += p.fresh
        state.pages += 1
        state.cursor[p.key] = p.page + 1
        state.report = PageReport(**state.report).plus(report).__dict__ if state.report else report.__dict__
        # Counted in the report (detail_failed, history_failed), not in state.errors; logged so they show.
        if report.detail_failed and self.on_error:
            await self.on_error(f"{p.key} p.{p.page + 1}: {report.detail_failed} card(s) failed to load")
        if report.history_failed and self.on_error:
            await self.on_error(f"{p.key} p.{p.page + 1}: history failed for {report.history_failed} lot(s)")
        await self.on_progress(state, f"{p.key} p.{p.page + 1}")

    async def _cancel_pending(self) -> None:
        tasks = [p.task for p in self._pending if p.task is not None and not p.task.done()]
        for t in tasks:
            t.cancel()
        for t in tasks:
            with contextlib.suppress(asyncio.CancelledError, Exception):
                await t
        self._pending.clear()

    def _take_new(
        self, items: list[dict[str, Any]], segment_seen: set[str], makers: dict[str, list[str]] | None
    ) -> list[dict[str, Any]]:
        """Items not seen earlier in this run; the global pass also records makers and models."""
        fresh = []
        for it in items:
            vid = str(it.get("Id", ""))
            if not vid or vid in self._seen:
                continue
            self._seen.add(vid)
            segment_seen.add(vid)
            fresh.append(it)
            if makers is not None and (mk := (it.get("Manufacturer") or "").strip()):
                models = makers.setdefault(mk, [])
                if (mo := (it.get("Model") or "").strip()) and mo not in models:
                    models.append(mo)
        return fresh

    async def _error(self, state: SweepState, message: str) -> None:
        """Counted in the run's result and written to the run log at once, as an error."""
        state.errors += 1
        state.error_log = [*state.error_log, message][-50:]
        if self.on_error:
            await self.on_error(message)

    async def _cut(self, state: SweepState, key: str, message: str) -> None:
        """A failure stopped `key` before its end: not done, the run is not full until a retry finishes it."""
        await self._error(state, message)
        if key not in state.incomplete:
            state.incomplete.append(key)
