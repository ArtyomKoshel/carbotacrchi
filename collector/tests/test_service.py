"""The collector process end to end: parse_jobs → fake Encar → ingest_lots / lot_history → result.

Real MySQL (COLLECTOR_TEST_DB_URL), fake api.encar.com (tests/fakes.py).
"""

from __future__ import annotations

import json
import os
from collections.abc import AsyncIterator
from datetime import UTC, datetime, timedelta
from typing import Any

import httpx
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from collector import __main__ as cli
from collector import storage
from collector.config import Settings
from collector.runner import history_cutoff, qualified
from collector.service import Service
from tests.fakes import INVENTORY, FakeEncar
from tests.test_storage import PARSE_JOBS, TABLES, URL

SCHEMA = URL.rsplit("/", 1)[1]
NOW = datetime(2026, 10, 21, 9, 0, tzinfo=UTC)
N_LOTS = sum(len(ids) for groups in INVENTORY.values() for ids in groups.values())

PARSE_FILTERS = """
CREATE TABLE parse_filters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL, source VARCHAR(20) NULL,
  field VARCHAR(100) NOT NULL, operator VARCHAR(20) NOT NULL, value TEXT NULL,
  action VARCHAR(20) NOT NULL DEFAULT 'skip', priority INT NOT NULL DEFAULT 100,
  rule_group_id VARCHAR(64) NULL, phase VARCHAR(10) NOT NULL DEFAULT 'pre',
  enabled TINYINT(1) NOT NULL DEFAULT 1
)"""

pytestmark = pytest.mark.mysql


@pytest.fixture
async def db() -> AsyncIterator[AsyncEngine]:
    eng = storage.engine(URL)
    try:
        async with eng.begin() as conn:
            for table in (*TABLES, "parse_filters"):
                await conn.execute(text(f"DROP TABLE IF EXISTS {table}"))
            await conn.execute(text(PARSE_JOBS))
            await conn.execute(text(PARSE_FILTERS))
    except Exception as e:
        await eng.dispose()
        pytest.skip(f"MySQL not reachable at {URL}: {type(e).__name__}")
    await storage.migrate(eng)
    yield eng
    await eng.dispose()


def settings(**over: Any) -> Settings:
    return Settings(database_url=URL, core_schema=SCHEMA, poll_seconds=0, history_every_runs=3, **over)


def service(db: AsyncEngine, fake: FakeEncar, **over: Any) -> Service:
    transport = httpx.MockTransport(fake)
    return Service(
        settings(**over),
        db,
        client_factory=lambda proxy: httpx.AsyncClient(transport=transport),
        clock=lambda: NOW,
    )


async def add_job(db: AsyncEngine, filters: dict[str, Any] | None = None, *, tagged: bool = True) -> int:
    tag = {"engine": "collector"} if tagged else {}
    row = {
        "source": "encar",
        "status": "pending",
        "filters": json.dumps({**tag, **(filters or {})}),
        "created_at": NOW.replace(tzinfo=None),
    }
    columns = ", ".join(row)
    values = ", ".join(":" + k for k in row)
    async with db.begin() as conn:
        res = await conn.execute(text(f"INSERT INTO parse_jobs ({columns}) VALUES ({values})"), row)  # noqa: S608
    return int(res.lastrowid)


async def job_row(db: AsyncEngine, job_id: int) -> Any:
    async with db.connect() as conn:
        return (await conn.execute(text("SELECT * FROM parse_jobs WHERE id = :i"), {"i": job_id})).one()


async def count(db: AsyncEngine, table: str) -> int:
    async with db.connect() as conn:
        return int((await conn.execute(text(f"SELECT COUNT(*) FROM {table}"))).scalar_one())  # noqa: S608


async def test_full_run_fills_the_buffer_and_reports_for_lifecycle(db: AsyncEngine) -> None:
    jid = await add_job(db)
    svc = service(db, FakeEncar())

    assert await svc.run_once() is True

    row = await job_row(db, jid)
    result = json.loads(row.result)
    assert row.status == "done"
    assert result["total"] == result["api_total"] == N_LOTS
    assert result["coverage_pct"] == 100.0
    assert result["full_run"] is True
    assert result["run_started_at"] == "2026-10-21 09:00:00"
    assert result["collector"] == "collector/1"
    assert await count(db, "ingest_lots") == N_LOTS
    assert result["ingest_last_id"] == N_LOTS
    assert await count(db, "lot_history") == N_LOTS
    progress = json.loads(row.progress)
    assert progress["status"] == "done"
    assert {"pct", "api_total", "found_total", "errors", "elapsed_s", "proxy_bytes"} <= progress.keys()
    assert await svc.run_once() is False


async def test_jobs_without_the_collector_tag_are_left_to_the_old_parser(db: AsyncEngine) -> None:
    await add_job(db, tagged=False)
    assert await service(db, FakeEncar()).run_once() is False


async def test_selection_rules_come_from_parse_filters(db: AsyncEngine) -> None:
    async with db.begin() as conn:
        await conn.execute(text(
            "INSERT INTO parse_filters (name, field, operator, value, action)"
            " VALUES ('old', 'mileage', 'gt', '1', 'skip')"
        ))  # fmt: skip
    jid = await add_job(db)
    await service(db, FakeEncar()).run_once()

    result = json.loads((await job_row(db, jid)).result)
    assert result["rejected"] == N_LOTS
    async with db.connect() as conn:
        payload = json.loads(
            (await conn.execute(text("SELECT payload FROM ingest_lots LIMIT 1"))).scalar_one()
        )
    assert payload["selection"]["passed"] is False
    assert payload["selection"]["action"] == "skip"


async def test_stop_hands_the_job_back_and_the_next_process_resumes_it(db: AsyncEngine) -> None:
    jid = await add_job(db)
    fake = FakeEncar()
    first = service(db, fake)
    pages = 0
    original = first.queue.progress

    async def stop_after_first_page(job_id: int, progress: dict[str, Any]) -> None:
        nonlocal pages
        pages += 1
        await original(job_id, progress)
        first.stop.set()  # SIGTERM arrives after the first page

    first.queue.progress = stop_after_first_page  # type: ignore[method-assign]
    await first.run_once()

    row = await job_row(db, jid)
    assert row.status == "interrupted"
    checkpoint = json.loads(row.progress)["checkpoint"]
    assert checkpoint["started_at"] == NOW.isoformat()
    assert "global" in checkpoint["done_segments"]

    later = NOW + timedelta(hours=2)
    second = service(db, fake)
    second.clock = lambda: later
    await second.run_once()

    row = await job_row(db, jid)
    result = json.loads(row.result)
    assert row.status == "done"
    assert result["run_started_at"] == "2026-10-21 09:00:00", "a resumed run keeps its original start"
    assert result["full_run"] is True


async def test_partial_run_is_not_a_full_run(db: AsyncEngine) -> None:
    jid = await add_job(db, filters={"maker": "기아"})
    await service(db, FakeEncar()).run_once()
    result = json.loads((await job_row(db, jid)).result)
    assert result["total"] == len(INVENTORY["기아"]["K5"])
    assert result["full_run"] is False


async def test_search_outage_ends_as_a_run_that_does_not_count(db: AsyncEngine) -> None:
    fake = FakeEncar()
    fake.search_status = 500
    jid = await add_job(db)
    await service(db, fake).run_once()
    result = json.loads((await job_row(db, jid)).result)
    assert result["full_run"] is False
    assert result["errors"] >= 1


async def test_cancelled_job_stays_cancelled(db: AsyncEngine) -> None:
    jid = await add_job(db)
    svc = service(db, FakeEncar())
    original = svc.queue.progress

    async def cancel_then_report(job_id: int, progress: dict[str, Any]) -> None:
        async with db.begin() as conn:
            await conn.execute(
                text("UPDATE parse_jobs SET status = 'cancelled' WHERE id = :i"), {"i": job_id}
            )
        await original(job_id, progress)

    svc.queue.progress = cancel_then_report  # type: ignore[method-assign]
    await svc.run_once()
    assert (await job_row(db, jid)).status == "cancelled"


async def test_exhausted_proxy_budget_fails_the_job(db: AsyncEngine) -> None:
    fake = FakeEncar()
    fake.search_status = 402
    jid = await add_job(db)
    await service(db, fake).run_once()
    row = await job_row(db, jid)
    assert row.status == "error"
    assert "proxy budget" in json.loads(row.result)["error"]


async def test_unexpected_error_fails_the_job_with_its_reason(db: AsyncEngine) -> None:
    async with db.begin() as conn:
        await conn.execute(text("DROP TABLE parse_filters"))
    jid = await add_job(db)
    await service(db, FakeEncar()).run_once()
    row = await job_row(db, jid)
    assert row.status == "error"
    assert "parse_filters" in json.loads(row.result)["error"]


async def test_serve_migrates_and_stops_on_signal(db: AsyncEngine) -> None:
    svc = service(db, FakeEncar())
    svc.stop.set()
    await svc.serve()  # returns at once: stop requested


async def test_history_cutoff_rechecks_the_oldest_third(db: AsyncEngine) -> None:
    assert await history_cutoff(db, "encar", 3, NOW) is None
    async with db.begin() as conn:
        for i in range(6):
            await conn.execute(
                text("INSERT INTO lot_history VALUES ('encar', :i, :t, '[]', '{}')"),
                {"i": str(i), "t": (NOW - timedelta(days=i)).replace(tzinfo=None)},
            )
    cutoff = await history_cutoff(db, "encar", 3, NOW)
    assert cutoff == NOW - timedelta(days=3)
    assert await history_cutoff(db, "encar", 1, NOW) is None


def test_qualified_names_are_validated() -> None:
    assert qualified("railway", "parse_jobs") == "railway.parse_jobs"
    with pytest.raises(ValueError, match="schema"):
        qualified("x; DROP", "parse_jobs")


def test_settings_from_env(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("COLLECTOR_DATABASE_URL", "mysql+aiomysql://u:p@h/collector")
    monkeypatch.setenv("COLLECTOR_SOURCES", "encar,kcar")
    monkeypatch.setenv("FLOPPY_USERNAME", "u")
    monkeypatch.setenv("FLOPPY_PASSWORD", "secret")
    monkeypatch.setenv("FLOPPY_HOST", "proxy:1")
    s = Settings.from_env()
    assert s.sources == ("encar", "kcar")
    assert s.has_proxy
    assert "secret" not in repr(s)
    assert "u:p" not in repr(s)


def test_cli(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("COLLECTOR_DATABASE_URL", raising=False)
    assert cli.main(["serve"]) == 2
    monkeypatch.setenv("COLLECTOR_DATABASE_URL", URL)
    assert cli.main(["bogus"]) == 2
    assert cli.main(["migrate"]) == 0
    if os.environ.get("CI_WRITE_SCHEMA"):
        assert cli.main(["schema"]) == 0
