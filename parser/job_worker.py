"""
Job worker — polls parse_jobs table, runs full-source parsing with
per-page progress published to Redis Pub/Sub for live admin monitoring.
"""
from __future__ import annotations

import json
import logging
import time as _time
from datetime import datetime

import pymysql
import redis

from config import Config

logger = logging.getLogger(__name__)

_CHANNEL_PREFIX = "parse_progress:"


def _redis() -> redis.Redis:
    return redis.Redis(
        host=getattr(Config, "REDIS_HOST", "localhost"),
        port=int(getattr(Config, "REDIS_PORT", 6379)),
        decode_responses=True,
    )


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


def _publish(r: redis.Redis, source: str, payload: dict):
    try:
        r.publish(f"{_CHANNEL_PREFIX}{source}", json.dumps(payload))
    except Exception as e:
        logger.warning(f"[job_worker] Redis publish failed: {e}")


def process_pending_job() -> None:
    """Pick one pending parse_job and run it."""
    conn = _get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT * FROM parse_jobs WHERE status = 'pending' "
                "ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED"
            )
            job = cur.fetchone()
        if not job:
            return

        job_id = job["id"]
        source = job["source"]
        filters = json.loads(job["filters"]) if job["filters"] else {}

        _set_job(conn, job_id, "running", progress={"status": "starting", "page": 0})
        logger.info(f"[job_worker] Job #{job_id} starting: source={source} filters={filters}")

        r = _redis()
        _publish(r, source, {"job_id": job_id, "status": "running", "page": 0, "found": 0})

        try:
            result = _run_parse(source, filters, job_id, conn, r)
            _set_job(conn, job_id, "done", progress={"status": "done"}, result=result)
            _publish(r, source, {"job_id": job_id, "status": "done", **result})
            logger.info(f"[job_worker] Job #{job_id} done: {result}")
        except Exception as e:
            msg = f"{type(e).__name__}: {e}"
            _set_job(conn, job_id, "error", progress={"status": "error"},
                     result={"error": msg})
            _publish(r, source, {"job_id": job_id, "status": "error", "error": msg})
            logger.error(f"[job_worker] Job #{job_id} failed: {msg}")
    finally:
        conn.close()


def _run_parse(source: str, filters: dict, job_id: int, conn, r: redis.Redis) -> dict:
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

        def _on_page(page: int, found: int, total_pages: int | None = None):
            page_counts.append(found)
            progress = {
                "status": "running",
                "page": page,
                "total_pages": total_pages,
                "found_this_page": found,
                "found_total": sum(page_counts),
            }
            _set_job(conn, job_id, "running", progress=progress)
            _publish(r, source, {"job_id": job_id, **progress})

        total = parser.run(
            max_pages=max_pages,
            maker_filter=maker_filter,
            on_page_callback=_on_page,
        )

        return {"total": total, "pages": len(page_counts)}
    finally:
        repo.close()
