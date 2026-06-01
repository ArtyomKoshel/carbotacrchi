"""
Scrape ALL catalog data from Encar search API and save to encar_full_catalog.json.

Collects from every listing (CarType.A + CarType.N):
  Hierarchy  : Manufacturer → ModelGroup → Model (등급) → Badge → BadgeDetail
  Flat lists : Color, SeatColor, FuelType, Transmission

Output file (encar_full_catalog.json):
  {
    "meta": { "scraped_at": "...", "listings_processed": 220000, ... },
    "taxonomy": {
      "현대": {
        "쏘나타": {
          "쏘나타(DN8)19-23": {
            "가솔린 2.5T 4WD": ["프레스티지", "시그니처"]
          }
        }
      }
    },
    "colors":       { "흰색": 12345, ... },
    "seat_colors":  { "블랙": 5000, ... },
    "fuel_types":   { "가솔린": 90000, ... },
    "transmissions": { "자동": 180000, ... }
  }

Usage:
  cd parser
  python ../analysis/scrape_encar_full_catalog.py
  python ../analysis/scrape_encar_full_catalog.py --resume --out-dir ../analysis
"""
from __future__ import annotations

import argparse
import json
import os
import signal
import sys
import time
from collections import defaultdict
from datetime import datetime, timezone

sys.path.insert(0, ".")
import httpx

_SEARCH_URL = "https://api.encar.com/search/car/list/mobile"

_QUERIES = {
    "A": "(And.Hidden.N._.CarType.A.)",   # private/auction cars (majority)
    "N": "(And.Hidden.N._.CarType.N.)",   # business/fleet cars
}

_HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json, text/javascript, */*; q=0.01",
    "Referer": "https://m.encar.com/",
    "Origin": "https://m.encar.com",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8",
}

_STOP = False


def _sigint(sig, frame):
    global _STOP
    print("\n[!] Interrupted — saving partial results...")
    _STOP = True


signal.signal(signal.SIGINT, _sigint)


def _fetch(client: httpx.Client, q: str, offset: int, page_size: int,
           retries: int = 4) -> tuple[list[dict], int]:
    for attempt in range(retries):
        try:
            r = client.get(_SEARCH_URL, params={
                "count": "true",
                "q": q,
                "sr": f"|ModifiedDate|{offset}|{page_size}",
            }, timeout=25)
            r.raise_for_status()
            data = r.json()
            return data.get("SearchResults", []), int(data.get("Count", 0))
        except Exception as e:
            wait = 2 ** attempt
            print(f"  [retry {attempt+1}/{retries}] offset={offset}: {e.__class__.__name__} — wait {wait}s")
            time.sleep(wait)
    return [], 0


def _empty_catalog() -> dict:
    return {
        "meta": {},
        "taxonomy": {},          # make_kr → mg_kr → model_kr → badge → [badge_details]
        "colors": {},            # color_kr → count
        "seat_colors": {},       # seat_color_kr → count
        "fuel_types": {},        # fuel_kr → count
        "transmissions": {},     # trans_kr → count
    }


def _absorb(catalog: dict, cars: list[dict]) -> int:
    """Merge one page of car dicts into the catalog. Returns count absorbed."""
    absorbed = 0
    for car in cars:
        mfr = (car.get("Manufacturer") or "").strip()
        mg  = (car.get("ModelGroup") or "").strip()
        if not mfr or not mg:
            continue
        absorbed += 1

        model        = (car.get("Model") or "").strip() or "_"
        badge        = (car.get("Badge") or "").strip() or "_"
        badge_detail = (car.get("BadgeDetail") or "").strip()
        color        = (car.get("Color") or "").strip()
        seat_color   = (car.get("SeatColor") or "").strip()
        fuel         = (car.get("FuelType") or "").strip()
        transmission = (car.get("Transmission") or "").strip()

        # Taxonomy tree
        tx = catalog["taxonomy"]
        mg_node    = tx.setdefault(mfr, {}).setdefault(mg, {})
        model_node = mg_node.setdefault(model, {})
        bd_set     = model_node.setdefault(badge, set())
        if badge_detail and badge_detail != "(세부등급 없음)":
            bd_set.add(badge_detail)

        # Flat counters
        if color:
            catalog["colors"][color] = catalog["colors"].get(color, 0) + 1
        if seat_color:
            catalog["seat_colors"][seat_color] = catalog["seat_colors"].get(seat_color, 0) + 1
        if fuel:
            catalog["fuel_types"][fuel] = catalog["fuel_types"].get(fuel, 0) + 1
        if transmission:
            catalog["transmissions"][transmission] = catalog["transmissions"].get(transmission, 0) + 1

    return absorbed


def _serialize(catalog: dict) -> dict:
    """Convert sets → sorted lists for JSON serialisation."""
    def _conv(node):
        if isinstance(node, set):
            return sorted(node)
        if isinstance(node, dict):
            return {k: _conv(v) for k, v in sorted(node.items())}
        return node

    out = dict(catalog)
    out["taxonomy"] = _conv(catalog["taxonomy"])
    # Sort flat dicts by count desc
    for key in ("colors", "seat_colors", "fuel_types", "transmissions"):
        out[key] = dict(sorted(catalog[key].items(), key=lambda kv: -kv[1]))
    return out


def _save(path: str, catalog: dict) -> None:
    data = _serialize(catalog)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


def _stats(catalog: dict) -> dict:
    tx = catalog["taxonomy"]
    n_make  = len(tx)
    n_mg    = sum(len(v) for v in tx.values())
    n_model = sum(len(m) for mg in tx.values() for m in mg.values())
    n_badge = sum(
        len(b) for mg in tx.values() for m in mg.values() for b in m.values()
    )
    n_bd = sum(
        len(bd) for mg in tx.values() for m in mg.values() for b in m.values()
        for bd in b.values() if isinstance(bd, (set, list))
    )
    return {
        "makes": n_make, "model_groups": n_mg, "models": n_model,
        "badges": n_badge, "badge_details": n_bd,
        "colors": len(catalog["colors"]),
        "seat_colors": len(catalog["seat_colors"]),
        "fuel_types": len(catalog["fuel_types"]),
        "transmissions": len(catalog["transmissions"]),
    }


def main():
    ap = argparse.ArgumentParser(description="Scrape full Encar catalog to JSON")
    ap.add_argument("--out-dir", default="../analysis")
    ap.add_argument("--page-size", type=int, default=100)
    ap.add_argument("--resume", action="store_true", help="Resume from existing file")
    ap.add_argument("--car-types", default="A,N", help="Comma-separated CarType codes (default: A,N)")
    args = ap.parse_args()

    out_path = os.path.join(args.out_dir, "encar_full_catalog.json")
    car_types = [t.strip().upper() for t in args.car_types.split(",")]

    # Load existing if resuming
    catalog = _empty_catalog()
    if args.resume and os.path.exists(out_path):
        with open(out_path, encoding="utf-8") as f:
            loaded = json.load(f)
        # Re-hydrate sets from lists
        for mfr, mg_dict in loaded.get("taxonomy", {}).items():
            for mg, model_dict in mg_dict.items():
                for model, badge_dict in model_dict.items():
                    for badge, bd_list in badge_dict.items():
                        catalog["taxonomy"] \
                            .setdefault(mfr, {}).setdefault(mg, {}) \
                            .setdefault(model, {})[badge] = set(bd_list)
        for key in ("colors", "seat_colors", "fuel_types", "transmissions"):
            catalog[key] = dict(loaded.get(key, {}))
        print(f"[resume] Loaded existing catalog from {out_path}")

    client = httpx.Client(headers=_HEADERS, follow_redirects=True, timeout=25)
    total_processed = 0
    start = datetime.now(timezone.utc)

    try:
        for car_type in car_types:
            q = _QUERIES.get(car_type)
            if not q:
                print(f"[warn] Unknown CarType: {car_type}, skipping")
                continue

            _, total = _fetch(client, q, 0, 1)
            pages = (total + args.page_size - 1) // args.page_size
            print(f"\n[CarType.{car_type}]  listings: {total:,}  pages: {pages}")

            page = 0
            errors = 0

            while page < pages and not _STOP:
                offset = page * args.page_size
                cars, _ = _fetch(client, q, offset, args.page_size)
                if not cars:
                    errors += 1
                    page += 1
                    continue

                absorbed = _absorb(catalog, cars)
                total_processed += absorbed
                page += 1

                if page % 100 == 0 or page == pages:
                    st = _stats(catalog)
                    elapsed = (datetime.now(timezone.utc) - start).seconds
                    print(
                        f"  [{car_type}] {page}/{pages}  processed={total_processed:,}  "
                        f"makes={st['makes']}  models={st['models']}  "
                        f"trims={st['badge_details']}  colors={st['colors']}  "
                        f"elapsed={elapsed}s"
                    )
                    _save(out_path, catalog)

                time.sleep(0.2)

    finally:
        client.close()

    catalog["meta"] = {
        "scraped_at": start.isoformat(),
        "listings_processed": total_processed,
        "car_types": car_types,
        "page_size": args.page_size,
    }
    _save(out_path, catalog)

    st = _stats(catalog)
    print(f"\n{'='*55}")
    print(f"  Scraped and saved: {out_path}")
    print(f"  Makes          : {st['makes']}")
    print(f"  Model groups   : {st['model_groups']}")
    print(f"  Models (등급)  : {st['models']}")
    print(f"  Badges         : {st['badges']}")
    print(f"  Trim names     : {st['badge_details']}")
    print(f"  Colors         : {st['colors']}")
    print(f"  Interior colors: {st['seat_colors']}")
    print(f"  Fuel types     : {st['fuel_types']}")
    print(f"  Transmissions  : {st['transmissions']}")


if __name__ == "__main__":
    main()
