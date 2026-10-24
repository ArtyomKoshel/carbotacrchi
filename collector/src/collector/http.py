"""Async HTTP with retries, proxy rotation and an explicit outcome per request.

Replaces the old parser's EncarClient request layer. The old layer turned any
failed call into "no data" (record()/inspection() returned None on error), so a
lot whose history call failed was stamped as checked. Here every request ends
in exactly one of: Ok (JSON), Missing (the source says there is nothing),
Failed (retries exhausted) — callers cannot confuse the last two.
"""

from __future__ import annotations

import asyncio
import random
import re
import secrets
import string
from collections.abc import Awaitable, Callable, Mapping, Sequence
from dataclasses import dataclass, field
from typing import Any, Final

import httpx

RETRY_STATUSES: Final = frozenset({401, 403, 407, 408, 410, 429, 500, 502, 503, 504})
RETRY_ERRORS: Final = (
    httpx.ProxyError,
    httpx.ConnectError,
    httpx.ReadTimeout,
    httpx.ConnectTimeout,
    httpx.ReadError,
)


@dataclass(frozen=True, slots=True)
class Ok:
    data: Any
    bytes: int = 0


@dataclass(frozen=True, slots=True)
class Missing:
    status: int


@dataclass(frozen=True, slots=True)
class Failed:
    reason: str


Outcome = Ok | Missing | Failed


class ProxyBudgetError(RuntimeError):
    """The proxy provider answered 402: no traffic left. The run must stop, not retry."""


# ── proxies ─────────────────────────────────────────────────────────────────

_SESSION_RE: Final = re.compile(r"(-session-)([^-:@]+)")


def _session_id() -> str:
    return "".join(secrets.choice(string.ascii_lowercase + string.digits) for _ in range(8))


@dataclass
class ProxyPool:
    """Residential proxy sessions; each session is a different exit IP."""

    urls: list[str]
    _next: int = 0

    @classmethod
    def floppydata(
        cls, username: str, password: str, host: str, sessions: int, country: str = "KR"
    ) -> ProxyPool:
        return cls(
            [
                f"http://{username}-type-residential-session-{_session_id()}-country-{country}-rotation-15:{password}@{host}"
                for _ in range(sessions)
            ]
        )

    def __bool__(self) -> bool:
        return bool(self.urls)

    def take(self) -> str | None:
        if not self.urls:
            return None
        url = self.urls[self._next % len(self.urls)]
        self._next += 1
        return url

    def refresh(self, url: str) -> str:
        """A new session id on the same account (a new exit IP)."""
        fresh, _ = _SESSION_RE.subn(rf"\g<1>{_session_id()}", url)
        if url in self.urls:
            self.urls[self.urls.index(url)] = fresh
        return fresh

    @staticmethod
    def masked(url: str | None) -> str:
        return re.sub(r"://([^:]+):([^@]+)@", r"://\1:***@", url or "direct")


# ── fetcher ─────────────────────────────────────────────────────────────────


@dataclass
class FetchStats:
    requests: int = 0
    retries: int = 0
    failed: int = 0
    proxy_bytes: int = 0
    by_status: dict[int, int] = field(default_factory=dict)


ClientFactory = Callable[[str | None], httpx.AsyncClient]
Sleep = Callable[[float], Awaitable[None]]


class Fetcher:
    """GET JSON through a pool of clients (one per proxy session), bounded concurrency.

    missing_statuses: statuses that mean "nothing there" (Encar: 404 once on a
    Korean proxy). Without proxies a 404 is treated as a block and retried.
    """

    def __init__(
        self,
        proxies: ProxyPool,
        *,
        headers: Mapping[str, str] | None = None,
        concurrency: int = 8,
        retries: int = 3,
        backoff: float = 1.0,
        timeout: float = 30.0,
        missing_statuses: Sequence[int] = (404,),
        client_factory: ClientFactory | None = None,
        sleep: Sleep = asyncio.sleep,
    ) -> None:
        self._proxies = proxies
        self._headers = dict(headers or {})
        self._sem = asyncio.Semaphore(max(1, concurrency))
        self._retries = retries
        self._backoff = backoff
        self._timeout = timeout
        self._missing = frozenset(missing_statuses) if proxies else frozenset()
        self._factory = client_factory or self._default_client
        self._sleep = sleep
        self._clients: dict[str | None, httpx.AsyncClient] = {}
        self.stats = FetchStats()

    def _default_client(self, proxy: str | None) -> httpx.AsyncClient:
        return httpx.AsyncClient(
            headers=self._headers, timeout=self._timeout, follow_redirects=True, proxy=proxy
        )

    def _client(self, proxy: str | None) -> httpx.AsyncClient:
        if proxy not in self._clients:
            self._clients[proxy] = self._factory(proxy)
        return self._clients[proxy]

    async def aclose(self) -> None:
        for client in self._clients.values():
            await client.aclose()
        self._clients.clear()

    async def __aenter__(self) -> Fetcher:
        return self

    async def __aexit__(self, *exc: object) -> None:
        await self.aclose()

    async def get_json(
        self, url: str, params: Mapping[str, str] | None = None, *, missing_ok: bool = True
    ) -> Outcome:
        """missing_ok=False: this endpoint never answers "nothing there" with a missing status
        (a search answers Count 0), so such a status is a refused request and retried."""
        missing = self._missing if missing_ok else frozenset()
        async with self._sem:
            proxy = self._proxies.take()
            reason = ""
            for attempt in range(self._retries + 1):
                if attempt:
                    self.stats.retries += 1
                    await self._sleep(self._backoff * (2 ** (attempt - 1)) * (1 + random.random() / 4))  # noqa: S311
                    if proxy is not None:
                        proxy = self._proxies.refresh(proxy)
                self.stats.requests += 1
                try:
                    response = await self._client(proxy).get(url, params=params)
                except httpx.ProxyError as e:
                    if "402" in str(e) or "Payment Required" in str(e):
                        raise ProxyBudgetError(str(e)) from e
                    reason = f"{type(e).__name__}"
                    continue
                except RETRY_ERRORS as e:
                    reason = f"{type(e).__name__}"
                    continue
                status = response.status_code
                self.stats.by_status[status] = self.stats.by_status.get(status, 0) + 1
                if proxy is not None:
                    self.stats.proxy_bytes += len(response.content)
                if status in missing:
                    return Missing(status)
                if status == 402:
                    raise ProxyBudgetError("402 Payment Required")
                if status in RETRY_STATUSES or status == 404 or status in self._missing:
                    reason = f"HTTP {status}"
                    continue
                if status >= 400:
                    self.stats.failed += 1
                    return Failed(f"HTTP {status}")
                try:
                    return Ok(response.json(), len(response.content))
                except ValueError:
                    reason = "invalid JSON"
                    continue
            self.stats.failed += 1
            return Failed(reason or "retries exhausted")
