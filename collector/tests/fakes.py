"""A fake api.encar.com for offline tests (httpx MockTransport handler)."""

from __future__ import annotations

import copy
import json
from collections.abc import Callable
from pathlib import Path
from typing import Any
from urllib.parse import unquote

import httpx

FIX = Path(__file__).parent / "fixtures" / "encar"


def load(name: str) -> dict[str, Any]:
    data: dict[str, Any] = json.loads((FIX / f"{name}.json").read_text(encoding="utf-8"))
    return data


def item(vid: int, **over: Any) -> dict[str, Any]:
    return load("search_item") | {"Id": vid} | over


def detail(vid: int) -> dict[str, Any]:
    d = copy.deepcopy(load("detail"))
    d["vehicleId"] = vid
    for p in d["photos"]:
        p["path"] = p["path"].replace("40011223", str(vid))
    return d


# Search inventory for sample runs: maker → model group → listing ids.
INVENTORY: dict[str, dict[str, list[int]]] = {
    "현대": {"쏘나타": [101, 102, 103], "그랜저": [104]},
    "기아": {"K5": [201, 202]},
}


class FakeEncar:
    """Answers like api.encar.com; records which paths were called."""

    def __init__(self) -> None:
        self.calls: list[str] = []
        self.batch_fails = False
        self.record_status = 200
        self.record: dict[str, Any] = load("record")
        self.search_status = 200
        # The results move while a run goes (Encar sorts by modification date):
        self.hidden: set[int] = set()  # delisted: gone, the rest shifts up
        self.top: list[tuple[int, str, str]] = []  # (id, maker, group) changed or new: first in the results
        self.on_search: Callable[[FakeEncar, str, int], None] | None = (
            None  # (fake, q, offset), before answering
        )
        self.searches: list[tuple[str, int, int]] = []  # (q, offset, count)
        # (q, offset, count) → HTTP status of that search, instead of search_status
        self.search_status_for: Callable[[str, int, int], int] | None = None
        self.global_makers: set[str] | None = None  # makers the unfiltered query reaches (None: all)
        self.nav_status = 200
        self.nav_makers: list[str] | None = None  # makers in the navigation (None: all of INVENTORY)

    def _search(self, request: httpx.Request) -> httpx.Response:
        q = unquote(request.url.params.get("q", ""))
        _, _, offset_s, count_s = request.url.params.get("sr", "|ModifiedDate|0|100").split("|")
        offset, count = int(offset_s), int(count_s)
        self.searches.append((q, offset, count))
        if self.on_search:
            self.on_search(self, q, offset)

        def matches(maker: str, group: str) -> bool:
            if "Manufacturer" not in q:
                return self.global_makers is None or maker in self.global_makers
            return f"Manufacturer.{maker}." in q and (f"ModelGroup.{group}." in q or "ModelGroup" not in q)

        results = [item(i, Manufacturer=mk, ModelGroup=g) for i, mk, g in self.top if matches(mk, g)]
        on_top = {i for i, _, _ in self.top}
        for maker, groups in INVENTORY.items():
            for group, ids in groups.items():
                if matches(maker, group):
                    results += [
                        item(i, Manufacturer=maker, ModelGroup=group)
                        for i in ids
                        if i not in self.hidden and i not in on_top
                    ]
        status = self.search_status_for(q, offset, count) if self.search_status_for else self.search_status
        if status != 200:
            return httpx.Response(status)
        return httpx.Response(
            200, json={"Count": len(results), "SearchResults": results[offset : offset + count]}
        )

    def _nav(self) -> httpx.Response:
        """The search navigation: makers nested under the selected CarType, as Encar nests them."""
        if self.nav_status != 200:
            return httpx.Response(self.nav_status)
        makers = self.nav_makers if self.nav_makers is not None else list(INVENTORY)
        counts = {mk: sum(len(ids) for ids in INVENTORY.get(mk, {}).values()) for mk in makers}
        manufacturer = {
            "Name": "Manufacturer",
            "Facets": [{"Value": mk, "Count": n} for mk, n in counts.items()],
        }
        car_type = {"Value": "A", "Count": sum(counts.values()), "Refinements": {"Nodes": [manufacturer]}}
        return httpx.Response(200, json={"iNav": {"Nodes": [{"Name": "CarType", "Facets": [car_type]}]}})

    def __call__(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path
        self.calls.append(path)
        if path == "/search/car/list/mobile":
            return self._search(request)
        if path == "/search/car/list/general":
            return self._nav()
        if path == "/v1/readside/vehicles":
            if self.batch_fails:
                return httpx.Response(400)
            ids = request.url.params["vehicleIds"].split(",")
            return httpx.Response(200, json=[detail(int(i)) for i in ids])
        if path.startswith("/v1/readside/vehicle/"):
            return httpx.Response(200, json=detail(int(path.rsplit("/", 1)[1])))
        if path.endswith("/open"):
            return httpx.Response(self.record_status, json=self.record)
        if path.startswith("/v1/readside/inspection/"):
            return httpx.Response(200, json=load("inspection"))
        if path.endswith("/sellingpoint"):
            return httpx.Response(200, json=load("sellingpoint"))
        return httpx.Response(404)
