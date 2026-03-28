"""Try correct relative paths for KBChacha search."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import json
import httpx

BASE_URL = "https://www.kbchachacha.com"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "*/*",
    "Accept-Language": "ko-KR,ko;q=0.9",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "X-Requested-With": "XMLHttpRequest",
}

client = httpx.Client(headers=HEADERS, timeout=30, follow_redirects=True)

# The JS uses relative URLs like "carMaker.json" from /public/search/main.kbc
# So the actual URL is /public/search/carMaker.json (which we already hit)
# But the car LIST uses this.carListUri which is set somewhere

# Let's try the main search page and look for carListUri
print("=== Looking for carListUri in page ===")
resp = client.get(f"{BASE_URL}/public/search/main.kbc")
text = resp.text

import re
uri_matches = re.findall(r'carListUri["\s:=]+["\']([^"\']+)["\']', text)
print(f"carListUri in HTML: {uri_matches}")

# Also search for any .kbc or .json URLs in the page
all_urls = re.findall(r'["\']([/a-zA-Z0-9._-]+(?:\.kbc|\.json)(?:\?[^"\']*)?)["\']', text)
search_urls = [u for u in all_urls if 'search' in u.lower() or 'list' in u.lower() or 'car' in u.lower()]
print(f"\nSearch/list/car URLs in HTML: {sorted(set(search_urls))}")

# The loadCarList function uses $.ajax with this.carListUri
# Let's try common patterns - the list might be an HTML fragment, not JSON
print("\n=== Trying HTML fragment endpoints ===")
test_urls = [
    ("/public/search/carList.kbc", {"makerCode": "101", "page": "1", "pageSize": "5", "sort": "ModifiedDate", "order": "desc"}),
    ("/public/search/list.kbc", {"makerCode": "101", "page": "1", "pageSize": "5"}),
    ("/public/search/main/list.kbc", {"makerCode": "101", "page": "1", "pageSize": "5"}),
    ("/public/search/carList", {"makerCode": "101", "page": "1", "pageSize": "5"}),
    ("/public/search/main/carList.kbc", {"makerCode": "101", "page": "1", "pageSize": "5"}),
    ("/public/search/totalSearch/list.kbc", {"makerCode": "101", "page": "1", "pageSize": "5"}),
]

for path, params in test_urls:
    try:
        resp = client.get(BASE_URL + path, params=params)
        if resp.status_code == 200 and len(resp.content) > 200:
            content_type = resp.headers.get("content-type", "")
            print(f"\n*** HIT: GET {path} -> 200 ({len(resp.content)} bytes, {content_type})")
            if "json" in content_type:
                d = resp.json()
                print(f"  JSON keys: {list(d.keys())[:10] if isinstance(d, dict) else type(d).__name__}")
            else:
                # Check if HTML contains car data
                html = resp.text
                car_links = re.findall(r'carSeq[=:]\s*["\']?(\d+)', html)
                prices = re.findall(r'(\d{1,2},?\d{3})\s*만원', html)
                print(f"  carSeq IDs found: {car_links[:5]}")
                print(f"  Prices found: {prices[:5]}")
                if car_links:
                    print(f"  HTML preview: {html[:500]}")
    except Exception as e:
        pass

    try:
        resp = client.post(BASE_URL + path, data=params)
        if resp.status_code == 200 and len(resp.content) > 200:
            content_type = resp.headers.get("content-type", "")
            print(f"\n*** HIT: POST {path} -> 200 ({len(resp.content)} bytes, {content_type})")
            if "json" in content_type:
                d = resp.json()
                print(f"  JSON keys: {list(d.keys())[:10] if isinstance(d, dict) else type(d).__name__}")
            else:
                html = resp.text
                car_links = re.findall(r'carSeq[=:]\s*["\']?(\d+)', html)
                prices = re.findall(r'(\d{1,2},?\d{3})\s*만원', html)
                print(f"  carSeq IDs found: {car_links[:5]}")
                print(f"  Prices found: {prices[:5]}")
    except:
        pass

# Try query string format from the JS: page=1&makerCode=101
print("\n=== Trying query string in URL ===")
qs_urls = [
    "/public/search/main.kbc?makerCode=101&page=1&pageSize=5&sort=ModifiedDate&order=desc&ajax=true",
    "/public/search/main.kbc?makerCode=101&page=1&ajax=true",
]
for url in qs_urls:
    resp = client.get(BASE_URL + url)
    if resp.status_code == 200:
        html = resp.text
        car_links = re.findall(r'carSeq[=:]\s*["\']?(\d+)', html)
        if car_links:
            print(f"HIT: {url}")
            print(f"  carSeq IDs: {car_links[:10]}")
            print(f"  Size: {len(html)} bytes")

client.close()
