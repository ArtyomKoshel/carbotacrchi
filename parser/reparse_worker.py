"""
Reparse worker — polls the `reparse_requests` table and re-enriches
individual lots on demand (triggered from the admin panel).
"""
from __future__ import annotations

import logging

import pymysql

from config import Config
from repository import LotRepository

logger = logging.getLogger(__name__)


def _get_conn():
    return pymysql.connect(
        host=Config.DB_HOST,
        port=Config.DB_PORT,
        user=Config.DB_USERNAME,
        password=Config.DB_PASSWORD,
        database=Config.DB_DATABASE,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def process_pending() -> None:
    """Pick up one pending reparse request and process it."""
    conn = _get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, lot_id FROM reparse_requests "
                "WHERE status = 'pending' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED"
            )
            row = cur.fetchone()
            if not row:
                return

            req_id = row["id"]
            lot_id = row["lot_id"]

            cur.execute(
                "UPDATE reparse_requests SET status = 'running', updated_at = NOW() WHERE id = %s",
                (req_id,),
            )
            conn.commit()

        logger.info(f"[reparse] Processing lot {lot_id} (request #{req_id})")

        try:
            _run_reparse(lot_id)
            _set_status(conn, req_id, "done", "OK")
            logger.info(f"[reparse] Lot {lot_id} re-parsed successfully")
        except Exception as e:
            msg = f"{type(e).__name__}: {e}"
            _set_status(conn, req_id, "error", msg)
            logger.error(f"[reparse] Lot {lot_id} failed: {msg}")
    finally:
        conn.close()


def _run_reparse(lot_id: str) -> None:
    from parsers.kbcha import KBChaParser

    repo = LotRepository()
    try:
        lots = repo.get_lots_by_source("kbcha", ids=[lot_id])
        if not lots:
            raise ValueError(f"Lot {lot_id} not found in DB")

        parser = KBChaParser(repo)
        stats: dict = {"detail_fetched": 0, "errors": 0}
        parser._enricher.enrich_details(lots, stats)
        parser._enricher.enrich_inspections(lots, stats)

        if stats["errors"]:
            raise RuntimeError(f"Enrichment had {stats['errors']} error(s)")
    finally:
        repo.close()


def _set_status(conn, req_id: int, status: str, result: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE reparse_requests SET status = %s, result = %s, updated_at = NOW() WHERE id = %s",
            (status, result, req_id),
        )
    conn.commit()
