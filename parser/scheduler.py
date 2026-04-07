import json
import logging
from datetime import datetime, timedelta

import pymysql
import pymysql.cursors
from apscheduler.schedulers.blocking import BlockingScheduler
from apscheduler.triggers.cron import CronTrigger
from apscheduler.triggers.interval import IntervalTrigger

from config import Config
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


def _enqueue_scheduled_job(
    source_key: str,
    max_pages: int | None = None,
    maker_filter: str | None = None,
) -> None:
    """Insert a parse_job row for the scheduler trigger; skip if one is already pending/running."""
    import json
    try:
        conn = pymysql.connect(
            host=Config.DB_HOST, port=Config.DB_PORT,
            user=Config.DB_USERNAME, password=Config.DB_PASSWORD,
            database=Config.DB_DATABASE, charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=5,
        )
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM parse_jobs WHERE source=%s AND status IN ('pending','running') LIMIT 1",
                (source_key,),
            )
            if cur.fetchone():
                logger.info(f"[{source_key}] Scheduled trigger skipped — job already pending/running")
                conn.close()
                return
            filters: dict = {"triggered_by": "scheduler"}
            if max_pages:
                filters["max_pages"] = max_pages
            if maker_filter:
                filters["maker"] = maker_filter
            cur.execute(
                "INSERT INTO parse_jobs (source, status, filters, created_at, updated_at) "
                "VALUES (%s, 'pending', %s, NOW(), NOW())",
                (source_key, json.dumps(filters)),
            )
        conn.commit()
        logger.info(f"[{source_key}] Scheduled parse_job enqueued (max_pages={max_pages} maker={maker_filter})")
        conn.close()
    except Exception as e:
        logger.error(f"[{source_key}] Failed to enqueue scheduled job: {e}")


def _make_job_fn(source_key: str, parser_cls, max_pages: int | None = None, maker_filter: str | None = None):
    def _run():
        _enqueue_scheduled_job(source_key, max_pages, maker_filter)
    _run.__name__ = f"run_{source_key}"
    return _run


def _apply_schedules(scheduler: BlockingScheduler, registry: dict, db_schedules: dict) -> None:
    """Add, reschedule, or remove parser import jobs based on current DB config."""
    for source_key, reg in registry.items():
        db = db_schedules.get(source_key, {})
        # Config flag (e.g. KBCHA_ENABLED) is a hard override — if False, ignore DB
        config_flag = getattr(Config, f"{source_key.upper()}_ENABLED", None)
        if config_flag is False:
            enabled = False
        else:
            enabled = bool(db.get("enabled", reg.enabled))
        job_id = f"{source_key}_import"

        if not enabled:
            if scheduler.get_job(job_id):
                scheduler.remove_job(job_id)
                logger.info(f"[{source_key}] disabled — removed from scheduler")
            continue

        schedule_str  = db.get("schedule") or reg.schedule
        interval_min  = int(db.get("interval_minutes") or reg.interval_minutes)
        max_pages     = int(db.get("max_pages") or 0) or None
        maker_filter  = db.get("maker_filter") or None
        trigger       = _build_trigger_from_str(schedule_str, interval_min)

        existing = scheduler.get_job(job_id)
        if existing is None:
            scheduler.add_job(
                _make_job_fn(source_key, reg.cls, max_pages, maker_filter),
                trigger,
                id=job_id,
                name=f"{source_key} Import",
                max_instances=1,
                next_run_time=datetime.now() + timedelta(seconds=60),
            )
            logger.info(f"[{source_key}] added: {trigger} max_pages={max_pages} maker={maker_filter} (first run in 60s)")
        else:
            # Reschedule only if trigger changed (compare string representation)
            if str(existing.trigger) != str(trigger):
                scheduler.reschedule_job(job_id, trigger=trigger)
                logger.info(f"[{source_key}] rescheduled: {trigger} max_pages={max_pages} maker={maker_filter}")


def _make_reload_fn(scheduler: BlockingScheduler, registry: dict, _state: dict):
    def _reload():
        fresh = _load_db_schedules()
        if fresh == _state.get("last"):
            return
        _state["last"] = fresh
        logger.info("[scheduler] DB schedules changed — applying hot-reload")
        _apply_schedules(scheduler, registry, fresh)
    return _reload


def _cleanup_stale_jobs() -> None:
    """On startup: mark orphaned 'running' jobs as error and release their Redis locks."""
    try:
        from job_worker import _redis, release_parse_lock
        r = _redis()
        conn = pymysql.connect(
            host=Config.DB_HOST, port=Config.DB_PORT,
            user=Config.DB_USERNAME, password=Config.DB_PASSWORD,
            database=Config.DB_DATABASE, charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
        )
        with conn.cursor() as cur:
            cur.execute("SELECT id, source FROM parse_jobs WHERE status = 'running'")
            stale = cur.fetchall()
        if stale:
            ids = [r["id"] for r in stale]
            with conn.cursor() as cur:
                cur.execute(
                    f"UPDATE parse_jobs SET status='error', progress=%s, updated_at=NOW() "
                    f"WHERE id IN ({','.join(['%s']*len(ids))})",
                    [json.dumps({"status": "error", "error": "service restarted"})]+ids,
                )
            conn.commit()
            for row in stale:
                try:
                    release_parse_lock(r, row["source"])
                except Exception:
                    pass
            logger.info(f"[scheduler] Cleaned up {len(stale)} stale running job(s): {ids}")
        conn.close()
    except Exception as e:
        logger.warning(f"[scheduler] Stale job cleanup failed (non-fatal): {e}")


def start_scheduler():
    scheduler = BlockingScheduler()
    registry  = get_all()

    _cleanup_stale_jobs()

    db_schedules = _load_db_schedules()
    _seed_schedules(db_schedules)
    _apply_schedules(scheduler, registry, db_schedules)

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

    _state = {"last": db_schedules}
    scheduler.add_job(
        _make_reload_fn(scheduler, registry, _state),
        IntervalTrigger(minutes=1),
        id="schedule_reload",
        name="Schedule Hot-Reload",
        coalesce=True,
    )

    logger.info("[job_worker] polling every 15s | [reparse_worker] polling every 30s")
    logger.info("[schedule_reload] checking DB every 1 min for schedule changes")
    logger.info(f"Scheduler started with {len(scheduler.get_jobs())} job(s)")
    scheduler.start()
