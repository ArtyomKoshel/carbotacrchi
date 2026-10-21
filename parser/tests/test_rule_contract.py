"""
Selection-rule contract with the admin (laravel). The rows in
fixtures/parse_filters_contract.json are what the admin's rule builder saves;
here they go through the parser's own loader (filters.config.load_db_rules)
and must match exactly the lots the fixture says. The Laravel side checks the
same file: tests/Feature/Admin/ParseRuleContractTest.php.

Usage: python -m pytest parser/tests/test_rule_contract.py -v
"""
from __future__ import annotations

import json
import os
import sys
from types import SimpleNamespace

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest

from filters.config import load_db_rules

FIXTURE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "parse_filters_contract.json")
CASES = json.load(open(FIXTURE, encoding="utf-8"))["cases"]


class _Cursor:
    """Stands in for a pymysql DictCursor over parse_filters."""

    def __init__(self, rows):
        self._rows = rows

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def execute(self, sql, params=None):
        assert "FROM parse_filters" in sql

    def fetchall(self):
        return self._rows


class _Conn:
    def __init__(self, rows):
        self._rows = rows

    def cursor(self):
        return _Cursor(self._rows)


def _db_row(case: dict) -> dict:
    row = dict(case["row"])
    row.update(name=case["case"], priority=10, enabled=1, description=None)
    return row


def test_every_row_loads():
    rules = load_db_rules(conn=_Conn([_db_row(c) for c in CASES]))
    assert [r.name for r in rules] == [c["case"] for c in CASES]


@pytest.mark.parametrize("case", CASES, ids=[c["case"] for c in CASES])
def test_loader_keeps_row_fields(case):
    (rule,) = load_db_rules(conn=_Conn([_db_row(case)]))
    row = case["row"]
    assert rule.field == row["field"]
    assert rule.operator == row["operator"]
    assert rule.action == row["action"]
    assert rule.phase == row["phase"]
    assert rule.source == row["source"]
    assert rule.group_id == row["rule_group_id"]
    expected_value = None if row["value"] is None else json.loads(row["value"])
    assert rule.value == expected_value
    assert type(rule.value) is type(expected_value)


@pytest.mark.parametrize(
    "case, sample",
    [(c, s) for c in CASES for s in c["lots"]],
    ids=[f"{c['case']}[{i}]" for c in CASES for i, _ in enumerate(c["lots"])],
)
def test_rule_matches_like_the_fixture_says(case, sample):
    (rule,) = load_db_rules(conn=_Conn([_db_row(case)]))
    lot = SimpleNamespace(source="encar", **sample["lot"])
    assert rule.evaluate(lot) is sample["matches"]
