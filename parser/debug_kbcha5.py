"""Find search-specific JS and API endpoints."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import re
import json
import httpx

BASE_URL = "https://www.kbchachacha.com"
client = httpx.Client(
    headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"},
    timeout=30, follow_redirects=True,
)

# Try the actual search results page
pages = [
    "/public/search/main.kbc?makerCode=101",
    "/public/search/main.kbc",
    "/public/search/totalSearch.kbc",
    "/public/search/totalSearch.kbc?makerCode=101",
]

for page_url in pages:
    resp = client.get(BASE_URL + page_url)
    if resp.status_code != 200:
        continue
    
    js_files = re.findall(r'src=["\']([^"\']*\.js[^"\']*)["\']', resp.text)
    search_js = [f for f in js_files if any(k in f.lower() for k in ('search', 'list', 'total', 'result'))]
    
    if search_js:
        print(f"\n{page_url} -> Search JS files:")
        for f in search_js:
            print(f"  {f}")
            
            if not f.startswith("http"):
                f = BASE_URL + f if f.startswith("/") else BASE_URL + "/" + f
            try:
                resp2 = client.get(f)
                if resp2.status_code == 200:
                    text = resp2.text
                    json_urls = re.findall(r'["\'](/[a-zA-Z0-9/._-]*\.json)["\']', text)
                    api_paths = re.findall(r'["\'](/public/[a-zA-Z0-9/._-]+)["\']', text)
                    all_urls = sorted(set(json_urls + api_paths))
                    if all_urls:
                        print(f"    Endpoints found ({len(all_urls)}):")
                        for u in all_urls:
                            print(f"      {u}")
            except:
                pass
    else:
        all_js = [f for f in js_files if '/js/' in f and 'vendor' not in f and 'common' not in f]
        if all_js:
            print(f"\n{page_url} -> Non-common JS files:")
            for f in all_js[:10]:
                print(f"  {f}")
                if not f.startswith("http"):
                    f = BASE_URL + f if f.startswith("/") else BASE_URL + "/" + f
                try:
                    resp2 = client.get(f)
                    if resp2.status_code == 200:
                        json_urls = re.findall(r'["\'](/[a-zA-Z0-9/._-]*\.json)["\']', resp2.text)
                        interesting = [u for u in json_urls if any(k in u for k in ('search','list','car','total'))]
                        if interesting:
                            print(f"    Endpoints: {interesting}")
                except:
                    pass

# Also try direct Elasticsearch-style endpoint
print("\n\n--- Trying Elasticsearch-style ---")
es_endpoints = [
    ("POST", "/public/search/main/carSearch.json", {"makerCode": "101"}),
    ("POST", "/public/search/totalSearch.json", {"makerCode": "101"}),
    ("POST", "/public/search/main/totalSearch.json", {"makerCode": "101"}),
    ("POST", "/public/search/main/carSearchList.json", {"makerCode": "101"}),
    ("POST", "/public/search/main/v2/list.json", {"makerCode": "101"}),
    ("POST", "/public/search/main/v3/list.json", {"makerCode": "101"}),
    ("POST", "/public/search/main/v4/list.json", {"makerCode": "101"}),
]

for method, path, payload in es_endpoints:
    try:
        resp = client.post(BASE_URL + path, data=payload)
        if resp.status_code == 200:
            print(f"HIT: {path} -> 200 ({len(resp.content)} bytes)")
            d = resp.json()
            print(f"  {json.dumps(d, ensure_ascii=False, default=str)[:300]}")
    except:
        pass

# Try the carSearch endpoint with JSON content type
print("\n--- JSON body ---")
for path in ["/public/search/main/carSearch.json", "/public/search/carSearch.json"]:
    try:
        resp = client.post(BASE_URL + path, json={"makerCode": "101", "page": 0, "pageSize": 5})
        if resp.status_code == 200:
            print(f"HIT: {path} -> 200 ({len(resp.content)} bytes)")
    except:
        pass

client.close()
