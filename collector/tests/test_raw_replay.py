"""Raw store and replay (ADR-0005): every response is kept, and a replay rebuilds the same lots offline."""

from __future__ import annotations

import asyncio
from collections.abc import AsyncIterator
from datetime import UTC, datetime

import httpx
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from collector import __main__ as cli
from collector import storage
from collector.adapters.encar.api import EncarApi
from collector.adapters.encar.golden import diff
from collector.adapters.encar.pipeline import EncarPipeline
from collector.adapters.encar.replay import RawApi, replay_all, replay_lots
from collector.http import Fetcher, Missing, Ok, ProxyPool
from collector.ports import MemoryHistoryStore, MemoryRawStore, MemorySink, RawEntry
from collector.selection import RuleEngine
from tests.fakes import FakeEncar, detail, item
from tests.test_storage import TABLES, URL

NOW = datetime(2026, 10, 21, 9, 0, tzinfo=UTC)


async def _no_sleep(_: float) -> None:
    return None


def live_pipeline(raw: MemoryRawStore, sink: MemorySink, fake: FakeEncar | None = None) -> EncarPipeline:
    transport = httpx.MockTransport(fake or FakeEncar())
    fetcher = Fetcher(
        ProxyPool(["http://u-session-a:p@h:1"]),
        client_factory=lambda p: httpx.AsyncClient(transport=transport),
        sleep=_no_sleep,
    )
    return EncarPipeline(
        EncarApi(fetcher), RuleEngine([]), MemoryHistoryStore(), sink, clock=lambda: NOW, raw=raw
    )


def offline_pipeline(sink: MemorySink) -> EncarPipeline:
    return EncarPipeline(RawApi({}), RuleEngine([]), MemoryHistoryStore(), sink, clock=lambda: NOW)  # type: ignore[arg-type]


async def test_pipeline_keeps_every_response_kind() -> None:
    raw = MemoryRawStore()
    await live_pipeline(raw, MemorySink()).process([item(1), item(2)])

    # sellingpoint is asked only when the drive type is still unknown (the fixture's detail gives it)
    assert set(raw.data[("encar", "1")]) == {"list", "detail", "record", "inspection"}
    assert raw.data[("encar", "2")]["detail"]["vehicleId"] == 2


async def test_replay_rebuilds_identical_lots_without_network() -> None:
    raw, live = MemoryRawStore(), MemorySink()
    await live_pipeline(raw, live).process([item(1), item(2), item(3)])

    replayed = MemorySink()
    report = await replay_all(raw, offline_pipeline(replayed), batch=2)

    assert report.listed == 3
    for lot_id, original in live.by_id().items():
        assert diff(original, replayed.by_id()[lot_id]) == []


async def test_raw_api_answers_relisted_lots_by_inner_id() -> None:
    relisted = detail(900)
    relisted["manage"] = {"dummy": True, "dummyVehicleId": 1}
    api = RawApi({"1": {"list": item(1), "detail": relisted, "record": {"openData": True}}})

    assert await api.record("900") == Ok({"openData": True})
    assert await api.inspection("900") == Missing(200)
    assert await api.detail("1") == Ok(relisted)
    assert await api.detail("2") == Missing(404)
    assert await api.batch_details(["1", "2"]) == Ok([relisted])
    assert await api.sellingpoint("1") == Missing(200)


async def test_replay_skips_lots_without_a_list_item() -> None:
    raw = MemoryRawStore()
    await raw.put_many("encar", [RawEntry("5", "detail", detail(5))], NOW)
    sink = MemorySink()
    report = await replay_lots(raw, offline_pipeline(sink), ["5"])
    assert report.listed == 0
    assert sink.lots == []


# ── MySQL ──────────────────────────────────────────────────────────────────


@pytest.fixture
async def db() -> AsyncIterator[AsyncEngine]:
    eng = storage.engine(URL)
    try:
        async with eng.begin() as conn:
            for table in TABLES:
                await conn.execute(text(f"DROP TABLE IF EXISTS {table}"))
    except Exception as e:
        await eng.dispose()
        pytest.skip(f"MySQL not reachable at {URL}: {type(e).__name__}")
    await storage.migrate(eng)
    yield eng
    await eng.dispose()


@pytest.mark.mysql
async def test_mysql_raw_store_round_trip_and_unchanged_bodies(db: AsyncEngine) -> None:
    store = storage.MySqlRawStore(db)
    await store.put_many(
        "encar", [RawEntry("1", "list", {"Id": 1, "명": "한글"}), RawEntry("2", "list", {"Id": 2})], NOW
    )
    later = NOW.replace(hour=10)
    await store.put_many("encar", [RawEntry("1", "list", {"Id": 1, "명": "한글"})], later)  # same body
    await store.put_many("encar", [RawEntry("2", "list", {"Id": 2, "Price": 5})], later)  # changed
    await store.put_many("encar", [], later)

    loaded = await store.load("encar", ["1", "2", "3"])
    assert loaded == {"1": {"list": {"Id": 1, "명": "한글"}}, "2": {"list": {"Id": 2, "Price": 5}}}
    assert await store.load("encar", []) == {}
    assert await store.lot_ids("encar") == ["1", "2"]
    assert await store.lot_ids("encar", after="1", limit=5) == ["2"]
    async with db.connect() as conn:
        rows = (
            await conn.execute(text("SELECT lot_id, fetched_at FROM raw_responses ORDER BY lot_id"))
        ).all()
    assert [r.fetched_at.hour for r in rows] == [10, 10]


async def _seed_for_cli() -> bool:
    eng = storage.engine(URL)
    try:
        async with eng.begin() as conn:
            for table in (*TABLES, "parse_filters"):
                await conn.execute(text(f"DROP TABLE IF EXISTS {table}"))
            await conn.execute(text(
                "CREATE TABLE parse_filters (id INT PRIMARY KEY, name VARCHAR(50), source VARCHAR(20),"
                " field VARCHAR(50), operator VARCHAR(20), value TEXT, action VARCHAR(20), priority INT,"
                " rule_group_id VARCHAR(20), phase VARCHAR(10), enabled TINYINT)"
            ))  # fmt: skip
    except Exception:
        await eng.dispose()
        return False
    await storage.migrate(eng)
    raw = MemoryRawStore()
    await live_pipeline(raw, MemorySink()).process([item(1), item(2)])
    entries = [RawEntry(i, k, v) for (_, i), kinds in raw.data.items() for k, v in kinds.items()]
    await storage.MySqlRawStore(eng).put_many("encar", entries, NOW)
    await eng.dispose()
    return True


async def _buffered() -> int:
    eng = storage.engine(URL)
    async with eng.connect() as conn:
        n = int((await conn.execute(text("SELECT COUNT(*) FROM ingest_lots"))).scalar_one())
    await eng.dispose()
    return n


@pytest.mark.mysql
def test_cli_replay_writes_the_buffer(monkeypatch: pytest.MonkeyPatch) -> None:
    if not asyncio.run(_seed_for_cli()):
        pytest.skip(f"MySQL not reachable at {URL}")
    monkeypatch.setenv("COLLECTOR_DATABASE_URL", URL)
    monkeypatch.setenv("COLLECTOR_CORE_SCHEMA", URL.rsplit("/", 1)[1])
    assert cli.main(["replay"]) == 0
    assert asyncio.run(_buffered()) == 2
