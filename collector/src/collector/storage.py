"""MySQL adapters for the collector's ports (schema `collector`, docs/adr/0001).

- migrate(): applies collector/migrations/*.sql once each (schema_migrations).
- MySqlHistoryStore: lot_history.
- MySqlIngestSink: appends CarLot records to ingest_lots with a content hash
  that ignores run-specific fields, so an unchanged lot hashes the same in
  every run and the core only marks it as seen.
- JobQueue: the collector's side of the core's parse_jobs table (claim, progress,
  finish, cancel check). parse_jobs lives in the core schema; the collector only
  updates status / progress / result of the jobs it claimed.
"""

from __future__ import annotations

import hashlib
import json
import re
import zlib
from collections.abc import Iterable, Sequence
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any, Final

from sqlalchemy import bindparam, text
from sqlalchemy.ext.asyncio import AsyncConnection, AsyncEngine, create_async_engine

from collector import COLLECTOR_VERSION
from collector.domain.carlot import CarLotV1
from collector.ports import HistoryState, RawEntry

_IDENTIFIER: Final = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$")
MIGRATIONS_DIR: Final = Path(__file__).resolve().parents[2] / "migrations"
# Different on every run for the same listing; not part of "did the lot change".
VOLATILE_PATHS: Final = (("observed_at",), ("run_id",), ("history", "observed_at"))


def engine(url: str, **kw: Any) -> AsyncEngine:
    return create_async_engine(url, pool_pre_ping=True, pool_recycle=1800, **kw)


def _statements(sql: str) -> list[str]:
    body = "\n".join(line for line in sql.splitlines() if not line.lstrip().startswith("--"))
    return [s.strip() for s in body.split(";") if s.strip()]


async def migrate(db: AsyncEngine, directory: Path = MIGRATIONS_DIR) -> list[str]:
    """Apply pending migrations in name order; returns the versions applied now."""
    applied_now: list[str] = []
    async with db.begin() as conn:
        await conn.execute(
            text(
                "CREATE TABLE IF NOT EXISTS schema_migrations ("
                " version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)"
            )
        )
        done = {r[0] for r in (await conn.execute(text("SELECT version FROM schema_migrations"))).all()}
    for path in sorted(directory.glob("*.sql")):
        version = path.stem
        if version in done:
            continue
        async with db.begin() as conn:
            for statement in _statements(path.read_text(encoding="utf-8")):
                await conn.execute(text(statement))
            await conn.execute(
                text("INSERT INTO schema_migrations (version, applied_at) VALUES (:v, UTC_TIMESTAMP())"),
                {"v": version},
            )
        applied_now.append(version)
    return applied_now


def content_hash(lot: CarLotV1) -> str:
    data = json.loads(lot.model_dump_json())
    for path in VOLATILE_PATHS:
        node = data
        for key in path[:-1]:
            node = node.get(key, {})
        node.pop(path[-1], None)
    canonical = json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode()).hexdigest()


def _naive_utc(ts: datetime) -> datetime:
    return ts.astimezone(UTC).replace(tzinfo=None)


class MySqlIngestSink:
    def __init__(self, db: AsyncEngine) -> None:
        self.db = db
        self.last_id = 0
        self.written = 0

    async def write(self, lots: Sequence[CarLotV1]) -> None:
        if not lots:
            return
        rows = [
            {
                "run_id": lot.run_id,
                "source": lot.source,
                "source_id": lot.source_id,
                "contract": lot.contract,
                "payload": lot.model_dump_json(),
                "content_hash": content_hash(lot),
            }
            for lot in lots
        ]
        async with self.db.begin() as conn:
            await conn.execute(
                text(
                    "INSERT INTO ingest_lots (run_id, source, source_id, contract, payload, content_hash)"
                    " VALUES (:run_id, :source, :source_id, :contract, :payload, :content_hash)"
                ),
                rows,
            )
            # One collector process writes the buffer, so the newest id is this batch's last.
            last = (await conn.execute(text("SELECT MAX(id) FROM ingest_lots"))).scalar_one()
        self.last_id = max(self.last_id, int(last or 0))
        self.written += len(rows)

    async def resume(self, run_id: int) -> set[str]:
        """Lots the run wrote before it stopped; its last buffer id stays the run's ingest_last_id."""
        async with self.db.connect() as conn:
            rows = (
                await conn.execute(
                    text("SELECT DISTINCT source_id FROM ingest_lots WHERE run_id = :r"), {"r": run_id}
                )
            ).all()
            last = (
                await conn.execute(text("SELECT MAX(id) FROM ingest_lots WHERE run_id = :r"), {"r": run_id})
            ).scalar_one()
        self.last_id = max(self.last_id, int(last or 0))
        return {r.source_id for r in rows}


class MySqlHistoryStore:
    def __init__(self, db: AsyncEngine) -> None:
        self.db = db

    async def states(self, source: str, lot_ids: Sequence[str]) -> dict[str, HistoryState]:
        if not lot_ids:
            return {}
        async with self.db.connect() as conn:
            rows = (
                await conn.execute(
                    text(
                        "SELECT lot_id, checked_at, condition_tags, fields FROM lot_history"
                        " WHERE source = :source AND lot_id IN :ids"
                    ).bindparams(bindparam("ids", expanding=True)),
                    {"source": source, "ids": list(lot_ids)},
                )
            ).all()
        return {
            r.lot_id: HistoryState(
                lot_id=r.lot_id,
                checked_at=r.checked_at.replace(tzinfo=UTC),
                condition=tuple(json.loads(r.condition_tags)),
                fields=json.loads(r.fields),
            )
            for r in rows
        }

    async def save(self, source: str, states: Iterable[HistoryState]) -> None:
        rows = [
            {
                "source": source,
                "lot_id": s.lot_id,
                "checked_at": _naive_utc(s.checked_at),
                "condition_tags": json.dumps(list(s.condition), ensure_ascii=False),
                "fields": json.dumps(s.fields, ensure_ascii=False, default=str),
            }
            for s in states
        ]
        if not rows:
            return
        async with self.db.begin() as conn:
            await conn.execute(
                text(
                    "INSERT INTO lot_history (source, lot_id, checked_at, condition_tags, fields)"
                    " VALUES (:source, :lot_id, :checked_at, :condition_tags, :fields) AS new"
                    " ON DUPLICATE KEY UPDATE checked_at = new.checked_at,"
                    " condition_tags = new.condition_tags, fields = new.fields"
                ),
                rows,
            )


class MySqlRawStore:
    """raw_responses: the latest response of each kind per lot, zlib; rewritten only when it changed."""

    def __init__(self, db: AsyncEngine) -> None:
        self.db = db

    async def put_many(self, source: str, entries: Sequence[RawEntry], fetched_at: datetime) -> None:
        rows = []
        for e in entries:
            body = json.dumps(e.data, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
            rows.append(
                {
                    "source": source,
                    "lot_id": e.lot_id,
                    "kind": e.kind,
                    "hash": hashlib.sha256(body).hexdigest(),
                    "body": zlib.compress(body, 6),
                    "at": _naive_utc(fetched_at),
                }
            )
        if not rows:
            return
        async with self.db.begin() as conn:
            await conn.execute(
                text(
                    "INSERT INTO raw_responses (source, lot_id, kind, content_hash, body, fetched_at)"
                    " VALUES (:source, :lot_id, :kind, :hash, :body, :at) AS new"
                    " ON DUPLICATE KEY UPDATE fetched_at = new.fetched_at,"
                    " body = IF(raw_responses.content_hash = new.content_hash, raw_responses.body, new.body),"
                    " content_hash = new.content_hash"
                ),
                rows,
            )

    async def load(self, source: str, lot_ids: Sequence[str]) -> dict[str, dict[str, Any]]:
        if not lot_ids:
            return {}
        async with self.db.connect() as conn:
            rows = (
                await conn.execute(
                    text(
                        "SELECT lot_id, kind, body FROM raw_responses WHERE source = :s AND lot_id IN :ids"
                    ).bindparams(bindparam("ids", expanding=True)),
                    {"s": source, "ids": list(lot_ids)},
                )
            ).all()
        out: dict[str, dict[str, Any]] = {}
        for r in rows:
            out.setdefault(r.lot_id, {})[r.kind] = json.loads(zlib.decompress(r.body))
        return out

    async def lot_ids(self, source: str, *, after: str = "", limit: int = 500) -> list[str]:
        async with self.db.connect() as conn:
            rows = await conn.execute(
                text(
                    "SELECT DISTINCT lot_id FROM raw_responses WHERE source = :s AND lot_id > :after"
                    " ORDER BY lot_id LIMIT :limit"
                ),
                {"s": source, "after": after, "limit": limit},
            )
            return [r.lot_id for r in rows]


# ── jobs (core table parse_jobs) ────────────────────────────────────────────


@dataclass(frozen=True)
class Job:
    id: int
    source: str
    type: str  # full | reparse
    filters: dict[str, Any]
    target_lot_ids: tuple[str, ...]
    checkpoint: dict[str, Any] | None
    recovered: bool = False  # was left running by a collector that was killed


class JobCancelledError(RuntimeError):
    """The admin cancelled the job while it ran."""


def _json(value: Any) -> Any:
    if value is None or isinstance(value, dict | list):
        return value
    try:
        return json.loads(value)
    except (TypeError, ValueError):
        return None


class JobQueue:
    """Claims and reports parse_jobs rows. `table` is schema-qualified in production (railway.parse_jobs)."""

    def __init__(self, db: AsyncEngine, table: str = "parse_jobs") -> None:
        if not _IDENTIFIER.fullmatch(table):
            raise ValueError(f"not a table name: {table!r}")
        self.db = db
        self.table = table  # validated identifier: safe to format into SQL

    async def claim(
        self,
        sources: Sequence[str],
        busy: Iterable[str] = (),
        *,
        types: Sequence[str] = ("full", "reparse"),
        tagged_only: bool = False,
        stale_minutes: int = 0,
    ) -> Job | None:
        """Oldest interrupted (or stale), then pending job of these sources; full jobs of a busy source wait.

        tagged_only: take only jobs with filters.engine = "collector" (shadow mode, docs/adr/0007),
        so the old parser and the collector never take each other's jobs.
        stale_minutes: also take back a job the collector left `running` (it was killed before it
        could hand the job back) once it has reported nothing for this long; it resumes from the
        checkpoint of its last progress. Jobs of the old parser carry no collector mark and are
        never taken this way. 0 = off.
        """
        busy = set(busy)
        tag = " AND JSON_UNQUOTE(JSON_EXTRACT(filters, '$.engine')) = 'collector'" if tagged_only else ""
        stale = (
            " OR (status = 'running' AND updated_at < UTC_TIMESTAMP() - INTERVAL :stale MINUTE"
            f" AND JSON_UNQUOTE(JSON_EXTRACT(progress, '$.collector')) = '{COLLECTOR_VERSION}')"
            if stale_minutes > 0
            else ""
        )
        sql = (
            "SELECT id, source, type, status, filters, target_lot_ids, progress"
            f" FROM {self.table}"
            f" WHERE (status IN ('interrupted', 'pending'){stale}) AND source IN :sources"
            f" AND COALESCE(type, 'full') IN :types{tag}"
            " ORDER BY FIELD(status, 'interrupted', 'running', 'pending'), created_at, id"
            " FOR UPDATE SKIP LOCKED"
        )
        params: dict[str, Any] = {"sources": list(sources), "types": list(types)}
        if stale_minutes > 0:
            params["stale"] = stale_minutes
        async with self.db.begin() as conn:
            rows = (
                await conn.execute(
                    text(sql).bindparams(
                        bindparam("sources", expanding=True), bindparam("types", expanding=True)
                    ),
                    params,
                )
            ).all()
            for r in rows:
                job_type = r.type or "full"
                if job_type == "full" and r.source in busy:
                    continue
                progress = _json(r.progress) or {}
                checkpoint = progress.get("checkpoint") if r.status in ("interrupted", "running") else None
                starting: dict[str, Any] = {
                    "status": "resuming" if checkpoint else "starting",
                    "page": 0,
                    "collector": COLLECTOR_VERSION,
                }
                if checkpoint:
                    starting["checkpoint"] = checkpoint
                claimed = await conn.execute(
                    text(
                        f"UPDATE {self.table} SET status = 'running', progress = :p,"
                        " updated_at = UTC_TIMESTAMP()"
                        " WHERE id = :id AND status = :old"
                    ),
                    {"p": json.dumps(starting), "id": r.id, "old": r.status},
                )
                if claimed.rowcount:
                    return Job(
                        id=r.id,
                        source=r.source,
                        type=job_type,
                        filters=_json(r.filters) or {},
                        target_lot_ids=tuple(str(i) for i in (_json(r.target_lot_ids) or [])),
                        checkpoint=checkpoint,
                        recovered=r.status == "running",
                    )
        return None

    async def progress(self, job_id: int, progress: dict[str, Any]) -> None:
        """Report progress; raises JobCancelledError when the admin cancelled the job."""
        async with self.db.begin() as conn:
            status = await self._status(conn, job_id)
            if status == "cancelled":
                raise JobCancelledError(f"job {job_id} cancelled")
            await conn.execute(
                text(f"UPDATE {self.table} SET progress = :p, updated_at = UTC_TIMESTAMP() WHERE id = :id"),
                {"p": json.dumps(progress, default=str), "id": job_id},
            )

    async def finish(
        self, job_id: int, status: str, result: dict[str, Any], progress: dict[str, Any] | None = None
    ) -> None:
        sets = "status = :s, result = :r, updated_at = UTC_TIMESTAMP()" + (
            ", progress = :p" if progress else ""
        )
        async with self.db.begin() as conn:
            await conn.execute(
                text(f"UPDATE {self.table} SET {sets} WHERE id = :id AND status = 'running'"),
                {
                    "s": status,
                    "r": json.dumps(result, default=str),
                    "p": json.dumps(progress or {}),
                    "id": job_id,
                },
            )

    async def interrupt(self, job_id: int, checkpoint: dict[str, Any]) -> None:
        """A stopping collector hands the job back; the next start resumes from the checkpoint."""
        async with self.db.begin() as conn:
            await conn.execute(
                text(
                    f"UPDATE {self.table} SET status = 'interrupted', progress = :p,"
                    " updated_at = UTC_TIMESTAMP()"
                    " WHERE id = :id AND status = 'running'"
                ),
                {
                    "p": json.dumps(
                        {"status": "interrupted", "collector": COLLECTOR_VERSION, "checkpoint": checkpoint}
                    ),
                    "id": job_id,
                },
            )

    async def interrupt_at_last_progress(self, job_id: int) -> None:
        """Hands back a job stopped mid-page: it resumes from the checkpoint of its last progress."""
        async with self.db.begin() as conn:
            await conn.execute(
                text(
                    f"UPDATE {self.table} SET status = 'interrupted', updated_at = UTC_TIMESTAMP(),"
                    " progress = JSON_OBJECT('status', 'interrupted', 'collector', :v,"
                    " 'checkpoint', JSON_EXTRACT(progress, '$.checkpoint'))"
                    " WHERE id = :id AND status = 'running'"
                ),
                {"id": job_id, "v": COLLECTOR_VERSION},
            )

    async def _status(self, conn: AsyncConnection, job_id: int) -> str | None:
        row = (
            await conn.execute(text(f"SELECT status FROM {self.table} WHERE id = :id"), {"id": job_id})
        ).first()
        return None if row is None else str(row.status)
