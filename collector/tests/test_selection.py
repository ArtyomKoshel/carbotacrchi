"""Selection rules: the shared parse_filters contract, engine semantics, parity with the old engine."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from types import SimpleNamespace
from typing import Any

import pytest
from hypothesis import given, settings
from hypothesis import strategies as st

from collector.domain.carlot import RejectAction, SelectionPhase
from collector.selection import Action, Rule, RuleEngine, parse_value

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = json.loads((ROOT / "contracts" / "fixtures" / "parse_filters.json").read_text(encoding="utf-8"))
CASES = CONTRACT["cases"]
PRE = SelectionPhase.PRE
POST = SelectionPhase.POST


def rule(rid: int, field: str, op: str, value: Any, action: str = "skip", **kw: Any) -> Rule:
    return Rule(id=rid, name=f"r{rid}", field=field, operator=op, value=value, action=Action(action), **kw)


# ── the contract shared with the admin and the old parser ──────────────────


@pytest.mark.parametrize("case", CASES, ids=[c["case"] for c in CASES])
def test_row_loads_like_the_contract_says(case: dict[str, Any]) -> None:
    row = case["row"]
    r = Rule.from_row({"id": 1, "name": case["case"], **row})
    assert (r.field, r.operator, r.action.value, r.phase.value, r.source, r.group_id) == (
        row["field"],
        row["operator"],
        row["action"],
        row["phase"],
        row["source"],
        row["rule_group_id"],
    )
    expected = None if row["value"] is None else json.loads(row["value"])
    assert r.value == expected
    assert type(r.value) is type(expected)


@pytest.mark.parametrize(
    ("case", "sample"),
    [(c, s) for c in CASES for s in c["lots"]],
    ids=[f"{c['case']}[{i}]" for c in CASES for i, _ in enumerate(c["lots"])],
)
def test_rule_matches_like_the_contract_says(case: dict[str, Any], sample: dict[str, Any]) -> None:
    r = Rule.from_row({"id": 1, "name": case["case"], **case["row"]})
    assert r.matches(sample["lot"]) is sample["matches"]


def test_parse_value() -> None:
    assert parse_value("200000") == 200_000
    assert parse_value('"rental"') == "rental"
    assert parse_value("[1, 2]") == [1, 2]
    assert parse_value("rental") == "rental"  # plain text fallback
    assert parse_value("  ") == ""
    assert parse_value(None) is None
    assert parse_value(5) == 5


def test_unknown_operator_is_refused() -> None:
    with pytest.raises(ValueError, match="unknown operator"):
        rule(1, "price", "approximately", 1)


# ── engine semantics ───────────────────────────────────────────────────────


def test_no_match_allows() -> None:
    engine = RuleEngine([rule(1, "mileage", "gt", 200_000)])
    verdict = engine.evaluate({"mileage": 100}, source="encar", phase=PRE)
    assert verdict.action is Action.ALLOW
    assert verdict.selection(PRE).passed is True


def test_strongest_action_wins_and_names_its_rules() -> None:
    engine = RuleEngine(
        [
            rule(1, "year", "lt", 2014, "flag"),
            rule(2, "year", "lt", 2014, "mark_inactive"),
            rule(3, "mileage", "gt", 200_000, "skip"),
        ]
    )
    verdict = engine.evaluate({"year": 2010, "mileage": 250_000}, source="encar", phase=PRE)
    assert verdict.action is Action.SKIP
    sel = verdict.selection(PRE)
    assert (sel.passed, sel.action, sel.rule_ids, sel.flagged_rule_ids) == (
        False,
        RejectAction.SKIP,
        (3,),
        (1,),
    )


def test_allow_short_circuits_in_priority_order() -> None:
    engine = RuleEngine(
        [
            rule(2, "mileage", "gt", 200_000, "skip", priority=20),
            rule(1, "make", "eq", "Porsche", "allow", priority=10),
        ]
    )
    assert (
        engine.evaluate({"make": "Porsche", "mileage": 300_000}, source="encar", phase=PRE).action
        is Action.ALLOW
    )
    assert (
        engine.evaluate({"make": "Kia", "mileage": 300_000}, source="encar", phase=PRE).action is Action.SKIP
    )


def test_and_group_needs_every_member() -> None:
    engine = RuleEngine(
        [
            rule(1, "year", "lt", 2016, group_id="old_expensive"),
            rule(2, "price", "gt", 40_000_000, group_id="old_expensive"),
        ]
    )
    assert engine.evaluate({"year": 2015, "price": 50_000_000}, source="encar", phase=PRE).rejects
    assert not engine.evaluate({"year": 2015, "price": 10_000_000}, source="encar", phase=PRE).rejects


def test_scope_phase_and_disabled_rules() -> None:
    engine = RuleEngine(
        [
            rule(1, "mileage", "gt", 1, source="kcar"),
            rule(2, "mileage", "gt", 1, phase=POST),
            rule(3, "mileage", "gt", 1, enabled=False),
        ]
    )
    assert not engine.evaluate({"mileage": 5}, source="encar", phase=PRE).rejects
    assert engine.evaluate({"mileage": 5}, source="encar", phase=POST).rejects
    assert engine.evaluate({"mileage": 5}, source="kcar", phase=PRE).rejects


def test_early_pass_judges_only_known_values() -> None:
    engine = RuleEngine([rule(1, "year", "lt", 2014), rule(2, "insurance_count", "gte", 4)])
    early = frozenset({"year", "insurance_count"})
    # year 0 = not known yet; insurance unknown → no early rejection
    assert not engine.evaluate({"year": 0}, source="encar", phase=PRE, only_fields=early).rejects
    assert engine.evaluate({"year": 2010}, source="encar", phase=PRE, only_fields=early).rejects
    # a field outside the early set is never judged early
    assert not engine.evaluate(
        {"year": 2010}, source="encar", phase=PRE, only_fields=frozenset({"price"})
    ).rejects


def test_early_pass_respects_unjudgeable_allow_and_incomplete_groups() -> None:
    allow = RuleEngine([rule(1, "trim", "eq", "X", "allow"), rule(2, "year", "lt", 2014)])
    assert not allow.evaluate(
        {"year": 2010}, source="encar", phase=PRE, only_fields=frozenset({"year"})
    ).rejects
    group = RuleEngine([rule(1, "year", "lt", 2016, group_id="g"), rule(2, "price", "gt", 1, group_id="g")])
    partial = group.evaluate({"year": 2010}, source="encar", phase=PRE, only_fields=frozenset({"year"}))
    assert not partial.rejects


def test_dotted_paths_and_type_errors() -> None:
    engine = RuleEngine([rule(1, "raw_data.ad_type", "eq", "rent"), rule(2, "year", "gt", "x")])
    assert engine.evaluate({"raw_data": {"ad_type": "rent"}, "year": 2020}, source="encar", phase=PRE).rejects
    assert not engine.evaluate({"raw_data": "oops", "year": 2020}, source="encar", phase=PRE).rejects


# ── parity with the old engine on random lots and rules ────────────────────

FIELDS = ("year", "mileage", "price", "insurance_count", "sell_type")
VALUES = {
    "year": st.integers(2000, 2026),
    "mileage": st.integers(0, 400_000),
    "price": st.integers(0, 200_000_000),
    "insurance_count": st.one_of(st.none(), st.integers(0, 9)),
    "sell_type": st.sampled_from(["sale", "rental", "lease"]),
}
rule_rows = st.lists(
    st.fixed_dictionaries(
        {
            "field": st.sampled_from(FIELDS[:4]),
            "operator": st.sampled_from(["gt", "gte", "lt", "lte", "eq", "ne", "is_null", "is_not_null"]),
            "value": st.integers(0, 300_000).map(str),
            "action": st.sampled_from(["skip", "mark_inactive", "flag", "allow"]),
            "priority": st.integers(1, 50),
            "rule_group_id": st.sampled_from([None, None, "g1"]),
        }
    ),
    max_size=6,
)
lots = st.fixed_dictionaries({f: VALUES[f] for f in FIELDS})


@pytest.fixture(scope="module")
def old_engine() -> Any:
    parser = ROOT / "parser"
    if not parser.exists():
        pytest.skip("old parser removed")
    sys.path.insert(0, str(parser))
    try:
        from filters.config import _parse_rule_value  # type: ignore[import-not-found]
        from filters.engine import FilterEngine  # type: ignore[import-not-found]
        from filters.rules import Rule as OldRule  # type: ignore[import-not-found]
    finally:
        sys.path.remove(str(parser))
    return FilterEngine, OldRule, _parse_rule_value


@pytest.mark.legacy
@settings(max_examples=300, deadline=None)
@given(rows=rule_rows, lot=lots)
def test_same_action_as_the_old_engine(
    old_engine: Any, rows: list[dict[str, Any]], lot: dict[str, Any]
) -> None:
    engine_cls, old_rule, parse = old_engine
    old_rules = [
        old_rule(
            name=f"r{i}",
            field=r["field"],
            operator=r["operator"],
            value=parse(r["value"]),
            action=r["action"],
            priority=r["priority"],
            group_id=r["rule_group_id"],
            phase="pre",
        )
        for i, r in enumerate(rows)
    ]
    old = engine_cls(old_rules).evaluate(SimpleNamespace(source="encar", raw_data={}, **lot), phase="pre")
    new = RuleEngine(
        Rule.from_row({"id": i, "name": f"r{i}", **r, "phase": "pre"}) for i, r in enumerate(rows)
    )
    assert new.evaluate(lot, source="encar", phase=PRE).action.value == old.action
