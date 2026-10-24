"""collector.adapters.encar.api: what the search navigation says about makers."""

from __future__ import annotations

import httpx

from collector.adapters.encar.api import EncarApi, facets
from collector.http import Failed, Fetcher, Ok, ProxyPool
from tests.fakes import FakeEncar


async def _no_sleep(_: float) -> None:
    return None


def api(fake: FakeEncar) -> EncarApi:
    transport = httpx.MockTransport(fake)
    return EncarApi(
        Fetcher(
            ProxyPool(["http://u-session-a-c:p@h:1"]),
            retries=1,
            client_factory=lambda proxy: httpx.AsyncClient(transport=transport),
            sleep=_no_sleep,
        )
    )


def makers(*pairs: tuple[str, int]) -> dict[str, object]:
    return {"Nodes": [{"Name": "Manufacturer", "Facets": [{"Value": v, "Count": n} for v, n in pairs]}]}


def test_facets_are_read_at_any_depth_and_merged() -> None:
    domestic = {"Value": "Y", "Count": 5, "Refinements": makers(("현대", 4), (" ", 9))}
    imported = {"Value": "N", "Count": 3, "Refinements": makers(("BMW", 3), ("현대", 1))}
    nav = {
        "Nodes": [
            {"Name": "CarType", "Facets": [domestic, imported]},
            {"Name": "Color", "Facets": [{"Value": "흰색", "Count": 8}]},
            "not a node",
        ]
    }
    assert facets(nav, "Manufacturer") == {"현대": 4, "BMW": 3}
    assert facets(None, "Manufacturer") == {}
    assert facets({"Nodes": [{"Name": "Manufacturer", "Facets": ["junk"]}]}, "Manufacturer") == {}


async def test_makers_come_from_the_navigation() -> None:
    outcome = await api(FakeEncar()).makers()
    assert isinstance(outcome, Ok)
    assert outcome.data == {"현대": 4, "기아": 2}


async def test_makers_of_a_refused_navigation_fail_instead_of_being_empty() -> None:
    fake = FakeEncar()
    fake.nav_status = 404
    assert isinstance(await api(fake).makers(), Failed)


async def test_makers_of_an_answer_without_navigation_are_none() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json=[])

    transport = httpx.MockTransport(handler)
    fetcher = Fetcher(ProxyPool([]), client_factory=lambda proxy: httpx.AsyncClient(transport=transport))
    assert await EncarApi(fetcher).makers() == Ok({}, 2)
