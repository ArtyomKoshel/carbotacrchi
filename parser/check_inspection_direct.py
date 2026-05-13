"""
Check if autocafe/mpark inspection URLs work without proxy (direct connection).
Usage: python check_inspection_direct.py <url1> <url2> ...
Or edit URLS list below.
"""
from __future__ import annotations
import sys, time
import httpx

URLS: list[str] = [
    # Paste URLs from SQL query here, or pass as CLI args
]

if len(sys.argv) > 1:
    URLS = sys.argv[1:]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8",
    "Accept-Encoding": "gzip, deflate, br",
}

BOT_MARKERS = [
    "bot", "captcha", "blocked", "403", "Access Denied",
    "로봇", "자동화", "차단", "인증", "reCAPTCHA",
    "cloudflare", "ddos-guard", "security check",
]

def check(url: str) -> None:
    print(f"\n{'='*60}")
    print(f"URL: {url}")
    try:
        t0 = time.monotonic()
        with httpx.Client(timeout=15, follow_redirects=True) as client:
            resp = client.get(url, headers=HEADERS)
        elapsed = time.monotonic() - t0

        print(f"  Status : {resp.status_code}")
        print(f"  Size   : {len(resp.content) / 1024:.1f} KB")
        print(f"  Time   : {elapsed:.2f}s")
        print(f"  Type   : {resp.headers.get('content-type', '?')}")

        text = resp.text.lower()
        hits = [m for m in BOT_MARKERS if m.lower() in text]
        if hits:
            print(f"  ⚠️  BOT MARKERS FOUND: {hits}")
        elif resp.status_code == 200 and len(resp.content) > 5000:
            print(f"  ✅  OK — looks like real content")
        elif resp.status_code == 200:
            print(f"  ⚠️  200 but very small ({len(resp.content)} bytes) — possible bot block")
        else:
            print(f"  ❌  HTTP {resp.status_code}")

    except Exception as e:
        print(f"  ❌  Error: {e}")

if not URLS:
    print("No URLs provided. Pass as CLI args or edit URLS list in script.")
    print("Example: python check_inspection_direct.py https://www.autocafe.co.kr/...")
    sys.exit(1)

for url in URLS:
    check(url)

print(f"\n{'='*60}")
print("Done.")
