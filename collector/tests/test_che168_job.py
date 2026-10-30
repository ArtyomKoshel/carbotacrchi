from __future__ import annotations

import asyncio
import json
from datetime import UTC, datetime
from typing import Any

from collector.adapters.che168.api import DETAIL_URL, Che168Api, Reply
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
    calls = iter([False, False, True])  # stop after the first page and one card
    assert collect(site, sink, queue, job(), should_stop=lambda: next(calls, True)) == "interrupted"
    assert queue.interrupted is not None
    assert queue.interrupted["pending"] == ["2"]
    assert [lot.source_id for lot in sink.lots] == ["1"]

    resumed = Queue()
    assert collect(site, sink, resumed, job(queue.interrupted)) == "done"
    assert sorted(sink.by_id()) == ["1", "2", "3"]
    assert site.details == ["1", "2", "3"]


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
