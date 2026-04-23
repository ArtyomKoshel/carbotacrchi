"""Filter rule primitives.

A Rule is a declarative predicate applied to a CarLot before it reaches the
database. Rules are authored as data (dict / DB rows) and evaluated at
runtime — no code changes are needed to adjust filters.

Supported operators:
    eq           - equality
    ne           - not equal
    gt, gte      - greater than / greater or equal
    lt, lte      - less than / less or equal
    in           - membership in list
    not_in       - negated membership
    between      - [min, max] inclusive
    is_null      - field is None
    is_not_null  - field is not None
    contains     - substring in string / element in list
    not_contains - negated contains
    regex        - matches regex (full match not required)

Supported actions:
    skip            - drop the lot before upsert
    flag            - tag the lot (lot.raw_data._flags += [rule.name]) but still upsert
    mark_inactive   - upsert with is_active = 0 (keeps history)
    allow           - explicit whitelist; stops rule chain on match
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any, Iterable


# ── Action constants ─────────────────────────────────────────────────────────
ACTION_ALLOW = "allow"
ACTION_SKIP = "skip"
ACTION_FLAG = "flag"
ACTION_MARK_INACTIVE = "mark_inactive"

_VALID_ACTIONS = frozenset({ACTION_ALLOW, ACTION_SKIP, ACTION_FLAG, ACTION_MARK_INACTIVE})


# ── Operators ────────────────────────────────────────────────────────────────
def _op_eq(a, b): return a == b
def _op_ne(a, b): return a != b
def _op_gt(a, b): return a is not None and a > b
def _op_gte(a, b): return a is not None and a >= b
def _op_lt(a, b): return a is not None and a < b
def _op_lte(a, b): return a is not None and a <= b


def _op_in(a, b):
    if not isinstance(b, (list, tuple, set, frozenset)):
        return False
    return a in b


def _op_not_in(a, b):
    if not isinstance(b, (list, tuple, set, frozenset)):
        return False
    if a is None:
        return True  # None is never "in" the list
    return a not in b


def _op_between(a, b):
    if a is None or not isinstance(b, (list, tuple)) or len(b) != 2:
        return False
    return b[0] <= a <= b[1]


def _op_is_null(a, b): return a is None
def _op_is_not_null(a, b): return a is not None


def _op_contains(a, b):
    if a is None:
        return False
    if isinstance(a, str):
        return b in a if isinstance(b, str) else False
    if isinstance(a, (list, tuple, set)):
        return b in a
    return False


def _op_not_contains(a, b):
    return not _op_contains(a, b)


_REGEX_CACHE: dict[str, re.Pattern] = {}


def _op_regex(a, b):
    if a is None or not isinstance(b, str):
        return False
    pat = _REGEX_CACHE.get(b)
    if pat is None:
        try:
            pat = re.compile(b)
        except re.error:
            return False
        _REGEX_CACHE[b] = pat
    return bool(pat.search(str(a)))


_OPERATORS = {
    "eq": _op_eq,
    "ne": _op_ne,
    "gt": _op_gt,
    "gte": _op_gte,
    "lt": _op_lt,
    "lte": _op_lte,
    "in": _op_in,
    "not_in": _op_not_in,
    "between": _op_between,
    "is_null": _op_is_null,
    "is_not_null": _op_is_not_null,
    "contains": _op_contains,
    "not_contains": _op_not_contains,
    "regex": _op_regex,
}


# ── Rule dataclass ───────────────────────────────────────────────────────────
@dataclass
class Rule:
    name: str
    field: str               # name of CarLot attribute (supports dotted e.g. "raw_data.sell_type")
    operator: str            # one of _OPERATORS keys
    value: Any               # rhs value, type depends on operator
    action: str = ACTION_SKIP
    source: str | None = None  # None = applies to all parsers; "encar"/"kbcha" = scoped
    priority: int = 100      # lower = evaluated first; allow-rules should have low numbers
    enabled: bool = True
    description: str = ""

    def __post_init__(self) -> None:
        import logging
        _log = logging.getLogger(__name__)

        if self.operator not in _OPERATORS:
            raise ValueError(
                f"Rule {self.name!r}: unknown operator {self.operator!r}, "
                f"valid: {sorted(_OPERATORS)}"
            )
        if self.action not in _VALID_ACTIONS:
            raise ValueError(
                f"Rule {self.name!r}: unknown action {self.action!r}, "
                f"valid: {sorted(_VALID_ACTIONS)}"
            )

        # Cross-check against Field Registry (soft — warn, don't fail). A dotted
        # path like 'raw_data.foo' is allowed as long as the root field exists.
        root = self.field.split(".", 1)[0]
        try:
            from fields import get_field
            spec = get_field(root)
            if spec is None:
                _log.warning(
                    f"[filter] rule {self.name!r}: field {root!r} not in FieldRegistry"
                )
            else:
                allowed = spec.allowed_operators
                if self.operator not in allowed and "." not in self.field:
                    _log.warning(
                        f"[filter] rule {self.name!r}: operator {self.operator!r} "
                        f"is unusual for {self.field!r} ({spec.dtype.value}); "
                        f"typical operators: {list(allowed)}"
                    )
        except Exception:
            # Registry import optional — never block rule creation.
            pass

    def applies_to(self, source: str) -> bool:
        return self.source is None or self.source == source

    def extract(self, lot) -> Any:
        """Resolve field path (supports dotted 'raw_data.foo') on a CarLot."""
        parts = self.field.split(".")
        obj: Any = lot
        for p in parts:
            if obj is None:
                return None
            if isinstance(obj, dict):
                obj = obj.get(p)
            else:
                obj = getattr(obj, p, None)
        return obj

    def evaluate(self, lot) -> bool:
        """Return True if the rule MATCHES (i.e. its action should fire)."""
        if not self.enabled:
            return False
        value = self.extract(lot)
        return _OPERATORS[self.operator](value, self.value)


@dataclass
class FilterResult:
    action: str                              # effective action for this lot
    matched_rules: list[Rule] = field(default_factory=list)  # rules that fired

    @property
    def should_skip(self) -> bool:
        return self.action == ACTION_SKIP

    @property
    def should_mark_inactive(self) -> bool:
        return self.action == ACTION_MARK_INACTIVE

    @property
    def is_allowed(self) -> bool:
        """True when action is ALLOW (explicit short-circuit whitelist)."""
        return self.action == ACTION_ALLOW

    @property
    def is_kept(self) -> bool:
        """True when the lot should be upserted (allow or flag — not skip/mark_inactive)."""
        return self.action in (ACTION_ALLOW, ACTION_FLAG)


# ── RuleSet (convenience collection) ─────────────────────────────────────────
@dataclass
class RuleSet:
    rules: list[Rule] = field(default_factory=list)

    def for_source(self, source: str) -> list[Rule]:
        """Return active rules applicable to given source, ordered by priority."""
        applicable = [r for r in self.rules if r.enabled and r.applies_to(source)]
        applicable.sort(key=lambda r: r.priority)
        return applicable

    def __iter__(self) -> Iterable[Rule]:
        return iter(self.rules)

    def __len__(self) -> int:
        return len(self.rules)
