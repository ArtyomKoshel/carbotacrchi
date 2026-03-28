"""Quick local test of KBChacha HTML parser (no DB needed)."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import logging
logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s", stream=sys.stdout)

from parsers.kbcha import KBChaParser

class FakeDB:
    def upsert_lots(self, lots):
        pass
    def mark_stale(self, source, ids):
        return 0
    def close(self):
        pass

parser = KBChaParser(FakeDB())

# Test with just one maker, one page
html = parser._fetch_page("101", 1)  # Hyundai
cars = parser._parse_page(html, "101")

print(f"\n{'='*60}")
print(f"Parsed {len(cars)} cars from Hyundai page 1")
print(f"{'='*60}")

for car in cars[:5]:
    print(f"\n  ID:       {car['id']}")
    print(f"  Make:     {car['make']}")
    print(f"  Model:    {car['model']}")
    print(f"  Year:     {car['year']}")
    print(f"  Price:    ${car['price']} ({car['price_krw']:,} KRW)")
    print(f"  Mileage:  {car['mileage']:,} km")
    print(f"  Location: {car['location']}")
    print(f"  Image:    {car['image_url'][:80] if car['image_url'] else 'None'}...")
    print(f"  URL:      {car['lot_url']}")

print(f"\nAll makes found: {sorted(set(c['make'] for c in cars))}")
print(f"All models found: {sorted(set(c['model'] for c in cars))[:15]}")
print(f"Price range: ${min(c['price'] for c in cars):,} - ${max(c['price'] for c in cars):,}")
print(f"Year range: {min(c['year'] for c in cars)} - {max(c['year'] for c in cars)}")
