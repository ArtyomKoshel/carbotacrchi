"""Golden-master parity: the new collector vs the old parser on the same recorded Encar traffic.

    python -m collector.adapters.encar.golden parser/tests/golden

The golden directory (parser/tools/golden.py record) holds encar_cassette.jsonl.gz
(the responses) and encar_golden.json (every CarLot / InspectionRecord the old
parser built from them). The new pipeline replays the cassette; each old lot is
turned into the record the new code must produce (same draft → to_carlot) and
the two are compared field by field.
"""

from __future__ import annotations

import asyncio
import dataclasses
import json
import sys
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from collector.adapters.encar import mapping as m
from collector.adapters.encar.api import HEADERS, EncarApi
from collector.adapters.encar.pipeline import EncarPipeline
from collector.adapters.encar.sample import run_sample
from collector.domain.carlot import CarLotV1
from collector.http import Fetcher, ProxyPool
from collector.ports import MemoryHistoryStore, MemorySink
from collector.selection import RuleEngine
from collector.testing.cassette import Cassette, replaying

NOW = datetime(2026, 1, 1, tzinfo=UTC)
# Differ by design between two runs; not part of the mapping.
VOLATILE = {("observed_at",), ("run_id",), ("history", "observed_at")}


@dataclass(frozen=True)
class Diff:
    lot_id: str
    path: str
    old: Any
    new: Any


def expected_from_golden(lot: dict[str, Any], inspection: dict[str, Any] | None) -> CarLotV1:
    draft_fields = {f.name for f in dataclasses.fields(m.EncarDraft)}
    draft = m.EncarDraft(**{k: v for k, v in lot.items() if k in draft_fields})
    insp = None
    if inspection is not None:
        insp_fields = {f.name for f in dataclasses.fields(m.InspectionDraft)}
        insp = m.InspectionDraft(**{k: v for k, v in inspection.items() if k in insp_fields})
    return m.to_carlot(draft, insp, observed_at=NOW)


def _flatten(data: Any, prefix: tuple[str, ...] = ()) -> dict[tuple[str, ...], Any]:
    if isinstance(data, dict):
        out: dict[tuple[str, ...], Any] = {}
        for k, v in data.items():
            out |= _flatten(v, (*prefix, str(k)))
        return out
    return {prefix: data}


def diff(expected: CarLotV1, actual: CarLotV1) -> list[Diff]:
    a = _flatten(json.loads(expected.model_dump_json()))
    b = _flatten(json.loads(actual.model_dump_json()))
    return [
        Diff(expected.source_id, ".".join(path), a.get(path), b.get(path))
        for path in sorted(set(a) | set(b))
        if path not in VOLATILE and a.get(path) != b.get(path)
    ]


async def replay(golden_dir: Path) -> tuple[dict[str, Any], MemorySink, Cassette]:
    golden = json.loads((golden_dir / "encar_golden.json").read_text(encoding="utf-8"))
    cassette = Cassette.load(golden_dir / "encar_cassette.jsonl.gz")
    sink = MemorySink()
    # A (never used) proxy puts the fetcher in proxy mode: a recorded 404 is "missing", as on prod.
    fetcher = Fetcher(ProxyPool(["http://replay-session-x:replay@localhost:1"]), headers=HEADERS, retries=0)
    pipeline = EncarPipeline(EncarApi(fetcher), RuleEngine([]), MemoryHistoryStore(), sink, clock=lambda: NOW)
    with replaying(cassette):
        await run_sample(pipeline, per_model=int(golden["per_model"]))
    await fetcher.aclose()
    return golden, sink, cassette


def compare(golden_dir: Path) -> tuple[list[Diff], dict[str, int]]:
    golden, sink, cassette = asyncio.run(replay(golden_dir))
    new = sink.by_id()
    inspections = {i["lot_id"]: i for i in golden["inspections"]}
    diffs: list[Diff] = []
    old_ids = {lot["id"] for lot in golden["lots"]}
    for lot in golden["lots"]:
        if lot["id"] not in new:
            diffs.append(Diff(lot["id"], "<lot>", "present", "missing"))
            continue
        diffs.extend(diff(expected_from_golden(lot, inspections.get(lot["id"])), new[lot["id"]]))
    diffs.extend(Diff(i, "<lot>", "missing", "present") for i in sorted(set(new) - old_ids))
    summary = {
        "old_lots": len(old_ids),
        "new_lots": len(new),
        "diffs": len(diffs),
        "cassette_misses": len(cassette.misses),
    }
    return diffs, summary


def main(argv: list[str] | None = None) -> int:
    args = argv if argv is not None else sys.argv[1:]
    diffs, summary = compare(Path(args[0] if args else "parser/tests/golden"))
    print(json.dumps(summary))
    by_path: dict[str, int] = {}
    for d in diffs:
        by_path[d.path] = by_path.get(d.path, 0) + 1
    for path, count in sorted(by_path.items(), key=lambda kv: -kv[1]):
        example = next(d for d in diffs if d.path == path)
        print(f"{count:5d}  {path}   e.g. {example.lot_id}: {example.old!r} -> {example.new!r}")
    return 0 if not diffs else 1


if __name__ == "__main__":
    raise SystemExit(main())
