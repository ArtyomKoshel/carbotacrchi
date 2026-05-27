"""
Scrape Encar search API to collect full taxonomy tree:
    Manufacturer → ModelGroup → Badge → BadgeDetail

Results saved to:
    analysis/encar_taxonomy_raw.json  - all unique tuples
    analysis/encar_taxonomy_tree.json - nested tree structure

Usage:
    cd parser && python ../analysis/scrape_encar_taxonomy.py [--limit N] [--out-dir PATH]

Options:
    --limit N      Stop after collecting N pages (default: all ~2200 pages)
    --out-dir PATH Output directory (default: ../analysis)
    --page-size N  Results per page (default: 100, max ~200)
    --resume       Resume from existing encar_taxonomy_raw.json
"""
from __future__ import annotations
import sys, json, time, os, argparse, signal
sys.path.insert(0, ".")

import httpx

_SEARCH_URL = "https://api.encar.com/search/car/list/mobile"
_SEARCH_Q   = "(And.Hidden.N._.CarType.A.)"

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


def fetch_page(client: httpx.Client, offset: int, page_size: int) -> tuple[list[dict], int]:
    """Fetch one search page. Returns (results, total_count)."""
    params = {
        "count": "true",
        "q": _SEARCH_Q,
        "sr": f"|ModifiedDate|{offset}|{page_size}",
    }
    r = client.get(_SEARCH_URL, params=params, timeout=20)
    r.raise_for_status()
    data = r.json()
    return data.get("SearchResults", []), data.get("Count", 0)


def extract_taxonomy(car: dict) -> tuple[str, str, str | None, str | None] | None:
    """Extract (manufacturer, model_group, badge, badge_detail) from a car dict."""
    mfr = car.get("Manufacturer") or ""
    mg  = car.get("ModelGroup") or ""
    badge = car.get("Badge") or None
    badge_detail = car.get("BadgeDetail") or None
    if not mfr or not mg:
        return None
    return (mfr.strip(), mg.strip(), badge.strip() if badge else None, badge_detail.strip() if badge_detail else None)


def build_tree(tuples: list[tuple]) -> dict:
    """Build nested tree: mfr → model_group → badge → [badge_details]."""
    tree: dict = {}
    for mfr, mg, badge, bd in tuples:
        mfr_node = tree.setdefault(mfr, {})
        mg_node  = mfr_node.setdefault(mg, {})
        if badge:
            bd_list = mg_node.setdefault(badge, set())
            if bd:
                bd_list.add(bd)
    # Convert sets to sorted lists
    def convert(node):
        if isinstance(node, set):
            return sorted(node)
        return {k: convert(v) for k, v in sorted(node.items())}
    return convert(tree)


def stats_summary(tree: dict) -> dict:
    n_mfr = len(tree)
    n_mg = sum(len(v) for v in tree.values())
    n_badge = sum(len(badge_dict) for mfr_v in tree.values() for badge_dict in mfr_v.values())
    n_bd = sum(len(bd_list) for mfr_v in tree.values() for badge_dict in mfr_v.values() for bd_list in badge_dict.values())
    return {"manufacturers": n_mfr, "model_groups": n_mg, "badges": n_badge, "badge_details": n_bd}


_STOP = False

def _sigint(sig, frame):
    global _STOP
    print("\n\n[!] Interrupted — saving partial results...")
    _STOP = True

signal.signal(signal.SIGINT, _sigint)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--limit", type=int, default=0, help="Max pages (0=all)")
    parser.add_argument("--out-dir", default="../analysis")
    parser.add_argument("--page-size", type=int, default=100)
    parser.add_argument("--resume", action="store_true")
    args = parser.parse_args()

    out_raw  = os.path.join(args.out_dir, "encar_taxonomy_raw.json")
    out_tree = os.path.join(args.out_dir, "encar_taxonomy_tree.json")

    # Load existing if resuming
    seen: set[tuple] = set()
    if args.resume and os.path.exists(out_raw):
        with open(out_raw, encoding="utf-8") as f:
            existing = json.load(f)
        seen = {tuple(x) for x in existing}
        print(f"[resume] Loaded {len(seen)} existing tuples from {out_raw}")

    client = httpx.Client(headers=_HEADERS, follow_redirects=True, timeout=20)

    # Get total count
    _, total = fetch_page(client, 0, 1)
    print(f"[info] Total listings: {total:,}")
    total_pages = (total + args.page_size - 1) // args.page_size
    max_pages = args.limit if args.limit > 0 else total_pages
    print(f"[info] Pages to scrape: {max_pages} (page_size={args.page_size})")
    print(f"[info] Estimated time: ~{max_pages * 0.35 / 60:.1f} min (0.35s/page)\n")

    new_tuples = 0
    errors = 0
    page = 0

    try:
        while page < max_pages and not _STOP:
            offset = page * args.page_size
            try:
                cars, _ = fetch_page(client, offset, args.page_size)
            except Exception as e:
                errors += 1
                print(f"  [err] page {page} offset={offset}: {e}")
                time.sleep(2)
                page += 1
                continue

            for car in cars:
                t = extract_taxonomy(car)
                if t and t not in seen:
                    seen.add(t)
                    new_tuples += 1

            page += 1
            if page % 50 == 0 or page == max_pages:
                print(f"  [progress] page {page}/{max_pages}  unique combos: {len(seen):,}  new: {new_tuples}  errors: {errors}")
                # Save checkpoint
                _save(out_raw, out_tree, seen)
                new_tuples = 0

            time.sleep(0.25)

    finally:
        client.close()
        _save(out_raw, out_tree, seen)

    tree = build_tree(list(seen))
    st = stats_summary(tree)
    print(f"\n[done] Taxonomy collected:")
    print(f"  Manufacturers : {st['manufacturers']}")
    print(f"  Model groups  : {st['model_groups']}")
    print(f"  Badges        : {st['badges']}")
    print(f"  Badge details : {st['badge_details']}")
    print(f"\n  Saved: {out_raw}")
    print(f"  Saved: {out_tree}")


def _save(out_raw: str, out_tree: str, seen: set):
    tuples_list = [list(t) for t in sorted(seen, key=lambda x: (x[0] or "", x[1] or "", x[2] or "", x[3] or ""))]
    with open(out_raw, "w", encoding="utf-8") as f:
        json.dump(tuples_list, f, ensure_ascii=False, indent=2)
    tree = build_tree(list(seen))
    with open(out_tree, "w", encoding="utf-8") as f:
        json.dump(tree, f, ensure_ascii=False, indent=2)


if __name__ == "__main__":
    main()
