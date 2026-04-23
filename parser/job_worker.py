"""
Job worker — polls parse_jobs table, runs full-source parsing with
per-page progress published to Redis Pub/Sub for live admin monitoring.
"""
from __future__ import annotations

import json
import logging
import os
import threading
import time as _time
from datetime import datetime
from logging.handlers import RotatingFileHandler

import pymysql
import redis

from config import Config

logger = logging.getLogger(__name__)

class _UTC3Formatter(logging.Formatter):
    """Logging formatter that stamps times in UTC+3 (Moscow/Railway default offset)."""
    _tz = __import__("datetime").timezone(__import__("datetime").timedelta(hours=3))

    def formatTime(self, record, datefmt=None):
        import datetime
        dt = datetime.datetime.fromtimestamp(record.created, tz=self._tz)
        return dt.strftime(datefmt or "%Y-%m-%d %H:%M:%S")


_LOG_FMT = _UTC3Formatter(
    "%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)

_CHANNEL_PREFIX = "parse_progress:"


class JobCancelledError(BaseException):
    pass


class _ImportantOnly(logging.Filter):
    """During a job, only pass WARNING+, [STAT], and job_worker messages to the main log."""

    def filter(self, record: logging.LogRecord) -> bool:
        if record.levelno >= logging.WARNING:
            return True
        msg = record.getMessage()
        if "[STAT]" in msg or "[job_worker]" in msg:
            return True
        return False


def _redis() -> redis.Redis:
    return redis.Redis(
        host=getattr(Config, "REDIS_HOST", "localhost"),
        port=int(getattr(Config, "REDIS_PORT", 6379)),
        password=getattr(Config, "REDIS_PASSWORD", None),
        decode_responses=True,
    )


PARSE_LOCK_TTL = 4 * 3600  # 4 hours max

_RUNNING_THREADS: dict[int, threading.Thread] = {}
_RUNNING_SOURCES: dict[str, int] = {}
_RUNNING_LOCK = threading.Lock()


def acquire_parse_lock(r: redis.Redis, source: str, owner: str) -> bool:
    """Returns True if lock acquired (SET NX)."""
    return bool(r.set(f"parse_lock:{source}", owner, nx=True, ex=PARSE_LOCK_TTL))


def release_parse_lock(r: redis.Redis, source: str) -> None:
    r.delete(f"parse_lock:{source}")


def _get_conn():
    return pymysql.connect(
        host=Config.DB_HOST, port=Config.DB_PORT,
        user=Config.DB_USERNAME, password=Config.DB_PASSWORD,
        database=Config.DB_DATABASE, charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def _set_job(conn, job_id: int, status: str, progress: dict | None = None, result: dict | None = None):
    sets = ["status = %s", "updated_at = NOW()"]
    params: list = [status]
    if progress is not None:
        sets.append("progress = %s")
        params.append(json.dumps(progress))
    if result is not None:
        sets.append("result = %s")
        params.append(json.dumps(result))
    params.append(job_id)
    with conn.cursor() as cur:
        cur.execute(f"UPDATE parse_jobs SET {', '.join(sets)} WHERE id = %s", params)
    conn.commit()


def _save_job_stats(conn, job_id: int, source: str, result: dict):
    """Persist structured stats into job_stats table."""
    try:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO job_stats "
                "(job_id, source, total, api_total, new_lots, updated_lots, stale_lots, errors, db_count, "
                " coverage_pct, elapsed_s, search_time_s, enrich_time_s, pause_time_s, avg_per_lot_s, "
                " pages, error_types, error_log) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (
                    job_id, source,
                    result.get("total", 0),
                    result.get("api_total", 0),
                    result.get("new", 0),
                    result.get("updated", 0),
                    result.get("stale", 0),
                    result.get("errors", 0),
                    result.get("db_count", 0),
                    result.get("coverage_pct", 0),
                    result.get("elapsed_s", 0),
                    result.get("search_time_s", 0),
                    result.get("enrich_time_s", 0),
                    result.get("pause_time_s", 0),
                    result.get("avg_per_lot_s", 0),
                    result.get("pages", 0),
                    json.dumps(result.get("error_types", {})),
                    json.dumps(result.get("error_log", [])),
                ),
            )
        conn.commit()
        logger.info(f"[job_worker] Job #{job_id} stats saved to job_stats")
    except Exception as e:
        logger.warning(f"[job_worker] Failed to save job_stats for #{job_id}: {e}")


def _publish(r: redis.Redis, source: str, payload: dict):
    try:
        r.publish(f"{_CHANNEL_PREFIX}{source}", json.dumps(payload))
    except Exception as e:
        logger.warning(f"[job_worker] Redis publish failed ({type(e).__name__}): {e} — progress will not be live but job continues")


def _cleanup_finished_threads() -> None:
    with _RUNNING_LOCK:
        finished = [job_id for job_id, t in _RUNNING_THREADS.items() if not t.is_alive()]
        for job_id in finished:
            _RUNNING_THREADS.pop(job_id, None)
            for src, src_job_id in list(_RUNNING_SOURCES.items()):
                if src_job_id == job_id:
                    _RUNNING_SOURCES.pop(src, None)


def _unregister_running(job_id: int, source: str) -> None:
    with _RUNNING_LOCK:
        _RUNNING_THREADS.pop(job_id, None)
        if _RUNNING_SOURCES.get(source) == job_id:
            _RUNNING_SOURCES.pop(source, None)


def _execute_job(job_id: int, source: str, filters: dict, checkpoint: dict | None = None) -> None:
    conn = _get_conn()
    r: redis.Redis | None = None
    locked = False
    job_handler: RotatingFileHandler | None = None
    main_filter: _ImportantOnly | None = None
    main_handler: logging.Handler | None = None
    root = logging.getLogger()
    try:
        logger.info(f"[job_worker] Job #{job_id} starting: source={source} filters={filters}")

        r = _redis()

        try:
            locked = acquire_parse_lock(r, source, f"job:{job_id}")
        except Exception as e:
            logger.error(f"[job_worker] Job #{job_id}: Redis lock failed ({type(e).__name__}): {e} — marking as error")
            _set_job(conn, job_id, "error", progress={"status": "error"}, result={"error": f"Redis: {e}"})
            return

        if not locked:
            holder = r.get(f"parse_lock:{source}") or "unknown"
            logger.warning(f"[job_worker] Job #{job_id}: source '{source}' locked by '{holder}', requeueing")
            with conn.cursor() as _cur:
                _cur.execute(
                    "UPDATE parse_jobs SET status='pending', updated_at=NOW() WHERE id=%s AND status='running'",
                    (job_id,)
                )
            conn.commit()
            return

        _publish(r, source, {"job_id": job_id, "status": "running", "page": 0, "found": 0})

        if Config.LOG_FILE:
            job_log_dir = os.path.join(os.path.dirname(Config.LOG_FILE), "jobs")
            os.makedirs(job_log_dir, exist_ok=True)
            job_log_path = os.path.join(job_log_dir, f"job-{job_id}.log")
            try:
                job_handler = RotatingFileHandler(
                    job_log_path, maxBytes=50 * 1024 * 1024, backupCount=1, encoding="utf-8"
                )
                job_handler.setFormatter(_LOG_FMT)
                job_handler.setLevel(logging.DEBUG)
                main_log_path = os.path.abspath(Config.LOG_FILE)
                for h in root.handlers:
                    if isinstance(h, RotatingFileHandler):
                        base = os.path.abspath(getattr(h, "baseFilename", ""))
                        if base == main_log_path:
                            main_handler = h
                            break
                if main_handler:
                    main_filter = _ImportantOnly()
                    main_handler.addFilter(main_filter)
                root.addHandler(job_handler)
                logger.info(f"[job_worker] Job #{job_id} log: {job_log_path}")
            except Exception as lh_err:
                logger.warning(f"[job_worker] Could not create job log file: {lh_err}")
                job_handler = None

        _MAX_JOB_RETRIES = 3
        for attempt in range(1, _MAX_JOB_RETRIES + 1):
            try:
                if attempt > 1:
                    logger.info(f"[job_worker] Job #{job_id} retry {attempt}/{_MAX_JOB_RETRIES} — regenerating proxies...")
                    _set_job(conn, job_id, "running", progress={"status": f"retry {attempt}/{_MAX_JOB_RETRIES}"})
                    _publish(r, source, {"job_id": job_id, "status": "running", "retry": attempt})
                    try:
                        from parsers.encar.client import _reset_proxy_cache
                        _reset_proxy_cache()
                    except ImportError:
                        pass
                    _time.sleep(10 * attempt)

                result = _run_parse(source, filters, job_id, conn, r, checkpoint=checkpoint)
                _set_job(conn, job_id, "done", progress={"status": "done"}, result=result)
                _save_job_stats(conn, job_id, source, result)
                _publish(r, source, {"job_id": job_id, "status": "done", **result})
                logger.info(f"[job_worker] Job #{job_id} done: {result}")
                break
            except JobCancelledError:
                logger.info(f"[job_worker] Job #{job_id} CANCELLED — parser stopped cleanly")
                _set_job(conn, job_id, "cancelled", progress={"status": "cancelled"})
                _publish(r, source, {"job_id": job_id, "status": "cancelled"})
                break
            except Exception as e:
                msg = f"{type(e).__name__}: {e}"
                logger.error(f"[job_worker] Job #{job_id} attempt {attempt}/{_MAX_JOB_RETRIES} failed: {msg}")
                if attempt >= _MAX_JOB_RETRIES:
                    _set_job(conn, job_id, "error", progress={"status": "error"}, result={"error": msg})
                    _publish(r, source, {"job_id": job_id, "status": "error", "error": msg})
                    logger.error(f"[job_worker] Job #{job_id} failed after {_MAX_JOB_RETRIES} attempts: {msg}")
    finally:
        if locked and r is not None:
            try:
                release_parse_lock(r, source)
            except Exception:
                pass
        if job_handler:
            root.removeHandler(job_handler)
            job_handler.close()
        if main_handler and main_filter:
            main_handler.removeFilter(main_filter)
        _unregister_running(job_id, source)
        conn.close()


def process_pending_job() -> None:
    """Pick one pending or interrupted parse_job and dispatch it to a background thread.

    Interrupted jobs are auto-resumed with their checkpoint data.
    """
    _cleanup_finished_threads()

    conn = _get_conn()
    try:
        with _RUNNING_LOCK:
            busy_sources = set(_RUNNING_SOURCES.keys())

        # Prioritize interrupted (resumable) jobs, then pending
        with conn.cursor() as cur:
            source_filter = ""
            params: list = []
            if busy_sources:
                placeholders = ",".join(["%s"] * len(busy_sources))
                source_filter = f"AND source NOT IN ({placeholders})"
                params = list(sorted(busy_sources))
            cur.execute(
                f"SELECT * FROM parse_jobs WHERE status IN ('interrupted', 'pending') "
                f"{source_filter} "
                f"ORDER BY FIELD(status, 'interrupted', 'pending'), created_at "
                f"LIMIT 1 FOR UPDATE SKIP LOCKED",
                params,
            )
            job = cur.fetchone()
        if not job:
            return

        job_id = job["id"]
        source = job["source"]
        old_status = job["status"]
        filters = json.loads(job["filters"]) if job["filters"] else {}

        # Extract checkpoint from interrupted job's progress
        checkpoint = None
        if old_status == "interrupted":
            try:
                prev_progress = json.loads(job["progress"]) if job.get("progress") else {}
                checkpoint = prev_progress.get("checkpoint")
            except (json.JSONDecodeError, TypeError):
                pass
            if checkpoint:
                logger.info(f"[job_worker] Job #{job_id} resuming from checkpoint: {checkpoint}")

        # Atomic claim
        with conn.cursor() as cur:
            starting_progress = {"status": "resuming" if checkpoint else "starting", "page": 0}
            if checkpoint:
                starting_progress["checkpoint"] = checkpoint
            cur.execute(
                f"UPDATE parse_jobs SET status='running', updated_at=NOW(), progress=%s "
                f"WHERE id=%s AND status=%s",
                (json.dumps(starting_progress), job_id, old_status),
            )
            claimed = cur.rowcount
        conn.commit()
        if not claimed:
            logger.info(f"[job_worker] Job #{job_id} no longer {old_status}, skipping")
            return

        thread = threading.Thread(
            target=_execute_job,
            args=(job_id, source, filters, checkpoint),
            name=f"parse-job-{job_id}",
            daemon=True,
        )
        with _RUNNING_LOCK:
            _RUNNING_THREADS[job_id] = thread
            _RUNNING_SOURCES[source] = job_id
        try:
            thread.start()
        except Exception as e:
            _unregister_running(job_id, source)
            _set_job(conn, job_id, "error", progress={"status": "error"}, result={"error": f"ThreadStartError: {e}"})
            logger.error(f"[job_worker] Job #{job_id} thread start failed: {e}")
            return

        logger.info(f"[job_worker] Job #{job_id} dispatched to {thread.name}")
    finally:
        conn.close()


def _run_parse(source: str, filters: dict, job_id: int, conn, r: redis.Redis,
               checkpoint: dict | None = None) -> dict:
    from repository import LotRepository
    import parsers  # noqa: F401

    from parsers.registry import get_all

    registry = get_all()
    if source not in registry:
        raise ValueError(f"Unknown source: {source!r}")

    repo = LotRepository()
    try:
        parser = registry[source].cls(repo)

        max_pages = filters.get("max_pages") or None
        maker_filter = filters.get("maker") or None

        page_counts: list[int] = []
        _api_total_ref: list[int] = [0]  # mutable ref for closure
        _run_start = _time.monotonic()
        _checkpoint_ref: list[dict | None] = [None]

        def _on_page(page: int, found: int, total_pages: int | None = None, stats: dict | None = None):
            page_counts.append(found)
            found_total = sum(page_counts)
            if total_pages and total_pages > _api_total_ref[0]:
                _api_total_ref[0] = total_pages
            # Check cancel BEFORE overwriting status
            with conn.cursor() as _cur:
                _cur.execute("SELECT status FROM parse_jobs WHERE id=%s", (job_id,))
                row = _cur.fetchone()
            if row and row["status"] == "cancelled":
                logger.info(f"[job_worker] Job #{job_id} cancel detected at page #{page}")
                raise JobCancelledError(f"Job #{job_id} cancelled by user")
            pct = round(found_total / _api_total_ref[0] * 100, 1) if _api_total_ref[0] else 0
            elapsed = round(_time.monotonic() - _run_start, 1)
            avg_lot = round(elapsed / found_total, 2) if found_total else 0

            # Build checkpoint from stats (parser stores last_completed_maker there)
            if stats and stats.get("_checkpoint"):
                _checkpoint_ref[0] = stats["_checkpoint"]

            progress = {
                "status": f"{pct}%",
                "pct": pct,
                "page": page,
                "found_total": found_total,
                "api_total": _api_total_ref[0],
                "total": stats["total"] if stats else found_total,
                "new": stats["new"] if stats else 0,
                "updated": stats["updated"] if stats else 0,
                "errors": stats["errors"] if stats else 0,
                "elapsed_s": elapsed,
                "avg_per_lot_s": avg_lot,
                "search_time_s": round(stats["search_time"], 1) if stats else 0,
                "enrich_time_s": round(stats["enrich_time"], 1) if stats else 0,
                "pause_time_s": round(stats["pause_time"], 1) if stats else 0,
            }
            if _checkpoint_ref[0]:
                progress["checkpoint"] = _checkpoint_ref[0]
            _set_job(conn, job_id, "running", progress=progress)
            _publish(r, source, {"job_id": job_id, **progress})

        run_kwargs: dict = {
            "max_pages": max_pages,
            "maker_filter": maker_filter,
            "on_page_callback": _on_page,
        }
        if checkpoint:
            run_kwargs["checkpoint"] = checkpoint

        run_result = parser.run(**run_kwargs)

        # parser.run() returns dict with full stats or int (legacy)
        if isinstance(run_result, dict):
            run_result["pages"] = len(page_counts)
            return run_result
        return {"total": run_result, "pages": len(page_counts)}
    finally:
        repo.close()
