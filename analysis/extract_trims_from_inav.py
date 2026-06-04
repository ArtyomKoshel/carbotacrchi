"""
Extract all possible trims (badge_detail) from Encar iNav tree.

Usage:
    python extract_trims_from_inav.py \
        --input ../analysis/encar_inav_tree.json \
        --output ../analysis/trims_by_model.json

Output format:
{
  "Hyundai": {
    "그랜저": {
      "model_group_kr": "그랜저",
      "trims": ["르블랑", "익스클루시브", "프레스티지", ...]
    },
    ...
  },
  ...
}
"""

import argparse
import json
from collections import defaultdict
from pathlib import Path


def extract_trims(input_path: str, output_path: str, min_count: int = 0) -> None:
    with open(input_path, encoding="utf-8") as f:
        data = json.load(f)

    taxonomy = data.get("taxonomy", data) if isinstance(data, dict) else data

    # make_en -> model_group_kr -> set of trims
    tree: dict[str, dict[str, set]] = defaultdict(lambda: defaultdict(set))

    for row in taxonomy:
        trim = (row.get("badge_detail_kr") or "").strip()
        if not trim:
            continue

        count = row.get("count", 0) or 0
        if count < min_count:
            continue

        make = row.get("make_en", "").strip()
        model_group = row.get("model_group_kr", "").strip()

        if not make or not model_group:
            continue

        tree[make][model_group].add(trim)

    # Build sorted output
    result: dict = {}
    for make in sorted(tree):
        result[make] = {}
        for mg in sorted(tree[make]):
            result[make][mg] = sorted(tree[make][mg])

    Path(output_path).write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    # Stats
    total_makes = len(result)
    total_groups = sum(len(v) for v in result.values())
    total_trims = sum(len(t) for make in result.values() for t in make.values())
    print(f"Done: {total_makes} makes, {total_groups} model groups, {total_trims} unique trims")
    print(f"Output: {output_path}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Extract trims from encar_inav_tree.json")
    parser.add_argument(
        "--input",
        default="encar_inav_tree.json",
        help="Path to encar_inav_tree.json",
    )
    parser.add_argument(
        "--output",
        default="trims_by_model.json",
        help="Output JSON file path",
    )
    parser.add_argument(
        "--min-count",
        type=int,
        default=0,
        help="Skip trim entries where lot count < N (0 = include all)",
    )
    args = parser.parse_args()

    extract_trims(args.input, args.output, args.min_count)


if __name__ == "__main__":
    main()
