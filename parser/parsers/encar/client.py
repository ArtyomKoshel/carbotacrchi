from __future__ import annotations

import logging
import time

import httpx

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
_BATCH_INCLUDE = "SPEC,ADVERTISEMENT,PHOTOS,CATEGORY,MANAGE,CONTACT,VIEW"

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
        kwargs: dict = {"headers": _HEADERS, "timeout": 30, "follow_redirects": True}
        if proxy:
            kwargs["transport"] = httpx.HTTPTransport(proxy=proxy)
        self._s = httpx.Client(**kwargs)

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
