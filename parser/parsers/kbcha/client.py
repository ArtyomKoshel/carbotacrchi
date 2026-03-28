from __future__ import annotations

import logging
import time as _time

import httpx

from config import Config

logger = logging.getLogger(__name__)

BASE_URL = "https://www.kbchachacha.com"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html, */*; q=0.01",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "X-Requested-With": "XMLHttpRequest",
}


class KBChaClient:
    def __init__(self):
        proxy = Config.KBCHA_PROXY or None
        transport = httpx.HTTPTransport(proxy=proxy) if proxy else None
        self._client = httpx.Client(
            headers=HEADERS,
            timeout=30.0,
            follow_redirects=True,
            transport=transport,
        )

    def fetch_list_page(self, maker_code: str, page: int) -> str:
        url = f"{BASE_URL}/public/search/list.empty"
        params = {"makerCode": maker_code, "page": str(page)}

        t0 = _time.monotonic()
        resp = self._client.get(url, params=params)
        elapsed = _time.monotonic() - t0

        resp.raise_for_status()
        logger.debug(f"[kbcha:http] list.empty maker={maker_code} p={page} "
                      f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def fetch_detail_page(self, car_seq: str) -> str:
        url = f"{BASE_URL}/public/car/detail.kbc"
        params = {"carSeq": car_seq}

        t0 = _time.monotonic()
        resp = self._client.get(url, params=params)
        elapsed = _time.monotonic() - t0

        resp.raise_for_status()
        logger.debug(f"[kbcha:http] detail carSeq={car_seq} "
                      f"-> {resp.status_code} in {elapsed:.2f}s ({len(resp.content)} bytes)")
        return resp.text

    def close(self):
        self._client.close()
