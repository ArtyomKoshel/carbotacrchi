"""Quick local test: verify ThreadPoolExecutor enrichment works (no DB needed)."""
import sys, time
sys.path.insert(0, ".")
from dotenv import load_dotenv; load_dotenv()

from concurrent.futures import ThreadPoolExecutor, as_completed
from parsers.encar.client import EncarClient
from parsers.encar.normalizer import EncarNormalizer
from parsers.encar import EncarParser, _lot_from_search

N_LOTS = 5
WORKERS = 5

client = EncarClient()
data = client.search(count=N_LOTS)
items = data.get("SearchResults", [])[:N_LOTS]
norm = EncarNormalizer()
lots = [_lot_from_search(i, norm) for i in items]
print(f"Lots to enrich ({N_LOTS}): {[l.id for l in lots]}\n")

def task(lot):
    c = EncarClient()
    t = time.monotonic()
    try:
        result = EncarParser._fetch_lot_enrichment(lot, c, norm)
        return result + (time.monotonic() - t,)
    finally:
        c.close()

t0 = time.monotonic()
with ThreadPoolExecutor(max_workers=WORKERS) as pool:
    futures = {pool.submit(task, lot): lot for lot in lots}
    for f in as_completed(futures):
        lot, insp, errs, took = f.result()
        cond = lot.raw_data.get("condition", [])
        insp_status = "ok" if insp else "none"
        print(f"  [{lot.id}] {took:.1f}s | cond={cond} | drive={lot.drive_type!r} | insp={insp_status} | err={errs}")

elapsed = time.monotonic() - t0
print(f"\nParallel:   {elapsed:.1f}s for {N_LOTS} lots")
print(f"Sequential est: ~{N_LOTS * 3:.0f}s  ({WORKERS}x speedup expected)")
client.close()
