"""Selection rules (parse_filters) evaluated in the collector pipeline (ADR-0004).

Same semantics as the old parser (parser/filters/rules.py, engine.py): the
operators, OR between ungrouped rules, AND inside a rule_group_id, `allow`
short-circuits, otherwise the strongest action wins (skip > mark_inactive >
flag). New: rules keep their database id, evaluation is pure (no stats or skip
log; the core writes filter_skip_log from the verdict), and the verdict is the
contract's Selection.
"""

from __future__ import annotations

import json
import re
from collections.abc import Callable, Iterable, Mapping
from dataclasses import dataclass
from enum import StrEnum
from functools import cache
from typing import Any, Final

from collector.domain.carlot import RejectAction, Selection, SelectionPhase


class Action(StrEnum):
    ALLOW = "allow"
    SKIP = "skip"
    FLAG = "flag"
    MARK_INACTIVE = "mark_inactive"


_STRENGTH: Final = {Action.ALLOW: 3, Action.SKIP: 2, Action.MARK_INACTIVE: 1, Action.FLAG: 0}

_LISTS = (list, tuple, set, frozenset)


@cache
def _regex(pattern: str) -> re.Pattern[str] | None:
    try:
        return re.compile(pattern)
    except re.error:
        return None


def _contains(a: Any, b: Any) -> bool:
    if isinstance(a, str):
        return isinstance(b, str) and b in a
    if isinstance(a, list | tuple | set):
        return b in a
    return False


def _regex_match(a: Any, b: Any) -> bool:
    if a is None or not isinstance(b, str):
        return False
    pat = _regex(b)
    return bool(pat and pat.search(str(a)))


OPERATORS: Final[dict[str, Callable[[Any, Any], bool]]] = {
    "eq": lambda a, b: bool(a == b),
    "ne": lambda a, b: bool(a != b),
    "gt": lambda a, b: a is not None and bool(a > b),
    "gte": lambda a, b: a is not None and bool(a >= b),
    "lt": lambda a, b: a is not None and bool(a < b),
    "lte": lambda a, b: a is not None and bool(a <= b),
    "in": lambda a, b: isinstance(b, _LISTS) and a in b,
    "not_in": lambda a, b: isinstance(b, _LISTS) and (a is None or a not in b),
    "between": lambda a, b: (
        a is not None and isinstance(b, list | tuple) and len(b) == 2 and bool(b[0] <= a <= b[1])
    ),
    "is_null": lambda a, b: a is None,
    "is_not_null": lambda a, b: a is not None,
    "contains": lambda a, b: a is not None and _contains(a, b),
    "not_contains": lambda a, b: not (a is not None and _contains(a, b)),
    "regex": _regex_match,
}


def parse_value(raw: Any) -> Any:
    """parse_filters.value is JSON text ("200000", "\\"rental\\"", "[1,2]"); a plain string is kept."""
    if raw is None or isinstance(raw, int | float | bool | list | dict):
        return raw
    s = str(raw).strip()
    if not s:
        return s
    try:
        return json.loads(s)
    except ValueError:
        return s


@dataclass(frozen=True, slots=True)
class Rule:
    id: int
    name: str
    field: str
    operator: str
    value: Any
    action: Action = Action.SKIP
    source: str | None = None
    priority: int = 100
    group_id: str | None = None
    phase: SelectionPhase = SelectionPhase.PRE
    enabled: bool = True

    def __post_init__(self) -> None:
        if self.operator not in OPERATORS:
            raise ValueError(f"rule {self.name!r}: unknown operator {self.operator!r}")

    @classmethod
    def from_row(cls, row: Mapping[str, Any]) -> Rule:
        return cls(
            id=int(row["id"]),
            name=str(row["name"]),
            field=str(row["field"]),
            operator=str(row["operator"]),
            value=parse_value(row.get("value")),
            action=Action(row.get("action") or Action.SKIP),
            source=row.get("source") or None,
            priority=int(row.get("priority") or 100),
            group_id=row.get("rule_group_id") or None,
            phase=SelectionPhase(row.get("phase") or SelectionPhase.PRE),
            enabled=bool(row.get("enabled", True)),
        )

    def extract(self, values: Mapping[str, Any]) -> Any:
        """Field value; dotted paths reach into nested dicts ('raw_data.ad_type')."""
        obj: Any = values
        for part in self.field.split("."):
            if not isinstance(obj, Mapping):
                return None
            obj = obj.get(part)
        return obj

    def matches(self, values: Mapping[str, Any]) -> bool:
        return self.enabled and OPERATORS[self.operator](self.extract(values), self.value)

    def value_known(self, values: Mapping[str, Any]) -> bool:
        """Early evaluation: None, '' and 0 mean 'not known yet' (the list API sends 0 for missing)."""
        v = self.extract(values)
        if v is None or v == "":
            return False
        return not (isinstance(v, int | float) and not isinstance(v, bool) and v == 0)


@dataclass(frozen=True, slots=True)
class Verdict:
    action: Action
    matched: tuple[Rule, ...] = ()

    @property
    def rejects(self) -> bool:
        return self.action in (Action.SKIP, Action.MARK_INACTIVE)

    def selection(self, phase: SelectionPhase) -> Selection:
        flagged = tuple(r.id for r in self.matched if r.action is Action.FLAG)
        if not self.rejects:
            return Selection(passed=True, flagged_rule_ids=flagged)
        rejecting = tuple(r.id for r in self.matched if r.action is self.action)
        return Selection(
            passed=False,
            phase=phase,
            action=RejectAction(self.action.value),
            rule_ids=rejecting,
            flagged_rule_ids=flagged,
        )


ALLOWED: Final = Verdict(Action.ALLOW)


class RuleEngine:
    def __init__(self, rules: Iterable[Rule]) -> None:
        self._rules = sorted((r for r in rules if r.enabled), key=lambda r: (r.priority, r.id))

    @classmethod
    def from_rows(cls, rows: Iterable[Mapping[str, Any]]) -> RuleEngine:
        return cls(Rule.from_row(r) for r in rows)

    def excluding(self, drop: Callable[[Rule], bool]) -> RuleEngine:
        """The same rules without those `drop` picks (a platform that cannot judge them)."""
        return RuleEngine(r for r in self._rules if not drop(r))

    def applicable(self, source: str, phase: SelectionPhase) -> list[Rule]:
        return [r for r in self._rules if r.phase is phase and (r.source is None or r.source == source)]

    def evaluate(
        self,
        values: Mapping[str, Any],
        *,
        source: str,
        phase: SelectionPhase,
        only_fields: frozenset[str] | None = None,
    ) -> Verdict:
        """Strongest action of the rules that match.

        only_fields: early pass on partial data (list stage). Only rules on these
        fields whose value is known take part; an AND group only if all its members
        do; an `allow` rule that cannot be judged yet allows, so an early pass can
        only reject what the full pass would reject too.
        """
        rules = self.applicable(source, phase)
        if only_fields is not None:

            def judged(r: Rule) -> bool:
                return r.field in only_fields and r.value_known(values)

            if any(r.action is Action.ALLOW and not judged(r) for r in rules):
                return ALLOWED
            incomplete = {r.group_id for r in rules if r.group_id is not None and not judged(r)}
            rules = [r for r in rules if judged(r) and (r.group_id is None or r.group_id not in incomplete)]

        matched: list[Rule] = []
        groups: dict[str, list[Rule]] = {}
        for rule in rules:
            if rule.group_id is not None:
                groups.setdefault(rule.group_id, []).append(rule)
            elif self._safe_match(rule, values):
                matched.append(rule)
                if rule.action is Action.ALLOW:
                    return Verdict(Action.ALLOW, tuple(matched))
        for members in groups.values():
            if all(self._safe_match(r, values) for r in members):
                matched.extend(members)
                if any(r.action is Action.ALLOW for r in members):
                    return Verdict(Action.ALLOW, tuple(matched))

        if not matched:
            return ALLOWED
        strongest = max((r.action for r in matched), key=_STRENGTH.__getitem__)
        return Verdict(strongest, tuple(matched))

    @staticmethod
    def _safe_match(rule: Rule, values: Mapping[str, Any]) -> bool:
        try:
            return rule.matches(values)
        except TypeError:  # e.g. comparing str with int: the old engine logged and skipped
            return False
