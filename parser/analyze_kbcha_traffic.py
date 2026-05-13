"""
Quick traffic analysis for KBCha parser.
Fetches one real car, measures response sizes per endpoint, estimates full-run cost.
Usage: python analyze_kbcha_traffic.py [car_seq]
"""
from __future__ import annotations
import sys, time, json, gzip, zlib
sys.path.insert(0, ".")

from parsers.kbcha.client import KBChaClient
from parsers.kbcha.detail_parser import KBChaDetailParser
from parsers.kbcha.normalizer import KBChaNormalizer

# ── Pick a car_seq (first arg or hardcode) ────────────────────────────────────
CAR_SEQ = sys.argv[1] if len(sys.argv) > 1 else None


def kb(n: int) -> str:
    return f"{n / 1024:.1f} KB"

def compressed_size(text: str) -> int:
    return len(gzip.compress(text.encode("utf-8")))

def line(label: str, raw: int, comp: int | None = None):
    comp_str = f"  → compressed ~{kb(comp)}" if comp else ""
    print(f"  {label:35s} {kb(raw):>9}{comp_str}")


def main():
    client = KBChaClient(proxy=None)  # direct (no proxy) for local testing
    norm   = KBChaNormalizer()
    parser = KBChaDetailParser(norm)

    # ── Step 1: get a real car_seq from listing ───────────────────────────────
    car_seq = CAR_SEQ
    if not car_seq:
        print("Fetching listing to get a sample car_seq …")
        # Use Hyundai maker code as sample
        html = client.fetch_list_page("101", 1)
        from bs4 import BeautifulSoup
        soup = BeautifulSoup(html, "lxml")
        seqs = [a.get("data-car-seq") for a in soup.select("div.area[data-car-seq]")]
        seqs = [s for s in seqs if s and s != "0"]
        if not seqs:
            print("Could not find any car_seq on page 1 of Hyundai. Try passing one manually.")
            return
        car_seq = seqs[0]
        print(f"  Listing page: {kb(len(html.encode()))} raw  (compressed: {kb(compressed_size(html))})")
        print(f"  Found {len(seqs)} cars on page. Using car_seq={car_seq}\n")

    print(f"=== Analyzing car_seq={car_seq} ===\n")

    results = {}

    # ── Step 2: full detail page ──────────────────────────────────────────────
    print("Fetching full detail page …")
    t0 = time.monotonic()
    detail_html = client.fetch_detail_page(car_seq)
    elapsed = time.monotonic() - t0
    detail_raw   = len(detail_html.encode("utf-8"))
    detail_comp  = compressed_size(detail_html)
    results["detail.kbc"] = {"raw": detail_raw, "comp": detail_comp, "elapsed": elapsed}
    line("detail.kbc (full page)", detail_raw, detail_comp)
    print(f"    fetched in {elapsed:.2f}s")

    detail_parsed = parser.parse(detail_html)
    print(f"    fields extracted: {list(detail_parsed.keys())}")
    print()

    # ── Step 3: basic_info popup (try up to 5 cars) ─────────────────────────
    print("Fetching basic_info popup …")
    basic_html = None
    if not CAR_SEQ:
        # Try other cars from listing if first one fails
        from bs4 import BeautifulSoup
        soup2 = BeautifulSoup(html, "lxml")
        all_seqs = [a.get("data-car-seq") for a in soup2.select("div.area[data-car-seq]")]
        all_seqs = [s for s in all_seqs if s and s != "0"][:5]
    else:
        all_seqs = [car_seq]
    for seq in all_seqs:
        time.sleep(1)
        try:
            t0 = time.monotonic()
            basic_html = client.fetch_basic_info(seq)
            elapsed = time.monotonic() - t0
            basic_raw  = len(basic_html.encode("utf-8"))
            basic_comp = compressed_size(basic_html)
            results["basic_info"] = {"raw": basic_raw, "comp": basic_comp, "elapsed": elapsed}
            line(f"basic/info popup (car={seq})", basic_raw, basic_comp)
            print(f"    fetched in {elapsed:.2f}s")
            basic_parsed = parser.parse_basic_info(basic_html)
            print(f"    fields extracted: {list(basic_parsed.keys())}")
            break
        except Exception as e:
            print(f"    car_seq={seq} FAILED: {e}")
    if not basic_html:
        print("    All basic_info attempts failed")
        basic_parsed = {}
    print()

    # ── Step 4: kb inspection popup ───────────────────────────────────────────
    print("Fetching kb_inspection popup …")
    time.sleep(1)
    t0 = time.monotonic()
    try:
        insp_html = client.fetch_kb_inspection(car_seq)
        elapsed = time.monotonic() - t0
        insp_raw  = len(insp_html.encode("utf-8"))
        insp_comp = compressed_size(insp_html)
        results["kb_popup"] = {"raw": insp_raw, "comp": insp_comp, "elapsed": elapsed}
        line("kb_inspection popup", insp_raw, insp_comp)
        print(f"    fetched in {elapsed:.2f}s  (size {kb(insp_raw)})")
    except Exception as e:
        print(f"    FAILED: {e}")
    print()

    # ── Step 5: field overlap analysis ───────────────────────────────────────
    print("=== Field overlap: detail vs basic_info ===")
    only_detail = set(detail_parsed) - set(basic_parsed) - {"photos", "paid_options", "options"}
    only_basic  = set(basic_parsed) - set(detail_parsed)
    both        = set(detail_parsed) & set(basic_parsed)
    print(f"  In BOTH:          {sorted(both)}")
    print(f"  Only in detail:   {sorted(only_detail)}")
    print(f"  Only in basic:    {sorted(only_basic)}")
    print()

    detail_has_photos   = bool(detail_parsed.get("photos"))
    detail_has_options  = bool(detail_parsed.get("options"))
    detail_has_insp_btn = "inspection_type" in detail_parsed
    print(f"  detail has photos:         {detail_has_photos}  ({len(detail_parsed.get('photos', []))} photos)")
    print(f"  detail has options:        {detail_has_options}  ({len(detail_parsed.get('options', []))} options)")
    print(f"  detail has inspection_btn: {detail_has_insp_btn}  type={detail_parsed.get('inspection_type')}")
    print()

    # ── Step 6: test gzip on list page ────────────────────────────────────────
    print("Testing gzip Accept-Encoding for list page …")
    import httpx
    gzip_headers = {**{k: v for k, v in client._client.headers.items()},
                    "Accept-Encoding": "gzip, deflate, br"}
    t0 = time.monotonic()
    r = client._client.get(f"https://www.kbchachacha.com/public/search/list.empty",
                           params={"makerCode": "101", "page": "1"}, headers=gzip_headers)
    elapsed_gz = time.monotonic() - t0
    list_gzip_raw = len(r.content)  # bytes over wire (compressed)
    list_gzip_text = len(r.text.encode("utf-8"))  # decoded size
    results["list_gzip"] = {"raw": list_gzip_raw, "comp": list_gzip_raw, "elapsed": elapsed_gz}
    line("list.empty WITH gzip (wire bytes)", list_gzip_raw)
    line("list.empty WITH gzip (decoded HTML)", list_gzip_text)
    print(f"    fetched in {elapsed_gz:.2f}s  Content-Encoding: {r.headers.get('Content-Encoding', 'none')}")
    print()

    # ── Step 7: full-run cost estimate ───────────────────────────────────────
    print("=== Full-run cost estimate ===")
    TOTAL_LOTS   = 29_100
    PAGES        = TOTAL_LOTS // 40  # 40 lots/page
    NEW_LOTS_PCT = 0.05  # ~5% are new per run
    NEW_LOTS     = int(TOTAL_LOTS * NEW_LOTS_PCT)

    # Sizes in KB
    list_no_gz_kb  = detail_raw / 1024          # same order of magnitude as list page
    list_no_gz_kb  = 309.3                       # from listing measurement above
    list_gz_kb     = list_gzip_raw / 1024
    detail_gz_kb   = detail_comp / 1024          # detail uses gzip already
    detail_raw_kb  = detail_raw / 1024

    print(f"\n  --- Listing pages ({PAGES} pages) ---")
    print(f"  Without gzip:  {PAGES * list_no_gz_kb / 1024:>8.1f} MB")
    print(f"  With gzip:     {PAGES * list_gz_kb    / 1024:>8.1f} MB   (save {(1-list_gz_kb/list_no_gz_kb)*100:.0f}%)")

    print(f"\n  --- Detail pages (already gzip ~{detail_gz_kb:.0f} KB/lot) ---")
    current_detail_mb = (TOTAL_LOTS * detail_gz_kb) / 1024
    new_only_mb       = (NEW_LOTS   * detail_gz_kb) / 1024
    print(f"  Current (ALL {TOTAL_LOTS:,} lots):  {current_detail_mb:>8.1f} MB")
    print(f"  New-only ({NEW_LOTS:,} lots):     {new_only_mb:>8.1f} MB   (save {(1-new_only_mb/current_detail_mb)*100:.0f}%)")

    print(f"\n  --- TOTAL per full run ---")
    before = (PAGES * list_no_gz_kb + TOTAL_LOTS * detail_gz_kb) / 1024
    after  = (PAGES * list_gz_kb    + NEW_LOTS   * detail_gz_kb) / 1024
    print(f"  BEFORE optimizations: {before:>8.1f} MB")
    print(f"  AFTER  optimizations: {after:>8.1f} MB")
    print(f"  Total saving:         {(1-after/before)*100:.0f}%")
    print()
    print("=== Raw sizes summary ===")
    for name, d in results.items():
        e = d.get('elapsed', 0)
        print(f"  {name:35s}  raw={kb(d['raw']):>9}  gzip~={kb(d['comp']):>9}  ({e:.2f}s)")


if __name__ == "__main__":
    main()
