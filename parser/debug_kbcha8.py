"""Try KBChacha URL format with slash-separated params."""
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

# From JS: this.param builds "page=1/makerCode=101" format
# carListUri might be relative to /public/search/
# Let's try various URL patterns

test_urls = [
    # Slash-separated params (KBChacha custom format)
    "/public/search/list.kbc#page=1/makerCode=101",
    "/public/search/main.kbc#page=1/makerCode=101",
    "/public/search/main.kbc?page=1/makerCode=101",
    
    # Standard query params
    "/public/search/list.kbc?page=1&makerCode=101&pageSize=20&sort=ModifiedDate&order=desc",
    "/public/search/list.kbc?page%5B%5D=1&makerCode%5B%5D=101",
    
    # The JS param function wraps values in arrays
    "/public/search/main.kbc?page=1&makerCode=101",
    "/public/search/main.kbc?page=1&makerCode=101&countryOrder=0",
    
    # Try the list endpoint with AJAX header
    "/public/search/list.kbc?page=1&makerCode=101",
    "/public/search/list.kbc?page=1&makerCode=101&countryOrder=0&sort=ModifiedDate&order=desc",
]

for url in test_urls:
    full_url = BASE_URL + url
    try:
        resp = client.get(full_url)
        if resp.status_code == 200:
            html = resp.text
            car_seqs = re.findall(r'carSeq[=:]\s*["\']?(\d+)', html)
            prices = re.findall(r'(\d{1,3}(?:,\d{3})*)\s*만원', html)
            has_car_data = bool(car_seqs) or bool(prices)
            size = len(html)
            
            if has_car_data or size < 100000:
                print(f"\n{'*'*3 if has_car_data else '   '} GET {url}")
                print(f"    Size: {size} bytes, carSeqs: {car_seqs[:5]}, prices: {prices[:5]}")
                if has_car_data:
                    # Find more details
                    makes = re.findall(r'(?:현대|기아|제네시스|BMW|벤츠|아우디)', html)
                    print(f"    Makes found: {list(set(makes))[:10]}")
                    print(f"    HTML snippet: {html[html.find('carSeq'):html.find('carSeq')+200] if 'carSeq' in html else 'N/A'}")
    except Exception as e:
        pass

# Try POST with X-Requested-With
print("\n\n=== POST with AJAX header ===")
post_urls = [
    "/public/search/list.kbc",
    "/public/search/main/list.kbc",
]
for path in post_urls:
    for params in [
        {"page": "1", "makerCode": "101", "pageSize": "20"},
        {"page[]": "1", "makerCode[]": "101"},
    ]:
        try:
            resp = client.post(BASE_URL + path, data=params)
            if resp.status_code == 200:
                html = resp.text
                car_seqs = re.findall(r'carSeq[=:]\s*["\']?(\d+)', html)
                if car_seqs:
                    print(f"*** HIT: POST {path} with {params}")
                    print(f"    carSeqs: {car_seqs[:10]}")
        except:
            pass

client.close()
