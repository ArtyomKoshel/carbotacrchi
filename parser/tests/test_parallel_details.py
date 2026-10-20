"""
Parallel detail batches (EncarParser._enrich_batch / _fetch_detail_chunk).

Usage: python -m pytest parser/tests/test_parallel_details.py -v
"""
from __future__ import annotations

import os
import sys
import threading
import time
from types import SimpleNamespace

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest

import parsers.encar as enc


class _FakeClient:
    """batch_details answers per id; ids in FAIL_BATCH make the whole batch fail,
    ids in FAIL_SINGLE also fail the per-lot fallback."""
    FAIL_BATCH: set[str] = set()
    FAIL_SINGLE: set[str] = set()
    created: list["_FakeClient"] = []

    def __init__(self, proxy=None, proxy_index=0):
        self.proxy_bytes = 0
        self.closed = False
        self.rotations = 0
        _FakeClient.created.append(self)

    def batch_details(self, ids):
        time.sleep(0.01 * (len(ids) % 3))  # shuffle completion order a bit
        if any(i in self.FAIL_BATCH for i in ids):
            raise RuntimeError("batch failed")
        self.proxy_bytes += 100 * len(ids)
        return [{"vehicleId": int(i), "manage": {}} for i in ids]

    def detail(self, vid):
        if vid in self.FAIL_SINGLE:
            raise RuntimeError("single failed")
        self.proxy_bytes += 100
        return {"vehicleId": int(vid), "manage": {}}

    def rotate_proxy(self):
        self.rotations += 1

    def close(self):
        self.closed = True


def _parser(monkeypatch, detail_workers):
    _FakeClient.created = []
    _FakeClient.FAIL_BATCH = set()
    _FakeClient.FAIL_SINGLE = set()
    monkeypatch.setattr(enc, "EncarClient", _FakeClient)
    monkeypatch.setattr(enc.Config, "ENCAR_WORKERS", 15)
    monkeypatch.setattr(enc.Config, "ENCAR_DETAIL_WORKERS", detail_workers)
    applied: list[tuple[str, int]] = []
    lock = threading.Lock()

    def fake_enrich(lot, detail, norm):
        with lock:
            applied.append((lot.id, detail["vehicleId"]))
            lot.raw_data["seen_detail"] = detail["vehicleId"]
    monkeypatch.setattr(enc, "_enrich_from_detail", fake_enrich)

    p = enc.EncarParser.__new__(enc.EncarParser)
    p._norm = None
    p._enrich_clients = None
    p._enrich_client_seq = 0
    p._enrich_lock = threading.Lock()
    p._detail_proxy_bytes = 0
    return p, applied


def _lots(n):
    return [SimpleNamespace(id=str(1000 + i), raw_data={}) for i in range(n)]


@pytest.mark.parametrize("n", [1, 20, 37, 100])
def test_parallel_equals_sequential(monkeypatch, n):
    p1, seq = _parser(monkeypatch, 1)
    lots1 = _lots(n)
    p1._enrich_batch(lots1, {"errors": 0})

    p5, par = _parser(monkeypatch, 5)
    lots5 = _lots(n)
    p5._enrich_batch(lots5, {"errors": 0})

    assert seq == par                                  # same lots, same order
    assert [l.raw_data for l in lots1] == [l.raw_data for l in lots5]
    assert p5._detail_proxy_bytes == 100 * n           # metric still counts details


def test_failed_batch_falls_back_to_single_fetch(monkeypatch):
    p, applied = _parser(monkeypatch, 5)
    monkeypatch.setattr(enc._time, "sleep", lambda s: None)   # skip the 0.5 s fallback spacing
    _FakeClient.FAIL_BATCH = {"1025"}                   # 2nd chunk (1020..1039) fails as a batch
    _FakeClient.FAIL_SINGLE = {"1025"}                  # ...and that one lot fails singly too
    stats = {"errors": 0}
    lots = _lots(60)
    p._enrich_batch(lots, stats)

    got = {lid for lid, _ in applied}
    assert got == {l.id for l in lots} - {"1025"}       # everything else still enriched
    assert stats["errors"] == 1
    assert [lid for lid, _ in applied][:20] == [str(1000 + i) for i in range(20)]  # chunk order kept
    assert any(c.closed for c in _FakeClient.created)   # the failing client was replaced


def test_no_lots_no_calls(monkeypatch):
    p, applied = _parser(monkeypatch, 5)
    p._enrich_batch([], {"errors": 0})
    assert applied == [] and _FakeClient.created == []
