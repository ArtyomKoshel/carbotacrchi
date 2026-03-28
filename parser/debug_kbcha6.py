"""Analyze search.list.v2 JS for API patterns."""
import sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

import re
import httpx

BASE_URL = "https://www.kbchachacha.com"
client = httpx.Client(
    headers={"User-Agent": "Mozilla/5.0"},
    timeout=30, follow_redirects=True,
)

for js_name in ["search.list.v2", "search.util"]:
    resp = client.get(f"{BASE_URL}/public/search/main.kbc")
    match = re.search(rf'src=["\']([^"\']*{js_name}[^"\']*\.js)["\']', resp.text)
    if not match:
        continue
    
    js_url = match.group(1)
    if not js_url.startswith("http"):
        js_url = BASE_URL + js_url
    
    print(f"{'='*60}")
    print(f"Analyzing: {js_url.split('/')[-1]}")
    print(f"{'='*60}")
    
    resp = client.get(js_url)
    text = resp.text
    
    # Find all URL-like strings
    urls = re.findall(r'["\']([/a-zA-Z0-9._-]*(?:\.json|\.kbc|/api/)[a-zA-Z0-9._/-]*)["\']', text)
    print(f"\nAll URL-like strings ({len(urls)}):")
    for u in sorted(set(urls)):
        print(f"  {u}")
    
    # Find ajax/fetch/post patterns
    ajax = re.findall(r'(?:url|URL)\s*[:=]\s*["\']([^"\']+)["\']', text)
    print(f"\nURL assignments ({len(ajax)}):")
    for u in sorted(set(ajax)):
        print(f"  {u}")
    
    # Find $.ajax, $.post, $.get patterns
    jquery = re.findall(r'\$\.(?:ajax|post|get)\s*\(\s*["\']([^"\']+)["\']', text)
    print(f"\njQuery calls ({len(jquery)}):")
    for u in sorted(set(jquery)):
        print(f"  {u}")
    
    # Find any string containing 'search' or 'list' or 'car'
    search_strings = re.findall(r'["\']([^"\']*(?:search|carList|carSearch|totalSearch|elasticsearch)[^"\']*)["\']', text, re.I)
    print(f"\nSearch-related strings ({len(search_strings)}):")
    for s in sorted(set(search_strings))[:30]:
        print(f"  {s}")
    
    # Find function names related to search
    funcs = re.findall(r'(?:function\s+|\.)(search[A-Za-z]*|fetch[A-Za-z]*|load[A-Za-z]*|getList[A-Za-z]*)\s*\(', text)
    print(f"\nSearch-related functions ({len(funcs)}):")
    for f in sorted(set(funcs)):
        print(f"  {f}")
    
    # Look for URL construction patterns
    url_concat = re.findall(r'["\'][^"\']*["\']\s*\+\s*["\'][^"\']*\.json["\']', text)
    print(f"\nURL concatenation patterns ({len(url_concat)}):")
    for u in url_concat[:10]:
        print(f"  {u}")

client.close()
