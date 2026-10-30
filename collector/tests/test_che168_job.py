from __future__ import annotations

import asyncio
import json
import threading
from datetime import UTC, datetime
from typing import Any

import pytest

from collector.adapters.che168.api import (
    DETAIL_URL,
    Che168Api,
    CollectionError,
    Reply,
    RequestBudget,
    RotationPolicy,
)
from collector.adapters.che168.job import Che168Run, run_che168
from collector.adapters.che168.signing import Signer
from collector.ports import MemorySink
from collector.storage import Job
from tests.test_che168 import detail, reply

NOW = datetime(2026, 10, 30, tzinfo=UTC)


class Site:
    """Two pages: 1, 2 then 2, 3 (a car moves between pages); cards in `refused` answer returncode 1."""

    def __init__(self) -> None:
        self.details: list[str] = []
        self.refused: set[str] = set()

    def request(self, url: str, params: dict[str, str]) -> Reply:
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
        self.lock = threading.Lock()

    def session(self, name: str) -> Che168Api:
        def request(url: str, params: dict[str, str]) -> Reply:
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
            return reply({**result, "carlist": [{"infoid": i} for i in ids]})

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
    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=shelf.session("list"),  # type: ignore[arg-type]
        card_apis=[shelf.session(f"w{i}") for i in range(n)], max_pages=kw.pop("max_pages", 10),
        max_cars=kw.pop("max_cars", 100), clock=lambda: NOW, runlog=log, **kw,  # type: ignore[arg-type]
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
    assert result["requests"] == 3 + 15  # three list pages, a card each, over every session
    assert result["proxy_bytes"] > 0
    assert result["avg_request_s"] is not None
    pages = [m for _, m in log.lines if m.startswith("page ")]
    assert [m.split(":")[0] for m in pages] == ["page 1/10", "page 2/10", "page 3/10"]
    for part in ("requests", "s per answer", "3 sessions", "proxy", "rotations"):
        assert part in pages[-1]
    assert log.lines[-1][1].startswith("done (api_end_reached): 15 cars")


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
