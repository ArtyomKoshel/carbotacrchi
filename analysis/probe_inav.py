"""
Probe Encar iNav navigation API to understand full structure.
Run: cd parser && python ../analysis/probe_inav.py
"""
import sys, json
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.path.insert(0, ".")
import httpx

BASE = "https://api.encar.com/search/car/list/general"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Referer": "https://www.encar.com/",
    "Accept": "application/json",
}
client = httpx.Client(headers=HEADERS, timeout=15)

def fetch(q, inav=None, sr=None):
    params = {"count": "true", "q": q}
    if inav:
        params["inav"] = inav
    if sr:
        params["sr"] = sr
    r = client.get(BASE, params=params)
    return r.json()

# ── Test 1: All nodes at top level ────────────────────────────────────────────
print("=== iNav Nodes at top level (inav=|Metadata|Sort) ===")
data = fetch("(And.Hidden.N.)", inav="|Metadata|Sort")
nodes = data.get("iNav", {}).get("Nodes", [])
for node in nodes:
    name = node.get("Name")
    disp = node.get("DisplayName")
    facets = node.get("Facets", [])
    print(f"  Node: {name} ({disp})  →  {len(facets)} facets")
    for f in facets[:5]:
        print(f"    [{f['Value']}] {f['DisplayValue']}  count={f['Count']}")
    if len(facets) > 5:
        print(f"    ... +{len(facets)-5} more")
print()

# ── Test 2: Try different inav params to get Manufacturer ─────────────────────
for inav_val in ["|Manufacturer", "|Manufacturer|ModelGroup", "|CarType|Manufacturer", "Manufacturer"]:
    print(f"=== inav='{inav_val}' ===")
    try:
        d = fetch("(And.Hidden.N.)", inav=inav_val)
        keys = list(d.keys())
        print(f"  keys: {keys}")
        nodes2 = d.get("iNav", {}).get("Nodes", [])
        for n in nodes2:
            print(f"  Node: {n.get('Name')} ({n.get('DisplayName')})  {len(n.get('Facets',[]))} facets")
            for f in n.get("Facets", [])[:3]:
                print(f"    {f['Value']} ({f['Count']})")
    except Exception as e:
        print(f"  ERROR: {e}")
    print()

# ── Test 3: Look at CarType Y filtering and see what nodes appear ─────────────
print("=== CarType.Y (국산차) — what nodes? ===")
d3 = fetch("(And.Hidden.N._.CarType.Y.)", inav="|Metadata|Sort")
nodes3 = d3.get("iNav", {}).get("Nodes", [])
for node in nodes3:
    name = node.get("Name")
    disp = node.get("DisplayName")
    facets = node.get("Facets", [])
    print(f"  Node: {name} ({disp})  →  {len(facets)} facets")
    for f in facets[:3]:
        print(f"    [{f['Value']}] {f['DisplayValue']}  count={f['Count']}")
print()

# ── Test 4: BreadCrumb node — does it contain the hierarchy? ─────────────────
print("=== Full iNav raw (first 2000 chars) for CarType.Y ===")
print(json.dumps(d3.get("iNav", {}), ensure_ascii=False)[:3000])

client.close()
