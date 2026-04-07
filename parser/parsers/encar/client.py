from __future__ import annotations

import logging
import re
import time

import httpx

from config import Config

logger = logging.getLogger(__name__)

_SEARCH_URL  = "https://api.encar.com/search/car/list/mobile"
_SEARCH_Q    = "(And.Hidden.N._.CarType.A.)"
_DETAIL_URL  = "https://api.encar.com/v1/readside/vehicle/{id}"
_BATCH_URL   = "https://api.encar.com/v1/readside/vehicles"
_INSPECT_URL = "https://api.encar.com/v1/readside/inspection/vehicle/{id}"
_RECORD_URL   = "https://api.encar.com/v1/readside/record/vehicle/{id}/open"
_SELLING_URL  = "https://api.encar.com/v1/readside/diagnosis/vehicle/{id}/sellingpoint"
_VERIFY_URL   = "https://api.encar.com/verification/{id}/simple"
_VERIFY_OPTS  = "10,16,327,328,329,330,1,85,332"   # keys, tinting, tire x4, photos
_DIAG_URL     = "https://api.encar.com/v1/readside/diagnosis/vehicle/{id}"
_PHOTO_CDN    = "https://ci.encar.com"
_VERIFY_CDN   = "https://imgcar.encar.com"

_DETAIL_INCLUDE = (
    "ADVERTISEMENT,CATEGORY,CONDITION,CONTACT,MANAGE,OPTIONS,PHOTOS,SPEC,VIEW"
)
_BATCH_INCLUDE = "SPEC,ADVERTISEMENT,PHOTOS,CATEGORY,MANAGE,CONTACT,CONDITION,OPTIONS,VIEW"

_HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/146.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json, text/javascript, */*; q=0.01",
    "Referer": "https://m.encar.com/",
    "Origin":  "https://m.encar.com",
}


class EncarClient:
    def __init__(self, proxy: str | None = None):
        # Build proxy list: ENCAR_PROXY_LIST takes precedence over single ENCAR_PROXY / arg
        proxy_list = Config.ENCAR_PROXY_LIST or ([proxy or Config.ENCAR_PROXY] if (proxy or Config.ENCAR_PROXY) else [])
        self._proxies: list[str | None] = proxy_list if proxy_list else [None]
        self._proxy_idx: int = 0
        self._s = self._build_client(self._proxies[0])

    def _build_client(self, proxy: str | None) -> httpx.Client:
        kwargs: dict = {"headers": _HEADERS, "timeout": 30, "follow_redirects": True}
        if proxy:
            kwargs["transport"] = httpx.HTTPTransport(proxy=proxy)
        return httpx.Client(**kwargs)

    @staticmethod
    def _bump_session(proxy_url: str) -> str | None:
        """Replace -session-XXX with new random session ID (rotating proxy support)."""
        import random, string
        new_id = ''.join(random.choices(string.ascii_lowercase + string.digits, k=8))
        result, n = re.subn(r'(-session-)([^-:@]+)', rf'\g<1>{new_id}', proxy_url)
        return result if n else None

    def rotate_proxy(self) -> bool:
        """Rotate to next proxy or bump session ID on rotating proxy."""
        if len(self._proxies) > 1:
            self._proxy_idx = (self._proxy_idx + 1) % len(self._proxies)
            self._s.close()
            self._s = self._build_client(self._proxies[self._proxy_idx])
            logger.info(f"[encar:proxy] Rotated to proxy {self._proxy_idx}")
            return True
        bumped = self._bump_session(self._proxies[0]) if self._proxies[0] else None
        if bumped:
            self._proxies[0] = bumped
            self._s.close()
            self._s = self._build_client(bumped)
            logger.info("[encar:proxy] Bumped session on rotating proxy")
            return True
        return False

    # ------------------------------------------------------------------
    def search(
        self,
        query: str = _SEARCH_Q,
        offset: int = 0,
        count: int = 20,
    ) -> dict:
        params = {
            "count": "true",
            "q": query,
            "sr": f"|ModifiedDate|{offset}|{count}",
        }
        r = self._s.get(_SEARCH_URL, params=params)
        r.raise_for_status()
        return r.json()

    def detail(self, vehicle_id: int | str) -> dict:
        url = _DETAIL_URL.format(id=vehicle_id)
        r = self._s.get(url, params={"include": _DETAIL_INCLUDE})
        r.raise_for_status()
        return r.json()

    def batch_details(self, vehicle_ids: list[int | str]) -> list[dict]:
        """Fetch details for up to 20 vehicles at once."""
        r = self._s.get(_BATCH_URL, params={
            "vehicleIds": ",".join(str(i) for i in vehicle_ids),
            "include": _BATCH_INCLUDE,
        })
        r.raise_for_status()
        data = r.json()
        return data if isinstance(data, list) else data.get("vehicles", [])

    def inspection(self, vehicle_id: int | str) -> dict | None:
        url = _INSPECT_URL.format(id=vehicle_id)
        try:
            r = self._s.get(url)
            r.raise_for_status()
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] inspection {vehicle_id}: {e}")
            return None

    def record(self, vehicle_id: int | str, plate_number: str | None = None) -> dict | None:
        """Fetch KIDI accident/ownership history. Plate optional — certified cars work without it."""
        url = _RECORD_URL.format(id=vehicle_id)
        params = {"vehicleNo": plate_number} if plate_number else {}
        try:
            r = self._s.get(url, params=params)
            r.raise_for_status()
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] record {vehicle_id}: {e}")
            return None

    def diagnosis(self, vehicle_id: int | str) -> dict | None:
        """Fetch Encar internal diagnosis (body panel check, certified cars only)."""
        url = _DIAG_URL.format(id=vehicle_id)
        try:
            r = self._s.get(url)
            r.raise_for_status()
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] diagnosis {vehicle_id}: {e}")
            return None

    def sellingpoint(self, vehicle_id: int | str) -> dict | None:
        """Fetch selling-point diagnosis (includes drive_type via uniqueOptionPhotos)."""
        url = _SELLING_URL.format(id=vehicle_id)
        try:
            r = self._s.get(url)
            r.raise_for_status()
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] sellingpoint {vehicle_id}: {e}")
            return None

    def verification(self, vehicle_id: int | str) -> dict | None:
        """Fetch dealer verification data: keys count, tire depth, tinting, extra photos."""
        url = _VERIFY_URL.format(id=vehicle_id)
        try:
            r = self._s.get(url, params={"optionIds": _VERIFY_OPTS})
            r.raise_for_status()
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] verification {vehicle_id}: {e}")
            return None

    @staticmethod
    def verify_photo_url(key: str) -> str:
        return f"{_VERIFY_CDN}{key}"

    @staticmethod
    def photo_url(path: str) -> str:
        return f"{_PHOTO_CDN}{path}?impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center"

    def close(self) -> None:
        self._s.close()
