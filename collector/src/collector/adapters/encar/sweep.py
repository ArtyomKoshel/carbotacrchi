"""Full sweep of Encar search results, in resumable segments.

Encar's search stops at ~10 000 results per query, so the old parser split the
catalogue: a global pass (which also discovers makers and models), then one
query per maker, split by year above the cap, and by model inside a year above
the cap. Same plan here, with one change: progress is checkpointed after every
segment, so a run stopped by a deploy resumes where it stopped instead of
starting over (the old run() ignored its checkpoint). Inside a segment the
cursor keeps the next page, and the lots a run already wrote are not counted
again when it resumes.
"""

from __future__ import annotations

from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Final

from collector.adapters.encar.api import DEFAULT_QUERY, MAX_SAFE_OFFSET, PAGE_SIZE, EncarApi
from collector.adapters.encar.pipeline import EncarPipeline, PageReport
from collector.http import Failed, Missing, Ok

MAX_PAGES_PER_SEGMENT: Final = 100
YEAR_FROM: Final = 1990


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

    def to_json(self) -> dict[str, Any]:
        return self.__dict__.copy()

    @classmethod
    def from_json(cls, data: dict[str, Any]) -> SweepState:
        known = {k: v for k, v in data.items() if k in cls.__dataclass_fields__}
        return cls(**known)


Progress = Callable[[SweepState, str], Awaitable[None]]


class Sweeper:
    def __init__(
        self,
        pipeline: EncarPipeline,
        *,
        run_id: int,
        history_cutoff: datetime | None,
        on_progress: Progress,
        should_stop: Callable[[], bool] = lambda: False,
        current_year: int,
        seen: set[str] | None = None,
    ) -> None:
        self.pipeline = pipeline
        self.api: EncarApi = pipeline.api
        self.run_id = run_id
        self.cutoff = history_cutoff
        self.on_progress = on_progress
        self.should_stop = should_stop
        self.current_year = current_year
        self._seen: set[str] = set(seen or ())
        self.stopped = False

    async def run(
        self, state: SweepState, *, maker: str | None = None, max_pages: int | None = None
    ) -> SweepState:
        """Sweep (or resume) a run. A maker or max_pages makes it a partial run."""
        if maker or max_pages:
            await self._segment(
                state,
                f"q:{maker or 'all'}",
                maker_query(maker) if maker else DEFAULT_QUERY,
                max_pages,
                total=True,
            )
            return state
        await self._segment(state, "global", DEFAULT_QUERY, MAX_PAGES_PER_SEGMENT, discover=True, total=True)
        for mk in sorted(state.makers):
            if self.stopped:
                break
            await self._maker(state, mk)
        return state

    async def _count(self, query: str) -> int | None:
        outcome = await self.api.search(query, 0, 1)
        return int(outcome.data.get("Count") or 0) if isinstance(outcome, Ok) else None

    async def _maker(self, state: SweepState, maker: str) -> None:
        if f"maker:{maker}" in state.done_segments:
            return
        total = await self._count(maker_query(maker))
        if total is None:
            self._error(state, f"[{maker}] count failed")
        elif total <= MAX_SAFE_OFFSET:
            await self._segment(state, f"maker:{maker}", maker_query(maker), MAX_PAGES_PER_SEGMENT)
            return
        else:
            for year in range(YEAR_FROM, self.current_year + 2):
                if self.stopped:
                    return
                await self._year(state, maker, year)
        if not self.stopped:
            state.done_segments.append(f"maker:{maker}")

    async def _year(self, state: SweepState, maker: str, year: int) -> None:
        key = f"year:{maker}:{year}"
        if key in state.done_segments:
            return
        total = await self._count(year_query(maker, year))
        if not total:
            if total is None:
                self._error(state, f"[{maker}/{year}] count failed")
            state.done_segments.append(key)
            return
        if total <= MAX_SAFE_OFFSET:
            await self._segment(state, key, year_query(maker, year), MAX_PAGES_PER_SEGMENT)
            return
        for model in sorted(state.makers.get(maker, [])):
            if self.stopped:
                return
            mkey = f"model:{maker}:{year}:{model}"
            if mkey not in state.done_segments and await self._count(year_query(maker, year, model)):
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
        for page in range(state.cursor.get(key, 0), max_pages or MAX_PAGES_PER_SEGMENT):
            if self.should_stop():
                self.stopped = True
                return  # not marked done: the resume repeats this segment
            offset = page * PAGE_SIZE
            if offset > MAX_SAFE_OFFSET:
                break
            outcome = await self.api.search(query, offset, PAGE_SIZE)
            if isinstance(outcome, Failed | Missing):
                self._error(state, f"{key} p.{page + 1}: {getattr(outcome, 'reason', 'missing')}")
                break
            data = outcome.data
            count = int(data.get("Count") or 0)
            if total and page == 0:
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
                if overlap_pages >= 5 or offset + PAGE_SIZE >= count:
                    break
                continue
            overlap_pages = 0
            report = await self.pipeline.process(fresh, run_id=self.run_id, history_cutoff=self.cutoff)
            state.seen += len(fresh)
            state.pages += 1
            state.cursor[key] = page + 1
            state.report = (
                PageReport(**state.report).plus(report).__dict__ if state.report else report.__dict__
            )
            await self.on_progress(state, f"{key} p.{page + 1}")
            if offset + PAGE_SIZE >= count:
                break
        state.cursor.pop(key, None)
        state.done_segments.append(key)

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

    @staticmethod
    def _error(state: SweepState, message: str) -> None:
        state.errors += 1
        state.error_log = [*state.error_log, message][-50:]
