import logging

import pymysql
import pymysql.cursors
from apscheduler.schedulers.blocking import BlockingScheduler
from apscheduler.triggers.cron import CronTrigger
from apscheduler.triggers.interval import IntervalTrigger

from config import Config
from repository import LotRepository
from reparse_worker import process_pending
from job_worker import process_pending_job
import parsers  # noqa: F401 — triggers parser registration
from parsers.registry import get_all, get_enabled, ParserRegistration

logger = logging.getLogger(__name__)


def _build_trigger_from_str(schedule: str, interval_minutes: int):
    if not schedule:
        return IntervalTrigger(minutes=interval_minutes)
    kind, _, expr = schedule.partition(":")
    kind = kind.strip().lower()
    if kind == "interval":
        minutes = int(expr.strip()) if expr.strip() else interval_minutes
        return IntervalTrigger(minutes=minutes)
    if kind == "cron":
        parts = expr.strip().split()
        if len(parts) != 5:
            raise ValueError(f"cron needs 5 fields, got: {expr!r}")
        minute, hour, day, month, dow = parts
        return CronTrigger(minute=minute, hour=hour, day=day, month=month, day_of_week=dow)
    raise ValueError(f"Unknown schedule kind {kind!r}")


def _build_trigger(reg: ParserRegistration):
    return _build_trigger_from_str(reg.schedule, reg.interval_minutes)


def _load_db_schedules() -> dict[str, dict]:
    """Load parser_schedules from DB; returns {source: row_dict}. Empty if table missing."""
    try:
        conn = pymysql.connect(
            host=Config.DB_HOST, port=Config.DB_PORT,
            user=Config.DB_USERNAME, password=Config.DB_PASSWORD,
            database=Config.DB_DATABASE, charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=5,
        )
        with conn.cursor() as cur:
            cur.execute("SELECT * FROM parser_schedules")
            rows = cur.fetchall()
        conn.close()
        return {r["source"]: r for r in rows}
    except Exception as e:
        logger.warning(f"[scheduler] Could not load parser_schedules from DB: {e}")
        return {}


def _seed_schedules(db_schedules: dict) -> None:
    """Insert default rows for any registered parsers missing from parser_schedules."""
    registry = get_all()
    missing = [k for k in registry if k not in db_schedules]
    if not missing:
        return
    try:
        conn = pymysql.connect(
            host=Config.DB_HOST, port=Config.DB_PORT,
            user=Config.DB_USERNAME, password=Config.DB_PASSWORD,
            database=Config.DB_DATABASE, charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
        )
        with conn.cursor() as cur:
            for source in missing:
                reg = registry[source]
                cur.execute(
                    "INSERT IGNORE INTO parser_schedules "
                    "(source, enabled, schedule, interval_minutes, max_pages, created_at, updated_at) "
                    "VALUES (%s, %s, %s, %s, 0, NOW(), NOW())",
                    (source, reg.enabled, reg.schedule, reg.interval_minutes),
                )
        conn.commit()
        conn.close()
        logger.info(f"[scheduler] Seeded parser_schedules for: {missing}")
    except Exception as e:
        logger.warning(f"[scheduler] Could not seed parser_schedules: {e}")


def _make_job_fn(source_key: str, parser_cls, max_pages: int | None = None):
    def _run():
        logger.info(f"[{source_key}] Scheduled import starting...")
        repo = LotRepository()
        try:
            count = parser_cls(repo).run(max_pages=max_pages or None)
            logger.info(f"[{source_key}] Import finished: {count} lots")
        except Exception as e:
            logger.error(f"[{source_key}] Import failed: {e}")
        finally:
            repo.close()
    _run.__name__ = f"run_{source_key}"
    return _run


def start_scheduler():
    scheduler = BlockingScheduler()
    registry = get_all()

    db_schedules = _load_db_schedules()
    _seed_schedules(db_schedules)

    for source_key, reg in registry.items():
        db = db_schedules.get(source_key, {})
        enabled = bool(db.get("enabled", reg.enabled))
        if not enabled:
            logger.info(f"[{source_key}] disabled in DB schedule config, skipping")
            continue

        schedule_str = db.get("schedule") or reg.schedule
        interval_min = int(db.get("interval_minutes") or reg.interval_minutes)
        max_pages = int(db.get("max_pages") or 0) or None

        trigger = _build_trigger_from_str(schedule_str, interval_min)
        scheduler.add_job(
            _make_job_fn(source_key, reg.cls, max_pages),
            trigger,
            id=f"{source_key}_import",
            name=f"{source_key} Import",
            max_instances=1,
        )
        logger.info(f"[{source_key}] scheduled: {trigger} max_pages={max_pages}")

    scheduler.add_job(
        process_pending_job,
        IntervalTrigger(seconds=15),
        id="job_worker",
        name="Parse Job Worker",
        max_instances=1,
        coalesce=True,
    )
    scheduler.add_job(
        process_pending,
        IntervalTrigger(seconds=30),
        id="reparse_worker",
        name="Reparse Worker",
        max_instances=1,
        coalesce=True,
    )
    logger.info("[job_worker] polling every 15s | [reparse_worker] polling every 30s")

    logger.info(f"Scheduler started with {len(scheduler.get_jobs())} job(s)")
    scheduler.start()
