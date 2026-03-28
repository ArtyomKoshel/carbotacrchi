"""Find API endpoints from KBChacha JS bundles."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import re
import httpx

BASE_URL = "https://www.kbchachacha.com"
client = httpx.Client(
    headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"},
    timeout=30, follow_redirects=True,
)

# Get main page and find JS files
resp = client.get(f"{BASE_URL}/public/search/main.kbc")
js_files = re.findall(r'src=["\']([^"\']*\.js[^"\']*)["\']', resp.text)
print(f"Found {len(js_files)} JS files:")
for f in js_files[:20]:
    print(f"  {f}")

# Search in JS files for API endpoints
print("\n\nSearching JS files for API endpoints...")
for js_url in js_files[:15]:
    if not js_url.startswith("http"):
        js_url = BASE_URL + js_url if js_url.startswith("/") else BASE_URL + "/" + js_url
    
    try:
        resp = client.get(js_url)
        if resp.status_code != 200:
            continue
        text = resp.text
        
        # Find .json endpoints
        json_urls = re.findall(r'["\'](/[a-zA-Z0-9/._-]*\.json)["\']', text)
        # Find search/list/car related paths
        api_paths = re.findall(r'["\'](/public/[a-zA-Z0-9/._-]+)["\']', text)
        # Find fetch/axios/ajax calls
        fetch_calls = re.findall(r'(?:fetch|axios|post|get)\s*\(\s*["\']([^"\']+)["\']', text, re.I)
        
        all_urls = set(json_urls + api_paths + fetch_calls)
        interesting = [u for u in all_urls if any(k in u.lower() for k in ('search', 'list', 'car', 'sell', 'buy', 'vehicle'))]
        
        if interesting:
            short_name = js_url.split("/")[-1][:50]
            print(f"\n  {short_name}:")
            for u in sorted(set(interesting)):
                print(f"    {u}")
    except Exception as e:
        pass

client.close()
