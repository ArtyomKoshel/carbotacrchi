"""python -m collector {serve|migrate|replay|schema}

serve    take jobs until SIGTERM (production entry point)
migrate  apply collector/migrations to COLLECTOR_DATABASE_URL and exit
replay   rebuild every lot from collector.raw_responses (no request to the platform) into the buffer
schema   write contracts/*.schema.json (same as python -m collector.contracts write)
"""

from __future__ import annotations

import asyncio
import logging
import os
import sys

from collector import contracts, storage
from collector.adapters.encar.pipeline import EncarPipeline
from collector.adapters.encar.replay import RawApi, replay_all
from collector.config import Settings
from collector.ports import MemoryHistoryStore
from collector.runner import load_rules
from collector.service import Service


async def _serve(settings: Settings) -> None:
    db = storage.engine(settings.database_url)
    service = Service(settings, db, tagged_only=os.environ.get("COLLECTOR_CLAIM", "tagged") != "all")
    service.install_signal_handlers()
    try:
        await service.serve()
    finally:
        await db.dispose()


async def _migrate(settings: Settings) -> None:
    db = storage.engine(settings.database_url)
    try:
        applied = await storage.migrate(db)
        print("applied:", ", ".join(applied) or "nothing")
    finally:
        await db.dispose()


async def _replay(settings: Settings) -> None:
    db = storage.engine(settings.database_url)
    try:
        sink = storage.MySqlIngestSink(db)
        pipeline = EncarPipeline(
            RawApi({}),  # type: ignore[arg-type]
            await load_rules(db, settings.core_schema),
            MemoryHistoryStore(),  # the live history state is not touched
            sink,
        )
        report = await replay_all(storage.MySqlRawStore(db), pipeline)
        print(f"replayed {report.listed} lots, wrote {sink.written} records (buffer up to id {sink.last_id})")
    finally:
        await db.dispose()


def main(argv: list[str] | None = None) -> int:
    args = argv if argv is not None else sys.argv[1:]
    command = args[0] if args else "serve"
    logging.basicConfig(
        level=os.environ.get("LOG_LEVEL", "INFO"), format="%(asctime)s %(levelname)s %(name)s %(message)s"
    )
    if command == "schema":
        return contracts.main(["write"])
    settings = Settings.from_env()
    if not settings.database_url:
        print("COLLECTOR_DATABASE_URL is not set", file=sys.stderr)
        return 2
    if command == "migrate":
        asyncio.run(_migrate(settings))
        return 0
    if command == "serve":
        asyncio.run(_serve(settings))
        return 0
    if command == "replay":
        asyncio.run(_replay(settings))
        return 0
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
