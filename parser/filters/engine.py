"""FilterEngine — applies a RuleSet to CarLot objects."""

from __future__ import annotations

import logging
from collections import defaultdict
from typing import Iterable

from .rules import (
    Rule, RuleSet, FilterResult,
    ACTION_ALLOW, ACTION_SKIP, ACTION_FLAG, ACTION_MARK_INACTIVE,
)

logger = logging.getLogger(__name__)

# Action precedence — higher number = stronger, overrides weaker actions.
# allow = 3 (wins over skip/flag/mark_inactive by explicit whitelist)
# skip = 2
# mark_inactive = 1
# flag = 0
_ACTION_PRIORITY: dict[str, int] = {
    ACTION_ALLOW: 3,
    ACTION_SKIP: 2,
    ACTION_MARK_INACTIVE: 1,
    ACTION_FLAG: 0,
}


class FilterEngine:
    """Applies a set of rules to incoming CarLots.

    Usage:
        engine = FilterEngine(rules)
        for lot in page_lots:
            result = engine.evaluate(lot)
            if result.should_skip:
                continue
            if result.should_mark_inactive:
                lot.raw_data["_mark_inactive"] = True
            ...
        engine.log_summary()
    """

    def __init__(self, rules: RuleSet | Iterable[Rule]):
        if isinstance(rules, RuleSet):
            self._rules = rules
        else:
            self._rules = RuleSet(rules=list(rules))
        self.stats: dict[str, int] = defaultdict(int)
        self.by_rule: dict[str, int] = defaultdict(int)
        self._skip_log_buffer: list[dict] = []

    def evaluate(self, lot) -> FilterResult:
        """Evaluate all applicable rules and return the strongest resulting action.

        Rules are partitioned into:
        - **Ungrouped** (group_id is None): each rule is independent (OR logic).
        - **Grouped** (same group_id): ALL rules in a group must match for the
          group's action to fire (AND logic). The group action is the strongest
          action among its member rules.
        """
        source = getattr(lot, "source", None) or ""
        applicable = self._rules.for_source(source)

        # ── Partition into ungrouped vs grouped ──────────────────────────────
        ungrouped: list[Rule] = []
        groups: dict[str, list[Rule]] = {}
        for rule in applicable:
            if rule.group_id is None:
                ungrouped.append(rule)
            else:
                groups.setdefault(rule.group_id, []).append(rule)

        matched: list[Rule] = []

        # ── 1. Evaluate ungrouped rules (OR — same as before) ────────────────
        for rule in ungrouped:
            try:
                if rule.evaluate(lot):
                    matched.append(rule)
                    self.by_rule[rule.name] += 1
                    self._log_skip_if_needed(lot, rule)
                    if rule.action == ACTION_ALLOW:
                        self.stats[ACTION_ALLOW] += 1
                        self._apply_flag_tags(lot, matched)
                        return FilterResult(action=ACTION_ALLOW, matched_rules=matched)
            except Exception as e:
                logger.warning(
                    f"[filter] rule {rule.name!r} raised {type(e).__name__}: {e}"
                )

        # ── 2. Evaluate AND-groups ───────────────────────────────────────────
        for gid, group_rules in groups.items():
            all_match = True
            for rule in group_rules:
                try:
                    if not rule.evaluate(lot):
                        all_match = False
                        break
                except Exception as e:
                    logger.warning(
                        f"[filter] rule {rule.name!r} (group={gid}) raised "
                        f"{type(e).__name__}: {e}"
                    )
                    all_match = False
                    break
            if all_match:
                matched.extend(group_rules)
                for rule in group_rules:
                    self.by_rule[rule.name] += 1
                    self._log_skip_if_needed(lot, rule)
                # Check if any rule in the group is an allow — short-circuit
                group_actions = [r.action for r in group_rules]
                if ACTION_ALLOW in group_actions:
                    self.stats[ACTION_ALLOW] += 1
                    self._apply_flag_tags(lot, matched)
                    return FilterResult(action=ACTION_ALLOW, matched_rules=matched)

        if not matched:
            self.stats[ACTION_ALLOW] += 1
            return FilterResult(action=ACTION_ALLOW, matched_rules=[])

        # Select strongest non-allow action
        best_action = max(
            (r.action for r in matched),
            key=lambda a: _ACTION_PRIORITY.get(a, -1),
        )
        self.stats[best_action] += 1
        self._apply_flag_tags(lot, matched)
        return FilterResult(action=best_action, matched_rules=matched)

    @staticmethod
    def _apply_flag_tags(lot, matched: list[Rule]) -> None:
        """Attach matched-rule names to lot.raw_data['_filter_flags'] for debugging/audit."""
        flag_rules = [r.name for r in matched if r.action == ACTION_FLAG]
        if not flag_rules:
            return
        if not hasattr(lot, "raw_data") or lot.raw_data is None:
            return
        existing = lot.raw_data.get("_filter_flags") or []
        if not isinstance(existing, list):
            existing = []
        # Preserve order, no duplicates
        for name in flag_rules:
            if name not in existing:
                existing.append(name)
        lot.raw_data["_filter_flags"] = existing

    def log_summary(self, source: str | None = None) -> None:
        """Log per-action totals and per-rule hit counts."""
        tag = f"[{source}] " if source else ""
        total = sum(self.stats.values())
        if total == 0:
            return
        skip = self.stats.get(ACTION_SKIP, 0)
        flag = self.stats.get(ACTION_FLAG, 0)
        inactive = self.stats.get(ACTION_MARK_INACTIVE, 0)
        allow = self.stats.get(ACTION_ALLOW, 0)
        logger.info(
            f"{tag}[filter] total={total} allow={allow} skip={skip} "
            f"flag={flag} mark_inactive={inactive}"
        )
        if self.by_rule:
            top = sorted(self.by_rule.items(), key=lambda kv: -kv[1])[:10]
            logger.info(
                f"{tag}[filter] top rules: "
                + ", ".join(f"{n}={c}" for n, c in top)
            )

    def reset_stats(self) -> None:
        self.stats.clear()
        self.by_rule.clear()
        self._skip_log_buffer.clear()

    def _log_skip_if_needed(self, lot, rule: Rule) -> None:
        """If rule action is skip/mark_inactive, add to buffer for DB logging."""
        if rule.action not in (ACTION_SKIP, ACTION_MARK_INACTIVE):
            return
        source = getattr(lot, "source", None) or ""
        source_id = getattr(lot, "id", None) or ""
        lot_url = getattr(lot, "lot_url", None) or ""
        field_value = rule.extract(lot)
        # Convert field_value to string for storage
        if isinstance(field_value, (list, tuple, set)):
            field_value_str = str(list(field_value))
        else:
            field_value_str = str(field_value) if field_value is not None else None

        self._skip_log_buffer.append({
            "source": source,
            "source_id": source_id,
            "lot_url": lot_url,
            "rule_name": rule.name,
            "rule_id": getattr(rule, "db_id", None),  # Set if loaded from DB
            "action": rule.action,
            "field_name": rule.field,
            "field_value": field_value_str,
        })

    def flush_skip_log(self, repo) -> int:
        """Bulk insert skip log entries to database."""
        if not self._skip_log_buffer:
            return 0
        try:
            count = repo.insert_filter_skip_log(self._skip_log_buffer)
            self._skip_log_buffer.clear()
            return count
        except Exception as e:
            logger.warning(f"[filter] flush_skip_log failed: {e}")
            return 0
