"""Virtual (computed) fields for the FilterEngine.

These fields aggregate data from `lot_inspections` and attach them as
temporary attributes on `CarLot` so filter rules can reference them.

Virtual fields are populated **after** enrichment (inspections written to DB)
and before the post-filter phase.  They are NOT stored in the `lots` table.

Supported virtual fields:
    inspection_count          — number of inspection records for the lot
    accident_max_cost         — max(my_accident_cost + other_accident_cost) across inspections
    total_accident_cost       — sum of all accident costs
    has_recall                — any inspection has_recall = True
    inspection_has_accident   — any inspection has_accident = True
    inspection_has_flood      — any inspection has_flood = True
"""

from __future__ import annotations

import logging
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from parser.models import CarLot

logger = logging.getLogger(__name__)

# All virtual field names — used by registry and engine to distinguish
# virtual from real columns.
VIRTUAL_FIELD_NAMES: frozenset[str] = frozenset({
    "inspection_count",
    "accident_max_cost",
    "total_accident_cost",
    "has_recall",
    "inspection_has_accident",
    "inspection_has_flood",
})


def populate_virtual_fields(lots: list, repo) -> None:
    """Fetch inspection aggregates from DB and set virtual attrs on each lot.

    Args:
        lots: list of CarLot objects (must have .id set)
        repo: LotRepository instance with DB access
    """
    if not lots:
        return

    lot_ids = [lot.id for lot in lots]
    aggregates = _fetch_inspection_aggregates(repo, lot_ids)

    for lot in lots:
        agg = aggregates.get(lot.id)
        if agg:
            lot.inspection_count = agg["inspection_count"]
            lot.accident_max_cost = agg["accident_max_cost"]
            lot.total_accident_cost = agg["total_accident_cost"]
            lot.has_recall = agg["has_recall"]
            lot.inspection_has_accident = agg["inspection_has_accident"]
            lot.inspection_has_flood = agg["inspection_has_flood"]
        else:
            lot.inspection_count = 0
            lot.accident_max_cost = 0
            lot.total_accident_cost = 0
            lot.has_recall = False
            lot.inspection_has_accident = False
            lot.inspection_has_flood = False


def _fetch_inspection_aggregates(
    repo, lot_ids: list[str]
) -> dict[str, dict]:
    """Query lot_inspections for aggregate data per lot_id.

    Returns dict keyed by lot_id with aggregate values.
    """
    if not lot_ids:
        return {}

    conn = repo._get_conn()
    placeholders = ",".join(["%s"] * len(lot_ids))
    sql = f"""
        SELECT
            lot_id,
            COUNT(*)                                          AS inspection_count,
            MAX(COALESCE(my_accident_cost, 0)
              + COALESCE(other_accident_cost, 0))             AS accident_max_cost,
            SUM(COALESCE(my_accident_cost, 0)
              + COALESCE(other_accident_cost, 0))             AS total_accident_cost,
            MAX(has_recall)                                   AS has_recall,
            MAX(has_accident)                                 AS insp_has_accident,
            MAX(has_flood)                                    AS insp_has_flood
        FROM lot_inspections
        WHERE lot_id IN ({placeholders})
        GROUP BY lot_id
    """
    try:
        with conn.cursor() as cur:
            cur.execute(sql, lot_ids)
            rows = cur.fetchall()
    except Exception as e:
        logger.warning(f"[virtual_fields] query failed: {e}")
        return {}

    result = {}
    for row in rows:
        result[row[0]] = {
            "inspection_count": row[1] or 0,
            "accident_max_cost": row[2] or 0,
            "total_accident_cost": row[3] or 0,
            "has_recall": bool(row[4]),
            "inspection_has_accident": bool(row[5]),
            "inspection_has_flood": bool(row[6]),
        }
    return result
