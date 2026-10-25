"""Encar HTTP endpoints (same URLs and parameters as the old parser's EncarClient)."""

from __future__ import annotations

from collections.abc import Sequence
from typing import Final

from collector.http import Failed, Fetcher, Missing, Ok, Outcome

SEARCH_URL: Final = "https://api.encar.com/search/car/list/mobile"
# The same search with its navigation (iNav): every maker of the query with its Count, as
# analysis/scrape_encar_inav_tree.py reads it.
NAV_URL: Final = "https://api.encar.com/search/car/list/general"
NAV_PARAM: Final = "|Metadata|Sort"
BATCH_URL: Final = "https://api.encar.com/v1/readside/vehicles"
DETAIL_URL: Final = "https://api.encar.com/v1/readside/vehicle/{id}"
INSPECTION_URL: Final = "https://api.encar.com/v1/readside/inspection/vehicle/{id}"
RECORD_URL: Final = "https://api.encar.com/v1/readside/record/vehicle/{id}/open"
SELLING_URL: Final = "https://api.encar.com/v1/readside/diagnosis/vehicle/{id}/sellingpoint"

DEFAULT_QUERY: Final = "(And.Hidden.N._.CarType.A.)"
DETAIL_INCLUDE: Final = "ADVERTISEMENT,CATEGORY,CONDITION,CONTACT,MANAGE,OPTIONS,PHOTOS,SPEC,VIEW"
BATCH_INCLUDE: Final = "SPEC,ADVERTISEMENT,PHOTOS,CATEGORY,MANAGE,CONTACT,CONDITION,OPTIONS,VIEW"
BATCH_SIZE: Final = 20  # the batch endpoint caps at 20 ids
PAGE_SIZE: Final = 100
MAX_SAFE_OFFSET: Final = 9900  # search (Elasticsearch) stops at ~10k results per query

HEADERS: Final = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/146.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json, text/javascript, */*; q=0.01",
    "Referer": "https://m.encar.com/",
    "Origin": "https://m.encar.com",
}


def facets(nav: object, aspect: str, depth: int = 0) -> dict[str, int]:
    """Value → Count of every `aspect` node in an iNav tree (Nodes → Facets → Refinements → Nodes)."""
    found: dict[str, int] = {}
    if not isinstance(nav, dict) or depth > 12:
        return found
    for node in nav.get("Nodes") or []:
        if not isinstance(node, dict):
            continue
        for facet in node.get("Facets") or []:
            if not isinstance(facet, dict):
                continue
            value = str(facet.get("Value") or "").strip()
            if node.get("Name") == aspect and value:
                found[value] = max(found.get(value, 0), int(facet.get("Count") or 0))
            for sub, count in facets(facet.get("Refinements"), aspect, depth + 1).items():
                found[sub] = max(found.get(sub, 0), count)
    return found


def maker_query(maker: str, model_group: str | None = None) -> str:
    if model_group:
        return f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.ModelGroup.{model_group}.)"
    return f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)"


class EncarApi:
    def __init__(self, fetcher: Fetcher) -> None:
        self.fetcher = fetcher

    async def search(self, query: str = DEFAULT_QUERY, offset: int = 0, count: int = PAGE_SIZE) -> Outcome:
        # No results is Count 0; a 404 is a refused request (run 19 lost nine Hyundai years
        # when a 404 page was taken for the end of the results).
        return await self.fetcher.get_json(
            SEARCH_URL,
            {"count": "true", "q": query, "sr": f"|ModifiedDate|{offset}|{count}"},
            missing_ok=False,
        )

    async def makers(self, query: str = DEFAULT_QUERY) -> Outcome:
        """Ok({maker: Count}) from the search navigation; empty when the answer has no makers."""
        outcome = await self.fetcher.get_json(
            NAV_URL, {"count": "true", "q": query, "inav": NAV_PARAM}, missing_ok=False
        )
        if not isinstance(outcome, Ok):
            return outcome
        nav = outcome.data.get("iNav") if isinstance(outcome.data, dict) else None
        return Ok(facets(nav, "Manufacturer"), outcome.bytes)

    async def batch_details(self, ids: Sequence[str]) -> Outcome:
        outcome = await self.fetcher.get_json(
            BATCH_URL, {"vehicleIds": ",".join(ids), "include": BATCH_INCLUDE}
        )
        if isinstance(outcome, Ok) and not isinstance(outcome.data, list):
            vehicles = outcome.data.get("vehicles") if isinstance(outcome.data, dict) else None
            if not isinstance(vehicles, list):
                return Failed("unexpected batch response shape")
            return Ok(vehicles, outcome.bytes)
        return outcome

    async def detail(self, vehicle_id: str) -> Outcome:
        return await self.fetcher.get_json(DETAIL_URL.format(id=vehicle_id), {"include": DETAIL_INCLUDE})

    async def record(self, vehicle_id: str, plate_number: str | None = None) -> Outcome:
        params = {"vehicleNo": plate_number} if plate_number else None
        outcome = await self.fetcher.get_json(RECORD_URL.format(id=vehicle_id), params)
        # The old parser used a record only when it carried openData.
        if isinstance(outcome, Ok) and not (isinstance(outcome.data, dict) and outcome.data.get("openData")):
            return Missing(200)
        return outcome

    async def inspection(self, vehicle_id: str) -> Outcome:
        outcome = await self.fetcher.get_json(INSPECTION_URL.format(id=vehicle_id))
        if isinstance(outcome, Ok) and not outcome.data:
            return Missing(200)
        return outcome

    async def sellingpoint(self, vehicle_id: str) -> Outcome:
        outcome = await self.fetcher.get_json(SELLING_URL.format(id=vehicle_id))
        if isinstance(outcome, Ok) and not outcome.data:
            return Missing(200)
        return outcome
