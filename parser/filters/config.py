"""Rule loader.

Filter rules are authored exclusively in the database (`parse_filters` table),
seeded via Laravel migration `*_create_parse_filters_table`. There are no
hardcoded baselines — if the migration is not applied or the table is empty,
the FilterEngine is a no-op and a warning is logged.

This keeps the single source of truth in the DB and avoids drift between
Python defaults and the admin-editable rule set.
"""

from __future__ import annotations

import json
import logging
from typing import Any

from .rules import Rule, RuleSet

logger = logging.getLogger(__name__)


def load_db_rules(conn=None) -> list[Rule]:
    """Load rules from `parse_filters` table. Returns [] on any error."""
    if conn is None:
        try:
            from repository import LotRepository
            repo = LotRepository()
            conn = repo._get_conn()
        except Exception as e:
            logger.warning(f"[filter] DB unavailable; no rules will be applied: {e}")
            return []

    try:
        with conn.cursor() as cur:
            # Try with rule_group_id column (AND-groups migration)
            try:
                cur.execute(
                    "SELECT name, source, field, operator, value, action, priority, "
                    "rule_group_id, enabled, description FROM parse_filters "
                    "WHERE enabled = 1 ORDER BY priority ASC, id ASC"
                )
            except Exception:
                # Column doesn't exist yet — fall back without it
                cur.execute(
                    "SELECT name, source, field, operator, value, action, priority, "
                    "enabled, description FROM parse_filters "
                    "WHERE enabled = 1 ORDER BY priority ASC, id ASC"
                )
            rows = cur.fetchall()
    except Exception as e:
        # Table may not exist yet (migration not applied). Non-fatal.
        logger.warning(
            f"[filter] parse_filters table not readable "
            f"({type(e).__name__}: {e}) — filter engine disabled. "
            f"Run Laravel migrations to enable filtering."
        )
        return []

    rules: list[Rule] = []
    for row in rows:
        try:
            raw_val = row["value"]
            value = _parse_rule_value(raw_val)
            rule = Rule(
                name=row["name"],
                field=row["field"],
                operator=row["operator"],
                value=value,
                action=row.get("action") or "skip",
                source=row.get("source") or None,
                priority=int(row.get("priority") or 100),
                group_id=row.get("rule_group_id") or None,
                enabled=bool(row.get("enabled", 1)),
                description=row.get("description") or "",
            )
            rules.append(rule)
        except Exception as e:
            logger.warning(f"[filter] skipping bad DB rule {row.get('name')!r}: {e}")
    if not rules:
        logger.warning(
            "[filter] parse_filters table is empty — filter engine disabled. "
            "Seed rules via migration or admin UI."
        )
    else:
        logger.info(f"[filter] loaded {len(rules)} rules from DB")
    return rules


def _parse_rule_value(raw: Any) -> Any:
    """Parse rule value from DB column.

    DB column stores JSON-encoded values. Accept:
      - JSON string (preferred): "123", "\"rental\"", "[1,2,3]"
      - plain string fallback: "rental"
      - plain scalar: 123
    """
    if raw is None:
        return None
    if isinstance(raw, (int, float, bool, list, dict)):
        return raw
    s = str(raw).strip()
    if not s:
        return s
    try:
        return json.loads(s)
    except Exception:
        return s


def load_rules(conn=None) -> RuleSet:
    """Load all enabled rules from DB, ordered by priority."""
    return RuleSet(rules=load_db_rules(conn=conn))
