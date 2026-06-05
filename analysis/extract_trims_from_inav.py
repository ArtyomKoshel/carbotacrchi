"""
Extract all possible trims from Encar iNav tree.

Collects trims from all three iNav sources:
  1. badge_detail_kr  — explicit trim level (most reliable)
  2. badge_kr         — trim embedded in badge when badge_detail absent
  3. badge_group_kr   — badge_group as fallback context

Output is grouped by make_en → model_group_kr, with full context for validation.
Each entry is deduplicated by (badge_group_kr, badge_kr, badge_detail_kr).

Usage:
    python extract_trims_from_inav.py \\
        --input /var/www/analysis/encar_inav_tree.json \\
        --output /var/www/analysis/trims_by_model.json

    # Only entries with actual lot count
    python extract_trims_from_inav.py \\
        --input encar_inav_tree.json \\
        --output trims_by_model.json \\
        --min-count 1

Output format:
{
  "Hyundai": {
    "그랜저": [
      {
        "badge_group_kr": "가솔린 2500cc",
        "badge_kr":       "2.5 인스퍼레이션",
        "badge_detail_kr":"인스퍼레이션",
        "trim_kr":        "인스퍼레이션",
        "source":         "badge_detail",
        "count":          47
      },
      {
        "badge_group_kr": "가솔린 2000cc",
        "badge_kr":       "2.0 유니크",
        "badge_detail_kr": null,
        "trim_kr":        "2.0 유니크",
        "source":         "badge",
        "count":          12
      }
    ]
  }
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

    # make_en -> model_group_kr -> list of unique entries (keyed by dedup_key)
    seen: dict[str, dict[str, dict[str, dict]]] = defaultdict(lambda: defaultdict(dict))

    for row in taxonomy:
        count = int(row.get("count") or 0)
        if count < min_count:
            continue

        make = (row.get("make_en") or "").strip()
        model_group = (row.get("model_group_kr") or "").strip()
        if not make or not model_group:
            continue

        badge_group = (row.get("badge_group_kr") or "").strip() or None
        badge = (row.get("badge_kr") or "").strip() or None
        badge_detail = (row.get("badge_detail_kr") or "").strip() or None

        if badge_detail:
            trim_kr = badge_detail
            source = "badge_detail"
        elif badge:
            trim_kr = badge
            source = "badge"
        elif badge_group:
            trim_kr = badge_group
            source = "badge_group"
        else:
            continue

        # Deduplicate by full iNav path
        dedup_key = f"{badge_group}|{badge}|{badge_detail}"

        existing = seen[make][model_group].get(dedup_key)
        if existing is None:
            seen[make][model_group][dedup_key] = {
                "badge_group_kr":  badge_group,
                "badge_kr":        badge,
                "badge_detail_kr": badge_detail,
                "trim_kr":         trim_kr,
                "source":          source,
                "count":           count,
            }
        else:
            # Accumulate count across duplicate paths
            existing["count"] += count

    # Build sorted output — sort entries by source priority, then count desc
    source_order = {"badge_detail": 0, "badge": 1, "badge_group": 2}
    result: dict = {}
    for make in sorted(seen):
        result[make] = {}
        for mg in sorted(seen[make]):
            entries = list(seen[make][mg].values())
            entries.sort(key=lambda e: (source_order[e["source"]], -e["count"]))
            result[make][mg] = entries

    Path(output_path).write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    # Stats
    total_makes = len(result)
    total_groups = sum(len(v) for v in result.values())
    total_entries = sum(len(e) for m in result.values() for e in m.values())
    bd_count = sum(
        1 for m in result.values()
        for entries in m.values()
        for e in entries if e["source"] == "badge_detail"
    )
    badge_count = total_entries - bd_count
    print(f"Done: {total_makes} makes, {total_groups} model groups, {total_entries} entries")
    print(f"  source=badge_detail : {bd_count}")
    print(f"  source=badge/group  : {badge_count}")
    print(f"Output: {output_path}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Extract trims from encar_inav_tree.json")
    parser.add_argument("--input",  default="encar_inav_tree.json")
    parser.add_argument("--output", default="trims_by_model.json")
    parser.add_argument(
        "--min-count", type=int, default=0,
        help="Skip entries where lot count < N (0 = include all)",
    )
    args = parser.parse_args()
    extract_trims(args.input, args.output, args.min_count)


if __name__ == "__main__":
    main()
