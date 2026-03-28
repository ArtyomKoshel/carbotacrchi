"""Try more KBChacha endpoints based on common SPA patterns."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import json
import httpx

BASE_URL = "https://www.kbchachacha.com"
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "ko-KR,ko;q=0.9",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "Origin": BASE_URL,
    "Content-Type": "application/json",
}

client = httpx.Client(headers=HEADERS, timeout=30, follow_redirects=True)

endpoints = [
    ("POST", "/public/search/main/carSearch.json", {"makerCode": "101", "page": 0, "pageSize": 5}),
    ("POST", "/public/search/main/carSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/carSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/search.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/list/v2.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/list.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/totalSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/carList.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/carSearchList.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/elasticsearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/esSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/v2/carSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/main/v3/carSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
]

for method, path, payload in endpoints:
    url = BASE_URL + path
    try:
        resp = client.post(url, json=payload)
        status = resp.status_code
        size = len(resp.content)
        if status == 200:
            print(f"*** HIT *** {method} {path} -> {status} ({size} bytes)")
            try:
                d = resp.json()
                print(f"  Keys: {list(d.keys())[:10] if isinstance(d, dict) else type(d).__name__}")
                print(f"  Preview: {json.dumps(d, ensure_ascii=False, default=str)[:500]}")
            except:
                print(f"  Body: {resp.text[:300]}")
        elif status != 404:
            print(f"  {method} {path} -> {status}")
    except Exception as e:
        print(f"  {method} {path} -> ERROR: {e}")

# Also try form-data instead of JSON
print("\n\n--- Trying form-data ---")
HEADERS2 = dict(HEADERS)
HEADERS2.pop("Content-Type", None)
client2 = httpx.Client(headers=HEADERS2, timeout=30, follow_redirects=True)

form_endpoints = [
    "/public/search/main/carSearch.json",
    "/public/search/main/list.json",
    "/public/search/main/totalSearch.json",
    "/public/search/main/carList.json",
]

for path in form_endpoints:
    url = BASE_URL + path
    try:
        resp = client2.post(url, data={"makerCode": "101", "page": 1, "pageSize": 5})
        if resp.status_code == 200:
            print(f"*** HIT *** POST {path} (form) -> 200 ({len(resp.content)} bytes)")
            try:
                d = resp.json()
                print(f"  Keys: {list(d.keys())[:10] if isinstance(d, dict) else type(d).__name__}")
                print(f"  Preview: {json.dumps(d, ensure_ascii=False, default=str)[:500]}")
            except:
                print(f"  Body: {resp.text[:300]}")
    except:
        pass

# Try the search page itself to find XHR patterns
print("\n\n--- Trying search page HTML for clues ---")
resp = client2.get(f"{BASE_URL}/public/search/main.kbc?makerCode=101")
if resp.status_code == 200:
    text = resp.text
    import re
    urls = re.findall(r'["\'](/[a-zA-Z/]+\.json)["\']', text)
    print(f"JSON endpoints found in HTML: {list(set(urls))[:20]}")
    
    api_urls = re.findall(r'["\']([^"\']*(?:api|search|list|car)[^"\']*\.json[^"\']*)["\']', text)
    print(f"API-like URLs: {list(set(api_urls))[:20]}")

client.close()
client2.close()
