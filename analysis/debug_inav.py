"""Debug iNav drill-down structure."""
import sys, json
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
import httpx

BASE = "https://api.encar.com/search/car/list/general"
HEADERS = {"User-Agent": "Mozilla/5.0", "Referer": "https://www.encar.com/"}
client = httpx.Client(headers=HEADERS, timeout=15)

def get_inav(q):
    r = client.get(BASE, params={"count": "true", "q": q, "inav": "|Metadata|Sort"})
    return r.json().get("iNav", {})

# ── Step 1: top-level, find CarType.Y and its Hyundai action ─────────────────
inav = get_inav("(And.Hidden.N.)")
nodes = inav.get("Nodes", [])

hyundai_action = None
for node in nodes:
    if node.get("Name") == "CarType":
        for f in node["Facets"]:
            if f["Value"] == "Y":
                print(f"CarType.Y Action: {f['Action']!r}")
                ref_nodes = f.get("Refinements", {}).get("Nodes", [])
                print(f"CarType.Y Refinements.Nodes: {[n.get('Name') for n in ref_nodes]}")
                for rn in ref_nodes:
                    if rn.get("Name") == "Manufacturer":
                        mfrs = rn.get("Facets", [])
                        print(f"Manufacturers: {len(mfrs)}")
                        for m in mfrs[:3]:
                            print(f"  {m['Value']} ({m.get('Metadata',{}).get('EngName',['?'])[0]}) Action={m['Action']!r}")
                            if m["Value"] == "현대":
                                hyundai_action = m["Action"]

print()

# ── Step 2: query with Hyundai action → find ModelGroup ──────────────────────
if not hyundai_action:
    hyundai_action = "(And.Hidden.N._.(C.CarType.Y._.Manufacturer.현대.))"

print(f"=== Querying with Hyundai action: {hyundai_action!r} ===")
inav2 = get_inav(hyundai_action)
nodes2 = inav2.get("Nodes", [])
print(f"Nodes count: {len(nodes2)}")
for n in nodes2:
    name = n.get("Name")
    facets = n.get("Facets", [])
    print(f"  Node: {name!r}  facets={len(facets)}")
    for f in facets[:2]:
        val = f.get("Value", "")
        selected = f.get("IsSelected", False)
        ref = f.get("Refinements", {})
        ref_node_names = [rn.get("Name") for rn in ref.get("Nodes", [])]
        print(f"    [{val}] selected={selected}  Refinements.Nodes={ref_node_names}")

print()
# ── Step 3: print FULL iNav for Hyundai to see all structure ─────────────────
print("=== Full iNav for Hyundai (truncated) ===")
txt = json.dumps(inav2, ensure_ascii=False)
print(txt[:3000])

client.close()
