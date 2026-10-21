"""Re-process stored raw responses through the normal pipeline, with no request to Encar (ADR-0005).

After a mapping fix: `python -m collector replay` rebuilds every lot from
collector.raw_responses and writes the records to the ingest buffer; the core
applies whatever changed. RawApi answers the pipeline's calls from the raw store,
so the replay runs exactly the code a live run does.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from collector.adapters.encar import mapping as m
from collector.adapters.encar.pipeline import EncarPipeline, PageReport
from collector.http import Missing, Ok, Outcome
from collector.ports import RawStore


class RawApi:
    """The EncarApi calls the pipeline makes, answered from raw responses of a set of lots."""

    def __init__(self, raw: dict[str, dict[str, Any]]) -> None:
        self.raw = raw
        # record / inspection are asked by the inner vehicle id (relisted lots): map it back.
        self._by_inner: dict[str, str] = {}
        for lot_id, kinds in raw.items():
            if "list" in kinds and "detail" in kinds:
                draft = m.from_search(kinds["list"])
                m.apply_detail(draft, kinds["detail"])
                inner = str(kinds["detail"].get("vehicleId", ""))
                for key in (inner, draft.raw_data.get("inspect_vehicle_id")):
                    if key:
                        self._by_inner.setdefault(str(key), lot_id)

    def _lot(self, vehicle_id: str) -> dict[str, Any]:
        return self.raw.get(vehicle_id) or self.raw.get(self._by_inner.get(vehicle_id, ""), {})

    async def batch_details(self, ids: Sequence[str]) -> Outcome:
        return Ok([self.raw[i]["detail"] for i in ids if "detail" in self.raw.get(i, {})])

    async def detail(self, vehicle_id: str) -> Outcome:
        found = self.raw.get(vehicle_id, {}).get("detail")
        return Ok(found) if found is not None else Missing(404)

    async def _kind(self, vehicle_id: str, kind: str) -> Outcome:
        found = self._lot(vehicle_id).get(kind)
        return Ok(found) if found is not None else Missing(200)

    async def record(self, vehicle_id: str, plate_number: str | None = None) -> Outcome:
        return await self._kind(vehicle_id, "record")

    async def inspection(self, vehicle_id: str) -> Outcome:
        return await self._kind(vehicle_id, "inspection")

    async def sellingpoint(self, vehicle_id: str) -> Outcome:
        return await self._kind(vehicle_id, "sellingpoint")


async def replay_lots(raw_store: RawStore, pipeline: EncarPipeline, lot_ids: Sequence[str]) -> PageReport:
    """Rebuild these lots from their raw responses; lots without a stored list item are skipped."""
    raw = await raw_store.load(m.SOURCE, lot_ids)
    items = [kinds["list"] for kinds in raw.values() if "list" in kinds]
    pipeline.api = RawApi(raw)  # type: ignore[assignment]
    return await pipeline.process(items)


async def replay_all(raw_store: RawStore, pipeline: EncarPipeline, *, batch: int = 200) -> PageReport:
    report = PageReport()
    after = ""
    while ids := await raw_store.lot_ids(m.SOURCE, after=after, limit=batch):
        report = report.plus(await replay_lots(raw_store, pipeline, ids))
        after = ids[-1]
    return report


class RefreshApi(RawApi):
    """Reparse: list and detail from the raw store (price and status stay as last seen, like the old
    reparse), record / inspection / sellingpoint asked from Encar again."""

    def __init__(self, raw: dict[str, dict[str, Any]], live: Any) -> None:
        super().__init__(raw)
        self.live = live

    async def record(self, vehicle_id: str, plate_number: str | None = None) -> Outcome:
        outcome: Outcome = await self.live.record(vehicle_id, plate_number)
        return outcome

    async def inspection(self, vehicle_id: str) -> Outcome:
        outcome: Outcome = await self.live.inspection(vehicle_id)
        return outcome

    async def sellingpoint(self, vehicle_id: str) -> Outcome:
        outcome: Outcome = await self.live.sellingpoint(vehicle_id)
        return outcome


async def refresh_lots(
    raw_store: RawStore, pipeline: EncarPipeline, lot_ids: Sequence[str], *, run_id: int | None = None
) -> tuple[PageReport, list[str]]:
    """Fresh history for these lots; returns the report and the ids the raw store does not know yet."""
    raw = await raw_store.load(m.SOURCE, lot_ids)
    known = {i: kinds for i, kinds in raw.items() if "list" in kinds and "detail" in kinds}
    missing = [i for i in lot_ids if i not in known]
    live = pipeline.api
    pipeline.api = RefreshApi(known, live)  # type: ignore[assignment]
    try:
        report = await pipeline.process([kinds["list"] for kinds in known.values()], run_id=run_id)
    finally:
        pipeline.api = live
    return report, missing
