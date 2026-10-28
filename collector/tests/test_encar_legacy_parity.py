"""The moved Encar mapping produces the same fields as the old parser on the same input.

Imports parser/parsers/encar side by side (dependency group `legacy`); deleted
together with parser/ in refactor stage 7. The golden master
(parser/tools/golden.py) adds the same check on recorded real traffic.
"""

from __future__ import annotations

import copy
import dataclasses
import sys
from pathlib import Path
from typing import Any

import pytest

from collector.adapters.encar import mapping as m
from tests.test_encar_mapping import load

PARSER_DIR = Path(__file__).resolve().parents[2] / "parser"

pytestmark = pytest.mark.legacy


@pytest.fixture(scope="module")
def legacy() -> Any:
    if not PARSER_DIR.exists():
        pytest.skip("old parser removed")
    sys.path.insert(0, str(PARSER_DIR))
    try:
        from models import InspectionRecord  # type: ignore[import-not-found]
        from parsers import encar as old  # type: ignore[import-not-found]
        from parsers.encar.normalizer import EncarNormalizer  # type: ignore[import-not-found]
    finally:
        sys.path.remove(str(PARSER_DIR))
    return old, EncarNormalizer(), InspectionRecord


def _old(
    legacy: Any, detail: dict[str, Any], record: dict[str, Any] | None, insp: dict[str, Any] | None
) -> Any:
    old, norm, inspection_record = legacy
    lot = old._lot_from_search(load("search_item"), norm)
    old._enrich_from_detail(lot, detail, norm)
    rec = old._enrich_from_record(lot, record) if record is not None else None
    if insp is not None:
        rec = rec or inspection_record(lot_id=lot.id, source="encar")
        old._enrich_from_inspection(lot, insp, rec)
    old._enrich_from_sellingpoint(lot, load("sellingpoint"), norm)
    return lot, rec


def _new(detail: dict[str, Any], record: dict[str, Any] | None, insp: dict[str, Any] | None) -> Any:
    lot = m.from_search(load("search_item"))
    m.apply_detail(lot, detail)
    rec = m.apply_record(lot, record) if record is not None else None
    if insp is not None:
        rec = rec or m.InspectionDraft()
        m.apply_inspection(lot, insp, rec)
    m.apply_sellingpoint(lot, load("sellingpoint"))
    return lot, rec


Case = tuple[dict[str, Any], dict[str, Any] | None, dict[str, Any] | None]

CASES: dict[str, Case] = {
    "full": (load("detail"), load("record"), load("inspection")),
    "no_record": (load("detail"), None, load("inspection")),
    "no_history": (load("detail"), None, None),
    "flags": (load("detail"), load("record") | {"loan": 1, "robberCnt": 1, "floodTotalLossCnt": 1}, None),
    "bare_detail": ({"vehicleId": 40011223}, None, None),
}


@pytest.mark.parametrize("case", CASES, ids=list(CASES))
def test_lot_fields_match_the_old_parser(legacy: Any, case: str) -> None:
    detail, record, insp = copy.deepcopy(CASES[case])
    old_lot, _ = _old(legacy, copy.deepcopy(detail), copy.deepcopy(record), copy.deepcopy(insp))
    new_lot, _ = _new(detail, record, insp)
    if record is None and insp is not None and old_lot.has_accident is False:
        # Departure: the old parser took the inspection's undamaged frame for «no accident».
        assert new_lot.has_accident is None
        old_lot.has_accident = None

    for f in dataclasses.fields(m.EncarDraft):
        assert getattr(new_lot, f.name) == getattr(old_lot, f.name), f.name


@pytest.mark.parametrize("case", ["full", "no_record"])
def test_inspection_fields_match_the_old_parser(legacy: Any, case: str) -> None:
    detail, record, insp = copy.deepcopy(CASES[case])
    _, old_rec = _old(legacy, copy.deepcopy(detail), copy.deepcopy(record), copy.deepcopy(insp))
    _, new_rec = _new(detail, record, insp)

    for f in dataclasses.fields(m.InspectionDraft):
        assert getattr(new_rec, f.name) == getattr(old_rec, f.name), f.name
