"""
Analyze scraped Encar taxonomy to find trim coverage gaps.
Usage: cd parser && python ../analysis/analyze_taxonomy_gaps.py
"""
import json, sys
from collections import defaultdict

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

with open("../analysis/encar_taxonomy_raw.json", encoding="utf-8") as f:
    tuples = json.load(f)

# Group by (mfr, model): count with/without badge_detail
stats = defaultdict(lambda: {"with_bd": 0, "without_bd": 0, "badge_examples": set(), "bd_values": set()})
for mfr, mg, badge, bd in tuples:
    key = (mfr, mg)
    if bd and bd != "(세부등급 없음)":
        stats[key]["with_bd"] += 1
        stats[key]["bd_values"].add(bd)
    else:
        stats[key]["without_bd"] += 1
    if badge:
        stats[key]["badge_examples"].add(badge)

# Models that NEVER have badge_detail (trim fully in Badge string, need set_trim rules)
print("=== MODELS WHERE BadgeDetail IS ALWAYS NULL (need set_trim rules from Badge) ===")
never_bd = [(k, v) for k, v in stats.items() if v["with_bd"] == 0 and v["without_bd"] > 0]
for (mfr, mg), v in sorted(never_bd):
    badges_str = ", ".join(sorted(v["badge_examples"])[:5])
    print(f"  [{mfr}] {mg}  ({v['without_bd']} badge variants)  badges=[{badges_str}]")

print()
print("=== MODELS WITH BADGE_DETAIL TRIM COVERAGE ===")
always_bd = [(k, v) for k, v in stats.items() if v["with_bd"] > 0]
for (mfr, mg), v in sorted(always_bd):
    total = v["with_bd"] + v["without_bd"]
    pct = v["with_bd"] / total * 100
    bds_str = ", ".join(sorted(v["bd_values"])[:6])
    print(f"  [{mfr}] {mg}: {pct:.0f}% covered ({v['with_bd']}/{total})  known_trims=[{bds_str}]")

# Overall
total = len(tuples)
with_bd = sum(1 for t in tuples if t[3] and t[3] != "(세부등급 없음)")
print(f"\n=== OVERALL COVERAGE ===")
print(f"  Total (mfr, model, badge, badge_detail) combos: {total}")
print(f"  With BadgeDetail (trim known): {with_bd} ({with_bd/total*100:.1f}%)")
print(f"  Without BadgeDetail: {total-with_bd} ({(total-with_bd)/total*100:.1f}%)")
print(f"  Unique (mfr, model) pairs: {len(stats)}")
print(f"  Models that NEVER have BD: {len(never_bd)}")
print(f"  Models that have BD (at least some): {len(always_bd)}")

# What badge strings for "never_bd" models look like they contain trim keywords?
print()
print("=== NEVER-BD MODELS: Badges that look like they embed trim ===")
# Common Korean trim keywords
TRIM_KEYWORDS = ["기본형", "고급형", "최고급형", "디럭스", "럭셔리", "프레미엄", "프리미엄",
                 "스마트", "모던", "스페셜", "인스퍼레이션", "익스클루시브", "프레스티지",
                 "노블레스", "시그니처", "캘리그래피", "트렌디", "플래티넘", "그래비티"]
for (mfr, mg), v in sorted(never_bd):
    for badge in sorted(v["badge_examples"]):
        for kw in TRIM_KEYWORDS:
            if kw in badge:
                print(f"  [{mfr}] {mg} | Badge='{badge}' → trim_hint='{kw}'")
                break
