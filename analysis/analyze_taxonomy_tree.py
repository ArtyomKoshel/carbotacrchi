"""
Analyze encar_taxonomy_tree.json to extract:
1. All unique trim names (badge_detail level)
2. All unique badge patterns with parsed components
3. Coverage stats
"""

import json
import re
from collections import Counter
from pathlib import Path

HERE = Path(__file__).parent
TREE_FILE = HERE / "encar_taxonomy_tree.json"

# Tokens that indicate drive type within a badge string
_DRIVE_TOKENS = {"xDrive": "awd", "4MATIC": "awd", "AWD": "awd", "4WD": "4wd",
                 "2WD": "2wd", "sDrive": "rwd", "FWD": "fwd", "RWD": "rwd",
                 "4TRONIC": "4wd", "콰트로": "awd", "quattro": "awd"}

# Known package suffixes in badge strings
_PACKAGE_HINTS = [
    "M 스포츠 플러스", "M 퍼포먼스", "M 스포츠 X", "M 스포츠 프로", "M 스포츠",
    "디자인 퓨어 엑셀런스", "인디비주얼",
    "xLine", "X Line", "GT Line", "N Line", "S line", "AMG Line",
    "럭셔리 플러스", "어드밴티지", "럭셔리",
    "조이", "스포츠",
]

# Variant regex: letter(s)+digits or digits+letters
_VARIANT_RE = re.compile(r'^(?:[A-Z]{1,4}\d{2,4}[a-zA-Z]{0,2}|\d{2,4}[A-Za-z]{1,3})$')

# Engine/fuel prefixes commonly seen in Korean badges
_FUEL_PREFIX_RE = re.compile(r'^(가솔린|디젤|하이브리드|LPG|HEV|바이퓨얼|전기)\s*')
_ENGINE_VOL_RE = re.compile(r'^\d{1,2}\.\d+\s*|^\d{1,2}\.\d+[TtDdLlEe]?\s*')


def parse_badge(badge: str):
    """Attempt to extract components from a badge string."""
    s = badge.strip()
    fuel = None
    drive = None
    variant = None
    package = None
    remaining_tokens = []

    # Strip fuel prefix
    m = _FUEL_PREFIX_RE.match(s)
    if m:
        fuel = m.group(1)
        s = s[m.end():].strip()

    tokens = s.split()
    consumed = set()

    # Find drive token
    for i, tok in enumerate(tokens):
        if tok in _DRIVE_TOKENS:
            drive = _DRIVE_TOKENS[tok]
            consumed.add(i)
            break

    # Find package (longest match first)
    for pkg in sorted(_PACKAGE_HINTS, key=len, reverse=True):
        if s.endswith(pkg):
            package = pkg
            # remove package from remaining
            s_trimmed = s[: s.rfind(pkg)].strip()
            tokens = s_trimmed.split()
            consumed = {i for i, t in enumerate(tokens) if t in _DRIVE_TOKENS}
            break

    # Find variant
    for i, tok in enumerate(tokens):
        if i in consumed:
            continue
        tok_upper = tok.upper()
        if _VARIANT_RE.match(tok_upper) and tok_upper not in {
            "GDI", "TDI", "MPI", "AWD", "FWD", "RWD", "EV", "HEV", "BEV",
        }:
            variant = tok
            consumed.add(i)
            break

    remaining_tokens = [t for i, t in enumerate(tokens) if i not in consumed]
    return {
        "fuel": fuel,
        "drive": drive,
        "variant": variant,
        "package": package,
        "remaining": " ".join(remaining_tokens),
    }


def main():
    with open(TREE_FILE, encoding="utf-8") as f:
        tree = json.load(f)

    make_count = len(tree)
    model_count = 0
    badge_count = 0
    trim_counter = Counter()
    badge_counter = Counter()
    makes = sorted(tree.keys())

    # Collect all badges and trims
    all_badges = []
    for make, models in tree.items():
        for model, badges in models.items():
            model_count += 1
            for badge, trims in badges.items():
                badge_count += 1
                badge_counter[badge] += 1
                all_badges.append((make, model, badge, trims))
                for trim in trims:
                    if trim and trim != "(세부등급 없음)":
                        trim_counter[trim] += 1

    print(f"=== ENCAR TAXONOMY TREE STATS ===")
    print(f"Makes:  {make_count}")
    print(f"Models: {model_count}")
    print(f"Badges: {badge_count}")
    print(f"Unique trims (badge_detail): {len(trim_counter)}")
    print()

    print("=== TOP 40 TRIM NAMES (badge_detail) ===")
    for trim, cnt in trim_counter.most_common(40):
        print(f"  {cnt:4d}x  {trim}")
    print()

    print("=== MAKES IN TREE ===")
    for m in makes:
        print(f"  {m}: {len(tree[m])} models")
    print()

    # Find badges with no variant extracted (potential variant coverage gaps)
    no_variant = []
    for make, model, badge, trims in all_badges:
        parsed = parse_badge(badge)
        if parsed["variant"] is None and parsed["package"] is None:
            no_variant.append((make, model, badge, parsed["remaining"]))

    print(f"=== BADGES WITH NO VARIANT/PACKAGE EXTRACTED ({len(no_variant)}) ===")
    # Group by remaining token pattern
    remaining_counter = Counter(x[3] for x in no_variant if x[3])
    for rem, cnt in remaining_counter.most_common(30):
        print(f"  {cnt:4d}x  '{rem}'")
    print()

    # Show parsed component stats
    drive_found = sum(1 for _, _, badge, _ in all_badges if parse_badge(badge)["drive"])
    variant_found = sum(1 for _, _, badge, _ in all_badges if parse_badge(badge)["variant"])
    package_found = sum(1 for _, _, badge, _ in all_badges if parse_badge(badge)["package"])
    fuel_found = sum(1 for _, _, badge, _ in all_badges if parse_badge(badge)["fuel"])

    print(f"=== COMPONENT EXTRACTION COVERAGE ===")
    print(f"  drive   found in {drive_found}/{badge_count} badges  ({100*drive_found//badge_count}%)")
    print(f"  variant found in {variant_found}/{badge_count} badges  ({100*variant_found//badge_count}%)")
    print(f"  package found in {package_found}/{badge_count} badges  ({100*package_found//badge_count}%)")
    print(f"  fuel    found in {fuel_found}/{badge_count} badges  ({100*fuel_found//badge_count}%)")
    print()

    # Export unique trim names to SQL fragment
    print("=== TRIM NAMES NOT YET IN SEED (TOP 20 unique) ===")
    # These are the most common trims we could add as trim_hints
    existing_hints = {
        "프레스티지", "노블레스", "시그니처", "럭셔리", "모던", "스마트",
        "리미티드", "아방가르드", "그란루쏘", "엘레강스", "캘리그래피",
        "인스퍼레이션", "익스클루시브", "디자인 퓨어 엑셀런스",
        "RE", "LE", "SE", "PE", "SVR", "SVX", "HSE",
    }
    new_trims = [(t, c) for t, c in trim_counter.most_common() if t not in existing_hints]
    for trim, cnt in new_trims[:20]:
        print(f"  {cnt:4d}x  {trim}")


if __name__ == "__main__":
    main()
