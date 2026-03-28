"""Try alternative KBChacha search endpoints."""
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
}

client = httpx.Client(headers=HEADERS, timeout=30, follow_redirects=True)

endpoints = [
    ("POST", "/public/search/list.json", {"makerCode": "101", "page": 1, "pageSize": 5, "sort": "ModifiedDate", "order": "desc"}),
    ("POST", "/public/search/car/list.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("GET", "/public/search/list.json?makerCode=101&page=1&pageSize=5", None),
    ("POST", "/public/search/main/list.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/car/list.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/car/list/v2.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/search/totalSearch.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
    ("POST", "/public/main/car/list.json", {"makerCode": "101", "page": 1, "pageSize": 5}),
]

for method, path, data in endpoints:
    url = BASE_URL + path
    print(f"\n{'='*60}")
    print(f"{method} {path}")
    print(f"{'='*60}")
    try:
        if method == "POST":
            resp = client.post(url, data=data)
        else:
            resp = client.get(url)
        print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")
        if resp.status_code == 200:
            try:
                d = resp.json()
                if isinstance(d, dict):
                    print(f"Keys: {list(d.keys())[:10]}")
                    for k, v in list(d.items())[:5]:
                        if isinstance(v, list) and v:
                            print(f"  {k}: list[{len(v)}]")
                            if isinstance(v[0], dict):
                                print(f"    first keys: {list(v[0].keys())[:15]}")
                                if any(x in v[0] for x in ("carSeq", "price", "mileage", "year", "sellAmt")):
                                    print(f"    >>> LOOKS LIKE CAR DATA!")
                                    print(f"    first item: {json.dumps(v[0], ensure_ascii=False, default=str)[:500]}")
                        elif isinstance(v, dict):
                            print(f"  {k}: dict keys={list(v.keys())[:10]}")
                        else:
                            print(f"  {k}: {type(v).__name__} = {str(v)[:100]}")
                elif isinstance(d, list):
                    print(f"Array [{len(d)} items]")
            except:
                print(f"Not JSON. Body: {resp.text[:200]}")
        else:
            print(f"Body: {resp.text[:200]}")
    except Exception as e:
        print(f"Error: {e}")

client.close()
