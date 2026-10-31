from __future__ import annotations

import asyncio
import json
import threading
from collections.abc import Iterator
from datetime import UTC, date, datetime, timedelta
from typing import Any

import pytest

from collector.adapters.che168.api import (
    DETAIL_URL,
    OPTIONS_URL,
    Che168Api,
    CollectionError,
    Reply,
    RequestBudget,
    RotationPolicy,
    transport_cause,
)
from collector.adapters.che168.job import Che168Run, che168_rules, run_che168
from collector.adapters.che168.mapping import stored_card, to_lot
from collector.adapters.che168.signing import Signer
from collector.domain.carlot import RejectAction, SelectionPhase
from collector.ports import MemoryHistoryStore, MemorySink
from collector.selection import Action, Rule, RuleEngine
from collector.storage import Job
from tests.test_che168 import detail, reply

NOW = datetime(2026, 10, 30, tzinfo=UTC)
OPTIONS = [
    {"optionid": 101, "optionname": "并线辅助", "optionpics": ""},
    {"optionid": 119, "optionname": "方向盘加热"},
]


class Site:
    """Two pages: 1, 2 then 2, 3 (a car moves between pages); cards in `refused` answer returncode 1."""

    def __init__(self) -> None:
        self.details: list[str] = []
        self.refused: set[str] = set()

    def request(self, url: str, params: dict[str, str]) -> Reply:
        if url == OPTIONS_URL:
            return reply(OPTIONS)
        if url == DETAIL_URL:
            self.details.append(params["infoid"])
            if params["infoid"] in self.refused:
                return reply({}, code=1)
            return reply(detail(params["infoid"]))
        page = int(params["pageindex"])
        ids = [1, 2] if page == 1 else [2, 3]
        result = {"pageindex": page, "pagesize": 20, "pagecount": 2, "totalcount": 3}
        return reply({**result, "carlist": [{"infoid": i} for i in ids]})


class Queue:
    def __init__(self) -> None:
        self.progress_calls: list[dict[str, Any]] = []
        self.finished: tuple[str, dict[str, Any]] | None = None
        self.interrupted: dict[str, Any] | None = None

    async def progress(self, job_id: int, progress: dict[str, Any]) -> None:
        self.progress_calls.append(json.loads(json.dumps(progress)))

    async def finish(self, job_id: int, status: str, result: dict[str, Any], progress: Any = None) -> None:
        self.finished = (status, result)
        self.final_progress = progress

    async def interrupt(self, job_id: int, checkpoint: dict[str, Any]) -> None:
        self.interrupted = json.loads(json.dumps(checkpoint))


def job(checkpoint: dict[str, Any] | None = None, **filters: Any) -> Job:
    return Job(id=7, source="che168", type="full", filters=filters, target_lot_ids=(), checkpoint=checkpoint)


def collect(site: Site, sink: MemorySink, queue: Queue, the_job: Job, **kw: Any) -> str:
    api = Che168Api(site.request, Signer.bundled(), delay=0)
    run = Che168Run(
        job=the_job, queue=queue, sink=sink, api=api,  # type: ignore[arg-type]
        max_pages=kw.pop("max_pages", 10), max_cars=kw.pop("max_cars", 100), clock=lambda: NOW, **kw,
    )  # fmt: skip
    return asyncio.run(run_che168(run))


def test_run_writes_each_car_once_and_is_never_a_full_run() -> None:
    site, sink, queue = Site(), MemorySink(), Queue()
    assert collect(site, sink, queue, job()) == "done"
    assert sorted(sink.by_id()) == ["1", "2", "3"]
    assert {lot.run_id for lot in sink.lots} == {7}
    assert site.details == ["1", "2", "3"]  # car 2 is on both pages, fetched once
    assert queue.finished is not None
    status, result = queue.finished
    assert (status, result["stop_reason"], result["total"], result["full_run"]) == (
        "done",
        "api_end_reached",
        3,
        False,
    )
    assert queue.progress_calls[-1]["checkpoint"]["next_page"] == 3


def test_limits_from_the_job_win() -> None:
    site, sink, queue = Site(), MemorySink(), Queue()
    assert collect(site, sink, queue, job(max_cars=2)) == "done"
    assert queue.finished is not None
    assert queue.finished[1]["stop_reason"] == "car_limit"
    assert len(sink.lots) == 2


def test_stop_hands_back_a_checkpoint_and_the_resume_fetches_nothing_twice() -> None:
    site, sink, queue = Site(), MemorySink(), Queue()
    stop_after_one_card = lambda: len(site.details) >= 1  # noqa: E731
    assert collect(site, sink, queue, job(), should_stop=stop_after_one_card) == "interrupted"
    assert queue.interrupted is not None
    assert [lot.source_id for lot in sink.lots] == ["1"]
    assert "1" not in queue.interrupted["pending"]  # in the buffer: never fetched again
    assert "2" in queue.interrupted["pending"]

    resumed = Queue()
    assert collect(site, sink, resumed, job(queue.interrupted)) == "done"
    assert sorted(sink.by_id()) == ["1", "2", "3"]
    assert sorted(site.details) == ["1", "2", "3"]


def test_a_refused_card_is_skipped_but_many_in_a_row_stop_the_run() -> None:
    site, sink, queue = Site(), MemorySink(), Queue()
    site.refused = {"2"}
    assert collect(site, sink, queue, job()) == "done"
    assert sorted(sink.by_id()) == ["1", "3"]
    assert queue.finished is not None
    assert queue.finished[1]["rejected"] == 1

    site, sink, queue = Site(), MemorySink(), Queue()
    site.refused = {"1", "2", "3"}
    run = collect(site, sink, queue, job())
    assert run == "done"  # three in a row is under the limit
    assert sink.lots == []


def test_a_blocked_site_ends_the_run_as_an_error() -> None:
    sink, queue = MemorySink(), Queue()

    def blocked(url: str, params: dict[str, str]) -> Reply:
        return Reply(429, b"")

    api = Che168Api(blocked, Signer.bundled(), delay=0)
    run = Che168Run(job=job(), queue=queue, sink=sink, api=api, max_pages=5, max_cars=5, clock=lambda: NOW)  # type: ignore[arg-type]
    assert asyncio.run(run_che168(run)) == "error"
    assert queue.finished is not None
    assert queue.finished[0] == "error"
    assert queue.finished[1]["error"] == "rate_limited"


class Shelf:
    """`pages` pages of `per_page` cars (ids 101, 102, … 201, …); records which session asked for a card."""

    def __init__(self, pages: int = 3, per_page: int = 5) -> None:
        self.pages, self.per_page = pages, per_page
        self.details: list[tuple[str, str]] = []
        self.prices: dict[int, str] = {}  # list price per car; default 12.68
        self.names: dict[int, str] = {}  # list name per car (its «2023款» is the model year)
        self.lock = threading.Lock()

    def session(self, name: str) -> Che168Api:
        def request(url: str, params: dict[str, str]) -> Reply:
            if url == OPTIONS_URL:
                return reply(OPTIONS)
            if url == DETAIL_URL:
                with self.lock:
                    self.details.append((name, params["infoid"]))
                return reply(detail(params["infoid"]))
            page = int(params["pageindex"])
            ids = [page * 100 + i for i in range(1, self.per_page + 1)]
            result = {
                "pageindex": page,
                "pagesize": 20,
                "pagecount": self.pages,
                "totalcount": self.pages * self.per_page,
            }
            cars = [
                {
                    "infoid": i,
                    "price": self.prices.get(i, "12.68"),
                    "mileage": "0.8",
                    "photocount": 3,
                    "carname": self.names.get(i, "逍客 2023款 1.3T CVT豪华版"),
                }
                for i in ids
            ]
            return reply({**result, "carlist": cars})

        return Che168Api(request, Signer.bundled(), delay=0)


class Lines:
    def __init__(self) -> None:
        self.lines: list[tuple[str, str]] = []

    async def info(self, message: str) -> None:
        self.lines.append(("info", message))

    async def warning(self, message: str) -> None:
        self.lines.append(("warning", message))

    async def error(self, message: str) -> None:
        self.lines.append(("error", message))


def workers_run(shelf: Shelf, n: int, **kw: Any) -> tuple[str, MemorySink, Queue, Lines]:
    sink, queue, log = MemorySink(), Queue(), Lines()
    lists = [shelf.session("list" if i == 1 else f"l{i}") for i in range(1, kw.pop("listers", 1) + 1)]
    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=lists[0], list_apis=lists,  # type: ignore[arg-type]
        card_apis=[shelf.session(f"w{i}") for i in range(n)], max_pages=kw.pop("max_pages", 10),
        max_cars=kw.pop("max_cars", 100), clock=kw.pop("clock", lambda: NOW), runlog=log, **kw,  # type: ignore[arg-type]
    )  # fmt: skip
    return asyncio.run(run_che168(run)), sink, queue, log


def test_card_workers_share_the_cards_and_fetch_each_once() -> None:
    shelf = Shelf(pages=3, per_page=5)
    status, sink, queue, log = workers_run(shelf, 3)
    assert status == "done"
    assert len(sink.lots) == 15
    assert sorted(i for _, i in shelf.details) == sorted({i for _, i in shelf.details})  # none twice
    assert {name for name, _ in shelf.details} <= {"w0", "w1", "w2"}  # cards never on the list session
    assert queue.finished is not None
    result = queue.finished[1]
    assert (result["stop_reason"], result["total"], result["workers"]) == ("api_end_reached", 15, 3)
    assert (
        result["requests"] == 3 + 15 * 2
    )  # three list pages, a card and its options each, over every session
    assert result["target"] == 15  # limits allow 100 cars, the site has 15
    assert queue.final_progress["pct"] == 100.0
    assert result["proxy_bytes"] > 0
    assert result["avg_request_s"] is not None
    pages = [m for _, m in log.lines if m.startswith("page ")]
    assert [m.split(":")[0] for m in pages] == ["page 1/10", "page 2/10", "page 3/10"]
    for part in ("requests", "s per answer", "3 sessions", "proxy", "rotations"):
        assert part in pages[-1]
    assert log.lines[-2][1].startswith("done (api_end_reached): 15 cars")
    assert log.lines[-1][1].startswith("sessions: list 3 req, 0 retries")  # then w1, w2, w3
    assert [s["name"] for s in result["sessions"]] == ["list", "w1", "w2", "w3"]


def test_retries_say_why_and_on_which_session() -> None:
    class ReadTimeout(Exception):  # noqa: N818 - named like curl_cffi's: the cause comes from the class name
        pass

    answers: Iterator[Exception | Reply] = iter([ReadTimeout(), Reply(502, b""), reply(detail("101"))])

    def flaky(url: str, params: dict[str, str]) -> Reply:
        answer = next(answers)
        if isinstance(answer, Exception):
            raise CollectionError("network_error", transport_cause(answer))
        return answer

    api = Che168Api(flaky, Signer.bundled(), delay=0, retries=2)
    api.detail("101")
    assert api.retries_used == 2
    assert api.retry_reasons == {"read_timeout": 1, "http_502": 1}
    assert transport_cause(ValueError()) == "network_error"


def test_car_limit_holds_with_many_workers() -> None:
    status, sink, queue, _ = workers_run(Shelf(pages=5, per_page=5), 4, max_cars=7)
    assert status == "done"
    assert len(sink.lots) == 7
    assert queue.finished is not None
    assert queue.finished[1]["stop_reason"] == "car_limit"


def test_a_blocked_card_session_stops_every_worker() -> None:
    shelf = Shelf(pages=2, per_page=5)
    sink, queue = MemorySink(), Queue()

    def blocked(url: str, params: dict[str, str]) -> Reply:
        return Reply(429, b"")

    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=shelf.session("list"),  # type: ignore[arg-type]
        card_apis=[shelf.session("w0"), Che168Api(blocked, Signer.bundled(), delay=0)],
        max_pages=10, max_cars=100, clock=lambda: NOW,
    )  # fmt: skip
    assert asyncio.run(run_che168(run)) == "error"
    assert queue.finished is not None
    assert queue.finished[1]["error"] == "rate_limited"


def test_the_sessions_of_a_run_share_one_request_limit() -> None:
    budget = RequestBudget(2)
    a, b = Shelf().session("a"), Shelf().session("b")
    a.budget = b.budget = budget
    a.detail("101")
    b.detail("102")
    with pytest.raises(CollectionError, match="request_limit"):
        a.detail("103")
    assert (a.requests, b.requests, budget.used) == (1, 1, 2)


def test_a_card_session_rotates_every_n_cards() -> None:
    rotations: list[int] = []
    shelf = Shelf()
    api = shelf.session("w")
    api.rotate = lambda: rotations.append(api.cards)
    api.rotation = RotationPolicy(every_pages=0, every_cards=2, max_rotations=10)
    for i in range(5):
        api.detail(str(101 + i))
    assert rotations == [2, 4]  # before the 3rd and the 5th card
    assert api.rotations == 2


def test_a_stuck_proxy_exit_is_retried_on_a_fresh_session() -> None:
    class ReadTimeout(Exception):  # noqa: N818 - named like curl_cffi's
        pass

    answers: Iterator[Exception | Reply] = iter([ReadTimeout(), Reply(502, b""), reply(detail("101"))])
    sessions: list[int] = []

    def flaky(url: str, params: dict[str, str]) -> Reply:
        answer = next(answers)
        if isinstance(answer, Exception):
            raise CollectionError("network_error", transport_cause(answer))
        return answer

    api = Che168Api(flaky, Signer.bundled(), delay=0, retries=2, rotate=lambda: sessions.append(1))
    api.rotation = RotationPolicy(every_pages=0, max_rotations=10)
    api.detail("101")
    assert len(sessions) == 1  # the timeout moved to a new exit; the 502 retried on the same one
    assert api.rotations == 1
    assert api.challenge_rotations == 0


def test_a_second_run_takes_unchanged_cars_from_the_card_store() -> None:
    shelf, store = Shelf(pages=2, per_page=5), MemoryHistoryStore()
    workers_run(shelf, 2, history=store)
    assert len(shelf.details) == 10
    shelf.details.clear()
    shelf.prices[101] = "99.9"  # one car changed its price since

    status, sink, queue, log = workers_run(shelf, 2, history=store)
    assert status == "done"
    assert [i for _, i in shelf.details] == ["101"]  # only the changed car is fetched
    assert sorted(lot.source_id for lot in sink.lots) == [
        str(i) for i in (101, 102, 103, 104, 105, 201, 202, 203, 204, 205)
    ]
    assert {lot.run_id for lot in sink.lots} == {7}
    assert queue.finished is not None
    assert queue.finished[1]["stored"] == 9
    assert "(9 from the card store)" in log.lines[-2][1]


def test_an_old_stored_card_is_fetched_again() -> None:
    shelf, store = Shelf(pages=1, per_page=3), MemoryHistoryStore()
    workers_run(shelf, 1, history=store)
    shelf.details.clear()
    later = NOW + timedelta(hours=2)
    workers_run(shelf, 1, history=store, card_max_age_s=3600, clock=lambda: later)
    assert len(shelf.details) == 3


def test_the_store_keeps_the_listing_day_not_today() -> None:
    card = stored_card(detail("101", publicdate="今天"), NOW)  # NOW is 30 Oct, 08:00 in China
    assert card["publicdate"] == "2026-10-30"
    lot = to_lot(card, NOW + timedelta(days=3))
    assert lot.listing.listed_at is not None
    assert lot.listing.listed_at.date() == date(2026, 10, 30)


def test_list_sessions_read_a_wave_of_pages_at_once() -> None:
    shelf = Shelf(pages=7, per_page=4)
    status, sink, queue, _ = workers_run(shelf, 2, listers=3)
    assert status == "done"
    assert len(sink.lots) == 28
    assert len({i for _, i in shelf.details}) == 28  # each car fetched once
    assert queue.finished is not None
    result = queue.finished[1]
    assert (result["stop_reason"], result["pages"]) == ("api_end_reached", 7)
    names = [s["name"] for s in result["sessions"]]
    assert names[:3] == ["list", "l2", "l3"]
    assert all(s["requests"] > 0 for s in result["sessions"][:3])  # every list session read pages
    assert sum(s["requests"] for s in result["sessions"][:3]) == 7


def test_a_wave_stops_at_the_page_limit() -> None:
    status, sink, queue, _ = workers_run(Shelf(pages=9, per_page=2), 1, listers=4, max_pages=5)
    assert status == "done"
    assert queue.finished is not None
    assert (queue.finished[1]["stop_reason"], queue.finished[1]["pages"]) == ("page_limit", 5)
    assert len(sink.lots) == 10


def test_a_list_session_rotates_by_its_own_searches() -> None:
    api = Shelf(pages=9).session("l")
    rotations: list[int] = []
    api.rotate = lambda: rotations.append(api.searches)
    api.rotation = RotationPolicy(every_pages=0, every_searches=2, max_rotations=10)
    for page in (1, 4, 7, 2, 5):  # a wave's pages are not consecutive
        api.search(page, 20)
    assert rotations == [2, 4]  # before its 3rd and 5th search


def test_an_unreachable_card_is_skipped_not_the_run() -> None:
    shelf = Shelf(pages=1, per_page=3)
    sink, queue = MemorySink(), Queue()
    broken = shelf.session("w0")
    inner = broken.request

    def flaky(url: str, params: dict[str, str]) -> Reply:
        if url == DETAIL_URL and params["infoid"] == "102":
            raise CollectionError("network_error", "tls_error")
        return inner(url, params)

    broken.request = flaky
    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=shelf.session("list"),  # type: ignore[arg-type]
        card_apis=[broken], max_pages=10, max_cars=100, clock=lambda: NOW,
    )  # fmt: skip
    assert asyncio.run(run_che168(run)) == "done"
    assert sorted(lot.source_id for lot in sink.lots) == ["101", "103"]
    assert queue.finished is not None
    assert queue.finished[1]["rejected"] == 1


def test_a_tls_error_retries_on_a_fresh_session() -> None:
    answers: Iterator[Exception | Reply] = iter(
        [CollectionError("network_error", "tls_error"), reply(detail("101"))]
    )

    def flaky(url: str, params: dict[str, str]) -> Reply:
        answer = next(answers)
        if isinstance(answer, Exception):
            raise answer
        return answer

    rotations: list[int] = []
    api = Che168Api(flaky, Signer.bundled(), delay=0, rotate=lambda: rotations.append(1))
    api.detail("101")
    assert (api.retries_used, len(rotations)) == (1, 1)


def test_the_listing_day_is_kept_as_a_date_in_the_raw_fields() -> None:
    fetched = to_lot(detail("101", publicdate="今天"), NOW)
    stored = to_lot(stored_card(detail("101", publicdate="今天"), NOW), NOW + timedelta(days=2))
    assert fetched.extra["che168"]["publicdate"] == "2026-10-30"
    assert fetched.extra == stored.extra  # a car from the store reads as unchanged


def rule(rid: int, field: str, operator: str, value: Any, source: str | None = None) -> Rule:
    return Rule(
        id=rid, name=f"r{rid}", field=field, operator=operator, value=value, action=Action.SKIP, source=source
    )


def test_the_list_year_rejects_a_car_before_its_card() -> None:
    shelf = Shelf(pages=1, per_page=3)
    shelf.names[102] = "逍客 2012款 1.6L"
    status, sink, queue, _ = workers_run(shelf, 1, rules=RuleEngine([rule(6, "year", "lt", 2014)]))
    assert status == "done"
    assert sorted(i for _, i in shelf.details) == ["101", "103"]  # no request for the 2012 car
    assert sorted(lot.source_id for lot in sink.lots) == ["101", "103"]
    assert all(lot.selection.passed for lot in sink.lots)
    assert queue.finished is not None
    assert queue.finished[1]["filtered"] == 1


def test_a_rejected_card_goes_to_the_core_with_its_verdict() -> None:
    shelf = Shelf(pages=1, per_page=2)
    status, sink, _, _ = workers_run(shelf, 1, rules=RuleEngine([rule(9, "make", "eq", "日产")]))
    assert status == "done"
    assert len(sink.lots) == 2  # written: the core logs the skip and keeps them out of lots
    for lot in sink.lots:
        assert (lot.selection.passed, lot.selection.action, lot.selection.rule_ids) == (
            False,
            RejectAction.SKIP,
            (9,),
        )
        assert lot.extra["selection_evaluated"] is True


def test_a_stored_car_is_judged_without_a_request() -> None:
    shelf, store = Shelf(pages=1, per_page=3), MemoryHistoryStore()
    workers_run(shelf, 1, history=store)
    shelf.details.clear()
    _, sink, queue, _ = workers_run(
        shelf, 1, history=store, rules=RuleEngine([rule(9, "make", "eq", "日产")])
    )
    assert shelf.details == []
    assert [lot.selection.passed for lot in sink.lots] == [False, False, False]
    assert queue.finished is not None
    assert (queue.finished[1]["stored"], queue.finished[1]["filtered"]) == (3, 3)


def test_a_limit_in_won_without_a_platform_does_not_judge_a_price_in_yuan() -> None:
    won, yuan, year = (
        rule(8, "price", "gte", 1_000_000_000),
        rule(10, "price", "gte", 500_000, "che168"),
        rule(6, "year", "lt", 2014),
    )
    rules = che168_rules(RuleEngine([won, yuan, year]))
    assert sorted(r.id for r in rules.applicable("che168", SelectionPhase.PRE)) == [6, 10]


def test_a_car_carries_its_options_as_che168_codes_with_their_names() -> None:
    _, sink, _, _ = workers_run(Shelf(pages=1, per_page=2), 1)
    lot = sink.lots[0]
    assert lot.listing.options == ("che168:101", "che168:119")  # never read as Encar's "101"
    assert lot.extra["che168_options"] == {"101": "并线辅助", "119": "方向盘加热"}


def test_failed_options_keep_the_car_and_are_fetched_next_time() -> None:
    shelf, store = Shelf(pages=1, per_page=2), MemoryHistoryStore()
    lists = [shelf.session("list")]
    card = shelf.session("w0")
    inner = card.request

    def no_options(url: str, params: dict[str, str]) -> Reply:
        if url == OPTIONS_URL:
            return Reply(429, b"")
        return inner(url, params)

    card.request = no_options
    sink, queue = MemorySink(), Queue()
    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=lists[0], card_apis=[card],  # type: ignore[arg-type]
        max_pages=10, max_cars=100, clock=lambda: NOW, history=store,
    )  # fmt: skip
    assert asyncio.run(run_che168(run)) == "done"
    assert len(sink.lots) == 2
    assert all(lot.listing.options == () and "che168_options" not in lot.extra for lot in sink.lots)
    assert queue.finished is not None
    assert queue.finished[1]["options_failed"] == 2

    shelf.details.clear()
    _, sink2, _, _ = workers_run(shelf, 1, history=store)
    assert len(shelf.details) == 2  # stored without options: fetched again
    assert all(lot.listing.options for lot in sink2.lots)


def test_a_stored_car_keeps_its_options() -> None:
    shelf, store = Shelf(pages=1, per_page=2), MemoryHistoryStore()
    workers_run(shelf, 1, history=store)
    shelf.details.clear()
    _, sink, _, _ = workers_run(shelf, 1, history=store)
    assert shelf.details == []
    assert all(lot.listing.options == ("che168:101", "che168:119") for lot in sink.lots)


def test_options_none_or_a_changed_schema() -> None:
    api = Che168Api(lambda u, p: reply(None), Signer.bundled(), delay=0)
    assert api.options("101") == []
    api = Che168Api(lambda u, p: reply([{"optionname": "x"}]), Signer.bundled(), delay=0)
    with pytest.raises(CollectionError, match="options_schema_changed"):
        api.options("101")


def test_an_unexpected_error_in_a_worker_stops_the_run_instead_of_hanging() -> None:
    shelf = Shelf(pages=3, per_page=5)
    card = shelf.session("w0")

    def broken(url: str, params: dict[str, str]) -> Reply:
        raise KeyError("bug")

    card.request = broken
    run = Che168Run(
        job=job(), queue=Queue(), sink=MemorySink(), api=shelf.session("list"),  # type: ignore[arg-type]
        card_apis=[card],
        max_pages=10, max_cars=100, clock=lambda: NOW,
    )  # fmt: skip
    with pytest.raises(KeyError):
        asyncio.run(asyncio.wait_for(run_che168(run), timeout=10))
