"""Cut che168's list into slices it can list whole.

One search query lists 19 pages of 20 at most (page_cap, 2026-10-31): a query with more cars than
that hides the rest. A slice under CAP is listed to its last page; one over it is cut by the next
filter, until every slice fits:

    segment (the selection rules as filters) → make → series → price ladder → halved price
    → single year of age → province

A cut keeps the slice's filters and adds one. Price ends are inclusive on che168 (a car at exactly
9.0 is in 8-9 and in 9-10): the run drops the repeat by id. A province slice still over CAP cannot
be cut further and is listed as far as che168 lets it (truncated: the pass is then not complete).
"""

from __future__ import annotations

from collections.abc import Callable
from itertools import pairwise
from typing import Any

from collector.domain.carlot import SelectionPhase
from collector.selection import Action, RuleEngine

# 19 pages of 20, less a margin for listings that come while the slice is read
CAP = 360
# 万 CNY: the price cuts of a series; the last end stands for «no upper bound»
PRICE_LADDER = (0, 3, 5, 8, 12, 18, 25, 35, 50, 80, 150, 10000)
MIN_PRICE_WIDTH = 0.2  # 万 CNY: narrower than this, a price cut stops and the age is cut instead
# China's provinces and province-level cities (che168 pid)
PROVINCES = (
    "110000", "120000", "130000", "140000", "150000", "210000", "220000", "230000", "310000",
    "320000", "330000", "340000", "350000", "360000", "370000", "410000", "420000", "430000",
    "440000", "450000", "460000", "500000", "510000", "520000", "530000", "540000", "610000",
    "620000", "630000", "640000", "650000", "710000", "810000", "820000",
)  # fmt: skip


def _range(value: str) -> tuple[float, float]:
    low, high = value.split("-")
    return float(low), float(high)


def _number(value: float) -> str:
    return f"{value:.2f}".rstrip("0").rstrip(".")


def cut(
    filters: dict[str, str],
    *,
    brands: Callable[[], list[str]],
    series: Callable[[str], list[str]],
    ages: tuple[float, float],
) -> list[dict[str, str]]:
    """The slices one level below a slice that is over CAP; [] when it cannot be cut any further.

    ages: the segment's age range in years when its filters name none, cut year by year."""
    if "pid" in filters:
        return []
    if "brandid" not in filters:
        return [{**filters, "brandid": b} for b in brands()]
    if "seriesid" not in filters:
        return [{**filters, "seriesid": s} for s in series(filters["brandid"])]
    return [{**filters, **part} for part in _finer(filters, ages)]


def _finer(filters: dict[str, str], ages: tuple[float, float]) -> list[dict[str, str]]:
    """Below a series: the price ladder, then halves of a price, then single years, then provinces."""
    if "price" not in filters:
        return [{"price": f"{_number(low)}-{_number(high)}"} for low, high in pairwise(PRICE_LADDER)]
    low, high = _range(filters["price"])
    first, last = _range(filters["agerange"]) if "agerange" in filters else ages
    if high - low >= 2 * MIN_PRICE_WIDTH:
        middle = round((low + high) / 2, 2)
        parts = [
            {"price": f"{_number(low)}-{_number(middle)}"},
            {"price": f"{_number(middle)}-{_number(high)}"},
        ]
    elif last > first:
        parts = [{"agerange": f"{year}-{year}"} for year in range(int(first), int(last) + 1)]
    else:
        parts = [{"pid": p} for p in PROVINCES]
    return parts


def key(filters: dict[str, Any]) -> str:
    """A slice's filters as one stable string, for the log and the checkpoint."""
    return "&".join(f"{k}={filters[k]}" for k in sorted(filters)) or "all"


def segment(rules: RuleEngine | None, year: int, source: str = "che168") -> dict[str, str]:
    """The selection rules che168's search can apply itself, as its filters: what they would skip
    is never listed. Only plain skip rules: a rule in an AND group, or any allow rule (it may let
    back what a skip drops), leaves the list whole. Ages get a year more: che168 counts them from the
    registration, the rules from the model year.

    year rule «lt N» → agerange 0-(year-N+1); mileage «gt M» → mileage 0-M/10000 (万 km);
    price «gt P» → price 0-P/10000 (万 CNY, a che168 rule: ₩ ones are dropped by che168_rules)."""
    if rules is None:
        return {}
    applicable = rules.applicable(source, SelectionPhase.PRE)
    if any(r.action is Action.ALLOW for r in applicable):
        return {}
    filters: dict[str, str] = {}
    for r in applicable:
        if r.action is not Action.SKIP or r.group_id or not isinstance(r.value, int | float):
            continue
        value = float(r.value)
        if r.field == "year" and r.operator in ("lt", "lte"):
            first = value if r.operator == "lt" else value + 1
            filters["agerange"] = f"0-{max(0, int(year - first + 1))}"
        elif r.field == "mileage" and r.operator in ("gt", "gte"):
            filters["mileage"] = f"0-{_number(value / 10000)}"
        elif r.field == "price" and r.operator in ("gt", "gte") and r.source == source:
            filters["price"] = f"0-{_number(value / 10000)}"
    return filters
