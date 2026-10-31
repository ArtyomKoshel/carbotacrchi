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
from collector.adapters.che168.mapping import CARD_VERSION, model_year, scaled_integer, stored_card, to_lot
from collector.domain.carlot import CarLotV1, SelectionPhase
from collector.ports import Clock, HistoryState, HistoryStore, IngestSink, utc_now
from collector.runlog import RunLog
from collector.selection import RuleEngine, Verdict
from collector.storage import Job, JobQueue

# A car whose card is refused (sold between the list and the card, or one the mapping cannot
# read) or still unreachable after its retries is skipped, not the run: the next run fetches it.
# This many in a row means the site changed or the proxy is down, and the run stops.
CARD_ERRORS = (
    "network_error",
    "temporary_http_error",
    "api_rejected",
    "detail_id_mismatch",
    "invalid_vehicle_fields",
    "invalid_price_or_mileage",
    "photo_schema_changed",
    "invalid_vehicle_id",
)
MAX_CARD_FAILURES_IN_A_ROW = 5
BATCH = 10  # cards per buffer write and checkpoint
SOURCE = "che168"

T = TypeVar("T")

# A rule with no platform is written in Encar's ₩: a limit on the price, the repair cost or the new
# price would compare ¥ with ₩, so che168 does not take it; a che168 limit is a Che168 rule in ¥.
KRW_FIELDS = frozenset({"price", "repair_cost", "retail_value"})
# What the search list knows before the card: the early pass rejects a car without a request.
LIST_STAGE_FIELDS = frozenset({"year", "mileage"})


def che168_rules(rules: RuleEngine) -> RuleEngine:
    """The selection rules che168 can judge: its own and those with no platform, but not ₩ limits."""
    return rules.excluding(lambda r: r.source is None and r.field in KRW_FIELDS)


def rule_values(lot: CarLotV1) -> dict[str, Any]:
    """What the rules see: the lots columns, as the core stores the lot (Encar: rule_values)."""
    v, li = lot.vehicle, lot.listing
    return {
        "source": SOURCE,
        "make": v.make,
        "model": v.model,
        "year": v.year,
        "first_reg_date": v.first_registration.isoformat() if v.first_registration else None,
        "fuel": v.fuel,
        "transmission": v.transmission,
        "drive_type": v.drive_type,
        "body_type": v.body_type,
        "engine_volume": v.engine_volume_l,
        "seat_count": v.seat_count,
        "color": v.color,
        "price": li.price.amount,
        "mileage": li.mileage_km,
        "location": li.location,
        "retail_value": li.retail_value.amount if li.retail_value else None,
        "sell_type": li.sell_type,
    }


def list_values(car: dict[str, Any]) -> dict[str, Any]:
    """The year and mileage the search list states, for the early pass; unknown stays None."""
    try:
        mileage: int | None = scaled_integer(car.get("mileage"))
    except CollectionError:
        mileage = None
    return {"source": SOURCE, "year": model_year(car.get("carname")), "mileage": mileage}


class Api(Protocol):
    requests: int
    rotations: int
    challenge_rotations: int
    response_bytes: int
    request_seconds: float
    retries_used: int
    retry_reasons: dict[str, int]
    max_request_s: float

    def search(self, page: int, pagesize: int) -> SearchPage: ...

    def detail(self, infoid: str) -> dict[str, Any]: ...

    def options(self, infoid: str) -> list[tuple[str, str]]: ...


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
    stored: int = 0  # of `written`: taken from the card store, no request
    filtered: int = 0  # rejected by the selection rules (of them, the early ones are never written)
    options_failed: int = 0  # cards written without options: the next run fetches them again
    page_count: int = 0  # known after the first page

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
    # more list sessions: a wave of pages is read at once, one per session; none = `api` alone
    list_apis: Sequence[Api] = ()
    # the card store (collector.lot_history, source che168): a listed car whose list facts match
    # its stored card, fetched less than card_max_age_s ago, is not fetched again
    history: HistoryStore | None = None
    card_max_age_s: float = 7 * 24 * 3600
    # parse_filters as che168 can judge them (che168_rules); None = every car passes
    rules: RuleEngine | None = None

    @property
    def listers(self) -> list[Api]:
        return [self.api, *(a for a in self.list_apis if a is not self.api)]

    @property
    def apis(self) -> list[Api]:
        return [*self.listers, *(a for a in self.card_apis if a is not self.api)]


def _limit(filters: dict[str, Any], key: str, default: int) -> int:
    value = filters.get(key)
    return int(value) if isinstance(value, int | str) and str(value).isdigit() and int(value) > 0 else default


def _traffic(run: Che168Run) -> dict[str, Any]:
    """Requests, proxy bytes and answer time over every session of the run."""
    apis = run.apis
    requests = sum(a.requests for a in apis)
    reasons: dict[str, int] = {}
    for a in apis:
        for cause, n in a.retry_reasons.items():
            reasons[cause] = reasons.get(cause, 0) + n
    return {
        "requests": requests,
        "proxy_bytes": sum(a.response_bytes for a in apis),
        "avg_request_s": round(sum(a.request_seconds for a in apis) / requests, 2) if requests else None,
        "proxy_rotations": sum(a.rotations for a in apis),
        "challenges": sum(a.challenge_rotations for a in apis),
        "retries": sum(a.retries_used for a in apis),
        "retry_reasons": dict(sorted(reasons.items(), key=lambda kv: -kv[1])),
        "max_request_s": round(max((a.max_request_s for a in apis), default=0.0), 1),
        "workers": len(run.card_apis) or 1,
        "sessions": [_session(name, a) for name, a in _named(run)],
    }


def _named(run: Che168Run) -> list[tuple[str, Api]]:
    """«list», «l2»… read the pages, «w1»… fetch the cards; without card_apis «list» does both."""
    lists = [("list" if i == 1 else f"l{i}", a) for i, a in enumerate(run.listers, 1)]
    return [*lists, *((f"w{i}", a) for i, a in enumerate(run.card_apis, 1) if a is not run.api)]


def _session(name: str, api: Api) -> dict[str, Any]:
    return {
        "name": name,
        "requests": api.requests,
        "retries": api.retries_used,
        "avg_request_s": round(api.request_seconds / api.requests, 2) if api.requests else None,
        "max_request_s": round(api.max_request_s, 1),
    }


def _elapsed(run: Che168Run, state: Che168State) -> float:
    return round(max(0.0, (run.clock() - datetime.fromisoformat(state.started_at)).total_seconds()), 1)


def _target(state: Che168State, max_pages: int, max_cars: int, pagesize: int) -> int:
    """The cars this run sets out to fetch: its limits, or fewer when the site has fewer."""
    target = min(max_cars, max_pages * pagesize)
    return min(target, state.api_total) if state.api_total else target


def _progress(run: Che168Run, state: Che168State, target: int, message: str) -> dict[str, Any]:
    pct = round(min((state.written + state.skipped) / target, 1.0) * 100, 1) if target else 0.0
    return {
        "status": f"{pct}%",
        "pct": pct,
        "phase": "search",
        "phase_progress": round(pct / 100, 3),
        "total_progress": round(pct / 100, 3),
        "found_total": state.written,
        "api_total": state.api_total,
        "target": target,
        "total": state.written,
        "rejected": state.skipped,
        "stored": state.stored,
        "filtered": state.filtered,
        "options_failed": state.options_failed,
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
        self.batch_cards: list[HistoryState] = []  # fetched cards for the store, saved with their batch
        self.facts: dict[str, tuple[str, ...]] = {}  # the list's facts per listed car
        self.outstanding: dict[str, None] = {}  # listed, not yet in the buffer, in list order
        self.ids: asyncio.Queue[str | None] = asyncio.Queue()
        self.claimed = 0  # cars written or in flight, against max_cars
        self.failures = 0
        self.error: CollectionError | None = None
        self.car_limit = False
        self.crash: BaseException | None = None  # a worker's unexpected error, raised after the others stop
        self.flushing = asyncio.Lock()
        self.sessions: dict[int, asyncio.Lock] = {}  # without card_apis the list and the cards share `api`
        self.listers = run.listers
        threads = len(self.workers) + len(self.listers)
        self.threads = ThreadPoolExecutor(max_workers=threads, thread_name_prefix="che168")

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
            await self._log(self._sessions())
            result = {**_result(run, state, e.kind, self._target()), "error": e.kind}
            await run.queue.finish(run.job.id, "error", result)
            return "error"
        finally:
            self.threads.shutdown(wait=False, cancel_futures=True)
        await self._write()  # a limit can end the run mid-batch
        if reason == "interrupted":
            await run.queue.interrupt(run.job.id, self._checkpoint())
            await self._log(f"stopped after {state.written} cars; resumes from the checkpoint", "warning")
            return "interrupted"
        target = self._target()
        progress = {**_progress(run, state, target, "done"), "status": "done"}
        await run.queue.finish(run.job.id, "done", _result(run, state, reason, target), progress=progress)
        await self._log(f"done ({reason}): {self._totals()}")
        await self._log(self._sessions())
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
            # a wave ahead of the workers, not the whole site
            ahead = run.pagesize * len(self.listers)
            while self.ids.qsize() >= ahead and not self._halted() and not self.car_limit:
                await asyncio.sleep(0.05)
            if self._halted():
                return "interrupted"
            if self.car_limit or self.claimed >= self.max_cars:
                return "car_limit"
            if state.exhausted:
                return "api_end_reached"
            if state.next_page > self.max_pages:
                return "page_limit"
            # the first page says how many there are; then a page per list session at once
            last = min(self.max_pages, state.page_count) if state.page_count else self.max_pages
            width = len(self.listers) if state.page_count else 1
            numbers = range(state.next_page, min(state.next_page + width, last + 1))
            answers = await asyncio.gather(
                *(
                    self._call(api, api.search, n, run.pagesize)
                    for api, n in zip(self.listers, numbers, strict=False)
                ),
                return_exceptions=True,
            )
            pages: list[SearchPage] = []
            for answer in answers:
                if isinstance(answer, CollectionError):
                    self.error = self.error or answer
                elif isinstance(answer, BaseException):
                    raise answer
                else:
                    pages.append(answer)
            if self.error is not None:
                return "error"
            for page in pages:  # in page order: the checkpoint's next page passes the whole wave
                await self._listed(page)

    async def _listed(self, page: SearchPage) -> None:
        """A page's cars: from the store when unchanged, else to the card workers."""
        state = self.state
        state.api_total = page.total
        state.page_count = page.page_count
        state.pages = page.number
        state.next_page = page.number + 1
        state.exhausted = page.number >= page.page_count
        fresh = [i for i in dict.fromkeys(page.ids) if i not in self.done and i not in self.outstanding]
        self.facts.update(page.facts)
        stored = await self._stored_cards(fresh)
        for infoid in fresh:
            if infoid not in stored and self._rejected_early(page.cars.get(infoid)):
                # never written: it is not in lots (it was never fetched), there is nothing to deactivate
                self.state.filtered += 1
                self.done.add(infoid)
                continue
            self.outstanding[infoid] = None
            if infoid not in stored or not self._from_store(infoid, stored[infoid]):
                self.ids.put_nowait(infoid)
        await self._flush(f"page {page.number}: {len(page.ids)} cars listed")
        listed = f"page {page.number}/{self.max_pages}: {len(page.ids)} listed, {len(fresh)} new"
        await self._log(f"{listed} · {self._totals()}")

    async def _card_worker(self, api: Api) -> None:
        """A worker that dies of an unexpected error halts the run: the list would wait for it forever."""
        try:
            await self._card_work(api)
        except BaseException as e:
            self.crash = self.crash or e
            raise

    async def _card_work(self, api: Api) -> None:
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
                data = {**data, "options": await self._options(api, infoid)}
                lot = self._judged(
                    to_lot(data, self.run_.clock()).model_copy(update={"run_id": self.run_.job.id})
                )
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
            if infoid in self.facts:  # a resumed car without its list facts is not stored
                now = self.run_.clock()
                card = {"card": stored_card(data, now)}
                self.batch_cards.append(HistoryState(infoid, now, self.facts[infoid], card))
            self.state.written += 1
            if len(self.batch) >= BATCH:
                await self._flush(f"page {self.state.pages}: {self.state.written} cars")

    async def _stored_cards(self, ids: list[str]) -> dict[str, dict[str, Any]]:
        """Stored cards of these cars still good for this run: same list facts, fresh enough."""
        history = self.run_.history
        if history is None or not ids:
            return {}
        cutoff = self.run_.clock().timestamp() - self.run_.card_max_age_s
        states = await history.states(SOURCE, ids)
        return {
            i: s.fields["card"]
            for i, s in states.items()
            if isinstance(s.fields.get("card"), dict)
            and "options" in s.fields["card"]  # a card stored without its options is fetched again
            and s.fields["card"].get("_v") == CARD_VERSION  # so is one stored before newer fields
            and s.condition == self.facts.get(i)
            and s.checked_at.timestamp() >= cutoff
        }

    def _from_store(self, infoid: str, card: dict[str, Any]) -> bool:
        """The car from its stored card, like a fetched one; False: fetch it (limit reached or a bad card)."""
        if self.claimed >= self.max_cars:
            return False  # the worker that takes it marks the car limit
        try:
            lot = self._judged(
                to_lot(card, self.run_.clock()).model_copy(update={"run_id": self.run_.job.id})
            )
        except CollectionError:
            return False
        self.claimed += 1
        self.done.add(infoid)
        self.batch.append(lot)
        self.batch_ids.append(infoid)
        self.state.written += 1
        self.state.stored += 1
        return True

    async def _options(self, api: Api, infoid: str) -> list[tuple[str, str]] | None:
        """The card's options, a second request; None when it failed: the car is written without them."""
        try:
            return await self._call(api, api.options, infoid)
        except CollectionError as e:
            self.state.options_failed += 1
            await self._log(
                f"car {infoid}: options not fetched ({e.kind}), the next run fetches them", "warning"
            )
            return None

    def _rejected_early(self, car: dict[str, Any] | None) -> bool:
        """The list's year and mileage alone reject the car (Encar's list stage): no card to fetch."""
        rules = self.run_.rules
        if rules is None or car is None:
            return False
        verdict = rules.evaluate(
            list_values(car), source=SOURCE, phase=SelectionPhase.PRE, only_fields=LIST_STAGE_FIELDS
        )
        return verdict.rejects

    def _judged(self, lot: CarLotV1) -> CarLotV1:
        """The lot with the rules' verdict (PRE, then POST, as Encar); the core acts on it."""
        rules = self.run_.rules
        if rules is None:
            return lot
        values = rule_values(lot)
        verdict, phase = rules.evaluate(values, source=SOURCE, phase=SelectionPhase.PRE), SelectionPhase.PRE
        if not verdict.rejects:
            post = rules.evaluate(values, source=SOURCE, phase=SelectionPhase.POST)
            if post.rejects:
                verdict, phase = post, SelectionPhase.POST
            else:
                verdict = Verdict(verdict.action, verdict.matched + post.matched)
        if verdict.rejects:
            self.state.filtered += 1
        extra = {**lot.extra, "selection_evaluated": True}
        return lot.model_copy(update={"selection": verdict.selection(phase), "extra": extra})

    def _halted(self) -> bool:
        return self.error is not None or self.crash is not None or self.run_.should_stop()

    async def _call(self, api: Api, fn: Callable[..., T], *args: Any) -> T:
        """A synchronous curl_cffi request in this run's own threads; one request at a time per session."""
        async with self.sessions.setdefault(id(api), asyncio.Lock()):
            return await asyncio.get_running_loop().run_in_executor(self.threads, fn, *args)

    async def _write(self) -> None:
        """The cars gathered so far into the buffer; only then they leave the checkpoint's pending."""
        if not self.batch:
            return
        batch, ids, cards = self.batch, self.batch_ids, self.batch_cards
        self.batch, self.batch_ids, self.batch_cards = [], [], []
        await self.run_.sink.write(batch)
        if self.run_.history is not None and cards:
            await self.run_.history.save(SOURCE, cards)  # after the lots: a lost save only costs a refetch
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
            progress = _progress(self.run_, self.state, self._target(), message)
            await self.run_.queue.progress(self.run_.job.id, progress)

    def _target(self) -> int:
        return _target(self.state, self.max_pages, self.max_cars, self.run_.pagesize)

    def _totals(self) -> str:
        """«97 cars, 10.8 cars/min, 0 skipped · 102 requests, 2.3 s per answer, 4 sessions · proxy …»."""
        t, state = _traffic(self.run_), self.state
        elapsed = _elapsed(self.run_, state)
        from_store = f" ({state.stored} from the card store)" if state.stored else ""
        per_min = f", {state.written / elapsed * 60:.1f} cars/min" if elapsed > 0 else ""
        avg = f"{t['avg_request_s']:.1f} s per answer" if t["avg_request_s"] is not None else "no answers yet"
        sessions = f"{t['workers']} session{'s' if t['workers'] > 1 else ''}"
        reasons = ", ".join(f"{cause} {n}" for cause, n in t["retry_reasons"].items())
        why = f" ({reasons})" if reasons else ""
        return (
            f"{state.written} cars{from_store}{per_min}, {state.skipped} skipped, {state.filtered} filtered"
            f" · {t['requests']} requests, {avg}, {sessions}"
            f" · proxy {t['proxy_bytes'] / 1024**2:.1f} MB"
            f" · {t['proxy_rotations']} rotations, {t['challenges']} challenges, {t['retries']} retries{why}"
        )

    def _sessions(self) -> str:
        """«sessions: list 6 req, 0 retries, 0.4 s avg, 0.9 s max · w1 31 req, 9 retries, 9.8 s avg, …»."""
        parts = []
        for s in _traffic(self.run_)["sessions"]:
            avg = s["avg_request_s"] if s["avg_request_s"] is not None else "-"
            name, req, retries, slowest = s["name"], s["requests"], s["retries"], s["max_request_s"]
            parts.append(f"{name} {req} req, {retries} retries, {avg} s avg, {slowest} s max")
        return "sessions: " + " · ".join(parts)

    async def _log(self, message: str, level: str = "info") -> None:
        if self.run_.runlog:
            await getattr(self.run_.runlog, level)(message)


def _result(run: Che168Run, state: Che168State, reason: str, target: int) -> dict[str, Any]:
    started = datetime.fromisoformat(state.started_at)
    return {
        "engine": "che168",
        "stop_reason": reason,
        "total": state.written,
        "api_total": state.api_total,
        "target": target,
        "pages": state.pages,
        "rejected": state.skipped,
        "stored": state.stored,
        "filtered": state.filtered,
        "options_failed": state.options_failed,
        # never a full run: lots:lifecycle must not delist che168 lots by it
        "full_run": False,
        "coverage_pct": 0.0,
        "run_started_at": started.strftime("%Y-%m-%d %H:%M:%S"),
        "ingest_last_id": getattr(run.sink, "last_id", None),
        **_traffic(run),
        "elapsed_s": _elapsed(run, state),
        "collector": COLLECTOR_VERSION,
    }
