"""JSON Schema export of the contracts the core consumes (ADR-0002).

python -m collector.contracts write   # regenerate contracts/*.schema.json
python -m collector.contracts check   # fail if the committed schema is stale
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

from collector.domain.carlot import CONTRACT, CarLotV1

SCHEMA_ID = "https://carbot.local/contracts/carlot.v1.schema.json"
REPO_ROOT = Path(__file__).resolve().parents[3]
CARLOT_SCHEMA_PATH = REPO_ROOT / "contracts" / "carlot.v1.schema.json"


def carlot_schema() -> dict[str, Any]:
    schema = CarLotV1.model_json_schema(mode="serialization")
    return {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "$id": SCHEMA_ID,
        "title": f"CarLot ({CONTRACT})",
        **schema,
    }


def render(schema: dict[str, Any]) -> str:
    return json.dumps(schema, ensure_ascii=False, indent=2, sort_keys=True) + "\n"


def main(argv: list[str] | None = None) -> int:
    cmd = (argv if argv is not None else sys.argv[1:] or ["check"])[0]
    text = render(carlot_schema())
    if cmd == "write":
        CARLOT_SCHEMA_PATH.parent.mkdir(parents=True, exist_ok=True)
        CARLOT_SCHEMA_PATH.write_text(text, encoding="utf-8")
        print(f"wrote {CARLOT_SCHEMA_PATH}")
        return 0
    if cmd == "check":
        current = CARLOT_SCHEMA_PATH.read_text(encoding="utf-8") if CARLOT_SCHEMA_PATH.exists() else ""
        if current != text:
            print(f"{CARLOT_SCHEMA_PATH} is stale: run python -m collector.contracts write", file=sys.stderr)
            return 1
        print("contracts up to date")
        return 0
    print(f"unknown command {cmd!r}; use write or check", file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
