"""Test list.empty endpoint and parse HTML."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import re
import httpx

BASE_URL = "https://www.kbchachacha.com"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "text/html, */*; q=0.01",
    "Accept-Language": "ko-KR,ko;q=0.9",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "X-Requested-With": "XMLHttpRequest",
}

client = httpx.Client(headers=HEADERS, timeout=30, follow_redirects=True)

# The real endpoint!
url = f"{BASE_URL}/public/search/list.empty"
params = {"makerCode": "102", "page": "1"}

print(f"GET {url}")
print(f"Params: {params}\n")

resp = client.get(url, params=params)
print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")

html = resp.text

with open("debug_list_empty.html", "w", encoding="utf-8") as f:
    f.write(html)

# Find carSeq links
car_seqs = re.findall(r'carSeq=(\d+)', html)
print(f"\ncarSeq IDs found: {len(car_seqs)}")
print(f"First 10: {car_seqs[:10]}")

# Find car details in HTML structure
# Pattern: title with car name, year/month, mileage, location, price
titles = re.findall(r'<strong[^>]*>([^<]*(?:기아|현대|제네시스|BMW|벤츠|아우디|도요타|혼다|닛산|폭스바겐|볼보|테슬라|포르쉐|렉서스)[^<]*)</strong>', html)
print(f"\nCar titles: {len(titles)}")
for t in titles[:5]:
    print(f"  {t.strip()}")

# Prices
prices = re.findall(r'(\d{1,3}(?:,\d{3})*)\s*만원', html)
print(f"\nPrices: {len(prices)}")
print(f"  {prices[:10]}")

# Mileage
mileages = re.findall(r'([\d,]+)\s*km', html)
print(f"\nMileages: {len(mileages)}")
print(f"  {mileages[:10]}")

# Year/month
years = re.findall(r'(\d{2})/(\d{2})식\((\d{2})년형\)', html)
print(f"\nYear/month: {len(years)}")
print(f"  {years[:10]}")

# Images
images = re.findall(r'(?:src|data-src)=["\']([^"\']*(?:carpicture|carphoto|upload|img\.kbchachacha)[^"\']*)["\']', html)
print(f"\nImages: {len(images)}")
for img in images[:3]:
    print(f"  {img}")

# Location
locations = re.findall(r'(\d{2}/\d{2}식\(\d{2}년형\)\s+[\d,]+km\s+(\S+))', html)
print(f"\nLocations: {[l[1] for l in locations[:10]]}")

# Print a sample of raw HTML around first car
if car_seqs:
    idx = html.find(f'carSeq={car_seqs[0]}')
    if idx > 0:
        start = max(0, idx - 500)
        end = min(len(html), idx + 1000)
        print(f"\n=== HTML around first car (carSeq={car_seqs[0]}) ===")
        print(html[start:end])

client.close()
