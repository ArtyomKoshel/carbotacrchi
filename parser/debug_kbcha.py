"""Quick debug script to inspect KBChacha API responses."""
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

print("=" * 60)
print("1. carMaker.json")
print("=" * 60)
resp = client.get(f"{BASE_URL}/public/search/carMaker.json")
print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")
data = resp.json()
print(f"Type: {type(data).__name__}")
if isinstance(data, dict):
    print(f"Keys: {list(data.keys())}")
    for k, v in data.items():
        print(f"  {k}: {type(v).__name__}", end="")
        if isinstance(v, list):
            print(f" [{len(v)} items]", end="")
            if v and isinstance(v[0], dict):
                print(f" first keys: {list(v[0].keys())[:10]}")
            else:
                print()
        elif isinstance(v, dict):
            print(f" keys: {list(v.keys())[:10]}")
        elif isinstance(v, str):
            print(f" = '{v[:100]}'")
        else:
            print(f" = {v}")

print("\nFull response (first 3000 chars):")
print(json.dumps(data, ensure_ascii=False, indent=2, default=str)[:3000])

print("\n" + "=" * 60)
print("2. Search (makerCode=015, page=1)")
print("=" * 60)
resp = client.post(
    f"{BASE_URL}/public/main/car/recommend/car/model/search/list/v3.json",
    data={"makerCode": "015", "page": 1, "pageSize": 20, "sort": "ModifiedDate", "order": "desc", "countryOrder": "0"},
)
print(f"Status: {resp.status_code}, Size: {len(resp.content)} bytes")
data = resp.json()
print(f"Type: {type(data).__name__}")
if isinstance(data, dict):
    print(f"Keys: {list(data.keys())}")
    for k, v in data.items():
        print(f"  {k}: {type(v).__name__}", end="")
        if isinstance(v, list):
            print(f" [{len(v)} items]", end="")
            if v and isinstance(v[0], dict):
                print(f" first keys: {list(v[0].keys())[:15]}")
                print(f"    FULL first item:")
                print(f"    {json.dumps(v[0], ensure_ascii=False, indent=4, default=str)[:2000]}")
            else:
                print()
        elif isinstance(v, dict):
            print(f" keys: {list(v.keys())[:10]}")
        elif isinstance(v, str):
            print(f" = '{v[:200]}'")
        else:
            print(f" = {v}")

print("\nFull response (first 5000 chars):")
print(json.dumps(data, ensure_ascii=False, indent=2, default=str)[:5000])

client.close()
