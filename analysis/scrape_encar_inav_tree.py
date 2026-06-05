"""
Encar iNav hierarchical drill-down scraper.

Traverses the 6-level iNav navigation tree using the Encar search API:
  CarType → Manufacturer → ModelGroup → Model → BadgeGroup → Badge → BadgeDetail

Optimisations vs v1:
  - Concurrent model processing via ThreadPoolExecutor (--workers, default 8)
  - Per-thread httpx.Client with dedicated FloppyData residential proxy
    (each worker = own Korean IP → no shared rate limit)
  - Reduced base sleep (--sleep, default 0.05 s)
  - Thread-safe row collection with threading.Lock
  - Autosave after every make

Proxy env vars (same as parser):
  FLOPPY_USERNAME   e.g. "myuser"
  FLOPPY_PASSWORD   e.g. "secret"
  FLOPPY_HOST       default: geo.g-w.info:10080

Usage:
  python scrape_encar_inav_tree.py --out-dir /app/logs/analysis
  python scrape_encar_inav_tree.py --out-dir /app/logs/analysis --workers 8 --sleep 0.1
  python scrape_encar_inav_tree.py --out-dir /app/logs/analysis --resume
  python scrape_encar_inav_tree.py --dry-run   # no proxy needed
"""
from __future__ import annotations

import argparse
import json
import os
import random
import signal
import string
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

import httpx

BASE_URL   = "https://api.encar.com/search/car/list/general"
INAV_PARAM = "|Metadata|Sort"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
    ),
    "Referer":         "https://www.encar.com/",
    "Accept":          "application/json",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8",
}

_STOP       = False
_api_calls  = 0
_api_lock   = threading.Lock()
_print_lock = threading.Lock()

SLEEP = 0.1   # overridden by --sleep


# ── FloppyData proxy helpers ───────────────────────────────────────────────────

def _random_session() -> str:
    return "".join(random.choices(string.ascii_lowercase + string.digits, k=8))


def _make_proxy_url(idx: int) -> str | None:
    """Build a unique residential proxy URL for worker idx. Returns None if not configured."""
    username = os.getenv("FLOPPY_USERNAME", "")
    password = os.getenv("FLOPPY_PASSWORD", "")
    host     = os.getenv("FLOPPY_HOST", "geo.g-w.info:10080")
    if not username or not password:
        return None
    session = _random_session()
    return (
        f"http://{username}-type-residential-session-{session}"
        f"-country-KR-rotation-15:{password}@{host}"
    )


# Assign one proxy URL per worker index (generated once at startup)
_WORKER_PROXIES: list[str | None] = []


def _sigint(sig, frame):
    global _STOP
    print("\n[!] Interrupted — saving partial results...")
    _STOP = True


signal.signal(signal.SIGINT, _sigint)


# ── thread-local httpx clients (one per worker, each with own proxy) ──────────

_local = threading.local()
_worker_counter = 0
_worker_counter_lock = threading.Lock()


def _client() -> httpx.Client:
    if not hasattr(_local, "client"):
        # Assign a worker index to this thread
        global _worker_counter
        with _worker_counter_lock:
            idx = _worker_counter
            _worker_counter += 1

        proxy = _WORKER_PROXIES[idx] if idx < len(_WORKER_PROXIES) else None
        kwargs: dict = {"headers": HEADERS, "follow_redirects": True, "timeout": 20}
        if proxy:
            kwargs["transport"] = httpx.HTTPTransport(proxy=proxy)
            with _print_lock:
                print(f"    [worker-{idx}] using proxy session ...{proxy[-20:]}")
        _local.client = httpx.Client(**kwargs)
    return _local.client


# ── API helpers ────────────────────────────────────────────────────────────────

def _get_inav(q: str, retries: int = 4) -> dict:
    global _api_calls
    client = _client()
    for attempt in range(retries):
        try:
            r = client.get(BASE_URL, params={"count": "true", "q": q, "inav": INAV_PARAM}, timeout=20)
            r.raise_for_status()
            with _api_lock:
                _api_calls += 1
            time.sleep(SLEEP)
            return r.json().get("iNav", {})
        except Exception as e:
            wait = 2 ** attempt
            with _print_lock:
                print(f"    [retry {attempt+1}] {e.__class__.__name__} — wait {wait}s")
            time.sleep(wait)
    return {}


def _find_facets(container: dict, aspect_name: str, _depth: int = 0) -> list[dict]:
    if _depth > 12:
        return []
    for node in container.get("Nodes", []):
        if node.get("Name") == aspect_name:
            return node.get("Facets", [])
        for facet in node.get("Facets", []):
            ref = facet.get("Refinements")
            if ref:
                result = _find_facets(ref, aspect_name, _depth + 1)
                if result:
                    return result
    return []


def _get_next_level(action: str, aspect: str) -> list[dict]:
    inav = _get_inav(action)
    return _find_facets(inav, aspect)


def _facet_meta(f: dict, key: str) -> str | None:
    vals = f.get("Metadata", {}).get(key, [])
    return vals[0] if vals else None


def _extract_flat_catalogs(inav: dict) -> dict:
    aspect_map = {
        "Color":           "colors",
        "SeatColor":       "seat_colors",
        "FuelType":        "fuel_types",
        "Transmission":    "transmissions",
        "Category":        "body_types",
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


# ── Per-model worker ───────────────────────────────────────────────────────────

def _process_model(
    ct_value: str,
    make_kr: str,
    make_en: str,
    mg_kr: str,
    model_f: dict,
) -> list[dict]:
    """Fetch badge groups → badges → badge details for ONE model. Returns rows."""
    if _STOP:
        return []

    model_kr  = model_f["Value"]
    model_act = model_f["Action"]
    model_rows: list[dict] = []

    base = dict(
        car_type=ct_value, make_kr=make_kr, make_en=make_en,
        model_group_kr=mg_kr, model_kr=model_kr,
    )

    bg_facets = _get_next_level(model_act, "BadgeGroup")
    if not bg_facets:
        model_rows.append({**base, "badge_group_kr": None, "badge_kr": None,
                           "badge_detail_kr": None, "count": model_f["Count"]})
        return model_rows

    for bg_f in bg_facets:
        if _STOP:
            break
        bg_kr  = bg_f["Value"]
        bg_act = bg_f["Action"]

        badge_facets = _get_next_level(bg_act, "Badge")
        for badge_f in badge_facets:
            if _STOP:
                break
            badge_kr  = badge_f["Value"]
            badge_act = badge_f["Action"]

            bd_facets = _get_next_level(badge_act, "BadgeDetail")
            if not bd_facets:
                model_rows.append({**base, "badge_group_kr": bg_kr, "badge_kr": badge_kr,
                                   "badge_detail_kr": None, "count": badge_f["Count"]})
                continue

            for bd_f in bd_facets:
                model_rows.append({**base, "badge_group_kr": bg_kr, "badge_kr": badge_kr,
                                   "badge_detail_kr": bd_f["Value"], "count": bd_f["Count"]})
    return model_rows


# ── Main scraper ───────────────────────────────────────────────────────────────

def scrape(
    workers: int = 8,
    dry_run: bool = False,
    existing_rows: list[dict] | None = None,
) -> tuple[dict, list[dict]]:

    rows: list[dict] = list(existing_rows or [])
    rows_lock = threading.Lock()

    scraped_models: set[tuple] = {
        (r["make_kr"], r["model_group_kr"] or "", r["model_kr"] or "")
        for r in rows if r.get("model_kr")
    }
    if scraped_models:
        print(f"  [resume] {len(scraped_models):,} models already scraped — skipping")

    # Step 0: top-level iNav for flat catalogs
    print("[0] Fetching top-level iNav for flat catalogs...")
    top_inav = _get_inav("(And.Hidden.N.)")
    flat = _extract_flat_catalogs(top_inav)
    print(f"    colors={len(flat['colors'])}  fuels={len(flat['fuel_types'])}  "
          f"transmissions={len(flat['transmissions'])}  body_types={len(flat['body_types'])}")

    # Step 1: CarTypes
    car_type_facets = _find_facets(top_inav, "CarType")
    target_car_types = [f for f in car_type_facets if f.get("Value") in ("Y", "N")]
    print(f"\n[1] CarTypes: {[f['Value'] for f in target_car_types]}")

    for ct_facet in target_car_types:
        if _STOP:
            break
        ct_value  = ct_facet["Value"]
        ct_action = ct_facet["Action"]
        print(f"\n  CarType={ct_value}  ({ct_facet['Count']:,})")

        # Step 2: Manufacturers
        make_facets = _get_next_level(ct_action, "Manufacturer")
        print(f"    Manufacturers: {len(make_facets)}")
        if dry_run:
            make_facets = make_facets[:2]

        for make_f in make_facets:
            if _STOP:
                break
            make_kr  = make_f["Value"]
            make_en  = _facet_meta(make_f, "EngName") or ""
            make_act = make_f["Action"]
            print(f"\n    [{make_kr} / {make_en}]  {make_f['Count']:,}")

            make_rows_before = len(rows)

            # Step 3: ModelGroups
            mg_facets = _get_next_level(make_act, "ModelGroup")
            print(f"      ModelGroups: {len(mg_facets)}")
            if dry_run:
                mg_facets = mg_facets[:2]

            for mg_f in mg_facets:
                if _STOP:
                    break
                mg_kr  = mg_f["Value"]
                mg_act = mg_f["Action"]

                # Step 4: Models
                model_facets = _get_next_level(mg_act, "Model")
                print(f"        {mg_kr}: {len(model_facets)} models", flush=True)
                if dry_run:
                    model_facets = model_facets[:1]

                # Filter already-scraped
                pending = [
                    m for m in model_facets
                    if (make_kr, mg_kr, m["Value"]) not in scraped_models
                ]
                if not pending:
                    continue

                # ── Concurrent badge fetch ──────────────────────────────────
                with ThreadPoolExecutor(max_workers=workers) as pool:
                    futures = {
                        pool.submit(_process_model, ct_value, make_kr, make_en, mg_kr, m): m
                        for m in pending
                    }
                    for fut in as_completed(futures):
                        if _STOP:
                            break
                        model_rows = fut.result()
                        with rows_lock:
                            rows.extend(model_rows)
                            if len(rows) % 500 < len(model_rows):
                                with _api_lock:
                                    calls = _api_calls
                                print(f"      rows so far: {len(rows):,}  api_calls: {calls:,}",
                                      flush=True)

            # Autosave after each make
            if len(rows) > make_rows_before:
                with _api_lock:
                    calls = _api_calls
                print(f"      [autosave] {len(rows):,} rows  api_calls: {calls:,}", flush=True)

    return flat, rows


def _save(out_path: str, flat: dict, rows: list[dict], start: datetime) -> None:
    data = {
        "meta": {
            "scraped_at": start.isoformat(),
            "api_calls":  _api_calls,
            "rows":       len(rows),
        },
        "flat":     flat,
        "taxonomy": rows,
    }
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f"\n  Saved → {out_path}  ({len(rows):,} rows, {_api_calls:,} API calls)")


def main():
    ap = argparse.ArgumentParser(description="Scrape Encar iNav tree to JSON (fast)")
    ap.add_argument("--out-dir",  default="../analysis")
    ap.add_argument("--workers",  type=int, default=8,    help="Concurrent model workers (default 8)")
    ap.add_argument("--sleep",    type=float, default=0.05, help="Sleep between API calls per thread (default 0.05)")
    ap.add_argument("--dry-run",  action="store_true")
    ap.add_argument("--resume",   action="store_true")
    args = ap.parse_args()

    global SLEEP
    SLEEP = args.sleep

    out_path = os.path.join(args.out_dir, "encar_inav_tree.json")
    start    = datetime.now(timezone.utc)

    os.makedirs(args.out_dir, exist_ok=True)

    existing_rows: list[dict] = []
    existing_flat: dict = {}
    if args.resume and os.path.exists(out_path):
        print(f"[resume] Loading {out_path} ...")
        with open(out_path, encoding="utf-8") as f:
            prev = json.load(f)
        existing_rows = prev.get("taxonomy", [])
        existing_flat = prev.get("flat", {})
        print(f"[resume] Loaded {len(existing_rows):,} existing rows")

    # Build proxy pool — one unique session per worker
    global _WORKER_PROXIES
    _WORKER_PROXIES = [_make_proxy_url(i) for i in range(args.workers)]
    proxy_count = sum(1 for p in _WORKER_PROXIES if p)
    print(f"Workers: {args.workers}  Sleep: {args.sleep}s  Proxies: {proxy_count}/{args.workers}  Output: {out_path}")
    if proxy_count == 0:
        print("  [!] No proxy configured (FLOPPY_USERNAME/FLOPPY_PASSWORD not set) — running direct")

    flat, rows = scrape(
        workers=args.workers,
        dry_run=args.dry_run,
        existing_rows=existing_rows,
    )

    if existing_flat and not flat.get("colors"):
        flat = existing_flat

    _save(out_path, flat, rows, start)

    makes  = len({r["make_kr"] for r in rows})
    models = len({(r["make_kr"], r["model_group_kr"], r["model_kr"]) for r in rows})
    trims  = len({r["badge_detail_kr"] for r in rows if r["badge_detail_kr"]})
    print(f"\n{'='*55}")
    print(f"  Makes      : {makes}")
    print(f"  Models     : {models}")
    print(f"  Trim names : {trims}")
    print(f"  API calls  : {_api_calls:,}")


if __name__ == "__main__":
    main()
