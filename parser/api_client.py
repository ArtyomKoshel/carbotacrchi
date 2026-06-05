"""
Optional HTTP client for the Laravel internal API.

When LARAVEL_API_URL and LARAVEL_INTERNAL_TOKEN are set, the parser can send
lots through the API instead of writing to MySQL directly.

This is a thin wrapper — the main repository.py still works when the API
is not configured (direct DB access stays as the default).
"""

import json
import logging
import os
from typing import Any

import httpx

logger = logging.getLogger(__name__)

LARAVEL_API_URL   = os.getenv("LARAVEL_API_URL", "").rstrip("/")
INTERNAL_TOKEN    = os.getenv("LARAVEL_INTERNAL_TOKEN", "")
_SESSION          = None


def _session() -> httpx.Client:
    global _SESSION
    if _SESSION is None:
        _SESSION = httpx.Client(headers={
            "X-Internal-Token": INTERNAL_TOKEN,
            "Content-Type": "application/json",
            "Accept": "application/json",
        })
    return _SESSION


def is_configured() -> bool:
    return bool(LARAVEL_API_URL and INTERNAL_TOKEN)


def upsert_lots(source: str, lots: list[dict[str, Any]]) -> dict:
    """
    POST /api/internal/lots/upsert
    Returns: {"ok": true, "data": {"inserted": N, "updated": N, "errors": N}}
    """
    url = f"{LARAVEL_API_URL}/api/internal/lots/upsert"
    try:
        resp = _session().post(url, content=json.dumps(
            {"source": source, "lots": lots},
            ensure_ascii=False, default=str
        ), timeout=30)
        resp.raise_for_status()
        return resp.json()
    except httpx.TimeoutException:
        logger.error(f"[api_client] upsert_lots timeout (source={source}, lots={len(lots)})")
        raise
    except httpx.HTTPStatusError as e:
        logger.error(f"[api_client] upsert_lots HTTP {e.response.status_code}: {e.response.text[:200]}")
        raise
    except Exception as e:
        logger.error(f"[api_client] upsert_lots failed: {e}")
        raise


def delist_lots(source: str, lot_ids: list[str], reason: str = "not_seen") -> dict:
    """
    POST /api/internal/lots/delist
    Returns: {"ok": true, "data": {"delisted": N}}
    """
    url = f"{LARAVEL_API_URL}/api/internal/lots/delist"
    try:
        resp = _session().post(url, content=json.dumps(
            {"source": source, "lot_ids": lot_ids, "reason": reason},
            ensure_ascii=False
        ), timeout=30)
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        logger.error(f"[api_client] delist_lots failed: {e}")
        raise
