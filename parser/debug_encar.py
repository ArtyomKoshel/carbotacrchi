"""Test Encar API - check if it works without Korean proxy."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import json
import httpx

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "application/json",
    "Accept-Language": "ko-KR,ko;q=0.9",
    "Referer": "https://www.encar.com/",
    "Origin": "https://www.encar.com",
}

client = httpx.Client(headers=HEADERS, timeout=30, follow_redirects=True)

# Encar search API from PLAN_V2
url = "https://api.encar.com/search/car/list/premium"
params = {
    "count": "true",
    "q": "(And.Hidden.N._.Manufacturer.BMW._.ModelGroup.X3.)",
    "sr": "|ModifiedDate|0|10",
    "inav": "|Metadata|Sort",
}

print(f"GET {url}")
print(f"Params: {params}\n")

try:
    resp = client.get(url, params=params)
    print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")
    print(f"Content-Type: {resp.headers.get('content-type', '?')}")
    
    if resp.status_code == 200:
        data = resp.json()
        if isinstance(data, dict):
            print(f"Keys: {list(data.keys())}")
            count = data.get("Count", "?")
            print(f"Count: {count}")
            
            results = data.get("SearchResults", [])
            print(f"SearchResults: {len(results)} items")
            
            if results:
                first = results[0]
                print(f"\nFirst result keys: {list(first.keys())}")
                print(f"\nFirst result:")
                print(json.dumps(first, ensure_ascii=False, indent=2, default=str)[:1500])
        else:
            print(f"Response: {json.dumps(data, ensure_ascii=False, default=str)[:500]}")
    else:
        print(f"Body: {resp.text[:500]}")
except Exception as e:
    print(f"Error: {type(e).__name__}: {e}")

# Also try without specific model
print(f"\n{'='*60}")
print("Try broader search (all Hyundai)")
try:
    resp = client.get(url, params={
        "count": "true",
        "q": "(And.Hidden.N._.Manufacturer.Hyundai.)",
        "sr": "|ModifiedDate|0|5",
    })
    print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")
    if resp.status_code == 200:
        data = resp.json()
        print(f"Count: {data.get('Count', '?')}")
        results = data.get("SearchResults", [])
        print(f"Results: {len(results)}")
        if results:
            r = results[0]
            print(f"Sample: {r.get('Manufacturer','')} {r.get('Model','')} {r.get('Year','')} Price={r.get('Price','')} Mileage={r.get('Mileage','')}")
except Exception as e:
    print(f"Error: {e}")

client.close()
