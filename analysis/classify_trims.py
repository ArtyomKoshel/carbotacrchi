cat > /var/www/analysis/classify_trims.py << 'PYEOF'
"""
Classify all trims from encar_inav_tree.json and generate catalog_model_trims seed data.

Classification rules (in priority order):
  1. source=badge_detail                          → trim       (confidence 1.00)
  2. trim ends with 팩|패키지                      → package    (confidence 1.00)
  3. trim contains 수출형|렌터카|장애인용|택시형|모범형|개인형  → usage_type (confidence 1.00)
  4. trim equals (세부등급 없음)                    → no_trim    → skip
  5. trim matches engine-only noise patterns       → noise      → skip
  6. manual review conditions                      → manual_review
  7. default                                       → trim       (confidence 0.85)

Outputs:
  - catalog_model_trims_seed.json   → ready for DB insert
  - trim_review.json                → needs human review
  - classify_stats.json             → summary stats

Usage:
  python classify_trims.py \\
    --input  /var/www/analysis/encar_inav_tree.json \\
    --output /var/www/analysis
"""

from __future__ import annotations

import argparse
import json
import re
from collections import defaultdict
from pathlib import Path

# ── Engine/spec noise patterns to DROP entirely ────────────────────────────────
ENGINE_EXACT = {
    "v6", "v8", "v12", "dohc", "sohc", "cvvt", "gdi", "mpi", "crdi", "t-gdi",
    "lpi", "lpg", "e-vgt", "tci", "vtec", "e-vtec", "diesel", "gasoline",
    "2wd", "4wd", "fwd", "rwd", "awd",
}

ENGINE_REGEX = re.compile(
    r"""^(
        \d+\.\d+(\s+v\d+)?          # 2.0  2.4  3.5  2.0 V6
        | hg\d{3}                   # HG300
        | q\d{3}                    # Q240 Q270
        | l\d{3}                    # L330
        | s\d{2}                    # S30
        | lpg\s+r?\d+\.\d+          # LPG 2.7  LPG R2.7
        | lpi\s+\d+\.\d+            # LPI 2.7
        | lpi\s+q\d+                # LPI Q270
        | lpg\s+hg\d+               # LPG HG300
        )\s*$""",
    re.IGNORECASE | re.VERBOSE,
)

# ── Prefixes to strip from badge_kr when badge_detail is absent ───────────────
BADGE_PREFIX_RE = re.compile(
    r"""^(
        (?:lpg\s+|lpi\s+)?hg\d{3,4}    # HG240  LPG HG300
        | lpg\s+r?\d+\.\d+             # LPG 2.7  LPG R2.7
        | lpi\s+q\d+                   # LPI Q270
        | q\d{3}                       # Q270
        | l\d{3}                       # L330
        | s\d{2,3}                     # S30
        | \d+\.\d+(?:\s+(?:gdi|lpi|lpg|lpi|diesel|mpi|crdi|t-gdi|cvvt|v\d+))?
        | (?:가솔린|디젤|lpg|lpi|하이브리드|전기)\s+\d+\.\d+
    )\s+""",
    re.IGNORECASE | re.VERBOSE,
)

# ── Suffix noise to strip (rental/taxi markers) ───────────────────────────────
SUFFIX_NOISE_RE = re.compile(
    r"""\s*[\(\[](렌터카용?|택시형|장애인용|영업용|수출형)[)\]]""",
    re.IGNORECASE,
)

# ── Classification patterns ────────────────────────────────────────────────────
PACKAGE_RE    = re.compile(r"(팩|패키지)$", re.IGNORECASE)
USAGE_RE      = re.compile(r"(수출형|렌터카|장애인용|택시형|모범형|개인형|영업용)", re.IGNORECASE)
NO_TRIM_RE    = re.compile(r"^\(세부등급 없음\)$")

# Digits mixed with Korean / English flags for manual review
MIXED_DIGIT_RE = re.compile(r"\d+.*[가-힣]|[가-힣].*\d+")
MIXED_ENG_RE   = re.compile(r"[a-zA-Z]{3,}")  # 3+ consecutive Latin chars → suspicious


def strip_badge_prefix(badge_kr: str) -> str:
    """Strip engine/displacement prefix from badge_kr to isolate trim part."""
    cleaned = SUFFIX_NOISE_RE.sub("", badge_kr).strip()
    cleaned = BADGE_PREFIX_RE.sub("", cleaned).strip()
    return cleaned


def classify(trim_candidate: str, source: str, count: int) -> str:
    """Return classification string."""
    t = trim_candidate.strip()

    # 4. no_trim
    if NO_TRIM_RE.match(t):
        return "no_trim"

    # 1. badge_detail → always trim (Encar's own taxonomy)
    if source == "badge_detail":
        return "trim"

    # 5. engine noise → drop
    if ENGINE_REGEX.match(t) or t.lower() in ENGINE_EXACT:
        return "noise"

    # 2. package
    if PACKAGE_RE.search(t):
        return "package"

    # 3. usage type
    if USAGE_RE.search(t):
        return "usage_type"

    # "/" = split usage type (휠체어 리프트/슬로프, 캠핑카/이동사무차)
    if "/" in t:
        return "usage_type"

    # 6. manual review — only truly ambiguous (very long, unrecognised pattern)
    if len(t) > 35:
        return "manual_review"

    # 7. default → trim
    return "trim"


def process(input_path: str, output_dir: str) -> None:
    with open(input_path, encoding="utf-8") as f:
        data = json.load(f)

    taxonomy = data.get("taxonomy", data) if isinstance(data, dict) else data

    # make_en → make_kr mapping from the tree itself
    make_kr_map: dict[str, str] = {}
    for row in taxonomy:
        en = (row.get("make_en") or "").strip()
        kr = (row.get("make_kr") or "").strip()
        if en and kr:
            make_kr_map[en] = kr

    # Dedup key → best record
    seed_records: dict[str, dict] = {}
    review_records: dict[str, dict] = {}

    stats: dict[str, int] = defaultdict(int)

    for row in taxonomy:
        count = int(row.get("count") or 0)

        make_en    = (row.get("make_en") or "").strip()
        make_kr    = (row.get("make_kr") or make_kr_map.get(make_en, "")).strip()
        model_group_kr = (row.get("model_group_kr") or "").strip()
        if not make_kr or not model_group_kr:
            continue

        badge_group = (row.get("badge_group_kr") or "").strip() or None
        badge       = (row.get("badge_kr") or "").strip() or None
        badge_detail = (row.get("badge_detail_kr") or "").strip() or None

        # Determine candidate trim + match keys
        if badge_detail:
            candidate = badge_detail
            source    = "badge_detail"
            badge_exact       = badge   # badge is the parent
            badge_group_exact = None
        elif badge:
            candidate = strip_badge_prefix(badge)
            source    = "badge"
            badge_exact       = badge
            badge_group_exact = None
        elif badge_group:
            candidate = badge_group
            source    = "badge_group"
            badge_exact       = None
            badge_group_exact = badge_group
        else:
            continue

        if not candidate:
            stats["empty_candidate"] += 1
            continue

        classification = classify(candidate, source, count)
        stats[classification] += 1

        if classification in ("no_trim", "noise"):
            continue

        # Build record
        record: dict = {
            "source":             "encar",
            "make_kr":            make_kr,
            "make_en":            make_en,
            "model_group_kr":     model_group_kr,
            "badge_exact":        badge_exact,
            "badge_group_exact":  badge_group_exact,
            "trim_kr":            candidate if classification == "trim" else None,
            "unknown_trim_part":  candidate if classification in ("package", "usage_type") else None,
            "candidate":          candidate,   # always kept for review/debug
            "classification":     classification,
            "origin":             "inav",
            "confidence":         1.00 if source == "badge_detail" else 0.85,
            "count":              count,
        }

        dedup_key = (
            f"{make_kr}|{model_group_kr}|"
            f"{badge_exact or ''}|{badge_group_exact or ''}|"
            f"{candidate}"
        )

        if classification == "manual_review":
            if dedup_key not in review_records or count > review_records[dedup_key]["count"]:
                record["raw_badge"] = badge
                record["raw_badge_group"] = badge_group
                review_records[dedup_key] = record
        else:
            if dedup_key not in seed_records or count > seed_records[dedup_key]["count"]:
                seed_records[dedup_key] = record

    # Write outputs
    out = Path(output_dir)
    seed_list = sorted(
        seed_records.values(),
        key=lambda r: (r["make_en"], r["model_group_kr"], r["classification"], -(r["count"] or 0)),
    )
    review_list = sorted(
        review_records.values(),
        key=lambda r: (r["make_en"], r["model_group_kr"], -(r["count"] or 0)),
    )

    (out / "catalog_model_trims_seed.json").write_text(
        json.dumps(seed_list, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (out / "trim_review.json").write_text(
        json.dumps(review_list, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (out / "classify_stats.json").write_text(
        json.dumps(dict(stats), ensure_ascii=False, indent=2), encoding="utf-8"
    )

    total = sum(stats.values())
    print("=" * 55)
    print(f"  Total rows processed : {len(taxonomy):,}")
    print(f"  Total classified     : {total:,}")
    print()
    for cls in ("trim", "package", "usage_type", "no_trim", "noise", "manual_review", "empty_candidate"):
        n = stats.get(cls, 0)
        pct = n / total * 100 if total else 0
        print(f"  {cls:<20} {n:5,}  ({pct:.1f}%)")
    print()
    print(f"  Seed records (ready) : {len(seed_list):,}")
    print(f"  Review records       : {len(review_list):,}")
    print()
    print(f"  catalog_model_trims_seed.json : {out / 'catalog_model_trims_seed.json'}")
    print(f"  trim_review.json              : {out / 'trim_review.json'}")
    print(f"  classify_stats.json           : {out / 'classify_stats.json'}")


def main() -> None:
    ap = argparse.ArgumentParser(description="Classify Encar iNav trims")
    ap.add_argument("--input",  default="encar_inav_tree.json")
    ap.add_argument("--output", default=".")
    args = ap.parse_args()
    process(args.input, args.output)


if __name__ == "__main__":
    main()
PYEOF