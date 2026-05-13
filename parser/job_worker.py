"""
Job worker — polls parse_jobs table, runs full-source parsing with
per-page progress published to Redis Pub/Sub for live admin monitoring.
"""
from __future__ import annotations

import json
import logging
import os
import re
import threading
import time as _time
from logging.handlers import RotatingFileHandler

import pymysql
import redis

from config import Config
from logging_config import LOG_FMT, setup_logging
from parsers.base import ProgressUpdate

logger = logging.getLogger(__name__)

_CHANNEL_PREFIX = "parse_progress:"


class JobCancelledError(BaseException):
    pass


class _JobLogRouterHandler(logging.Handler):
    """Persistent root handler that routes records to per-job files.

    Installed once per process. Avoids per-job add/remove handler mutations on root.
    """

    def __init__(self, base_log_file: str):
        super().__init__(level=logging.DEBUG)
        self._job_dir = os.path.join(os.path.dirname(base_log_file), "jobs")
        os.makedirs(self._job_dir, exist_ok=True)
        self._handlers: dict[int, RotatingFileHandler] = {}
        self._lock = threading.Lock()

    def _resolve_job_id(self, record: logging.LogRecord) -> int | None:
        rec_job_id = getattr(record, "job_id", None)
        if isinstance(rec_job_id, int):
            return rec_job_id

        if record.name == "job_worker":
            m = re.search(r"Job #(\d+)", record.getMessage())
            if m:
                return int(m.group(1))

        with _RUNNING_LOCK:
            running = tuple(_RUNNING_SOURCES.items())
        matched = [job_id for src, job_id in running if src in record.name]
        if len(matched) == 1:
            return matched[0]
        return None

    def _handler_for(self, job_id: int) -> RotatingFileHandler:
        with self._lock:
            h = self._handlers.get(job_id)
            if h is not None:
                return h
            path = os.path.join(self._job_dir, f"job-{job_id}.log")
            h = RotatingFileHandler(path, maxBytes=50 * 1024 * 1024, backupCount=1, encoding="utf-8")
            h.setFormatter(LOG_FMT)
            h.setLevel(logging.DEBUG)
            self._handlers[job_id] = h
            return h

    def close_job(self, job_id: int) -> None:
        with self._lock:
            h = self._handlers.pop(job_id, None)
        if h is not None:
            h.close()

    def emit(self, record: logging.LogRecord) -> None:
        job_id = self._resolve_job_id(record)
        if job_id is None:
            return
        try:
            self._handler_for(job_id).emit(record)
        except Exception:
            self.handleError(record)

    def close(self) -> None:
        with self._lock:
            handlers = list(self._handlers.values())
            self._handlers.clear()
        for h in handlers:
            h.close()
        super().close()


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
_JOB_LOG_ROUTER: _JobLogRouterHandler | None = None
_JOB_LOG_ROUTER_LOCK = threading.Lock()


def _ensure_job_log_router() -> None:
    global _JOB_LOG_ROUTER
    if not Config.LOG_FILE:
        return
    if _JOB_LOG_ROUTER is not None:
        return
    with _JOB_LOG_ROUTER_LOCK:
        if _JOB_LOG_ROUTER is not None:
            return
        router = _JobLogRouterHandler(Config.LOG_FILE)
        logging.getLogger().addHandler(router)
        _JOB_LOG_ROUTER = router


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


def _execute_job(
    job_id: int,
    source: str,
    filters: dict,
    checkpoint: dict | None = None,
    job_type: str = "full",
    target_lot_ids: list[str] | None = None,
) -> None:
    conn = _get_conn()
    r: redis.Redis | None = None
    locked = False
    is_reparse = job_type == "reparse"
    try:
        _ensure_job_log_router()
        logger.info(
            f"[job_worker] Job #{job_id} starting: source={source} type={job_type}"
            + (f" lots={target_lot_ids}" if is_reparse else f" filters={filters}")
        )
        if Config.LOG_FILE:
            job_log_path = os.path.join(os.path.dirname(Config.LOG_FILE), "jobs", f"job-{job_id}.log")
            logger.info(f"[job_worker] Job #{job_id} log: {job_log_path}")

        r = _redis()

        if not is_reparse:
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

        _MAX_JOB_RETRIES = 1 if is_reparse else 3
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

                if is_reparse:
                    result = _run_reparse(source, target_lot_ids or [], job_id, conn, r)
                else:
                    result = _run_parse(source, filters, job_id, conn, r, checkpoint=checkpoint)
                _set_job(conn, job_id, "done", progress={"status": "done"}, result=result)
                if not is_reparse:
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
        if _JOB_LOG_ROUTER is not None:
            _JOB_LOG_ROUTER.close_job(job_id)
        _unregister_running(job_id, source)


def process_pending_job() -> None:
    """Poll parse_jobs for pending/interrupted work and dispatch it in a background thread."""
    setup_logging()
    _ensure_job_log_router()
    _cleanup_finished_threads()

    conn = _get_conn()
    try:
        with _RUNNING_LOCK:
            busy_sources = set(_RUNNING_SOURCES.keys())

        # Prioritize interrupted (resumable) jobs, then pending.
        # Reparse jobs are allowed through even when the source is busy (no lock needed).
        with conn.cursor() as cur:
            source_filter = ""
            params: list = []
            if busy_sources:
                placeholders = ",".join(["%s"] * len(busy_sources))
                source_filter = (
                    f"AND (COALESCE(type, 'full') = 'reparse' "
                    f"OR source NOT IN ({placeholders}))"
                )
                params = list(sorted(busy_sources))
            try:
                cur.execute(
                    f"SELECT * FROM parse_jobs WHERE status IN ('interrupted', 'pending') "
                    f"{source_filter} "
                    f"ORDER BY FIELD(status, 'interrupted', 'pending'), created_at "
                    f"LIMIT 1 FOR UPDATE SKIP LOCKED",
                    params,
                )
            except Exception:
                # type column may not exist before migration — fall back to source-only filter
                fallback_filter = ""
                fallback_params: list = []
                if busy_sources:
                    placeholders = ",".join(["%s"] * len(busy_sources))
                    fallback_filter = f"AND source NOT IN ({placeholders})"
                    fallback_params = list(sorted(busy_sources))
                cur.execute(
                    f"SELECT * FROM parse_jobs WHERE status IN ('interrupted', 'pending') "
                    f"{fallback_filter} "
                    f"ORDER BY FIELD(status, 'interrupted', 'pending'), created_at "
                    f"LIMIT 1 FOR UPDATE SKIP LOCKED",
                    fallback_params,
                )
            job = cur.fetchone()
        if not job:
            return

        job_id = job["id"]
        source = job["source"]
        old_status = job["status"]
        filters = json.loads(job["filters"]) if job["filters"] else {}
        job_type = job.get("type") or "full"
        try:
            raw_ids = job.get("target_lot_ids")
            target_lot_ids: list[str] = (
                json.loads(raw_ids) if isinstance(raw_ids, str)
                else (raw_ids or [])
            )
        except (json.JSONDecodeError, TypeError):
            target_lot_ids = []

        # Extract checkpoint from interrupted job's progress (full jobs only)
        checkpoint = None
        if old_status == "interrupted" and job_type != "reparse":
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
            args=(job_id, source, filters, checkpoint, job_type, target_lot_ids),
            name=f"parse-job-{job_id}",
            daemon=True,
        )
        with _RUNNING_LOCK:
            _RUNNING_THREADS[job_id] = thread
            if job_type != "reparse":
                _RUNNING_SOURCES[source] = job_id
        try:
            thread.start()
        except Exception as e:
            _unregister_running(job_id, source)
            _set_job(conn, job_id, "error", progress={"status": "error"}, result={"error": f"ThreadStartError: {e}"})
            logger.error(f"[job_worker] Job #{job_id} thread start failed: {e}")
            return

        logger.info(f"[job_worker] Job #{job_id} ({job_type}) dispatched to {thread.name}")
    finally:
        conn.close()


def _run_reparse(source: str, target_lot_ids: list[str], job_id: int, conn, r: redis.Redis) -> dict:
    """Execute a reparse job for specific lot IDs, publishing progress to Redis."""
    from repository import LotRepository
    import parsers  # noqa: F401
    from parsers.registry import get_all

    registry = get_all()
    if source not in registry:
        raise ValueError(f"Unknown source: {source!r}")

    repo = LotRepository()
    try:
        parser = registry[source].cls(repo)
        _run_start = _time.monotonic()

        def _on_progress(update: ProgressUpdate) -> None:
            pct = round(update.total_progress * 100, 1)
            elapsed = round(_time.monotonic() - _run_start, 1)
            # Check cancel
            with conn.cursor() as _cur:
                _cur.execute("SELECT status FROM parse_jobs WHERE id=%s", (job_id,))
                row = _cur.fetchone()
            if row and row["status"] == "cancelled":
                raise JobCancelledError(f"Job #{job_id} cancelled by user")
            progress = {
                "status": f"{pct}%",
                "pct": pct,
                "phase": update.phase,
                "phase_progress": round(update.phase_progress, 3),
                "total_progress": round(update.total_progress, 3),
                "found_total": update.lots_processed or 0,
                "api_total": update.lots_found or len(target_lot_ids),
                "message": update.message,
                "elapsed_s": elapsed,
            }
            _set_job(conn, job_id, "running", progress=progress)
            _publish(r, source, {"job_id": job_id, **progress})

        result = parser.run_reparse(target_lot_ids, on_progress=_on_progress)
        return result
    finally:
        repo.close()


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

        def _on_progress(update: ProgressUpdate) -> None:
            """Unified progress handler — accepts ProgressUpdate from parsers."""
            page_counts.append(update.lots_processed or 1)
            found_total = sum(page_counts)
            if update.lots_found and update.lots_found > _api_total_ref[0]:
                _api_total_ref[0] = update.lots_found
            # Check cancel BEFORE overwriting status
            with conn.cursor() as _cur:
                _cur.execute("SELECT status FROM parse_jobs WHERE id=%s", (job_id,))
                row = _cur.fetchone()
            if row and row["status"] == "cancelled":
                logger.info(f"[job_worker] Job #{job_id} cancel detected during {update.phase}")
                raise JobCancelledError(f"Job #{job_id} cancelled by user")

            if update.phase in ('enrich', 'inspect'):
                pct = round(update.phase_progress * 100, 1)
            elif _api_total_ref[0] and found_total:
                pct = round(found_total / _api_total_ref[0] * 100, 1)
            elif update.total_progress:
                pct = round(update.total_progress * 100, 1)
            else:
                pct = 0
            elapsed = round(_time.monotonic() - _run_start, 1)
            avg_lot = round(elapsed / found_total, 2) if found_total else 0

            stats = update.stats
            # Build checkpoint from stats (parser stores last_completed_maker there)
            if stats and stats.get("_checkpoint"):
                _checkpoint_ref[0] = stats["_checkpoint"]

            progress = {
                "status": f"{pct}%",
                "pct": pct,
                "phase": update.phase,
                "phase_progress": round(update.phase_progress, 3),
                "total_progress": round(update.total_progress, 3),
                "found_total": found_total,
                "api_total": _api_total_ref[0],
                "total": stats["total"] if stats else found_total,
                "new": stats.get("new", 0) if stats else 0,
                "updated": stats.get("updated", 0) if stats else 0,
                "errors": stats.get("errors", 0) if stats else 0,
                "elapsed_s": elapsed,
                "avg_per_lot_s": avg_lot,
                "search_time_s": round(stats.get("search_time", 0), 1) if stats else 0,
                "enrich_time_s": round(stats.get("enrich_time", 0), 1) if stats else 0,
                "inspect_time_s": round(stats.get("inspect_time", 0), 1) if stats else 0,
                "pause_time_s": round(stats.get("pause_time", 0), 1) if stats else 0,
                "proxy_bytes": stats.get("proxy_bytes", 0) if stats else 0,
                "message": update.message,
            }
            if _checkpoint_ref[0]:
                progress["checkpoint"] = _checkpoint_ref[0]
            _set_job(conn, job_id, "running", progress=progress)
            _publish(r, source, {"job_id": job_id, **progress})

        run_kwargs: dict = {
            "max_pages": max_pages,
            "maker_filter": maker_filter,
            "on_page_callback": _on_progress,
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
