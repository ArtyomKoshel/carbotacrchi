from __future__ import annotations

import logging
import random
import re
import string
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
_INSPECT_HTML_URL = "https://www.encar.com/md/sl/mdsl_regcar.do"
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


def _generate_random_session() -> str:
    """Generate a random session ID for FloppyData proxy."""
    return ''.join(random.choices(string.ascii_lowercase + string.digits, k=8))


# Module-level cache for generated proxies
_CACHED_PROXIES: list[str] | None = None


def _generate_floppy_proxies(count: int = 20) -> list[str]:
    """
    Generate proxy URLs using FloppyData API.
    If API key is not configured, return an empty list.
    Proxies are cached at module level to avoid regenerating on each client initialization.
    """
    global _CACHED_PROXIES

    # Return cached proxies if already generated
    if _CACHED_PROXIES is not None:
        return _CACHED_PROXIES

    if not Config.FLOPPYDATA_API_KEY:
        logger.warning("[FloppyData] API key not configured, dynamic proxy generation disabled")
        _CACHED_PROXIES = []
        return _CACHED_PROXIES

    proxies = []
    base_creds = "user-3L8YmcrVpKK3wN9W"  # Base username from provider
    password = "1TigQ7ujPds0xcv6"  # Password from provider

    for _ in range(count):
        session = _generate_random_session()
        proxy_url = (
            f"http://{base_creds}-type-residential-session-{session}"
            f"-country-US-city-New_York-rotation-15:{password}@geo.g-w.info:10080"
        )
        proxies.append(proxy_url)

    logger.info(f"[FloppyData] Generated {len(proxies)} dynamic proxy sessions (cached)")
    _CACHED_PROXIES = proxies
    return proxies


def _reset_proxy_cache() -> None:
    """Clear cached proxies so that the next call to _generate_floppy_proxies creates fresh sessions."""
    global _CACHED_PROXIES
    _CACHED_PROXIES = None
    logger.info("[FloppyData] Proxy cache cleared — next generation will create fresh sessions")


def check_floppy_balance() -> dict | None:
    """Check remaining FloppyData traffic balance. Returns balance dict or None on error."""
    if not Config.FLOPPYDATA_API_KEY:
        return None
    try:
        url = f"{Config.FLOPPY_BASE_URL}/v1/rotating/balance"
        r = httpx.get(url, headers={"X-Api-Key": Config.FLOPPYDATA_API_KEY}, timeout=10)
        r.raise_for_status()
        data = r.json()
        # Log readable summary
        for proxy_type in ("residential", "mobile", "datacenter"):
            info = data.get(proxy_type, {})
            sub = info.get("subscription", {})
            non_exp = info.get("nonExpiring", {})
            sub_gb = sub.get("gb", 0)
            non_gb = non_exp.get("gb", 0)
            expires = sub.get("expiresOn", "n/a")
            if sub_gb or non_gb:
                logger.info(f"[FloppyData] {proxy_type}: subscription={sub_gb}GB (expires {expires}), non-expiring={non_gb}GB")
        return data
    except Exception as e:
        logger.warning(f"[FloppyData] Failed to check balance: {e}")
        return None


_CLIENT_MAX_AGE = 30 * 60  # rebuild httpx.Client every 30 minutes


class EncarClient:
    def __init__(self, proxy: str | None = None):
        if Config.FLOPPYDATA_API_KEY:
            proxy_list = _generate_floppy_proxies(count=max(Config.ENCAR_WORKERS, 20))
        else:
            proxy_list = [proxy] if proxy else []
        self._proxies: list[str | None] = proxy_list if proxy_list else [None]
        self._proxy_idx: int = 0
        self._s = self._build_client(self._proxies[0])
        self._request_count: int = 0
        self._error_count: int = 0
        self._client_created_at: float = time.monotonic()

    def _build_client(self, proxy: str | None) -> httpx.Client:
        kwargs: dict = {"headers": _HEADERS, "timeout": 30, "follow_redirects": True}
        if proxy:
            kwargs["transport"] = httpx.HTTPTransport(proxy=proxy)
        return httpx.Client(**kwargs)

    def _maybe_rebuild_client(self) -> None:
        """Rebuild httpx.Client if it's older than _CLIENT_MAX_AGE to prevent stale connections."""
        age = time.monotonic() - self._client_created_at
        if age > _CLIENT_MAX_AGE:
            if Config.PROXY_DEBUG:
                logger.info(
                    f"[encar:proxy] Client age {age:.0f}s > {_CLIENT_MAX_AGE}s \u2014 "
                    f"rebuilding (requests={self._request_count}, errors={self._error_count})"
                )
            self._s.close()
            self._s = self._build_client(self._proxies[self._proxy_idx])
            self._client_created_at = time.monotonic()
            self._request_count = 0
            self._error_count = 0

    @staticmethod
    def _bump_session(proxy_url: str) -> str | None:
        """Replace -session-XXX with new random session ID (rotating proxy support)."""
        import random, string
        new_id = ''.join(random.choices(string.ascii_lowercase + string.digits, k=8))
        result, n = re.subn(r'(-session-)([^-:@]+)', rf'\g<1>{new_id}', proxy_url)
        return result if n else None

    def rotate_proxy(self) -> bool:
        """Rotate to next proxy or bump session ID on rotating proxy."""
        if Config.PROXY_DEBUG:
            age = time.monotonic() - self._client_created_at
            logger.info(
                f"[encar:proxy] rotate_proxy called | client age={age:.0f}s, "
                f"requests={self._request_count}, errors={self._error_count}"
            )
        if len(self._proxies) > 1:
            self._proxy_idx = (self._proxy_idx + 1) % len(self._proxies)
            self._s.close()
            self._s = self._build_client(self._proxies[self._proxy_idx])
            self._client_created_at = time.monotonic()
            self._request_count = 0
            self._error_count = 0
            logger.info(f"[encar:proxy] Rotated to proxy {self._proxy_idx}")
            return True
        bumped = self._bump_session(self._proxies[0]) if self._proxies[0] else None
        if bumped:
            self._proxies[0] = bumped
            self._s.close()
            self._s = self._build_client(bumped)
            self._client_created_at = time.monotonic()
            self._request_count = 0
            self._error_count = 0
            logger.info("[encar:proxy] Bumped session on rotating proxy")
            return True
        return False

    # ------------------------------------------------------------------
    def _masked_proxy(self) -> str:
        """Return current proxy URL with masked password for logging."""
        proxy_info = self._proxies[self._proxy_idx] if self._proxies else "none"
        if proxy_info and "://" in str(proxy_info):
            return re.sub(r'://([^:]+):([^@]+)@', r'://\1:***@', str(proxy_info))
        return str(proxy_info)

    def _request(self, method: str, url: str, context: str = "", **kwargs) -> httpx.Response:
        """Execute HTTP request with comprehensive logging on errors."""
        self._maybe_rebuild_client()
        self._request_count += 1
        proxy_masked = self._masked_proxy()
        client_age = time.monotonic() - self._client_created_at
        req_id = self._request_count

        try:
            t0 = time.monotonic()
            r = self._s.request(method, url, **kwargs)
            elapsed = time.monotonic() - t0
        except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout, httpx.ConnectTimeout) as e:
            self._error_count += 1
            if Config.PROXY_DEBUG:
                logger.warning(
                    f"[encar:http] {type(e).__name__} | {context}\n"
                    f"  URL: {url}\n"
                    f"  Proxy: {proxy_masked}\n"
                    f"  Proxy idx: {self._proxy_idx}/{len(self._proxies)}\n"
                    f"  Client age: {client_age:.0f}s, request #{req_id}, errors so far: {self._error_count}\n"
                    f"  Error: {e}\n"
                    f"  Error type: {type(e).__module__}.{type(e).__qualname__}\n"
                    f"  Args: {e.args}"
                )
            raise
        except Exception as e:
            self._error_count += 1
            if Config.PROXY_DEBUG:
                logger.warning(
                    f"[encar:http] UNEXPECTED {type(e).__name__} | {context}\n"
                    f"  URL: {url}\n"
                    f"  Proxy: {proxy_masked}\n"
                    f"  Client age: {client_age:.0f}s, request #{req_id}, errors so far: {self._error_count}\n"
                    f"  Error: {e}"
                )
            raise

        if not r.is_success:
            self._error_count += 1
            # 404 is expected for optional endpoints (diagnosis, inspection, etc.) — skip verbose dump
            if Config.PROXY_DEBUG and r.status_code != 404:
                body = r.text[:1000] if r.text else "<empty>"
                logger.warning(
                    f"[encar:http] {r.status_code} {r.reason_phrase} | {context}\n"
                    f"  URL: {r.url}\n"
                    f"  Proxy: {proxy_masked}\n"
                    f"  Proxy idx: {self._proxy_idx}/{len(self._proxies)}\n"
                    f"  Client age: {client_age:.0f}s, request #{req_id}, errors so far: {self._error_count}\n"
                    f"  Response time: {elapsed:.2f}s\n"
                    f"  Response headers: {dict(r.headers)}\n"
                    f"  Response body: {body}"
                )
            r.raise_for_status()
        return r

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
        r = self._request("GET", _SEARCH_URL, context=f"search offset={offset}", params=params)
        return r.json()

    def detail(self, vehicle_id: int | str) -> dict:
        url = _DETAIL_URL.format(id=vehicle_id)
        r = self._request("GET", url, context=f"detail id={vehicle_id}", params={"include": _DETAIL_INCLUDE})
        return r.json()

    def batch_details(self, vehicle_ids: list[int | str]) -> list[dict]:
        """Fetch details for up to 20 vehicles at once."""
        r = self._request("GET", _BATCH_URL, context=f"batch_details ids={len(vehicle_ids)}", params={
            "vehicleIds": ",".join(str(i) for i in vehicle_ids),
            "include": _BATCH_INCLUDE,
        })
        data = r.json()
        return data if isinstance(data, list) else data.get("vehicles", [])

    def inspection(self, vehicle_id: int | str) -> dict | None:
        url = _INSPECT_URL.format(id=vehicle_id)
        try:
            r = self._request("GET", url, context=f"inspection id={vehicle_id}")
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] inspection {vehicle_id}: {type(e).__name__}: {e}")
            return None

    def record(self, vehicle_id: int | str, plate_number: str | None = None) -> dict | None:
        """Fetch KIDI accident/ownership history. Plate optional — certified cars work without it."""
        url = _RECORD_URL.format(id=vehicle_id)
        params = {"vehicleNo": plate_number} if plate_number else {}
        try:
            r = self._request("GET", url, context=f"record id={vehicle_id}", params=params)
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] record {vehicle_id}: {type(e).__name__}: {e}")
            return None

    def diagnosis(self, vehicle_id: int | str) -> dict | None:
        """Fetch Encar internal diagnosis (body panel check, certified cars only).

        NOTE: Currently unused — endpoint returns 404 via proxies.
        Kept for potential future re-enablement.
        """
        url = _DIAG_URL.format(id=vehicle_id)
        try:
            r = self._request("GET", url, context=f"diagnosis id={vehicle_id}")
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] diagnosis {vehicle_id}: {type(e).__name__}: {e}")
            return None

    def sellingpoint(self, vehicle_id: int | str) -> dict | None:
        """Fetch selling-point diagnosis (includes drive_type via uniqueOptionPhotos)."""
        url = _SELLING_URL.format(id=vehicle_id)
        try:
            r = self._request("GET", url, context=f"sellingpoint id={vehicle_id}")
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] sellingpoint {vehicle_id}: {type(e).__name__}: {e}")
            return None

    def verification(self, vehicle_id: int | str) -> dict | None:
        """Fetch dealer verification data: keys count, tire depth, tinting, extra photos."""
        url = _VERIFY_URL.format(id=vehicle_id)
        try:
            r = self._request("GET", url, context=f"verification id={vehicle_id}", params={"optionIds": _VERIFY_OPTS})
            return r.json()
        except Exception as e:
            logger.debug(f"[encar] verification {vehicle_id}: {type(e).__name__}: {e}")
            return None

    @staticmethod
    def verify_photo_url(key: str) -> str:
        return f"{_VERIFY_CDN}{key}"

    @staticmethod
    def photo_url(path: str) -> str:
        return f"{_PHOTO_CDN}{path}?impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center"

    def inspection_html(self, carid: int | str) -> str | None:
        """Fetch the human-readable inspection report HTML page (www.encar.com)."""
        try:
            r = self._request("GET", _INSPECT_HTML_URL, context=f"inspection_html id={carid}",
                              params={"method": "inspectionViewNew", "carid": str(carid)})
            return r.text
        except Exception as e:
            logger.debug(f"[encar] inspection_html {carid}: {type(e).__name__}: {e}")
            return None

    def close(self) -> None:
        self._s.close()
