"""MySQL adapters on a real MySQL (COLLECTOR_TEST_DB_URL; the local dev container by default)."""

from __future__ import annotations

import json
import os
from collections.abc import AsyncIterator
from datetime import UTC, datetime, timedelta
from typing import Any

import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from collector import storage
from collector.adapters.encar import mapping as m
from collector.domain.carlot import CarLotV1
from collector.ports import HistoryState
from collector.storage import JobCancelledError, JobQueue, MySqlHistoryStore, MySqlIngestSink, content_hash
from tests.fakes import item

URL = os.environ.get("COLLECTOR_TEST_DB_URL", "mysql+aiomysql://carbot:carbot@127.0.0.1:3318/collector_test")
NOW = datetime(2026, 10, 21, 9, 0, tzinfo=UTC)

# The core's parse_jobs as on prod (laravel migrations), for the job queue tests.
PARSE_JOBS = """
CREATE TABLE parse_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(32) NOT NULL,
  type ENUM('full','reparse') NOT NULL DEFAULT 'full',
  status ENUM('pending','running','done','error','cancelled','interrupted') NOT NULL DEFAULT 'pending',
  filters JSON NULL, target_lot_ids JSON NULL, progress JSON NULL, result JSON NULL,
  triggered_by VARCHAR(64) NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
)"""

TABLES = ("ingest_lots", "lot_history", "run_checkpoints", "run_log", "schema_migrations", "parse_jobs")

pytestmark = pytest.mark.mysql


@pytest.fixture
async def db() -> AsyncIterator[AsyncEngine]:
    eng = storage.engine(URL)
    try:
        async with eng.begin() as conn:
            for table in TABLES:
                await conn.execute(text(f"DROP TABLE IF EXISTS {table}"))
            await conn.execute(text(PARSE_JOBS))
    except Exception as e:
        await eng.dispose()
        pytest.skip(f"MySQL not reachable at {URL}: {type(e).__name__}")
    await storage.migrate(eng)
    yield eng
    await eng.dispose()


def carlot(vid: int = 1, *, run_id: int = 1, at: datetime = NOW, **over: Any) -> CarLotV1:
    return m.to_carlot(m.from_search(item(vid, **over)), None, observed_at=at, run_id=run_id)


async def test_migrations_apply_once(db: AsyncEngine) -> None:
    assert await storage.migrate(db) == []
    async with db.connect() as conn:
        rows = (await conn.execute(text("SELECT version FROM schema_migrations ORDER BY 1"))).all()
    assert [r[0] for r in rows] == ["001_ingest_lots", "002_lot_history", "003_runs"]


def test_statement_splitter_ignores_comments() -> None:
    assert storage._statements("-- a; b\nCREATE x;\n\n-- c\nCREATE y;") == ["CREATE x", "CREATE y"]


async def test_sink_appends_records_with_a_stable_hash(db: AsyncEngine) -> None:
    sink = MySqlIngestSink(db)
    first = carlot(1, run_id=1)
    again = carlot(1, run_id=2, at=NOW + timedelta(hours=1))
    changed = carlot(1, run_id=2, Price=1)

    await sink.write([first, again, changed])
    await sink.write([])

    assert content_hash(first) == content_hash(again), "run-specific fields do not change the hash"
    assert content_hash(first) != content_hash(changed)
    async with db.connect() as conn:
        rows = (
            await conn.execute(
                text("SELECT id, run_id, source_id, contract, payload FROM ingest_lots ORDER BY id")
            )
        ).all()
    assert [(r.run_id, r.source_id, r.contract) for r in rows] == [
        (1, "1", "carlot/1"),
        (2, "1", "carlot/1"),
        (2, "1", "carlot/1"),
    ]
    assert json.loads(rows[0].payload)["listing"]["price"]["amount"] == 18_900_000
    assert sink.last_id == rows[-1].id
    assert sink.written == 3


async def test_history_round_trip_and_update(db: AsyncEngine) -> None:
    store = MySqlHistoryStore(db)
    await store.save(
        "encar", [HistoryState("1", NOW, ("Inspection", "Record"), {"insurance_count": 2, "vin": "X"})]
    )
    await store.save(
        "encar", [HistoryState("1", NOW + timedelta(days=1), ("Record",), {"insurance_count": 3})]
    )
    await store.save("encar", [])

    states = await store.states("encar", ["1", "2"])
    assert set(states) == {"1"}
    assert states["1"].checked_at == NOW + timedelta(days=1)
    assert states["1"].condition == ("Record",)
    assert states["1"].fields == {"insurance_count": 3}
    assert await store.states("encar", []) == {}
    assert await store.states("kcar", ["1"]) == {}


async def _job(db: AsyncEngine, **cols: Any) -> int:
    cols = {"source": "encar", "status": "pending", "created_at": NOW.replace(tzinfo=None), **cols}
    names = ", ".join(cols)
    values = ", ".join(f":{k}" for k in cols)
    async with db.begin() as conn:
        res = await conn.execute(text(f"INSERT INTO parse_jobs ({names}) VALUES ({values})"), cols)  # noqa: S608
    return int(res.lastrowid)


async def test_claim_prefers_interrupted_and_resumes_its_checkpoint(db: AsyncEngine) -> None:
    pending = await _job(db)
    interrupted = await _job(db, status="interrupted", progress=json.dumps({"checkpoint": {"page": 7}}))
    queue = JobQueue(db)

    job = await queue.claim(["encar"])
    assert job is not None
    assert (job.id, job.checkpoint) == (interrupted, {"page": 7})
    nxt = await queue.claim(["encar"])
    assert nxt is not None
    assert (nxt.id, nxt.checkpoint) == (pending, None)
    assert await queue.claim(["encar"]) is None


async def test_full_job_waits_while_its_source_is_busy_but_reparse_does_not(db: AsyncEngine) -> None:
    await _job(db)
    reparse = await _job(db, type="reparse", target_lot_ids=json.dumps(["5", 6]))
    queue = JobQueue(db)

    job = await queue.claim(["encar"], busy={"encar"})
    assert job is not None
    assert (job.id, job.type, job.target_lot_ids) == (reparse, "reparse", ("5", "6"))
    assert await queue.claim(["encar"], busy={"encar"}) is None
    assert await queue.claim(["kcar"]) is None


async def test_progress_finish_interrupt_and_cancel(db: AsyncEngine) -> None:
    jid = await _job(db, filters=json.dumps({"max_pages": 5}))
    queue = JobQueue(db)
    job = await queue.claim(["encar"])
    assert job is not None
    assert job.filters == {"max_pages": 5}

    await queue.progress(jid, {"pct": 50})
    await queue.interrupt(jid, {"page": 3})
    async with db.connect() as conn:
        row = (
            await conn.execute(text("SELECT status, progress FROM parse_jobs WHERE id = :i"), {"i": jid})
        ).one()
    assert row.status == "interrupted"
    assert json.loads(row.progress)["checkpoint"] == {"page": 3}

    assert await queue.claim(["encar"]) is not None
    await queue.finish(jid, "done", {"total": 10}, progress={"pct": 100})
    async with db.connect() as conn:
        row = (
            await conn.execute(text("SELECT status, result FROM parse_jobs WHERE id = :i"), {"i": jid})
        ).one()
    assert (row.status, json.loads(row.result)) == ("done", {"total": 10})

    cancelled = await _job(db)
    assert await queue.claim(["encar"]) is not None
    async with db.begin() as conn:
        await conn.execute(text("UPDATE parse_jobs SET status = 'cancelled' WHERE id = :i"), {"i": cancelled})
    with pytest.raises(JobCancelledError):
        await queue.progress(cancelled, {"pct": 1})
