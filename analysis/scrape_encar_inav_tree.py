"""
Encar iNav hierarchical drill-down scraper.

Traverses the 6-level iNav navigation tree using the Encar search API:
  CarType → Manufacturer → ModelGroup → Model → BadgeGroup → Badge → BadgeDetail

Key advantages over search-list scraping:
  - Manufacturer Metadata.EngName = official English name (Hyundai, Kia, BMW…)
  - BadgeGroup gives structured fuel + displacement ("가솔린 2500cc")
  - Badge gives fuel + engine + drive ("가솔린 2.5T 2WD")
  - BadgeDetail = trim name directly, no parsing needed
  - All flat facets (Color, FuelType, Transmission, Category/차종) in ONE request

Output: analysis/encar_inav_tree.json
  {
    "meta": { "scraped_at": "...", "api_calls": 1234 },
    "flat": {
      "colors":       {"흰색": 87396, ...},
      "seat_colors":  {"검정색 계열": 135400, ...},
      "fuel_types":   {"가솔린": 125488, ...},
      "transmissions":{"오토": 219698, ...},
      "body_types":   {"SUV": 85263, ...},   // Category node
      "seat_counts":  {"5인승": 171566, ...}
    },
    "taxonomy": [
      {
        "car_type": "Y",
        "make_kr": "현대",
        "make_en": "Hyundai",
        "model_group_kr": "싼타페",
        "model_kr": "싼타페 (MX5)",
        "badge_group_kr": "가솔린 2500cc",
        "badge_kr": "가솔린 2.5T 2WD",
        "badge_detail_kr": "프레스티지",
        "count": 47
      },
      ...
    ]
  }

Usage:
  cd parser
  python ../analysis/scrape_encar_inav_tree.py
  python ../analysis/scrape_encar_inav_tree.py --dry-run     # first 2 makes only
  python ../analysis/scrape_encar_inav_tree.py --resume
"""
from __future__ import annotations

import argparse
import json
import os
import signal
import sys
import time
from datetime import datetime, timezone

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.path.insert(0, ".")

import httpx

BASE_URL = "https://api.encar.com/search/car/list/general"
INAV_PARAM = "|Metadata|Sort"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
    ),
    "Referer": "https://www.encar.com/",
    "Accept": "application/json",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8",
}

_STOP = False
_api_calls = 0


def _sigint(sig, frame):
    global _STOP
    print("\n[!] Interrupted — saving partial results...")
    _STOP = True


signal.signal(signal.SIGINT, _sigint)


# ── API helpers ────────────────────────────────────────────────────────────────

def _get_inav(client: httpx.Client, q: str, retries: int = 4) -> dict:
    """Fetch iNav navigation for a query. Returns full iNav dict."""
    global _api_calls
    for attempt in range(retries):
        try:
            r = client.get(BASE_URL, params={
                "count": "true",
                "q": q,
                "inav": INAV_PARAM,
            }, timeout=20)
            r.raise_for_status()
            _api_calls += 1
            return r.json().get("iNav", {})
        except Exception as e:
            wait = 2 ** attempt
            print(f"    [retry {attempt+1}] {e.__class__.__name__} — wait {wait}s")
            time.sleep(wait)
    return {}


def _find_facets(container: dict, aspect_name: str, _depth: int = 0) -> list[dict]:
    """
    Recursively search iNav Nodes for a specific Aspect name.
    ModelGroups can be 5+ levels deep inside nested Refinements.

    container: dict with "Nodes" key (iNav root or a Refinements dict).
    """
    if _depth > 12:
        return []

    for node in container.get("Nodes", []):
        # This node IS the target
        if node.get("Name") == aspect_name:
            return node.get("Facets", [])

        # Recurse into each facet's Refinements
        for facet in node.get("Facets", []):
            ref = facet.get("Refinements")
            if ref:
                result = _find_facets(ref, aspect_name, _depth + 1)
                if result:
                    return result

    return []


def _extract_flat_catalogs(inav: dict) -> dict:
    """Extract flat facet lists (colors, transmissions, etc.) from top-level iNav."""
    aspect_map = {
        "Color":           "colors",
        "SeatColor":       "seat_colors",
        "FuelType":        "fuel_types",
        "Transmission":    "transmissions",
        "Category":        "body_types",     # 차종: SUV, 대형차, …
        "SeatingCapacity": "seat_counts",
    }
    result = {v: {} for v in aspect_map.values()}
    for aspect, key in aspect_map.items():
        for f in _find_facets(inav, aspect):
            val = f.get("Value") or f.get("DisplayValue", "")
            cnt = f.get("Count", 0)
            if val:
                result[key][val] = cnt
    return result


# ── Drill-down logic ───────────────────────────────────────────────────────────

def _get_next_level(client: httpx.Client, action: str, aspect: str) -> list[dict]:
    """
    Apply 'action' as query, find aspect in response navigation.
    Returns list of facet dicts [{Value, DisplayValue, Count, Action, Metadata}, …].
    """
    inav = _get_inav(client, action)
    return _find_facets(inav, aspect)


def _facet_meta(f: dict, key: str) -> str | None:
    """Extract first value from Metadata[key] list, or None."""
    vals = f.get("Metadata", {}).get(key, [])
    return vals[0] if vals else None


# ── Main scraper ───────────────────────────────────────────────────────────────

def scrape(client: httpx.Client, dry_run: bool = False) -> tuple[dict, list[dict]]:
    """
    Returns (flat_catalogs, taxonomy_rows).
    taxonomy_rows: list of dicts with full path + count.
    """
    rows: list[dict] = []

    # ── Step 0: top-level iNav → flat catalogs ────────────────────────────────
    print("[0] Fetching top-level iNav for flat catalogs…")
    top_inav = _get_inav(client, "(And.Hidden.N.)")
    flat = _extract_flat_catalogs(top_inav)
    print(f"    colors={len(flat['colors'])}  seat_colors={len(flat['seat_colors'])}"
          f"  fuels={len(flat['fuel_types'])}  transmissions={len(flat['transmissions'])}"
          f"  body_types={len(flat['body_types'])}  seat_counts={len(flat['seat_counts'])}")

    # ── Step 1: get all CarTypes → Manufacturers ──────────────────────────────
    car_type_facets = _find_facets(top_inav, "CarType")
    # We want domestic (Y) + imported (N). A = all, skip to avoid duplicates.
    target_car_types = [f for f in car_type_facets if f.get("Value") in ("Y", "N")]
    print(f"\n[1] CarTypes: {[f['Value'] for f in target_car_types]}")

    for ct_facet in target_car_types:
        if _STOP:
            break
        ct_value  = ct_facet["Value"]
        ct_action = ct_facet["Action"]   # e.g. "(And.Hidden.N._.CarType.Y.)"

        print(f"\n  CarType={ct_value}  ({ct_facet['Count']:,} listings)")

        # ── Step 2: Manufacturers ──────────────────────────────────────────────
        make_facets = _get_next_level(client, ct_action, "Manufacturer")
        print(f"    Manufacturers: {len(make_facets)}")
        if dry_run:
            make_facets = make_facets[:2]
            print(f"    [dry-run] limiting to {len(make_facets)} makes")

        for make_f in make_facets:
            if _STOP:
                break
            make_kr  = make_f["Value"]
            make_en  = _facet_meta(make_f, "EngName") or ""
            make_act = make_f["Action"]

            print(f"    [{make_kr} / {make_en}]  {make_f['Count']:,}")
            time.sleep(0.3)

            # ── Step 3: ModelGroups ────────────────────────────────────────────
            mg_facets = _get_next_level(client, make_act, "ModelGroup")
            print(f"      ModelGroups: {len(mg_facets)}")
            if dry_run:
                mg_facets = mg_facets[:2]
            for mg_f in mg_facets:
                if _STOP:
                    break
                mg_kr  = mg_f["Value"]
                mg_act = mg_f["Action"]
                time.sleep(0.25)

                # ── Step 4: Models (등급) ──────────────────────────────────────
                model_facets = _get_next_level(client, mg_act, "Model")
                print(f"        {mg_kr}: {len(model_facets)} models")
                if dry_run:
                    model_facets = model_facets[:1]
                for model_f in model_facets:
                    if _STOP:
                        break
                    model_kr  = model_f["Value"]
                    model_act = model_f["Action"]
                    time.sleep(0.25)

                    # ── Step 5: BadgeGroups ────────────────────────────────────
                    bg_facets = _get_next_level(client, model_act, "BadgeGroup")
                    if not bg_facets:
                        # Model with no badge groups → record as-is
                        rows.append({
                            "car_type": ct_value, "make_kr": make_kr, "make_en": make_en,
                            "model_group_kr": mg_kr, "model_kr": model_kr,
                            "badge_group_kr": None, "badge_kr": None, "badge_detail_kr": None,
                            "count": model_f["Count"],
                        })
                        continue

                    for bg_f in bg_facets:
                        if _STOP:
                            break
                        bg_kr  = bg_f["Value"]
                        bg_act = bg_f["Action"]
                        time.sleep(0.25)

                        # ── Step 6: Badges ─────────────────────────────────────
                        badge_facets = _get_next_level(client, bg_act, "Badge")
                        for badge_f in badge_facets:
                            if _STOP:
                                break
                            badge_kr  = badge_f["Value"]
                            badge_act = badge_f["Action"]
                            time.sleep(0.25)

                            # ── Step 7: BadgeDetails (trim names) ──────────────
                            bd_facets = _get_next_level(client, badge_act, "BadgeDetail")
                            if not bd_facets:
                                rows.append({
                                    "car_type": ct_value, "make_kr": make_kr, "make_en": make_en,
                                    "model_group_kr": mg_kr, "model_kr": model_kr,
                                    "badge_group_kr": bg_kr, "badge_kr": badge_kr,
                                    "badge_detail_kr": None, "count": badge_f["Count"],
                                })
                                continue

                            for bd_f in bd_facets:
                                rows.append({
                                    "car_type": ct_value, "make_kr": make_kr, "make_en": make_en,
                                    "model_group_kr": mg_kr, "model_kr": model_kr,
                                    "badge_group_kr": bg_kr, "badge_kr": badge_kr,
                                    "badge_detail_kr": bd_f["Value"],
                                    "count": bd_f["Count"],
                                })

                    if len(rows) % 500 == 0:
                        print(f"      rows so far: {len(rows):,}  api_calls: {_api_calls:,}")

    return flat, rows


def _save(out_path: str, flat: dict, rows: list[dict], start: datetime) -> None:
    data = {
        "meta": {
            "scraped_at": start.isoformat(),
            "api_calls":  _api_calls,
            "rows":       len(rows),
        },
        "flat": flat,
        "taxonomy": rows,
    }
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f"\n  Saved → {out_path}  ({len(rows):,} rows, {_api_calls:,} API calls)")


def main():
    ap = argparse.ArgumentParser(description="Scrape Encar iNav tree to JSON")
    ap.add_argument("--out-dir", default="../analysis")
    ap.add_argument("--dry-run", action="store_true", help="Only first 2 makes per CarType")
    ap.add_argument("--resume", action="store_true", help="Skip rows already in file")
    args = ap.parse_args()

    out_path = os.path.join(args.out_dir, "encar_inav_tree.json")
    start    = datetime.now(timezone.utc)

    client = httpx.Client(headers=HEADERS, follow_redirects=True, timeout=20)
    try:
        flat, rows = scrape(client, dry_run=args.dry_run)
    finally:
        client.close()
        _save(out_path, flat, rows, start)

    # Summary
    makes  = len({r["make_kr"] for r in rows})
    models = len({(r["make_kr"], r["model_group_kr"], r["model_kr"]) for r in rows})
    badges = len({r["badge_kr"] for r in rows if r["badge_kr"]})
    trims  = len({r["badge_detail_kr"] for r in rows if r["badge_detail_kr"]})

    print(f"\n{'='*55}")
    print(f"  Makes      : {makes}")
    print(f"  Models     : {models}")
    print(f"  Badges     : {badges}")
    print(f"  Trim names : {trims}")
    print(f"  API calls  : {_api_calls:,}")
    estimated = _api_calls * 0.27 / 60
    print(f"  Time       : ~{estimated:.0f} min estimated for full run")


if __name__ == "__main__":
    main()
