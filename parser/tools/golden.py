"""Golden master of the current Encar parser (refactor stage 0).

    # On Railway (Korean proxy available): record traffic + golden output
    python -m tools.golden record --per-model 10 --out-dir /tmp/golden

    # Anywhere, offline: replay the cassette through the parser
    python -m tools.golden replay --cassette tests/golden/encar_cassette.jsonl.gz \
        --out /tmp/replayed.json

Both modes run EncarParser.run_sample() against CaptureRepository, which keeps
lots in memory and never touches a database. The output lists every CarLot and
InspectionRecord the parser would have written, sorted by id, so the new
collector can be compared field by field (tools/golden_diff.py, stage 3).
"""
from __future__ import annotations

import argparse
import dataclasses
import json
import logging
import sys
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from models import CarLot, InspectionRecord  # noqa: E402
from tools.cassette import Cassette, recording, replaying  # noqa: E402

GOLDEN_FORMAT = "carbot-golden/1"


class CaptureRepository:
    """The slice of LotRepository that run_sample() uses, kept in memory.

    Selection rules are not applied (prefilter = identity, no post filters):
    the golden master pins field mapping; rule evaluation has its own contract
    fixtures (tests/fixtures/parse_filters_contract.json).
    """

    def __init__(self) -> None:
        self.lots: dict[str, CarLot] = {}
        self.inspections: dict[str, InspectionRecord] = {}

    def get_existing_ids(self, source: str) -> set[str]:
        return set()

    def prefilter_list_stage(self, lots: list[CarLot], stats: dict | None = None) -> list[CarLot]:
        return list(lots)

    def upsert_batch(self, lots: list[CarLot], stats: dict | None = None) -> int:
        for lot in lots:
            self.lots[lot.id] = lot
        return len(lots)

    def upsert_inspection(self, record: InspectionRecord) -> None:
        self.inspections[record.lot_id] = record

    def mark_history_checked(self, lot_ids: list[str]) -> None:
        return None

    def apply_post_filters(self, lot_ids: list[str], stats: dict | None = None) -> int:
        return 0

    def history_state(self, *args: Any, **kwargs: Any) -> None:
        return None  # run_sample never sets a history cutoff

    # ── output ───────────────────────────────────────────────────────────
    def snapshot(self) -> dict:
        return {
            "format": GOLDEN_FORMAT,
            "lots": [_plain(self.lots[k]) for k in sorted(self.lots)],
            "inspections": [_plain(self.inspections[k]) for k in sorted(self.inspections)],
        }


def _plain(obj: Any) -> Any:
    if dataclasses.is_dataclass(obj) and not isinstance(obj, type):
        return {f.name: _plain(getattr(obj, f.name)) for f in dataclasses.fields(obj)}
    if isinstance(obj, dict):
        return {str(k): _plain(v) for k, v in sorted(obj.items(), key=lambda kv: str(kv[0]))}
    if isinstance(obj, (list, tuple)):
        return [_plain(v) for v in obj]
    if isinstance(obj, set):
        return sorted(_plain(v) for v in obj)
    if isinstance(obj, (datetime, date)):
        return obj.isoformat()
    return obj


def run_sample(per_model: int) -> dict:
    # Imported late so that a cassette patch is already in place for any
    # client built at import or construction time.
    from config import Config
    from parsers.encar import EncarParser

    Config.ENCAR_WORKERS = 1  # deterministic request order for the cassette
    repo = CaptureRepository()
    parser = EncarParser(repo)  # type: ignore[arg-type]
    stats = parser.run_sample(lots_per_model=per_model)
    out = repo.snapshot()
    out["stats"] = {k: stats.get(k) for k in ("total", "errors", "mode")}
    return out


def write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=1, sort_keys=True) + "\n", encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    rec = sub.add_parser("record", help="call Encar, store cassette and golden output")
    rec.add_argument("--per-model", type=int, default=10)
    rec.add_argument("--out-dir", type=Path, required=True)
    rep = sub.add_parser("replay", help="replay a cassette offline")
    rep.add_argument("--cassette", type=Path, required=True)
    rep.add_argument("--per-model", type=int, default=10)
    rep.add_argument("--out", type=Path, required=True)
    args = ap.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")

    if args.cmd == "record":
        cassette = Cassette()
        with recording(cassette):
            data = run_sample(args.per_model)
        data["recorded_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
        data["per_model"] = args.per_model
        n = cassette.save(args.out_dir / "encar_cassette.jsonl.gz")
        write_json(args.out_dir / "encar_golden.json", data)
        print(f"recorded {n} responses, {len(data['lots'])} lots, {len(data['inspections'])} inspections")
        return 0

    with replaying(Cassette.load(args.cassette)):
        data = run_sample(args.per_model)
    write_json(args.out, data)
    print(f"replayed: {len(data['lots'])} lots, {len(data['inspections'])} inspections")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
