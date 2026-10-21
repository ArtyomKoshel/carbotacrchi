"""Golden master: the new collector reproduces what the old parser built from the same recorded traffic.

The recording (parser/tools/golden.py record, on Railway) holds third-party data
and is never committed; the test runs where parser/tests/golden/ is present.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from collector.adapters.encar.golden import compare, diff, expected_from_golden
from tests.test_encar_mapping import full_draft

GOLDEN = Path(__file__).resolve().parents[2] / "parser" / "tests" / "golden"


def test_diff_ignores_volatile_fields_and_reports_real_ones() -> None:
    lot, record = full_draft()
    old = {f: getattr(lot, f) for f in lot.__dataclass_fields__}
    rec = {f: getattr(record, f) for f in record.__dataclass_fields__}
    expected = expected_from_golden(old, rec)
    same = expected.model_copy(update={"run_id": 9})
    assert diff(expected, same) == []
    changed = expected.model_copy(update={"source_id": "x"})
    assert [d.path for d in diff(expected, changed)] == ["source_id"]


@pytest.mark.skipif(not (GOLDEN / "encar_golden.json").exists(), reason="golden recording not present")
def test_new_collector_matches_the_old_parser_on_recorded_traffic() -> None:
    diffs, summary = compare(GOLDEN)
    assert summary["cassette_misses"] == 0, "the new collector made a request the old one did not"
    assert summary["new_lots"] == summary["old_lots"]
    assert diffs == []
