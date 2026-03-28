"""Try Elasticsearch-style search on KBChacha."""
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

# carMaker.json returned params with ES-style fields (searchAfter, sort, etc)
# Maybe there's an ES search endpoint

es_payloads = [
    # Try with the params structure from carMaker response
    {"page": 0, "pageSize": 10, "sort": [{"field": "modifiedDate", "order": "desc"}], "defaultFields": [], "includeFields": [], "excludeFields": []},
    {"page": 1, "pageSize": 10, "makerCode": "101"},
    {"page": 0, "pageSize": 10, "query": {"makerCode": "101"}},
    {"makerCode": "101", "page": 0, "pageSize": 10},
]

es_endpoints = [
    "/public/search/carSearch.json",
    "/public/search/car/search.json",
    "/public/search/main/search.json",
    "/public/search/es/search.json",
    "/public/search/car/list.json",
    "/public/search/main/car/list.json",
    "/public/search/main/car/search.json",
    "/public/search/main/car/search/list.json",
    "/public/search/main/car/recommend/car/search/list.json",
    "/public/search/main/car/recommend/car/search/list/v2.json",
    "/public/search/main/car/recommend/car/search/list/v3.json",
    "/public/search/main/car/recommend/car/search/list/v4.json",
    "/public/search/main/car/recommend/search/list.json",
    "/public/search/main/car/recommend/list.json",
    "/public/search/main/car/search/list/v2.json",
    "/public/search/main/car/search/list/v3.json",
    "/public/search/main/car/model/search/list.json",
    "/public/search/main/car/model/search/list/v2.json",
    "/public/search/main/car/model/search/list/v3.json",
    "/public/search/main/car/model/search/list/v4.json",
    "/public/search/carList.json",
    "/public/search/car/carList.json",
]

print("=== Trying ES-style endpoints with JSON body ===")
for endpoint in es_endpoints:
    for payload in es_payloads[:2]:
        try:
            resp = client.post(BASE_URL + endpoint, json=payload)
            if resp.status_code == 200:
                d = resp.json()
                size = len(resp.content)
                keys = list(d.keys()) if isinstance(d, dict) else "array"
                print(f"*** HIT: POST {endpoint} ({size}b) keys={keys}")
                if size > 100:
                    print(f"  {json.dumps(d, ensure_ascii=False, default=str)[:500]}")
                break
        except:
            pass
    
    # Also try form-data
    try:
        resp = client.post(BASE_URL + endpoint, data={"makerCode": "101", "page": "1", "pageSize": "10"})
        if resp.status_code == 200:
            d = resp.json()
            size = len(resp.content)
            keys = list(d.keys()) if isinstance(d, dict) else "array"
            print(f"*** HIT: POST {endpoint} (form) ({size}b) keys={keys}")
            if size > 100:
                print(f"  {json.dumps(d, ensure_ascii=False, default=str)[:500]}")
    except:
        pass

# Try detail page to at least get individual car data
print("\n\n=== Trying detail page ===")
# First get a real carSeq from the main page
resp = client.get(f"{BASE_URL}/public/search/main.kbc")
import re
car_seqs = re.findall(r'carSeq[=:]\s*["\']?(\d+)', resp.text)
print(f"carSeqs from main page: {car_seqs[:5]}")

# Try a known detail page
for seq in ["30000000", "35000000", "40000000"]:
    resp = client.get(f"{BASE_URL}/public/car/detail.kbc?carSeq={seq}")
    if resp.status_code == 200 and len(resp.content) > 5000:
        html = resp.text
        # Look for JSON-LD
        ld = re.findall(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>', html, re.S)
        if ld:
            print(f"\nJSON-LD from carSeq={seq}:")
            print(f"  {ld[0][:500]}")
        
        # Look for car data in meta tags
        og_title = re.findall(r'<meta[^>]*property="og:title"[^>]*content="([^"]*)"', html)
        og_desc = re.findall(r'<meta[^>]*property="og:description"[^>]*content="([^"]*)"', html)
        if og_title:
            print(f"  og:title = {og_title[0]}")
        if og_desc:
            print(f"  og:description = {og_desc[0]}")

client.close()
