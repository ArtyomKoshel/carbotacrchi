"""End to end on synthetic traffic: the old parser records a golden master from a fake Encar,
the new collector replays the same cassette, and the golden comparison must be empty.

Same check as test_golden_parity, but runnable anywhere (no prod recording needed).
"""

from __future__ import annotations

import gzip
import json
import sys
from pathlib import Path
from typing import Any

import httpx
import pytest

from collector.adapters.encar.golden import compare, main
from tests.fakes import FakeEncar

PARSER = Path(__file__).resolve().parents[2] / "parser"

pytestmark = pytest.mark.legacy


@pytest.fixture
def golden_dir(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    if not PARSER.exists():
        pytest.skip("old parser removed")
    sys.path.insert(0, str(PARSER))
    try:
        from tools import cassette as old_cassette  # type: ignore[import-not-found]
        from tools import golden as old_golden
    finally:
        sys.path.remove(str(PARSER))

    fake = FakeEncar()
    cassette = old_cassette.Cassette()

    def fake_send(self: httpx.Client, request: httpx.Request, **_: Any) -> httpx.Response:
        response = fake(request)
        response.request = request
        response.read()
        cassette.add(request, response)
        return response

    monkeypatch.setattr(httpx.Client, "send", fake_send)
    data = old_golden.run_sample(per_model=2)
    monkeypatch.undo()

    data["per_model"] = 2
    cassette.save(tmp_path / "encar_cassette.jsonl.gz")
    (tmp_path / "encar_golden.json").write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
    return tmp_path


def test_new_collector_reproduces_the_old_parser(golden_dir: Path) -> None:
    golden = json.loads((golden_dir / "encar_golden.json").read_text(encoding="utf-8"))
    assert len(golden["lots"]) == 5, "2 per model group: 2 + 1 (현대) + 2 (기아)"

    diffs, summary = compare(golden_dir)

    assert summary == {"old_lots": 5, "new_lots": 5, "diffs": 0, "cassette_misses": 0}
    assert diffs == []
    assert main([str(golden_dir)]) == 0


def test_a_mapping_change_shows_up_as_a_diff(golden_dir: Path, capsys: pytest.CaptureFixture[str]) -> None:
    path = golden_dir / "encar_golden.json"
    golden = json.loads(path.read_text(encoding="utf-8"))
    golden["lots"][0]["mileage"] += 1
    golden["lots"].pop()
    path.write_text(json.dumps(golden, ensure_ascii=False), encoding="utf-8")

    diffs, summary = compare(golden_dir)

    assert {d.path for d in diffs} == {"listing.mileage_km", "<lot>"}
    assert summary["diffs"] == 2
    assert main([str(golden_dir)]) == 1
    assert "listing.mileage_km" in capsys.readouterr().out


def test_cassette_is_readable_by_both_sides(golden_dir: Path) -> None:
    with gzip.open(golden_dir / "encar_cassette.jsonl.gz", "rt", encoding="utf-8") as fh:
        keys = [json.loads(line)["key"] for line in fh]
    assert any(k.startswith("GET https://api.encar.com/search/") for k in keys)
    assert all("X-Api-Key" not in k for k in keys)
