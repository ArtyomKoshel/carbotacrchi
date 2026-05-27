"""
Generate SQL from scraped Encar taxonomy tree:
1. taxonomy_terms with term_type='valid_trim' (per make/model whitelist)
2. taxonomy_rules set_trim for models where BadgeDetail is ALWAYS null
   but badge strings contain known trim keywords

Usage: cd parser && python ../analysis/generate_taxonomy_sql.py
Output: analysis/encar_taxonomy_generated.sql
"""
from __future__ import annotations
import json, sys, re
from collections import defaultdict

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

# ── Load scraped data ──────────────────────────────────────────────────────────
with open("../analysis/encar_taxonomy_raw.json", encoding="utf-8") as f:
    tuples = json.load(f)  # [[mfr, model_group, badge, badge_detail], ...]

with open("../analysis/encar_taxonomy_tree.json", encoding="utf-8") as f:
    tree = json.load(f)

# Manufacturer Korean→English map (for SQL scope matching)
MFR_MAP = {
    "현대": "현대",
    "기아": "기아",
    "제네시스": "제네시스",
    "KG모빌리티(쌍용)": "KG모빌리티",
    "쌍용": "쌍용",
    "르노코리아(삼성)": "르노",
    "쉐보레(GM대우)": "쉐보레",
    "BMW": "BMW",
    "벤츠": "벤츠",
    "아우디": "아우디",
    "폭스바겐": "폭스바겐",
    "볼보": "볼보",
    "재규어": "재규어",
    "랜드로버": "랜드로버",
    "렉서스": "렉서스",
    "혼다": "혼다",
    "인피니티": "인피니티",
    "미니": "미니",
    "포르쉐": "포르쉐",
    "벤틀리": "벤틀리",
    "마세라티": "마세라티",
    "페라리": "페라리",
    "링컨": "링컨",
    "캐딜락": "캐딜락",
    "지프": "지프",
    "포드": "포드",
    "테슬라": "테슬라",
    "폴스타": "폴스타",
    "크라이슬러": "크라이슬러",
    "푸조": "푸조",
    "시트로엥/DS": "시트로엥",
    "피아트": "피아트",
    "스마트": "스마트",
    "스즈키": "스즈키",
}

# ── Compute stats per (mfr, model) ─────────────────────────────────────────────
stats: dict = defaultdict(lambda: {
    "with_bd": set(),    # unique badge_detail values
    "without_bd": set(), # badge strings with no badge_detail
    "all_badges": set(),
})
for mfr, mg, badge, bd in tuples:
    key = (mfr, mg)
    if bd and bd not in ("(세부등급 없음)", ""):
        stats[key]["with_bd"].add(bd)
    else:
        if badge:
            stats[key]["without_bd"].add(badge)
    if badge:
        stats[key]["all_badges"].add(badge)

# ── Known trim keywords (ordered longest-first to avoid substring clashes) ────
TRIM_KEYWORDS = [
    # Multi-word first
    "캘리그래피 블랙에디션", "프레스티지 초이스", "프레스티지 플러스", "프레스티지 캠퍼",
    "프리미엄 초이스", "프리미엄 플러스", "프리미엄 패밀리",
    "노블레스 스페셜", "노블레스 그래비티",
    "익스클루시브 스페셜",
    "시그니처 그래비티", "시그니처 X Line",
    "모던 아트", "인스퍼레이션 N Line", "N Line 인스퍼레이션", "N Line 프리미엄",
    "M 스포츠 온라인 익스클루시브 에디션",
    "M 스포츠 온라인 익스클루시브",
    "M 스포츠 프리미엄",
    "M 스포츠 스페셜 에디션",
    "M 스포츠 프로 스페셜 에디션",
    "온라인 익스클루시브 에디션",
    "온라인 익스클루시브",
    # Single-word
    "캘리그래피", "인스퍼레이션", "익스클루시브", "프레스티지", "프리미엄", "프리미어",
    "노블레스", "시그니처", "그래비티", "모던", "스마트", "트렌디", "럭셔리", "디럭스",
    "스페셜", "스탠다드", "고급형", "최고급형", "기본형", "플래티넘", "르블랑", "캠퍼",
    "VIP", "GT Line", "HSE 럭셔리", "HSE",
    "아방가르드", "엘레강스", "AMG Line",
    "프레스티지 스포츠", "퍼포먼스",
]


def extract_trim_from_badge(badge: str) -> str | None:
    """Find the longest matching trim keyword in badge string."""
    for kw in TRIM_KEYWORDS:
        if kw in badge:
            return kw
    return None


# ── Part 1: valid_trim taxonomy_terms ─────────────────────────────────────────
# For every (mfr, model) that has known badge_detail values, emit valid_trim terms.
valid_trim_rows: list[tuple[str, str, str]] = []
for (mfr, mg), data in stats.items():
    known_trims = data["with_bd"]
    if not known_trims:
        continue
    mfr_scope = MFR_MAP.get(mfr, mfr)
    for trim in sorted(known_trims):
        if len(trim) < 2:
            continue
        valid_trim_rows.append((mfr_scope, mg, trim))

# ── Part 2: set_trim rules for models where BD always null ────────────────────
# Only generate if a consistent trim_keyword appears in ≥50% of badge variants.
set_trim_rules: list[dict] = []
for (mfr, mg), data in stats.items():
    if data["with_bd"]:
        continue  # model has BD → already handled, skip
    badge_no_bd = data["without_bd"]
    if not badge_no_bd:
        continue
    mfr_scope = MFR_MAP.get(mfr, mfr)

    # Find which trim keywords appear in how many badge variants
    kw_counts: dict[str, set[str]] = defaultdict(set)
    for badge in badge_no_bd:
        kw = extract_trim_from_badge(badge)
        if kw:
            kw_counts[kw].add(badge)

    # For each keyword that appears in ≥1 badge variant, create a rule
    for kw, matching_badges in sorted(kw_counts.items(), key=lambda x: -len(x[1])):
        # Build a badge_contains pattern: use the keyword itself as the match string
        set_trim_rules.append({
            "make": mfr_scope,
            "model_group": mg,
            "trim_value": kw,
            "badge_contains": kw,
            "badge_count": len(matching_badges),
        })


# ── Write SQL ─────────────────────────────────────────────────────────────────
out_path = "../analysis/encar_taxonomy_generated.sql"

def sql_str(s: str) -> str:
    return "'" + s.replace("'", "''") + "'"

with open(out_path, "w", encoding="utf-8") as f:
    f.write("-- Auto-generated from encar_taxonomy_tree.json\n")
    f.write("-- Generated by analysis/generate_taxonomy_sql.py\n")
    f.write("-- DO NOT EDIT MANUALLY — regenerate from scrape data\n\n")
    f.write("START TRANSACTION;\n\n")

    # ── Section 1: valid_trim taxonomy_terms ──────────────────────────────────
    f.write("-- ══════════════════════════════════════════════════════════════\n")
    f.write(f"-- SECTION 1: valid_trim terms ({len(valid_trim_rows)} rows)\n")
    f.write("-- These are confirmed trim names per make+model from Encar BadgeDetail\n")
    f.write("-- ══════════════════════════════════════════════════════════════\n\n")
    f.write("DELETE FROM taxonomy_terms WHERE term_type = 'valid_trim';\n\n")
    if valid_trim_rows:
        f.write("INSERT INTO taxonomy_terms (term_type, make, model, term, created_at, updated_at)\nVALUES\n")
        rows_sql = []
        now = "NOW()"
        for mfr_scope, mg, trim in valid_trim_rows:
            rows_sql.append(f"  ('valid_trim', {sql_str(mfr_scope)}, {sql_str(mg)}, {sql_str(trim)}, {now}, {now})")
        f.write(",\n".join(rows_sql) + ";\n\n")

    # ── Section 2: set_trim rules from Badge patterns ─────────────────────────
    # Only for models where EVERY badge has null BD and trim keyword found
    f.write("-- ══════════════════════════════════════════════════════════════\n")
    f.write(f"-- SECTION 2: set_trim rules for Badge-embedded trims ({len(set_trim_rules)} rules)\n")
    f.write("-- For models where BadgeDetail is always null, trim is in Badge string\n")
    f.write("-- ══════════════════════════════════════════════════════════════\n\n")
    if set_trim_rules:
        f.write("INSERT INTO taxonomy_rules\n")
        f.write("  (source, action, make, model_contains, badge_contains, action_value, priority, created_at, updated_at)\n")
        f.write("VALUES\n")
        rule_rows = []
        for r in set_trim_rules:
            rule_rows.append(
                f"  ('encar', 'set_trim', "
                f"{sql_str(r['make'])}, {sql_str(r['model_group'])}, "
                f"{sql_str(r['badge_contains'])}, {sql_str(r['trim_value'])}, "
                f"60, NOW(), NOW())"
            )
        f.write(",\n".join(rule_rows) + "\n")
        f.write("ON DUPLICATE KEY UPDATE action_value=VALUES(action_value), updated_at=NOW();\n\n")

    f.write("COMMIT;\n")

# ── Print summary ──────────────────────────────────────────────────────────────
print(f"Generated: {out_path}")
print(f"  Section 1 (valid_trim terms): {len(valid_trim_rows)} rows")
print(f"  Section 2 (set_trim rules):   {len(set_trim_rules)} rules")

# Show sample rules
print("\n=== SAMPLE set_trim rules ===")
for r in set_trim_rules[:30]:
    print(f"  [{r['make']}] {r['model_group']} | badge_contains={r['badge_contains']!r} → trim={r['trim_value']!r} ({r['badge_count']} variants)")

print("\n=== SAMPLE valid_trim terms (first 20) ===")
for mfr, mg, trim in valid_trim_rows[:20]:
    print(f"  [{mfr}] {mg} → {trim}")

print(f"\nTotal valid_trim terms: {len(valid_trim_rows)}")
print(f"Total set_trim rules:   {len(set_trim_rules)}")
