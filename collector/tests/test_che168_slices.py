from __future__ import annotations

import asyncio
import json
import math
from datetime import UTC, datetime
from typing import Any

import pytest

from collector.adapters.che168.api import (
    BRANDS_URL,
    DETAIL_URL,
    OPTIONS_URL,
    SERIES_URL,
    Che168Api,
    CollectionError,
    Reply,
)
from collector.adapters.che168.job import Che168Run, run_che168
from collector.adapters.che168.signing import Signer
from collector.adapters.che168.slices import CAP, PROVINCES, cut, segment
from collector.ports import MemoryHistoryStore, MemorySink
from collector.selection import RuleEngine
from tests.test_che168 import detail, reply
from tests.test_che168_job import Queue, job

NOW = datetime(2026, 10, 31, tzinfo=UTC)


def test_rules_become_search_filters_with_a_year_to_spare() -> None:
    rows = [
        {"id": 5, "name": "max_mileage", "source": None, "field": "mileage", "operator": "gt",
         "value": "200000",
         "action": "skip", "priority": 100, "rule_group_id": None, "phase": "pre", "enabled": 1},
        {"id": 6, "name": "min_year", "source": None, "field": "year", "operator": "lt", "value": "2014",
         "action": "skip", "priority": 100, "rule_group_id": None, "phase": "pre", "enabled": 1},
    ]  # fmt: skip
    assert segment(RuleEngine.from_rows(rows), 2026) == {"mileage": "0-20", "agerange": "0-13"}
    allow = {**rows[0], "id": 9, "action": "allow", "field": "make", "operator": "eq", "value": '"BYD"'}
    assert segment(RuleEngine.from_rows([*rows, allow]), 2026) == {}  # an allow rule may let back a skip
    grouped = {**rows[0], "rule_group_id": "g"}
    assert segment(RuleEngine.from_rows([grouped]), 2026) == {}
    assert segment(None, 2026) == {}


def test_a_slice_is_cut_one_filter_deeper_each_time() -> None:
    lists: dict[str, Any] = {
        "brands": lambda: ["33", "36"],
        "series": lambda b: [f"{b}1", f"{b}2"],
        "ages": (0, 2),
    }
    assert cut({}, **lists) == [{"brandid": "33"}, {"brandid": "36"}]
    assert cut({"brandid": "36"}, **lists) == [
        {"brandid": "36", "seriesid": "361"},
        {"brandid": "36", "seriesid": "362"},
    ]
    ladder = cut({"brandid": "36", "seriesid": "361"}, **lists)
    assert ladder[0]["price"] == "0-3"
    assert ladder[-1]["price"] == "150-10000"
    assert [c["price"] for c in cut({"seriesid": "1", "brandid": "1", "price": "8-12"}, **lists)] == [
        "8-10",
        "10-12",
    ]
    narrow = cut({"seriesid": "1", "brandid": "1", "price": "9-9.2"}, **lists)
    assert [c["agerange"] for c in narrow] == ["0-0", "1-1", "2-2"]
    provinces = cut({"seriesid": "1", "brandid": "1", "price": "9-9.2", "agerange": "1-1"}, **lists)
    assert len(provinces) == len(PROVINCES)
    assert cut({**provinces[0]}, **lists) == []


def cars() -> list[dict[str, Any]]:
    """800 cars: make 1 has a big series (500, prices 5-15) and a small one (100); make 2 has 200."""
    out = []
    for i in range(800):
        brand, series = ("1", "11") if i < 500 else ("1", "12") if i < 600 else ("2", "21")
        out.append({"infoid": 10_000 + i, "brandid": brand, "seriesid": series,
                    "price": round(5 + (i % 100) / 10, 2), "age": i % 5,
                    "pid": PROVINCES[i % 3]})  # fmt: skip
    return out


class Site:
    """che168 as it answers: filters narrow the list, newest first, page 20 answers page 1."""

    def __init__(self) -> None:
        self.cars = cars()
        self.searches: list[dict[str, str]] = []

    def match(self, car: dict[str, Any], p: dict[str, str]) -> bool:
        def within(field: str, value: float) -> bool:
            if field not in p:
                return True
            low, high = map(float, p[field].split("-"))
            return low <= value <= high

        return (
            all(
                p.get(k) in (None, "0", car[k]) for k in ("brandid", "seriesid", "pid")
            )  # pid 0: the whole country
            and within("price", car["price"])
            and within("agerange", car["age"])
        )

    def request(self, url: str, params: dict[str, str]) -> Reply:
        if url == BRANDS_URL:
            return reply({"brands": [{"letter": "A", "brand": [{"bid": 1}, {"bid": 2}]}]})
        if url == SERIES_URL:
            return reply(
                {
                    "list": [
                        {
                            "item": [
                                {"sid": int(f"{params['brandid']}1")},
                                {"sid": int(f"{params['brandid']}2")},
                            ]
                        }
                    ]
                }
            )
        if url == OPTIONS_URL:
            return reply([])
        if url == DETAIL_URL:
            car = next(c for c in self.cars if str(c["infoid"]) == params["infoid"])
            return reply(detail(params["infoid"], price=str(car["price"])))
        self.searches.append(params)
        found = sorted((c for c in self.cars if self.match(c, params)), key=lambda c: -c["infoid"])
        size, page = int(params["pagesize"]), int(params["pageindex"])
        if page > 19:
            page = 1  # che168's page_cap
        chunk = found[(page - 1) * size : page * size]
        return reply({
            "pageindex": page, "pagesize": size, "totalcount": len(found),
            "pagecount": math.ceil(len(found) / size),
            "carlist": [{"infoid": c["infoid"], "price": str(c["price"]), "mileage": "1"} for c in chunk],
        })  # fmt: skip


def pass_over(
    site: Site, *, max_cars: int, store: MemoryHistoryStore | None = None
) -> tuple[MemorySink, Queue]:
    api = Che168Api(site.request, Signer.bundled(), delay=0, max_requests=10_000)
    sink, queue = MemorySink(), Queue()
    run = Che168Run(
        job=job(), queue=queue, sink=sink, api=api,  # type: ignore[arg-type]
        max_pages=10, max_cars=max_cars, clock=lambda: NOW, history=store or MemoryHistoryStore(),
        slices=True, segment={}, ages=(0, 4),
    )  # fmt: skip
    assert asyncio.run(run_che168(run)) == "done"
    return sink, queue


def test_a_pass_lists_every_car_through_slices_under_the_cap() -> None:
    site = Site()
    sink, queue = pass_over(site, max_cars=1000)
    assert len(sink.by_id()) == 800  # all of them, though one query lists 380 at most
    assert all(int(p["pageindex"]) <= 19 for p in site.searches)
    assert queue.finished is not None
    result = queue.finished[1]
    assert result["stop_reason"] == "slices_done"
    assert result["full_run"] is True
    assert (result["listed"], result["api_total"], result["truncated"]) == (800, 800, 0)
    assert result["cuts"] >= 3  # the site, make 1 and its big series were over CAP
    assert len(site.cars) > CAP


def test_the_car_limit_stops_the_cards_not_the_pass() -> None:
    site, store = Site(), MemoryHistoryStore()
    sink, queue = pass_over(site, max_cars=50, store=store)
    assert len(sink.lots) == 50
    assert queue.finished is not None
    result = queue.finished[1]
    assert (result["listed"], result["not_fetched"], result["full_run"]) == (800, 750, True)


def test_a_slice_that_cannot_be_cut_is_listed_as_far_as_che168_lets_and_the_pass_is_not_full() -> None:
    site = Site()
    for c in site.cars:  # one province, one year, one price: nothing left to cut by
        c.update(brandid="1", seriesid="11", price=9.0, age=1, pid=PROVINCES[0])
    sink, queue = pass_over(site, max_cars=1000)
    assert len(sink.by_id()) == 380  # 19 pages of 20
    assert queue.finished is not None
    assert queue.finished[1]["truncated"] >= 1  # 9.0 sits on the border of two halves: both are full
    assert queue.finished[1]["full_run"] is False


def test_a_resumed_pass_goes_on_from_the_slices_left() -> None:
    site = Site()
    api = Che168Api(site.request, Signer.bundled(), delay=0, max_requests=10_000)
    left = [{"brandid": "2"}]
    checkpoint = {
        "engine": "che168",
        "started_at": NOW.isoformat(),
        "slicing": True,
        "slices": left,
        "api_total": 800,
    }
    sink, queue = MemorySink(), Queue()
    run = Che168Run(
        job=job(checkpoint), queue=queue, sink=sink, api=api,  # type: ignore[arg-type]
        max_pages=10, max_cars=1000, clock=lambda: NOW, history=MemoryHistoryStore(),
        slices=True, ages=(0, 4),
    )  # fmt: skip
    assert asyncio.run(run_che168(run)) == "done"
    assert len(sink.by_id()) == 200  # make 2 only
    assert all(p.get("brandid") == "2" for p in site.searches)
    assert json.dumps(queue.finished)  # serialisable result


def test_an_empty_slice_is_a_page_without_cars() -> None:
    def empty(url: str, params: dict[str, str]) -> Reply:
        return reply({"pageindex": 0, "pagesize": 0, "totalcount": 0, "pagecount": 0, "carlist": []})

    page = Che168Api(empty, Signer.bundled(), delay=0).search(1, 20, {"price": "0-3"})
    assert (page.total, page.page_count, page.ids) == (0, 0, ())


def test_page_20_answering_page_1_is_the_end_of_the_query() -> None:
    def capped(url: str, params: dict[str, str]) -> Reply:
        return reply({"pageindex": 1, "pagesize": 20, "totalcount": 900, "pagecount": 45,
                      "carlist": [{"infoid": 1, "price": "1", "mileage": "1"}]})  # fmt: skip

    with pytest.raises(CollectionError, match="page_cap"):
        Che168Api(capped, Signer.bundled(), delay=0).search(20, 20)
