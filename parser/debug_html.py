"""Parse KBChacha search page HTML for car data."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import re
import httpx

BASE_URL = "https://www.kbchachacha.com"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
}

client = httpx.Client(headers=HEADERS, timeout=30, follow_redirects=True)

# The URL you navigated to
url = f"{BASE_URL}/public/search/main.kbc?makerCode=102&classCode=1170&carCode=1257"
print(f"Fetching: {url}\n")

resp = client.get(url)
print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")

html = resp.text

# Save full HTML for inspection
with open("debug_kbcha_page.html", "w", encoding="utf-8") as f:
    f.write(html)
print("Saved full HTML to debug_kbcha_page.html")

# Look for car-related patterns
print(f"\n=== Searching for car data patterns ===")

# carSeq
car_seqs = re.findall(r'carSeq[=:"\s]+(\d+)', html)
print(f"carSeq values: {car_seqs[:20]}")

# Prices
prices_manwon = re.findall(r'(\d{1,3}(?:,\d{3})*)\s*만원', html)
print(f"Prices (만원): {prices_manwon[:20]}")

# Car names / models
car_names = re.findall(r'class="[^"]*tit[^"]*"[^>]*>([^<]+)<', html)
print(f"Title elements: {car_names[:10]}")

# Year patterns
years = re.findall(r'(20[12]\d)년?\s*(\d{1,2})월?', html)
print(f"Year/month: {years[:10]}")

# Mileage
mileage = re.findall(r'(\d{1,3}(?:,\d{3})*)\s*km', html)
print(f"Mileage (km): {mileage[:10]}")

# Images
images = re.findall(r'(?:src|data-src)=["\']([^"\']*(?:carpicture|carphoto|carimg|img\.kbchachacha)[^"\']*)["\']', html)
print(f"Car images: {images[:5]}")

# Links to detail pages
detail_links = re.findall(r'href=["\']([^"\']*detail[^"\']*carSeq[^"\']*)["\']', html)
print(f"Detail links: {detail_links[:5]}")

# Look for structured data blocks
# Common pattern: <div class="list-item"> or <li class="car-item">
list_items = re.findall(r'<(?:div|li|article)[^>]*class="[^"]*(?:list-in|car-item|item-box|cs-list)[^"]*"[^>]*>', html)
print(f"\nList item containers: {len(list_items)}")
for item in list_items[:3]:
    print(f"  {item[:150]}")

# Look for any data attributes
data_attrs = re.findall(r'data-(?:car-?seq|car-?id|price|year|maker|model)[="]["\']?([^"\'>\s]+)', html)
print(f"\nData attributes: {data_attrs[:20]}")

# Check if page uses hash routing (SPA)
hash_links = re.findall(r'href=["\']#([^"\']+)["\']', html)
print(f"\nHash links: {hash_links[:10]}")

# Look for inline JSON data
json_blocks = re.findall(r'var\s+(\w+)\s*=\s*(\{[^;]{50,500})', html)
print(f"\nJS variable assignments with objects:")
for name, val in json_blocks[:5]:
    print(f"  {name} = {val[:200]}")

print(f"\n=== HTML size breakdown ===")
print(f"Total: {len(html)} chars")
print(f"Scripts: {len(re.findall(r'<script', html))} tags")
print(f"Contains '검색결과': {'검색결과' in html}")
print(f"Contains '대': {'대' in html}")
print(f"Contains 'empty': {'empty' in html}")
print(f"Contains 'no-data': {'no-data' in html}")

client.close()
