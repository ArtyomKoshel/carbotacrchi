"""Rebuild encar_taxonomy_tree.json from the merged raw file."""
import json, sys
sys.stdout.reconfigure(encoding="utf-8", errors="replace")

with open("../analysis/encar_taxonomy_raw.json", encoding="utf-8") as f:
    tuples = json.load(f)

print(f"Total tuples: {len(tuples)}")

tree: dict = {}
for mfr, mg, badge, bd in tuples:
    mfr_node = tree.setdefault(mfr, {})
    mg_node  = mfr_node.setdefault(mg, {})
    if badge:
        bd_set = mg_node.setdefault(badge, set())
        if bd and bd != "(세부등급 없음)":
            bd_set.add(bd)

def convert(node):
    if isinstance(node, set):
        return sorted(node)
    return {k: convert(v) for k, v in sorted(node.items())}

tree = convert(tree)

n_mfr = len(tree)
n_mg = sum(len(v) for v in tree.values())
n_badge = sum(len(b) for mv in tree.values() for b in mv.values())
n_bd = sum(len(bds) for mv in tree.values() for bv in mv.values() for bds in bv.values())

print(f"Manufacturers: {n_mfr}")
print(f"Model groups:  {n_mg}")
print(f"Badges:        {n_badge}")
print(f"Badge details: {n_bd}")

with open("../analysis/encar_taxonomy_tree.json", "w", encoding="utf-8") as f:
    json.dump(tree, f, ensure_ascii=False, indent=2)
print("Saved encar_taxonomy_tree.json")
