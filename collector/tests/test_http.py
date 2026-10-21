"""collector.http: retries, proxy rotation and explicit outcomes."""

from __future__ import annotations

from collections.abc import Callable

import httpx
import pytest

from collector.http import Failed, Fetcher, Missing, Ok, ProxyBudgetError, ProxyPool

URL = "https://api.encar.com/x"


async def _no_sleep(_: float) -> None:
    return None


def fetcher(handler: Callable[[httpx.Request], httpx.Response], proxies: ProxyPool | None = None) -> Fetcher:
    transport = httpx.MockTransport(handler)
    return Fetcher(
        proxies or ProxyPool([]),
        retries=2,
        client_factory=lambda proxy: httpx.AsyncClient(transport=transport),
        sleep=_no_sleep,
    )


def sequence(*responses: httpx.Response | Exception) -> Callable[[httpx.Request], httpx.Response]:
    queue = list(responses)

    def handler(request: httpx.Request) -> httpx.Response:
        item = queue.pop(0) if len(queue) > 1 else queue[0]
        if isinstance(item, Exception):
            raise item
        return item

    return handler


async def test_ok_returns_json_and_counts_bytes() -> None:
    f = fetcher(sequence(httpx.Response(200, json={"a": 1})), ProxyPool(["http://u-session-x-c:p@h:1"]))
    outcome = await f.get_json(URL)
    assert outcome == Ok({"a": 1}, len(b'{"a":1}'))
    assert f.stats.proxy_bytes > 0


async def test_retryable_status_then_success() -> None:
    f = fetcher(sequence(httpx.Response(429), httpx.Response(503), httpx.Response(200, json=[1])))
    assert await f.get_json(URL) == Ok([1], 3)
    assert f.stats.retries == 2


async def test_retries_exhausted_is_failed_not_missing() -> None:
    f = fetcher(sequence(httpx.Response(503)))
    outcome = await f.get_json(URL)
    assert isinstance(outcome, Failed)
    assert outcome.reason == "HTTP 503"
    assert f.stats.failed == 1


async def test_404_on_proxy_means_missing() -> None:
    f = fetcher(sequence(httpx.Response(404)), ProxyPool(["http://u-session-a-c:p@h:1"]))
    assert await f.get_json(URL) == Missing(404)


async def test_404_without_proxy_is_a_geo_block_and_retried() -> None:
    f = fetcher(sequence(httpx.Response(404)))
    assert isinstance(await f.get_json(URL), Failed)
    assert f.stats.retries == 2


async def test_network_errors_are_retried() -> None:
    f = fetcher(sequence(httpx.ConnectError("boom"), httpx.Response(200, json={})))
    assert await f.get_json(URL) == Ok({}, 2)


async def test_invalid_json_is_retried_then_failed() -> None:
    f = fetcher(sequence(httpx.Response(200, content=b"<html>")))
    assert await f.get_json(URL) == Failed("invalid JSON")


async def test_other_client_errors_fail_at_once() -> None:
    f = fetcher(sequence(httpx.Response(400)))
    assert await f.get_json(URL) == Failed("HTTP 400")
    assert f.stats.retries == 0


async def test_payment_required_stops_the_run() -> None:
    with pytest.raises(ProxyBudgetError):
        await fetcher(sequence(httpx.Response(402))).get_json(URL)
    with pytest.raises(ProxyBudgetError):
        await fetcher(sequence(httpx.ProxyError("402 Payment Required"))).get_json(URL)


async def test_other_proxy_errors_are_retried() -> None:
    f = fetcher(sequence(httpx.ProxyError("tunnel failed"), httpx.Response(200, json=1)))
    assert await f.get_json(URL) == Ok(1, 1)


async def test_retry_refreshes_the_proxy_session() -> None:
    seen: list[str] = []
    pool = ProxyPool(["http://user-type-residential-session-aaaaaaaa-country-KR-rotation-15:pw@host:1"])

    def factory(proxy: str | None) -> httpx.AsyncClient:
        seen.append(proxy or "")
        return httpx.AsyncClient(transport=httpx.MockTransport(sequence(httpx.Response(503))))

    f = Fetcher(pool, retries=2, client_factory=factory, sleep=_no_sleep)
    await f.get_json(URL)
    assert len(set(seen)) == 3, "each retry goes out on a new session"
    assert pool.urls[0] == seen[-1]
    await f.aclose()


def test_floppydata_sessions_and_masking() -> None:
    pool = ProxyPool.floppydata("user", "secret", "proxy.example:8080", sessions=3)
    assert len(pool.urls) == 3
    assert len(set(pool.urls)) == 3
    assert all("-country-KR-" in u for u in pool.urls)
    assert "secret" not in ProxyPool.masked(pool.urls[0])
    assert ProxyPool.masked(None) == "direct"
    assert ProxyPool([]).take() is None
    assert not ProxyPool([])


async def test_context_manager_closes_clients() -> None:
    async with fetcher(sequence(httpx.Response(200, json={}))) as f:
        await f.get_json(URL)
    assert f._clients == {}
