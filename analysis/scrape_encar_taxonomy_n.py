"""
Quick scrape of CarType.N to find unique combos not in CarType.A taxonomy.
Usage: cd parser && python ../analysis/scrape_encar_taxonomy_n.py
"""
from __future__ import annotations
import sys, json, time, os
sys.path.insert(0, ".")
import httpx

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

_SEARCH_URL = "https://api.encar.com/search/car/list/mobile"
_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "application/json",
    "Referer": "https://m.encar.com/",
}

# Load existing combos from CarType.A scrape
with open("../analysis/encar_taxonomy_raw.json", encoding="utf-8") as f:
    existing = {tuple(x) for x in json.load(f)}
print(f"Loaded {len(existing)} existing combos from CarType.A scrape")

client = httpx.Client(headers=_HEADERS, follow_redirects=True, timeout=15)

# Get count
r = client.get(_SEARCH_URL, params={
    "count": "true", "q": "(And.Hidden.N._.CarType.N.)", "sr": "|ModifiedDate|0|0"
}, timeout=10)
total = r.json().get("Count", 0)
print(f"CarType.N total: {total:,}")

def _save_merge(existing_set: set, new_set: set) -> None:
    all_combos = existing_set | new_set
    out = sorted(all_combos, key=lambda x: (x[0] or "", x[1] or "", x[2] or "", x[3] or ""))
    with open("../analysis/encar_taxonomy_raw.json", "w", encoding="utf-8") as f:
        json.dump([list(t) for t in out], f, ensure_ascii=False, indent=2)

def _fetch_page(offset: int, retries: int = 4) -> list:
    for attempt in range(retries):
        try:
            r = client.get(_SEARCH_URL, params={
                "count": "true", "q": "(And.Hidden.N._.CarType.N.)",
                "sr": f"|ModifiedDate|{offset}|100"
            }, timeout=20)
            if r.status_code == 200:
                return r.json().get("SearchResults", [])
            print(f"  [warn] offset {offset}: HTTP {r.status_code}")
        except Exception as e:
            wait = 2 ** attempt
            print(f"  [retry {attempt+1}/{retries}] offset {offset}: {e.__class__.__name__} — wait {wait}s")
            time.sleep(wait)
    return []

new_combos: set[tuple] = set()
pages = (total + 99) // 100
print(f"Pages: {pages}. Scanning until plateau...\n")

prev_new = 0
for page in range(pages):
    offset = page * 100
    cars = _fetch_page(offset)
    for car in cars:
        mfr = (car.get("Manufacturer") or "").strip()
        mg  = (car.get("ModelGroup") or "").strip()
        badge = (car.get("Badge") or "").strip() or None
        bd    = (car.get("BadgeDetail") or "").strip() or None
        if mfr and mg:
            t = (mfr, mg, badge, bd)
            if t not in existing:
                new_combos.add(t)

    if (page + 1) % 50 == 0:
        added_this_batch = len(new_combos) - prev_new
        print(f"  page {page+1}/{pages}  new unique combos (not in A): {len(new_combos)}  (+{added_this_batch} this batch)")
        prev_new = len(new_combos)
        _save_merge(existing, new_combos)  # intermediate save
        # Early stop if plateau
        if page >= 100 and added_this_batch == 0:
            print("  [plateau] Stopping early")
            break

    time.sleep(0.2)

client.close()

# Save merged result
print(f"\n=== CarType.N unique combos not in CarType.A: {len(new_combos)} ===")
for t in sorted(new_combos, key=lambda x: (x[0] or "", x[1] or "", x[2] or "", x[3] or ""))[:50]:
    print(f"  {t}")

# Merge and save
all_combos = existing | new_combos
print(f"\nMerging: {len(existing)} + {len(new_combos)} = {len(all_combos)} total")

out = sorted(all_combos, key=lambda x: (x[0] or "", x[1] or "", x[2] or "", x[3] or ""))
with open("../analysis/encar_taxonomy_raw.json", "w", encoding="utf-8") as f:
    json.dump([list(t) for t in out], f, ensure_ascii=False, indent=2)
print(f"Saved merged taxonomy to encar_taxonomy_raw.json")
