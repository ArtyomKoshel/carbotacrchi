"""
List-stage prefilter (FilterEngine.evaluate(only_fields=...)) and the Encar
enrichment client pool.

Usage: python -m pytest parser/tests/test_prefilter_and_pool.py -v
"""
from __future__ import annotations

import os
import sys
from concurrent.futures import ThreadPoolExecutor
from types import SimpleNamespace

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest

from filters.engine import FilterEngine
from filters.rules import Rule, RuleSet
from repository import LotRepository

LIST = LotRepository.LIST_STAGE_FIELDS

# The PRE rules on prod (parse_filters, 2026-10-19).
PROD_RULES = [
    Rule("exclude_rental", "sell_type", "eq", "rental"),
    Rule("exclude_lease", "sell_type", "eq", "lease"),
    Rule("max_mileage", "mileage", "gt", 200000),
    Rule("min_year", "year", "lt", 2014),
    Rule("max_insurance_count", "insurance_count", "gte", 4),
    Rule("absurd_price", "price", "gte", 1_000_000_000),
    Rule("zero_price", "price", "lte", 0),
]


def _lot(**kw):
    base = dict(id="1", source="encar", lot_url="", year=2020, price=25_000_000,
                mileage=50_000, sell_type="sale", insurance_count=None, make="기아", raw_data={})
    base.update(kw)
    return SimpleNamespace(**base)


def _early(rules, lot):
    return FilterEngine(RuleSet(rules=list(rules))).evaluate(lot, phase="pre", only_fields=LIST)


def _full(rules, lot):
    return FilterEngine(RuleSet(rules=list(rules))).evaluate(lot, phase="pre")


# ── what the list stage may skip ────────────────────────────────────────────

@pytest.mark.parametrize("kw, skipped_by", [
    ({"year": 2010}, "min_year"),
    ({"mileage": 250_000}, "max_mileage"),
    ({"sell_type": "rental"}, "exclude_rental"),
    ({"price": 1_200_000_000}, "absurd_price"),
])
def test_list_known_values_are_skipped_early(kw, skipped_by):
    r = _early(PROD_RULES, _lot(**kw))
    assert r.should_skip and [m.name for m in r.matched_rules] == [skipped_by]


@pytest.mark.parametrize("kw", [
    {"year": 0},              # list sent no year; detail may fill it
    {"price": 0},             # absurd/missing price zeroed by the list builder
    {"mileage": 0},
    {"sell_type": None},
    {"insurance_count": 7},   # needs the record API; never judged early
])
def test_unknown_or_late_fields_are_deferred(kw):
    assert not _early(PROD_RULES, _lot(**kw)).should_skip


@pytest.mark.parametrize("kw", [
    {"year": 2010}, {"mileage": 250_000}, {"sell_type": "rental"}, {"price": 1_200_000_000},
    {"year": 0}, {"insurance_count": 7}, {}, {"price": 0},
])
def test_early_skip_never_drops_what_full_pass_keeps(kw):
    lot = _lot(**kw)
    if _early(PROD_RULES, lot).should_skip:
        assert _full(PROD_RULES, lot).should_skip


def test_deferred_allow_rule_blocks_early_skip():
    rules = PROD_RULES + [Rule("keep_genesis", "make", "eq", "제네시스", action="allow")]
    lot = _lot(year=2010, make="제네시스")
    assert _full(rules, lot).should_skip is False          # allow wins in the full pass
    assert _early(rules, lot).should_skip is False         # so the early pass must not skip


def test_and_group_fires_early_only_when_all_members_are_known():
    grp = [
        Rule("old_and_far", "year", "lt", 2016, group_id="g1"),
        Rule("old_and_far2", "mileage", "gt", 150_000, group_id="g1"),
    ]
    assert _early(grp, _lot(year=2015, mileage=160_000)).should_skip
    mixed = [
        Rule("old_and_crashed", "year", "lt", 2016, group_id="g2"),
        Rule("old_and_crashed2", "insurance_count", "gte", 2, group_id="g2"),
    ]
    assert not _early(mixed, _lot(year=2015, insurance_count=3)).should_skip


# ── enrichment client pool ──────────────────────────────────────────────────

class _FakeClient:
    created: list["_FakeClient"] = []

    def __init__(self, proxy=None, proxy_index=0):
        self.proxy_index = proxy_index
        self.closed = False
        _FakeClient.created.append(self)

    def close(self):
        self.closed = True


@pytest.fixture
def parser(monkeypatch):
    import parsers.encar as enc
    _FakeClient.created = []
    monkeypatch.setattr(enc, "EncarClient", _FakeClient)
    monkeypatch.setattr(enc.Config, "ENCAR_WORKERS", 3)
    p = enc.EncarParser.__new__(enc.EncarParser)   # skip real __init__ (no network)
    p._enrich_clients = None
    p._enrich_client_seq = 0
    import threading
    p._enrich_lock = threading.Lock()
    return p


def test_pool_spreads_clients_over_proxy_sessions(parser):
    clients = [parser._borrow_enrich_client() for _ in range(3)]
    assert sorted(c.proxy_index for c in clients) == [0, 1, 2]


def test_pool_reuses_healthy_and_replaces_failed_clients(parser):
    a = parser._borrow_enrich_client()
    parser._return_enrich_client(a, healthy=True)
    b = parser._borrow_enrich_client()
    parser._return_enrich_client(b, healthy=False)
    assert b.closed
    assert len(_FakeClient.created) == 4          # 3 initial + 1 replacement
    left = [parser._borrow_enrich_client() for _ in range(3)]
    assert b not in left and all(not c.closed for c in left)


def test_pool_under_concurrency_and_close(parser):
    def work(_):
        c = parser._borrow_enrich_client()
        parser._return_enrich_client(c, healthy=True)
        return c

    with ThreadPoolExecutor(max_workers=3) as ex:
        used = list(ex.map(work, range(60)))
    assert len({id(c) for c in used}) <= 3        # only pooled clients were used
    parser._close_enrich_clients()
    assert all(c.closed for c in _FakeClient.created)
    assert parser._enrich_clients is None


def test_client_returned_after_close_is_closed_not_leaked(parser):
    c = parser._borrow_enrich_client()
    parser._close_enrich_clients()
    parser._return_enrich_client(c, healthy=True)
    assert c.closed
