"""
History re-check (EncarParser._history_plan / _enrich_accident_data, ENCAR_HISTORY_EVERY_RUNS).

Usage: python -m pytest parser/tests/test_history_refresh.py -v
"""
from __future__ import annotations

import ast
import datetime as dt
import inspect
import os
import sys
import textwrap
import threading

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest

import parsers.encar as enc
from models import CarLot

CUTOFF = dt.datetime(2026, 10, 20, 12, 0, 0)
OLD = CUTOFF - dt.timedelta(hours=1)
RECENT = CUTOFF + dt.timedelta(hours=1)
COND = ["Inspection", "Record"]


class _Client:
    calls: list[tuple[str, str]] = []
    fail: set[str] = set()

    def __init__(self, *a, **k):
        self.proxy_bytes = 0

    def _hit(self, name, vid):
        _Client.calls.append((name, str(vid)))
        if f"{name}:{vid}" in _Client.fail:
            raise RuntimeError("boom")

    def record(self, vid, plate=None):
        self._hit("record", vid)
        return {"openData": True, "ownerChangeCnt": 2, "accidentCnt": 1, "myAccidentCnt": 1}

    def inspection(self, vid):
        self._hit("inspection", vid)
        return {}

    def sellingpoint(self, vid):
        self._hit("sellingpoint", vid)
        return {}

    def rotate_proxy(self):
        pass

    def close(self):
        pass


class _Repo:
    def __init__(self, state):
        self.state = state
        self.marked: list[str] = []
        self.upserted: list[CarLot] = []
        self.drop: set[str] = set()   # ids a PRE rule drops in upsert_batch

    def history_state(self, ids, fields, raw_keys):
        return None if self.state is None else {i: self.state[i] for i in ids if i in self.state}

    def mark_history_checked(self, ids):
        self.marked += ids

    def upsert_batch(self, lots, stats):
        self.upserted += lots
        return sum(1 for l in lots if l.id not in self.drop)

    def upsert_inspection(self, rec):
        pass

    def apply_post_filters(self, ids, stats):
        return 0


def _row(checked, cond=COND, **fields):
    base = {f: None for f in enc._HISTORY_FIELDS + enc._DRIVE_FIELDS}
    base.update(fields)
    base["history_checked_at"] = checked
    base["raw"] = {"condition": cond, "engine_code": "G4KN", "selling_point": "nice"}
    return base


def _parser(monkeypatch, state, cutoff=CUTOFF):
    _Client.calls = []
    _Client.fail = set()
    monkeypatch.setattr(enc, "EncarClient", _Client)
    monkeypatch.setattr(enc.Config, "ENCAR_WORKERS", 3)
    monkeypatch.setattr(enc, "_enrich_from_inspection", lambda lot, insp, rec: None)
    p = enc.EncarParser.__new__(enc.EncarParser)
    p.repo = _Repo(state)
    p._norm = None
    p._enrich_clients = None
    p._enrich_client_seq = 0
    p._enrich_lock = threading.Lock()
    p._history_cutoff = cutoff
    return p


def _lot(i, cond=COND):
    return CarLot(id=str(i), source="encar", make="", model="", year=2020, price=1, mileage=1,
                  lot_url="", raw_data={"condition": list(cond)})


def _calls(lot_id):
    return sorted(n for n, v in _Client.calls if v == str(lot_id))


def test_no_cutoff_fetches_everything_as_before(monkeypatch):
    p = _parser(monkeypatch, {"1": _row(RECENT)}, cutoff=None)
    p._enrich_accident_data([_lot(1), _lot(2)], {"errors": 0})
    assert _calls(1) == ["inspection", "record", "sellingpoint"]
    assert _calls(2) == ["inspection", "record", "sellingpoint"]


def test_state_unavailable_falls_back_to_full(monkeypatch):
    p = _parser(monkeypatch, None)
    p._enrich_accident_data([_lot(1)], {"errors": 0})
    assert _calls(1) == ["inspection", "record", "sellingpoint"]


def test_plan_per_lot(monkeypatch):
    p = _parser(monkeypatch, {
        "1": _row(RECENT, owners_count=3, insurance_count=5, has_accident=1, first_reg_date=dt.date(2019, 5, 1),
                  drive_type="awd"),
        "2": _row(OLD, drive_type="fwd"),
        "3": _row(RECENT, cond=["Record"]),       # list now also shows Inspection
        # "4" is not in the DB -> new
    })
    lots = [_lot(i) for i in (1, 2, 3, 4)]
    stats = {"errors": 0}
    p._enrich_accident_data(lots, stats)

    assert _calls(1) == []                                    # stored
    assert _calls(2) == ["inspection", "record"]              # recheck: no sellingpoint
    assert _calls(3) == ["inspection", "record"]              # condition changed
    assert _calls(4) == ["inspection", "record", "sellingpoint"]  # new

    stored = lots[0]
    assert (stored.owners_count, stored.insurance_count, stored.has_accident) == (3, 5, True)
    assert stored.first_reg_date == "2019-05-01"
    assert stored.drive_type == "awd"
    assert stored.raw_data["engine_code"] == "G4KN" and stored.raw_data["selling_point"] == "nice"
    assert lots[1].drive_type == "fwd"                       # drive from DB on recheck
    assert lots[1].owners_count == 2                         # fresh from the record call

    assert {l.id for l in p.repo.upserted} == {"1", "2", "3", "4"}  # all still written + filtered
    assert sorted(p.repo.marked) == ["2", "3", "4"]          # stored lot keeps its old stamp
    assert stats["history_stored"] == 1 and stats["history_fetched"] == 3


def test_lot_without_stored_history_is_rechecked(monkeypatch):
    # e.g. dropped by max_insurance_count last run: lot_inspections got a row, `lots` no history
    p = _parser(monkeypatch, {"1": _row(RECENT)})
    p._enrich_accident_data([_lot(1)], {"errors": 0})
    assert _calls(1) == ["inspection", "record"]


def test_lot_dropped_by_filter_is_not_marked(monkeypatch):
    p = _parser(monkeypatch, {"1": _row(OLD), "2": _row(OLD)})
    p.repo.drop = {"1"}
    p._enrich_accident_data([_lot(1), _lot(2)], {"errors": 0})
    assert p.repo.marked == ["2"]


def test_stored_values_never_override_fresh_ones(monkeypatch):
    p = _parser(monkeypatch, {"1": _row(RECENT, vin="DBVIN", owners_count=4, insurance_count=0)})
    lot = _lot(1)
    lot.vin = "DETAILVIN"
    p._enrich_accident_data([lot], {"errors": 0})
    assert lot.vin == "DETAILVIN" and lot.owners_count == 4


def test_failed_call_is_not_marked(monkeypatch):
    p = _parser(monkeypatch, {"1": _row(OLD), "2": _row(OLD)})
    _Client.fail = {"inspection:1"}
    p._enrich_accident_data([_lot(1), _lot(2)], {"errors": 0})
    assert p.repo.marked == ["2"]                            # lot 1 is retried next run


def _assigned(func) -> tuple[set[str], set[str]]:
    """lot.<attr> = ... and lot.raw_data["key"] = ... targets inside func."""
    tree = ast.parse(textwrap.dedent(inspect.getsource(func)))
    attrs, keys = set(), set()
    for node in ast.walk(tree):
        for t in getattr(node, "targets", []) or ([node.target] if isinstance(node, ast.AugAssign) else []):
            if isinstance(t, ast.Attribute) and isinstance(t.value, ast.Name) and t.value.id == "lot":
                attrs.add(t.attr)
            if (isinstance(t, ast.Subscript) and isinstance(t.value, ast.Attribute)
                    and t.value.attr == "raw_data" and isinstance(t.slice, ast.Constant)):
                keys.add(t.slice.value)
    return attrs, keys


@pytest.mark.parametrize("func, fields, keys", [
    (enc._enrich_from_record, enc._HISTORY_FIELDS, enc._HISTORY_RAW_KEYS),
    (enc._enrich_from_inspection, enc._HISTORY_FIELDS, enc._HISTORY_RAW_KEYS),
    (enc._enrich_from_sellingpoint, enc._DRIVE_FIELDS, enc._DRIVE_RAW_KEYS),
])
def test_stored_lists_cover_what_the_calls_set(func, fields, keys):
    # A field the history calls set but _apply_stored does not restore would be
    # NULL on "stored" lots and could flip filters (e.g. max_insurance_count).
    attrs, raw = _assigned(func)
    assert attrs <= set(fields), attrs - set(fields)
    assert raw <= set(keys), raw - set(keys)
