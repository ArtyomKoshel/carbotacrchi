"""API access with bounded time, requests and same-session retries; one Che168Api per proxy session."""

from __future__ import annotations

import json
import os
import re
import threading
import time
import uuid
from collections.abc import Callable
from dataclasses import dataclass, field
from importlib import import_module
from typing import Any
from urllib.parse import parse_qs, quote, urlsplit

from collector.adapters.che168.signing import Signer

SEARCH_URL = "https://api2scsou.che168.com/api/v11/search"
DETAIL_URL = "https://apiuscdt.che168.com/apic/v2/car/getcarinfo"
# the card's highlighted options (亮点配置), as the mobile site asks for them: unsigned, _appid 2sc.m
OPTIONS_URL = "https://apipcmusc.che168.com/v1/car/getusedcaroptiondata"
# Chaboshi (查博士) inspection reports a card links in cxc.reporturl: the report page (api.chaboshi.cn)
# and the data request its own script makes with the page's parameters (analysis/che168,
# enrichment-plan section 4). Nothing is signed here: the page's own parameters are sent as given.
CHABOSHI_PAGE_HOST = "api.chaboshi.cn"
CHABOSHI_REPORTS = {
    "/insurance/report": ("fuel", "https://app.chaboshi.cn/wap/insurance/getDetectionFuelVehicleReportData"),
    "/platform/v2/insurance/auth_report_h5": (
        "ev",
        "https://app.chaboshi.cn/wap/insurance/getDetectionNewEnergyReportData",
    ),
}
_INIT_PARAMS = re.compile(r"window\.__INIT_PARAMS__\s*=\s*\{(.*?)\};", re.S)
_INIT_PARAM = re.compile(r"(\w+)\s*:\s*'([^']*)'")


class CollectionError(Exception):
    """Safe reason only; never include remote bodies, cookies or transport exception text.

    cause: a finer safe reason for the run log (read_timeout, proxy_error, http_502, …).
    """

    def __init__(self, kind: str, cause: str | None = None) -> None:
        self.kind = kind
        self.cause = cause
        super().__init__(kind)


# curl_cffi exception class → a safe cause; the first match in the class's MRO wins
_TRANSPORT_CAUSES = (
    ("ConnectTimeout", "connect_timeout"),
    ("ReadTimeout", "read_timeout"),
    ("ProxyError", "proxy_error"),
    ("SSLError", "tls_error"),
    ("DNSError", "dns_error"),
    ("Timeout", "timeout"),
    ("ConnectionError", "connection_error"),
)


# A proxy exit that does not answer: Che168 answers in 0.4-3 s (run 30: 3.3 s at most), a request
# still open after TIMEOUT_S hangs on that exit. The retry goes through a fresh session, as
# Encar's fetcher does (COLLECTOR_HTTP_TIMEOUT), instead of waiting on the same exit again.
TIMEOUT_S = 10.0
# a bad proxy exit: retried on a fresh session, not the same exit (run 32: three tls_error in a row on one)
STUCK_EXIT_CAUSES = frozenset(
    {"timeout", "read_timeout", "connect_timeout", "proxy_error", "tls_error", "connection_error"}
)


def transport_cause(exc: BaseException) -> str:
    names = {cls.__name__ for cls in type(exc).__mro__}
    return next((cause for name, cause in _TRANSPORT_CAUSES if name in names), "network_error")


def identifier(value: Any) -> str:
    text = str(value)
    if isinstance(value, bool) or not re.fullmatch(r"[1-9][0-9]{0,17}", text):
        raise CollectionError("invalid_vehicle_id")
    return text


def proxy_url(session: str) -> str:
    user, password, host = (
        os.environ.get(k, "")
        for k in (
            "FLOPPY_USERNAME",
            "FLOPPY_PASSWORD",
            "FLOPPY_HOST",
        )
    )
    if not user or not password or not re.fullmatch(r"[A-Za-z0-9.-]+:[0-9]{1,5}", host):
        raise CollectionError("proxy_configuration_missing_or_invalid")
    if not re.fullmatch(r"[a-zA-Z0-9]{1,64}", session):
        raise CollectionError("invalid_proxy_session")
    username = f"{user}-type-residential-session-{session}-country-CN-rotation-15"
    return f"http://{quote(username, safe='')}:{quote(password, safe='')}@{host}"


@dataclass(frozen=True)
class Reply:
    status: int
    body: bytes


class CurlTransport:
    def __init__(self, session: str, timeout: float = TIMEOUT_S) -> None:
        self.session = self._session(session)
        self.timeout = timeout

    @staticmethod
    def _session(session: str) -> Any:
        url = proxy_url(session)
        requests = import_module("curl_cffi.requests")
        client = requests.Session(impersonate="chrome")
        client.trust_env = False
        client.proxies.update({"http": url, "https": url})
        client.headers.update(
            {
                "Referer": "https://m.che168.com/carlist/index",
                "Origin": "https://m.che168.com",
            }
        )
        return client

    def rotate(self) -> None:
        # Like Encar's ProxyPool.refresh: ask FloppyData for a new session identifier.
        # A fresh HTTP client also discards the previous connections and cookies.
        replacement = self._session("che" + uuid.uuid4().hex[:16])
        self.session.close()
        self.session = replacement

    def request(self, url: str, params: dict[str, str]) -> Reply:
        try:
            response = self.session.get(url, params=params, timeout=self.timeout, allow_redirects=False)
        except Exception as exc:
            raise CollectionError("network_error", transport_cause(exc)) from None
        return Reply(response.status_code, response.content)

    def post(self, url: str, data: dict[str, str], headers: dict[str, str]) -> Reply:
        try:
            response = self.session.post(
                url, data=data, headers=headers, timeout=self.timeout, allow_redirects=False
            )
        except Exception as exc:
            raise CollectionError("network_error", transport_cause(exc)) from None
        return Reply(response.status_code, response.content)

    def close(self) -> None:
        self.session.close()


@dataclass(frozen=True)
class SearchPage:
    number: int
    page_count: int
    total: int
    ids: tuple[str, ...]
    # what the list itself says of each car (price, mileage, photo count): a stored card
    # whose facts still match need not be fetched again (like Encar's listing facts)
    facts: dict[str, tuple[str, ...]] = field(default_factory=dict)
    # the list's own name and mileage per car, for the early pass of the selection rules
    cars: dict[str, dict[str, Any]] = field(default_factory=dict)


def list_facts(car: dict[str, Any]) -> tuple[str, ...]:
    return tuple(f"{key}={car.get(key)}" for key in ("price", "mileage", "photocount"))


@dataclass(frozen=True)
class RotationPolicy:
    every_pages: int = 2
    max_rotations: int = 8
    max_challenge_rotations: int = 3
    every_cards: int = 0  # a card session's planned rotation (collector serve); 0 = only on a challenge
    # a list session's planned rotation by its own searches (collector serve reads pages in parallel,
    # so one session's page numbers are not consecutive); 0 = by page number (every_pages)
    every_searches: int = 0


class RequestBudget:
    """One request limit shared by the sessions of a run (each session runs in its own thread)."""

    def __init__(self, limit: int) -> None:
        self.limit = limit
        self.used = 0
        self._lock = threading.Lock()

    def take(self) -> None:
        with self._lock:
            if self.used >= self.limit:
                raise CollectionError("request_limit")
            self.used += 1


class Che168Api:
    def __init__(
        self,
        request: Callable[[str, dict[str, str]], Reply],
        signer: Signer,
        *,
        delay: float = 3,
        max_requests: int = 500,
        max_seconds: float = 1800,
        retries: int = 1,
        sleep: Callable[[float], None] = time.sleep,
        clock: Callable[[], float] = time.monotonic,
        rotate: Callable[[], None] | None = None,
        rotation: RotationPolicy | None = None,
    ) -> None:
        self.request = request
        # the transport's POST (the Chaboshi report's data request); a run sets it like `budget`
        self.post: Callable[[str, dict[str, str], dict[str, str]], Reply] | None = None
        self.signer = signer
        self.delay, self.max_requests, self.retries = delay, max_requests, retries
        self.sleep, self.clock = sleep, clock
        self.deadline = clock() + max_seconds
        self.next_request_at = clock()
        self.requests = 0
        self.response_bytes = 0
        self.request_seconds = 0.0  # time spent waiting for answers, pauses excluded
        self.retries_used = 0
        self.retry_reasons: dict[str, int] = {}  # every failed attempt of a retried kind, by cause
        self.max_request_s = 0.0
        self.cards = 0
        self.budget: RequestBudget | None = None  # set: the run's shared limit replaces max_requests
        self.rotate = rotate
        self.rotation = rotation or RotationPolicy()
        self.rotations = 0
        self.challenge_rotations = 0
        self.last_rotated_page = 0
        self.searches = 0
        self.last_error: str | None = None

    def _rotate(self, *, challenge: bool) -> None:
        if self.rotations >= self.rotation.max_rotations:
            raise CollectionError("proxy_rotation_limit")
        if challenge and self.challenge_rotations >= self.rotation.max_challenge_rotations:
            raise CollectionError("challenge_rotation_limit")
        if self.rotate is None:
            raise CollectionError("rotation_unavailable")
        self.rotate()
        self.rotations += 1
        self.challenge_rotations += int(challenge)

    def _request(
        self, url: str, params: dict[str, str], form: tuple[dict[str, str], dict[str, str]] | None = None
    ) -> Reply:
        """form: (data, headers) of a POST, else a GET with params."""
        if self.budget is None and self.requests >= self.max_requests:
            raise CollectionError("request_limit")
        wait = max(0.0, self.next_request_at - self.clock())
        if self.clock() + wait >= self.deadline:
            raise CollectionError("time_limit")
        if self.budget is not None:
            self.budget.take()
        self.sleep(wait)
        self.requests += 1
        started = self.clock()
        try:
            if form is None:
                reply = self.request(url, params)
            elif self.post is None:
                raise CollectionError("post_unavailable")
            else:
                reply = self.post(url, *form)
            self.response_bytes += len(reply.body)
            return reply
        finally:
            took = self.clock() - started
            self.request_seconds += took
            self.max_request_s = max(self.max_request_s, took)
            self.next_request_at = self.clock() + self.delay

    def _get(
        self,
        url: str,
        params: dict[str, str],
        *,
        signed: bool = True,
        decode: Callable[[Reply], Any] | None = None,
        form: tuple[dict[str, str], dict[str, str]] | None = None,
    ) -> Any:
        transient_failures = 0
        while True:
            try:
                reply = self._request(url, self.signer.sign(params) if signed else params, form)
                if reply.status in (500, 502, 503, 504):
                    raise CollectionError("temporary_http_error", f"http_{reply.status}")
                return (decode or decode_reply)(reply)
            except CollectionError as exc:
                self.last_error = exc.kind
                if exc.kind in ("challenge", "access_blocked") and self.rotate is not None:
                    self._rotate(challenge=True)
                    continue  # exact same request, without advancing the checkpoint
                if exc.kind in ("network_error", "temporary_http_error"):
                    cause = exc.cause or exc.kind
                    self.retry_reasons[cause] = self.retry_reasons.get(cause, 0) + 1
                if (
                    exc.kind in ("network_error", "temporary_http_error")
                    and transient_failures < self.retries
                ):
                    transient_failures += 1
                    self.retries_used += 1
                    if exc.cause in STUCK_EXIT_CAUSES and self.rotate is not None:
                        self._rotate(challenge=False)  # not the same stuck exit again
                    continue
                raise

    def search(self, page: int, pagesize: int) -> SearchPage:
        own = self.rotation.every_searches
        if self.rotate is not None and own > 0 and self.searches > 0 and self.searches % own == 0:
            self._rotate(challenge=False)
        self.searches += 1
        every = self.rotation.every_pages
        if (
            self.rotate is not None
            and every > 0
            and page > 1
            and (page - 1) % every == 0
            and self.last_rotated_page != page
        ):
            self._rotate(challenge=False)
            self.last_rotated_page = page
        data = self._get(
            SEARCH_URL,
            {
                "pageindex": str(page),
                "pagesize": str(pagesize),
                "ssnew": "1",
                "pid": "0",
                "cid": "0",
            },
        )
        if data.get("pageindex") != page or data.get("pagesize") != pagesize:
            raise CollectionError("pagination_mismatch")
        total, count, cars = data.get("totalcount"), data.get("pagecount"), data.get("carlist")
        if (
            type(total) is not int
            or type(count) is not int
            or total < 0
            or count < 0
            or not isinstance(cars, list)
            or any(not isinstance(c, dict) for c in cars)
        ):
            raise CollectionError("search_schema_changed")
        if total > 0 and (not cars or count < page):
            raise CollectionError("incomplete_search_page")
        if total == 0 and (cars or count != 0):
            raise CollectionError("inconsistent_search_total")
        ids = tuple(identifier(c.get("infoid")) for c in cars)
        early = {
            i: {"carname": c.get("carname"), "mileage": c.get("mileage")}
            for i, c in zip(ids, cars, strict=True)
        }
        return SearchPage(
            page, count, total, ids, {i: list_facts(c) for i, c in zip(ids, cars, strict=True)}, early
        )

    def detail(self, infoid: str) -> dict[str, Any]:
        every = self.rotation.every_cards
        if self.rotate is not None and every > 0 and self.cards > 0 and self.cards % every == 0:
            self._rotate(challenge=False)
        self.cards += 1
        data: dict[str, Any] = self._get(DETAIL_URL, {"infoid": identifier(infoid)})
        if identifier(data.get("infoid")) != infoid:
            raise CollectionError("detail_id_mismatch")
        return data

    def options(self, infoid: str) -> list[tuple[str, str]]:
        """The card's highlighted options: (optionid, Chinese name); [] when che168 lists none.

        Not the whole equipment and no paid packages (extra-fields-report-2026-10-30): an option
        missing here is unknown, not absent."""
        params = {"_appid": "2sc.m", "infoid": identifier(infoid)}
        items = self._get(OPTIONS_URL, params, signed=False, decode=decode_list)
        options = []
        for item in items:
            oid = item.get("optionid") if isinstance(item, dict) else None
            if not (type(oid) is int and oid > 0) and not (isinstance(oid, str) and oid.isdigit()):
                raise CollectionError("options_schema_changed")
            name = item.get("optionname")
            options.append((str(oid), name.strip() if isinstance(name, str) else ""))
        return options

    def chaboshi(self, url: str) -> tuple[str, dict[str, Any]]:
        """The Chaboshi report a card links: (fuel | ev, the report as its page receives it).

        Two requests: the report page for its parameters, then the data request the page's own
        script makes with them. Raises report_* errors; the caller writes the car without it."""
        kind, data_url = CHABOSHI_REPORTS[urlsplit(url).path]
        params = self._get(url, {}, signed=False, decode=init_params)
        if params.get("orderNo"):
            params.pop("orderno", None)  # the page sends one of the two
        headers = {
            "Sign": params.get("sign", ""),
            "signparamname": params.get("signparamname", ""),
            "Referer": url,
            "Origin": f"https://{CHABOSHI_PAGE_HOST}",
        }
        body = self._get(data_url, {}, signed=False, decode=decode_chaboshi, form=(params, headers))
        if isinstance(body, str):  # code 5: the report is a file the page fetches by this link
            link = urlsplit(body)
            if link.scheme != "https" or not (link.hostname or "").endswith(".chaboshi.cn"):
                raise CollectionError("report_schema_changed")
            body = self._get(body, {}, signed=False, decode=decode_json_object)
        info = body.get("reportInfo") if isinstance(body, dict) else None
        if not isinstance(info, dict) or not info:
            raise CollectionError("report_schema_changed")
        return kind, info


def chaboshi_link(cxc: Any) -> str | None:
    """The Chaboshi report page a card links (cxc.reporturl), or None: other reports (Guazi, …) are not read.

    The card gives an app link (usedcar://…?param={"url": …}); its https page is what a browser opens."""
    url = cxc.get("reporturl") if isinstance(cxc, dict) else None
    if not isinstance(url, str) or not url:
        return None
    if url.startswith("usedcar://"):
        try:
            url = json.loads(parse_qs(urlsplit(url).query)["param"][0]).get("url", "")
        except (KeyError, IndexError, ValueError, AttributeError):
            return None
    if not isinstance(url, str):
        return None
    parts = urlsplit(url)
    if parts.scheme != "https" or parts.hostname != CHABOSHI_PAGE_HOST:
        return None
    return url if parts.path in CHABOSHI_REPORTS else None


def init_params(reply: Reply) -> dict[str, str]:
    """window.__INIT_PARAMS__ of the report page: the order and its signature, as the page sends them."""
    if reply.status != 200:
        raise CollectionError("report_unavailable", f"http_{reply.status}")
    text = reply.body.decode("utf-8", "replace")
    if text.lstrip().startswith("{"):
        # Chaboshi refuses the link che168 gave ({"Code":"1001","Message":"签名错误"}, run 37):
        # no report behind it until che168 gives another
        try:
            refusal = json.loads(text)
        except ValueError:
            refusal = None
        if isinstance(refusal, dict):
            raise CollectionError("report_link_rejected", f"code_{refusal.get('Code', refusal.get('code'))}")
    match = _INIT_PARAMS.search(text)
    params = dict(_INIT_PARAM.findall(match.group(1))) if match else {}
    if not params.get("orderNo") and not params.get("orderno"):
        raise CollectionError("report_schema_changed")
    return params


def decode_json_object(reply: Reply) -> dict[str, Any]:
    if reply.status != 200:
        raise CollectionError("report_unavailable", f"http_{reply.status}")
    try:
        data = json.loads(reply.body)
    except (ValueError, UnicodeError):
        raise CollectionError("report_schema_changed") from None
    if not isinstance(data, dict):
        raise CollectionError("report_schema_changed")
    return data


def decode_chaboshi(reply: Reply) -> Any:
    """{code, data, message}: code 0 → data; 5 → data is a link to the report; any other → no report."""
    data = decode_json_object(reply)
    if data.get("code") not in (0, 5):
        raise CollectionError("report_rejected")
    return data.get("data")


def decode_reply(reply: Reply) -> dict[str, Any]:
    result = decode_result(reply)
    if not isinstance(result, dict):
        raise CollectionError("api_schema_changed")
    return result


def decode_list(reply: Reply) -> list[Any]:
    """A result that is a list (the options); null reads as none."""
    result = decode_result(reply)
    if result is None:
        return []
    if not isinstance(result, list):
        raise CollectionError("api_schema_changed")
    return result


def decode_result(reply: Reply) -> Any:
    if reply.status == 402:
        raise CollectionError("proxy_budget_exhausted")
    if reply.status in (401, 407):
        raise CollectionError("authentication_error")
    if reply.status == 429:
        raise CollectionError("rate_limited")
    if reply.status == 403 or 300 <= reply.status < 400:
        raise CollectionError("access_blocked")
    if reply.status != 200:
        raise CollectionError("http_error")
    try:
        data = json.loads(reply.body)
    except (ValueError, UnicodeError):
        lower = reply.body[:10000].lower()
        challenge = any(marker in lower for marker in (b"captcha", b"security verification", b"eo_bot_ssid"))
        raise CollectionError("challenge" if challenge else "invalid_json") from None
    if not isinstance(data, dict):
        raise CollectionError("api_schema_changed")
    if data.get("returncode") == 107:
        raise CollectionError("signature_rejected")
    if data.get("returncode") != 0:
        raise CollectionError("api_rejected")
    return data.get("result")
