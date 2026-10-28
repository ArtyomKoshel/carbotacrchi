"""EncarPipeline on a fake Encar (MockTransport): one page from list items to CarLot records."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta
from typing import Any

import httpx
import pytest

from collector.adapters.encar import mapping as m
from collector.adapters.encar.api import EncarApi
from collector.adapters.encar.pipeline import EncarPipeline, history_snapshot
from collector.domain.carlot import RejectAction, SelectionPhase
from collector.http import Fetcher, ProxyPool
from collector.ports import HistoryState, MemoryHistoryStore, MemorySink
from collector.selection import Action, Rule, RuleEngine
from tests.fakes import FakeEncar, detail, item
from tests.test_encar_mapping import load

NOW = datetime(2026, 10, 21, 9, 0, tzinfo=UTC)
PROXY = ProxyPool(["http://u-session-a-country-KR:p@h:1"])


async def _no_sleep(_: float) -> None:
    return None


def pipeline(
    fake: FakeEncar, rules: list[Rule] | None = None
) -> tuple[EncarPipeline, MemorySink, MemoryHistoryStore]:
    transport = httpx.MockTransport(fake)
    fetch = Fetcher(
        PROXY, retries=1, client_factory=lambda p: httpx.AsyncClient(transport=transport), sleep=_no_sleep
    )
    sink, store = MemorySink(), MemoryHistoryStore()
    return (
        EncarPipeline(EncarApi(fetch), RuleEngine(rules or []), store, sink, clock=lambda: NOW),
        sink,
        store,
    )


def rule(rid: int, field: str, op: str, value: Any, action: str = "skip", phase: str = "pre") -> Rule:
    return Rule(
        id=rid,
        name=f"r{rid}",
        field=field,
        operator=op,
        value=value,
        action=Action(action),
        phase=SelectionPhase(phase),
    )


async def test_page_becomes_complete_carlots() -> None:
    fake = FakeEncar()
    pipe, sink, store = pipeline(fake)

    report = await pipe.process([item(1), item(2), item(1)], run_id=5)

    assert (report.listed, report.emitted, report.history_fetched, report.rejected) == (2, 2, 2, 0)
    lot = sink.by_id()["1"]
    assert lot.run_id == 5
    assert lot.selection.passed
    assert lot.vehicle.vin == "KMHL341CBMA000001"
    assert lot.history.insurance_count == 2
    assert lot.history.observed_at == NOW
    assert lot.inspection is not None
    assert lot.inspection.mileage_km == 45_100
    assert fake.calls.count("/v1/readside/vehicles") == 1, "both lots in one batch call"
    assert set(store.data) == {("encar", "1"), ("encar", "2")}


async def test_list_stage_rejection_skips_every_further_call() -> None:
    fake = FakeEncar()
    pipe, sink, _ = pipeline(fake, [rule(6, "year", "lt", 2014)])

    report = await pipe.process([item(1, FormYear="2010")])

    assert report.early_rejected == 1
    assert fake.calls == []
    sel = sink.lots[0].selection
    assert (sel.passed, sel.phase, sel.action, sel.rule_ids) == (
        False,
        SelectionPhase.PRE,
        RejectAction.SKIP,
        (6,),
    )


async def test_history_rule_rejects_once_on_complete_data_and_keeps_the_history() -> None:
    """The flapping case: insurance count is only known after the record call."""
    fake = FakeEncar()
    pipe, sink, store = pipeline(fake, [rule(7, "insurance_count", "gte", 2)])

    report = await pipe.process([item(1)])

    assert report.rejected == 1
    assert len(sink.lots) == 1, "one record per lot, written once"
    assert sink.lots[0].selection.rule_ids == (7,)
    assert sink.lots[0].history.insurance_count == 2
    assert ("encar", "1") in store.data, "a rejected lot keeps its history: no re-fetch next run"


async def test_recent_history_is_taken_from_the_store() -> None:
    fake = FakeEncar()
    pipe, sink, store = pipeline(fake)
    stored = m.from_search(item(1))
    m.apply_record(stored, load("record"))
    stored.drive_type = "awd"
    await store.save("encar", [history_snapshot(stored, NOW - timedelta(hours=1))])

    report = await pipe.process([item(1)], history_cutoff=NOW - timedelta(days=1))

    assert report.history_stored == 1
    assert not any(c.endswith("/open") or "inspection" in c for c in fake.calls)
    lot = sink.lots[0]
    assert lot.history.insurance_count == 2
    assert lot.history.observed_at is None, "stored history does not move history_checked_at"
    assert lot.vehicle.drive_type is not None


def _fresh_history(vid: int, **listing: Any) -> HistoryState:
    return HistoryState(str(vid), NOW, ("Inspection", "Record"), {"insurance_count": 1, "listing": listing})


async def test_a_changed_listing_brings_a_fresh_history() -> None:
    fake = FakeEncar()
    pipe, sink, store = pipeline(fake)
    lots = [m.from_search(item(i)) for i in (1, 2, 3, 4, 5)]
    await store.save(
        "encar",
        [
            _fresh_history(1, mileage=lots[0].mileage + 1500),  # the seller updated the odometer
            _fresh_history(2, price=lots[1].price + 1_000_000),  # price cut
            _fresh_history(3, plate_number="12가3456"),  # re-registered: another record
            _fresh_history(4, mileage=lots[3].mileage, price=lots[3].price),  # unchanged
            HistoryState(
                "5", NOW, ("Inspection", "Record"), {"insurance_count": 1}
            ),  # saved before this rule
        ],
    )

    report = await pipe.process([item(i) for i in (1, 2, 3, 4, 5)], history_cutoff=NOW - timedelta(days=1))

    assert report.history_listing_changed == 3
    assert report.history_fetched == 3
    assert report.history_stored == 2, "unchanged listing and a history without listing facts stay stored"
    assert not any(c.endswith("/sellingpoint") for c in fake.calls), "a recheck skips sellingpoint"
    by_id = sink.by_id()
    assert by_id["1"].history.observed_at == NOW
    assert by_id["4"].history.observed_at is None
    saved = store.data[("encar", "1")].fields["listing"]
    assert saved["mileage"] == lots[0].mileage
    assert saved["price"] == lots[0].price
    assert saved["plate_number"], "the plate from the detail is kept for the next comparison"


async def test_old_or_changed_history_is_rechecked_without_sellingpoint() -> None:
    fake = FakeEncar()
    pipe, _, store = pipeline(fake)
    await store.save(
        "encar",
        [HistoryState("1", NOW - timedelta(days=9), ("Inspection", "Record"), {"insurance_count": 1})],
    )
    await store.save(
        "encar", [HistoryState("2", NOW, ("Record",), {"insurance_count": 1})]
    )  # condition changed

    await pipe.process([item(1), item(2)], history_cutoff=NOW - timedelta(days=1))

    assert sum(c.endswith("/open") for c in fake.calls) == 2
    assert not any(c.endswith("/sellingpoint") for c in fake.calls)


async def test_failed_history_is_not_marked_checked() -> None:
    fake = FakeEncar()
    fake.record_status = 503
    pipe, sink, store = pipeline(fake)

    report = await pipe.process([item(1)])

    assert report.history_failed == 1
    assert sink.lots[0].history.observed_at is None
    assert store.data == {}


async def test_record_without_open_data_is_no_data_not_a_failure() -> None:
    fake = FakeEncar()
    fake.record = {"openData": False}
    pipe, sink, store = pipeline(fake)

    await pipe.process([item(1)])

    assert sink.lots[0].history.observed_at == NOW
    assert ("encar", "1") in store.data


async def test_a_record_not_open_yet_is_asked_again_next_run() -> None:
    """The inspection's flood flag does not stand in for the record: lot 43020329."""
    fake = FakeEncar()
    fake.record = {"openData": False}
    pipe, _, store = pipeline(fake)
    await pipe.process([item(1)])
    assert store.data[("encar", "1")].fields["flood_history"] is not None, "set by the inspection"
    fake.calls.clear()

    report = await pipe.process([item(1)], history_cutoff=NOW - timedelta(days=1))

    assert report.history_stored == 0
    assert sum(c.endswith("/open") for c in fake.calls) == 1


async def test_batch_failure_falls_back_to_single_details() -> None:
    fake = FakeEncar()
    fake.batch_fails = True
    pipe, sink, _ = pipeline(fake)

    await pipe.process([item(1), item(2)])

    assert sum(c.startswith("/v1/readside/vehicle/") for c in fake.calls) == 2
    assert sink.by_id()["2"].vehicle.vin == "KMHL341CBMA000001"


async def test_post_rule_and_flags() -> None:
    fake = FakeEncar()
    pipe, sink, _ = pipeline(
        fake,
        [
            rule(1, "inspection_has_accident", "eq", False, "flag", phase="post"),
            rule(2, "accident_max_cost", "gte", 400_000, "mark_inactive", phase="post"),
        ],
    )

    await pipe.process([item(1)])

    sel = sink.lots[0].selection
    assert (sel.passed, sel.phase, sel.action, sel.rule_ids, sel.flagged_rule_ids) == (
        False,
        SelectionPhase.POST,
        RejectAction.MARK_INACTIVE,
        (2,),
        (1,),
    )


async def test_empty_page_writes_nothing() -> None:
    pipe, sink, _ = pipeline(FakeEncar())
    assert (await pipe.process([])).emitted == 0
    assert sink.lots == []


@pytest.mark.parametrize("dummy", [True, False])
async def test_relisted_lot_maps_detail_by_dummy_id(dummy: bool) -> None:
    fake = FakeEncar()

    def relisted(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/v1/readside/vehicles":
            d = detail(900)
            d["manage"] = {"dummy": dummy, "dummyVehicleId": 1}
            return httpx.Response(200, json=[d])
        return fake(request)

    transport = httpx.MockTransport(relisted)
    f = Fetcher(PROXY, client_factory=lambda p: httpx.AsyncClient(transport=transport), sleep=_no_sleep)
    sink = MemorySink()
    pipe = EncarPipeline(EncarApi(f), RuleEngine([]), MemoryHistoryStore(), sink, clock=lambda: NOW)

    await pipe.process([item(1)])

    lot = sink.lots[0]
    assert (lot.extra.get("inspect_vehicle_id") == "900") is dummy
