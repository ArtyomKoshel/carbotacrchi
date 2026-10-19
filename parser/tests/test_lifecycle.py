"""
Lot lifecycle rules: which runs delist (hide) the lots they did not see.
Laravel's lots:lifecycle mirrors these rules (tests/Unit/LotLifecycleRunsTest.php).

Usage: python -m pytest parser/tests/test_lifecycle.py -v
"""
from __future__ import annotations

import os
import sys
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest

from lifecycle import coverage_pct, format_db_ts, is_successful_full_run, utc_now_seconds
from parsers.base import AbstractParser


# ── pure rules ──────────────────────────────────────────────────────────────

def test_coverage_is_rounded_like_run_result():
    # Run #12 on prod: 180,111 of 194,436.
    assert coverage_pct(180_111, 194_436) == 92.6
    assert coverage_pct(5, 0) == 0.0
    assert coverage_pct(0, 100) == 0.0


@pytest.mark.parametrize("coverage, kwargs, expected", [
    (92.6, {}, True),
    (85.0, {}, True),                         # threshold is inclusive
    (84.9, {}, False),
    (38.5, {}, False),                        # prod job #6: aborted halfway
    (99.0, {"maker_filter": "BMW"}, False),   # a slice never delists the rest
    (99.0, {"max_pages": 2}, False),
    (99.0, {"max_pages": 0}, True),           # 0 / None = all pages
    (99.0, {"resumed": True}, False),         # start time no longer bounds what it saw
])
def test_successful_full_run(coverage, kwargs, expected):
    assert is_successful_full_run(coverage=coverage, min_coverage=85.0, **kwargs) is expected


def test_run_start_matches_parsed_at_format():
    ts = utc_now_seconds()
    assert ts.microsecond == 0 and ts.tzinfo is None
    assert format_db_ts(datetime(2026, 10, 19, 5, 2, 3)) == "2026-10-19 05:02:03"


# ── parser hook ─────────────────────────────────────────────────────────────

class _FakeRepo:
    def __init__(self):
        self.calls: list[tuple[str, datetime]] = []

    def delist_not_seen_since(self, source, since):
        self.calls.append((source, since))
        return 42


class _Parser(AbstractParser):
    MIN_DELIST_COVERAGE = 85.0

    def get_source_key(self):
        return "encar"

    def get_source_name(self):
        return "Encar"

    def run(self, **kwargs):
        return {}


def _flow(coverage, **kwargs):
    """What EncarParser.run does at the end of a run."""
    repo = _FakeRepo()
    p = _Parser(repo)
    start = datetime(2026, 10, 18, 16, 41, 12)
    full = p.is_full_run(coverage, **kwargs)
    stale = p.delist_unseen(start) if full else 0
    return full, stale, repo.calls


def test_full_run_delists_lots_older_than_run_start():
    full, stale, calls = _flow(92.6)
    assert full is True and stale == 42
    assert calls == [("encar", datetime(2026, 10, 18, 16, 41, 12))]


@pytest.mark.parametrize("coverage, kwargs", [
    (60.0, {}),
    (99.0, {"maker_filter": "BMW", "max_pages": 2}),
])
def test_partial_run_touches_nothing(coverage, kwargs):
    full, stale, calls = _flow(coverage, **kwargs)
    assert full is False and stale == 0 and calls == []
