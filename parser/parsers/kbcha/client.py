from __future__ import annotations

import logging
import random
import re
import string
import time as _time
from urllib.parse import urlparse

import httpx
from httpx import Timeout as _Timeout

from config import Config

logger = logging.getLogger(__name__)

BASE_URL = "https://www.kbchachacha.com"
CARMODOO_URL = "https://ck.carmodoo.com"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html, */*; q=0.01",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "X-Requested-With": "XMLHttpRequest",
}

_PAGE_HEADERS = {
    "User-Agent": HEADERS["User-Agent"],
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
    "Accept-Language": HEADERS["Accept-Language"],
    "Accept-Encoding": "gzip, deflate, br",
    "Upgrade-Insecure-Requests": "1",
    "Sec-Fetch-Dest": "document",
    "Sec-Fetch-Mode": "navigate",
    "Sec-Fetch-Site": "same-origin",
    "Sec-Fetch-User": "?1",
    "Cache-Control": "max-age=0",
    "Connection": "keep-alive",
}


class KBChaClient:
    def __init__(self):
        # Build proxy list: KBCHA_PROXY_LIST takes precedence over single KBCHA_PROXY
        proxy_list = Config.KBCHA_PROXY_LIST or ([Config.KBCHA_PROXY] if Config.KBCHA_PROXY else [])
        self._proxies: list[str | None] = proxy_list if proxy_list else [None]
        self._proxy_idx: int = 0
        self._last_list_url: str = f"{BASE_URL}/public/search/main.kbc"
        self._client = self._build_client(self._proxies[0])

    def _build_client(self, proxy: str | None) -> httpx.Client:
        transport = httpx.HTTPTransport(proxy=proxy) if proxy else None
        return httpx.Client(
            headers=HEADERS,
            timeout=_Timeout(connect=30.0, read=90.0, write=15.0, pool=10.0),
            follow_redirects=True,
            transport=transport,
        )

    @staticmethod
    def _new_session_id() -> str:
        return ''.join(random.choices(string.ascii_lowercase + string.digits, k=8))

    @staticmethod
    def _bump_session(proxy_url: str | None) -> str | None:
        """Replace -session-XXX with a new random session ID (Floppydata / rotating proxy)."""
        if not proxy_url:
            return None
        new_id = KBChaClient._new_session_id()
        result, n = re.subn(r'(-session-)([^-:@]+)', rf'\g<1>{new_id}', proxy_url)
        return result if n else None

    def rotate_proxy(self) -> bool:
        """Switch to the next proxy in the list and reset the session. Returns True if rotated.
        For single-proxy Floppydata URLs with -session- pattern, bumps the session ID instead."""
        real_proxies = [p for p in self._proxies if p]
        if not real_proxies:
            return False
        if len(real_proxies) > 1 and len(self._proxies) > 1:
            old_idx = self._proxy_idx
            self._proxy_idx = (self._proxy_idx + 1) % len(self._proxies)
            self._client.close()
            self._client = self._build_client(self._proxies[self._proxy_idx])
            logger.info(f"[kbcha:proxy] Rotated proxy {old_idx} -> {self._proxy_idx} "
                        f"({self._proxies[self._proxy_idx]})")
            return True

        # Single rotating proxy (Floppydata) — bump session ID to force new IP
        bumped = self._bump_session(self._proxies[0])
        if bumped:
            self._proxies[0] = bumped
            self._client.close()
            self._client = self._build_client(bumped)
            logger.info(f"[kbcha:proxy] Bumped session on rotating proxy -> new IP requested")
            return True

        return False

    def _rebuild_client(self) -> None:
        """Close and recreate the HTTP client with the current proxy (fresh connection pool)."""
        try:
            self._client.close()
        except Exception:
            pass
        self._client = self._build_client(self._proxies[self._proxy_idx] if self._proxies else None)

    def _get(self, url: str, params: dict | None = None,
             headers: dict | None = None) -> httpx.Response:
        """Wrapper around client.get that handles network/proxy errors with retry."""
        try:
            return self._client.get(url, params=params, headers=headers)
        except httpx.ProxyError as e:
            logger.warning(f"[kbcha:proxy] ProxyError ({e}) — rotating and retrying...")
            self.rotate_proxy()
            _time.sleep(2)
            return self._client.get(url, params=params, headers=headers)
        except (httpx.RemoteProtocolError, httpx.ReadError, httpx.ConnectError) as e:
            logger.warning(f"[kbcha:proxy] {type(e).__name__} ({e}) — rebuilding client and retrying...")
            self._rebuild_client()
            _time.sleep(2)
            return self._client.get(url, params=params, headers=headers)

    def warmup(self) -> None:
        """Visit homepage → search page → list page to establish a real browser session."""
        steps = [
            (f"{BASE_URL}/", None),
            (f"{BASE_URL}/public/search/main.kbc", f"{BASE_URL}/"),
            (f"{BASE_URL}/public/search/list.kbc", f"{BASE_URL}/public/search/main.kbc"),
        ]
        try:
            for url, referer in steps:
                h = {
                    **_PAGE_HEADERS,
                    "Sec-Fetch-Site": "none" if referer is None else "same-origin",
                }
                if referer:
                    h["Referer"] = referer
                resp = self._client.get(url, headers=h)
                logger.debug(f"[kbcha:warmup] {url} -> {resp.status_code} ({len(resp.content)} bytes)")
                _time.sleep(1.2)
        except Exception as e:
            logger.warning(f"[kbcha:warmup] failed: {e}")

    def fetch_list_page(self, maker_code: str, page: int) -> str:
        url = f"{BASE_URL}/public/search/list.empty"
        params = {"makerCode": maker_code, "page": str(page)}

        t0 = _time.monotonic()
        resp = self._get(url, params=params)
        elapsed = _time.monotonic() - t0

        resp.raise_for_status()
        # Store the effective URL so detail page can use it as Referer
        self._last_list_url = str(resp.url)
        logger.debug(f"[kbcha:http] list.empty maker={maker_code} p={page} "
                      f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_detail_page(self, car_seq: str) -> str:
        url = f"{BASE_URL}/public/car/detail.kbc"
        params = {"carSeq": car_seq}
        headers = {
            **_PAGE_HEADERS,
            "Referer": self._last_list_url,
        }
        t0 = _time.monotonic()
        resp = self._get(url, params=params, headers=headers)
        elapsed = _time.monotonic() - t0

        resp.raise_for_status()
        logger.debug(f"[kbcha:http] detail carSeq={car_seq} "
                      f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_carmodoo(self, check_num: str) -> str:
        url = f"{CARMODOO_URL}/carCheck/carmodooPrint.do"
        params = {"print": "0", "checkNum": check_num}
        headers = {
            "User-Agent": HEADERS["User-Agent"],
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "ko-KR,ko;q=0.9",
            "Referer": "https://www.kbchachacha.com/",
        }
        t0 = _time.monotonic()
        resp = self._get(url, params=params, headers=headers)
        elapsed = _time.monotonic() - t0
        resp.raise_for_status()
        logger.debug(f"[kbcha:http] carmodoo checkNum={check_num} "
                      f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_km_analysis(self, car_seq: str) -> str:
        url = f"{BASE_URL}/public/layer/car/km/analysis/info.kbc"
        params = {"carSeq": car_seq}
        headers = {"Referer": f"{BASE_URL}/public/car/detail.kbc?carSeq={car_seq}"}
        t0 = _time.monotonic()
        resp = self._get(url, params=params, headers=headers)
        elapsed = _time.monotonic() - t0
        resp.raise_for_status()
        logger.debug(f"[kbcha:http] km_analysis carSeq={car_seq} "
                     f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_basic_info(self, car_seq: str) -> str:
        url = f"{BASE_URL}/public/layer/car/detail/basic/info/view.kbc"
        params = {"carSeq": car_seq}
        headers = {"Referer": f"{BASE_URL}/public/car/detail.kbc?carSeq={car_seq}"}
        t0 = _time.monotonic()
        resp = self._get(url, params=params, headers=headers)
        elapsed = _time.monotonic() - t0
        resp.raise_for_status()
        logger.debug(f"[kbcha:http] basic_info carSeq={car_seq} "
                     f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_kb_inspection(self, car_seq: str) -> str:
        url = f"{BASE_URL}/public/layer/car/check/info.kbc"
        params = {
            "layerId": "layerCarCheckInfo",
            "carSeq": car_seq,
            "diagCarYn": "N",
            "diagCarSeq": "",
            "premiumCarYn": "N",
        }
        headers = {
            "User-Agent": HEADERS["User-Agent"],
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "ko-KR,ko;q=0.9",
            "Referer": f"{BASE_URL}/public/car/detail.kbc?carSeq={car_seq}",
        }
        t0 = _time.monotonic()
        resp = self._get(url, params=params, headers=headers)
        elapsed = _time.monotonic() - t0
        resp.raise_for_status()
        logger.debug(f"[kbcha:http] kb_inspection carSeq={car_seq} "
                     f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_external_report(self, report_url: str, referer: str | None = None) -> str:
        url = (report_url or "").strip()
        if not url:
            raise ValueError("empty report_url")
        if url.startswith("//"):
            url = f"https:{url}"
        if not url.startswith(("http://", "https://")):
            url = f"https://{url.lstrip('/')}"

        headers = {
            "User-Agent": HEADERS["User-Agent"],
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8",
            "Referer": referer or f"{BASE_URL}/",
        }

        t0 = _time.monotonic()
        resp = self._get(url, headers=headers)
        elapsed = _time.monotonic() - t0
        resp.raise_for_status()

        host = urlparse(url).netloc
        logger.debug(
            f"[kbcha:http] external host={host} "
            f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)"
        )
        return resp.text

    def close(self):
        self._client.close()
