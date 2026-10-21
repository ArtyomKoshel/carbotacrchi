"""Characterization test: the current Encar parser still produces the recorded
golden output when fed the recorded Encar responses (refactor stage 0).

The cassette and golden file are recorded on Railway with
`python -m tools.golden record` and committed under tests/golden/. Until then
the test is skipped.
"""
from __future__ import annotations

import json
from pathlib import Path

import pytest

from tools.cassette import Cassette, replaying
from tools.golden import CaptureRepository, GOLDEN_FORMAT, run_sample

GOLDEN_DIR = Path(__file__).parent / "golden"
CASSETTE = GOLDEN_DIR / "encar_cassette.jsonl.gz"
GOLDEN = GOLDEN_DIR / "encar_golden.json"


def test_capture_repository_snapshot_is_sorted_and_plain():
    from models import CarLot

    repo = CaptureRepository()
    for i in ("b", "a"):
        repo.upsert_batch([CarLot(id=i, source="encar", make="M", model="X", year=2020, price=1, mileage=1, lot_url="u")])
    snap = repo.snapshot()
    assert snap["format"] == GOLDEN_FORMAT
    assert [l["id"] for l in snap["lots"]] == ["a", "b"]
    json.dumps(snap)  # fully serializable


@pytest.mark.skipif(not (CASSETTE.exists() and GOLDEN.exists()), reason="golden master not recorded yet")
def test_parser_matches_golden_master():
    golden = json.loads(GOLDEN.read_text(encoding="utf-8"))
    with replaying(Cassette.load(CASSETTE)):
        current = run_sample(golden["per_model"])
    assert current["lots"] == golden["lots"]
    assert current["inspections"] == golden["inspections"]
