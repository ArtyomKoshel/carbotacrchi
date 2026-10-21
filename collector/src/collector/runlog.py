"""Per-job log lines in the collector schema (run_log), instead of a file on a shared volume.

The admin reads them by job id; the web service no longer needs the collector's disk,
which is what blocked zero-downtime deploys (docs/refactor, stage 6).
"""

from __future__ import annotations

import logging
from datetime import UTC, datetime
from typing import Final

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncEngine

from collector.ports import Clock

MAX_MESSAGE: Final = 4000
log = logging.getLogger("collector.run")


class RunLog:
    """Buffers lines and writes them in batches; also mirrors them to the process log."""

    def __init__(self, db: AsyncEngine, job_id: int, *, clock: Clock | None = None, batch: int = 50) -> None:
        self.db = db
        self.job_id = job_id
        self.clock = clock or (lambda: datetime.now(UTC))
        self.batch = batch
        self._buffer: list[dict[str, object]] = []

    async def write(self, level: str, message: str) -> None:
        log.log(
            logging.getLevelNamesMapping().get(level.upper(), logging.INFO),
            "[job %s] %s",
            self.job_id,
            message,
        )
        self._buffer.append(
            {
                "job_id": self.job_id,
                "at": self.clock().astimezone(UTC).replace(tzinfo=None),
                "level": level.lower(),
                "message": message[:MAX_MESSAGE],
            }
        )
        if len(self._buffer) >= self.batch:
            await self.flush()

    async def info(self, message: str) -> None:
        await self.write("info", message)

    async def warning(self, message: str) -> None:
        await self.write("warning", message)

    async def error(self, message: str) -> None:
        await self.write("error", message)

    async def flush(self) -> None:
        if not self._buffer:
            return
        rows, self._buffer = self._buffer, []
        async with self.db.begin() as conn:
            await conn.execute(
                text(
                    "INSERT INTO run_log (job_id, at, level, message) VALUES (:job_id, :at, :level, :message)"
                ),
                rows,
            )
