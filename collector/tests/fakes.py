"""A fake api.encar.com for offline tests (httpx MockTransport handler)."""

from __future__ import annotations

import copy
import json
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

    def _search(self, request: httpx.Request) -> httpx.Response:
        q = unquote(request.url.params.get("q", ""))
        _, _, offset_s, count_s = request.url.params.get("sr", "|ModifiedDate|0|100").split("|")
        offset, count = int(offset_s), int(count_s)
        results = []
        for maker, groups in INVENTORY.items():
            for group, ids in groups.items():
                if (
                    f"Manufacturer.{maker}." in q and (f"ModelGroup.{group}." in q or "ModelGroup" not in q)
                ) or "Manufacturer" not in q:
                    results += [item(i, Manufacturer=maker, ModelGroup=group) for i in ids]
        if self.search_status != 200:
            return httpx.Response(self.search_status)
        return httpx.Response(
            200, json={"Count": len(results), "SearchResults": results[offset : offset + count]}
        )

    def __call__(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path
        self.calls.append(path)
        if path == "/search/car/list/mobile":
            return self._search(request)
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
