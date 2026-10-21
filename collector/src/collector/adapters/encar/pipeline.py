"""One page of Encar listings → CarLot v1 records in the ingest sink.

Order per page (the old parser wrote each lot twice and filtered in between;
here every lot is judged on complete data and written once, ADR-0004):

1. list items → drafts; PRE rules the list data can decide (early pass)
2. details in batches of 20, concurrently (single-id fallback on failure)
3. history plan from the collector's own HistoryStore: full / recheck / stored
4. record + inspection (+ sellingpoint for full) concurrently, explicit outcomes
5. PRE and POST rules on the complete lot → Selection
6. one write to the sink; history state saved for every fetched lot,
   rejected ones included (they used to be re-fetched every run)
"""

from __future__ import annotations

import asyncio
import dataclasses
from collections.abc import Iterable, Sequence
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Final

from collector.adapters.encar import mapping as m
from collector.adapters.encar.api import BATCH_SIZE, EncarApi
from collector.domain.carlot import CarLotV1, SelectionPhase
from collector.http import Failed, Ok
from collector.ports import Clock, HistoryState, HistoryStore, IngestSink, utc_now
from collector.selection import RuleEngine, Verdict

LIST_STAGE_FIELDS: Final = frozenset({"year", "price", "mileage", "sell_type"})
# What record / inspection / sellingpoint set on a lot; a lot not re-fetched takes them from the store.
HISTORY_FIELDS: Final = (
    "owners_count", "insurance_count", "has_accident", "flood_history", "total_loss_history",
    "lien_status", "seizure_status", "repair_cost", "first_reg_date", "vin",
)  # fmt: skip
HISTORY_RAW_KEYS: Final = (
    "engine_code",
    "warranty_type",
    "recall",
    "recall_status",
    "car_state",
    "mechanical_issues",
)
DRIVE_FIELDS: Final = ("drive_type",)
DRIVE_RAW_KEYS: Final = ("selling_point",)
_HISTORY_BEARING: Final = ("owners_count", "insurance_count", "has_accident", "flood_history")


@dataclass
class PageReport:
    listed: int = 0
    early_rejected: int = 0
    detail_failed: int = 0
    history_fetched: int = 0
    history_stored: int = 0
    history_failed: int = 0
    rejected: int = 0
    emitted: int = 0

    def plus(self, other: PageReport) -> PageReport:
        return PageReport(
            **{f.name: getattr(self, f.name) + getattr(other, f.name) for f in dataclasses.fields(self)}
        )


@dataclass
class _Lot:
    draft: m.EncarDraft
    inspection: m.InspectionDraft | None = None
    plan: str = "full"  # full | recheck | stored
    history_ok: bool = False


def rule_values(lot: m.EncarDraft, inspection: m.InspectionDraft | None) -> dict[str, Any]:
    """Field values the rules see: the lots columns plus the inspection aggregates (old virtual fields)."""
    values = {f.name: getattr(lot, f.name) for f in dataclasses.fields(lot)}
    values["source"] = m.SOURCE
    cost = (inspection.my_accident_cost or 0) + (inspection.other_accident_cost or 0) if inspection else 0
    values |= {
        "inspection_count": 1 if inspection else 0,
        "accident_max_cost": cost,
        "total_accident_cost": cost,
        "has_recall": bool(inspection and inspection.has_recall),
        "inspection_has_accident": bool(inspection and inspection.has_accident),
        "inspection_has_flood": bool(inspection and inspection.has_flood),
    }
    return values


def apply_stored(
    lot: m.EncarDraft, state: HistoryState, fields: Sequence[str], raw_keys: Sequence[str]
) -> None:
    """Fill what the lot does not have yet from the stored history (old _apply_stored)."""
    for name in fields:
        value = state.fields.get(name)
        if value is not None and getattr(lot, name) is None:
            setattr(lot, name, value)
    for key in raw_keys:
        if key in state.fields and key not in lot.raw_data:
            lot.raw_data[key] = state.fields[key]


def history_snapshot(lot: m.EncarDraft, checked_at: datetime) -> HistoryState:
    fields: dict[str, Any] = {f: getattr(lot, f) for f in (*HISTORY_FIELDS, *DRIVE_FIELDS)}
    fields |= {k: lot.raw_data[k] for k in (*HISTORY_RAW_KEYS, *DRIVE_RAW_KEYS) if k in lot.raw_data}
    return HistoryState(
        lot_id=lot.id,
        checked_at=checked_at,
        condition=tuple(sorted(lot.raw_data.get("condition") or [])),
        fields={k: v for k, v in fields.items() if v is not None},
    )


class EncarPipeline:
    def __init__(
        self,
        api: EncarApi,
        rules: RuleEngine,
        history: HistoryStore,
        sink: IngestSink,
        *,
        clock: Clock = utc_now,
        history_concurrency: int = 8,
    ) -> None:
        self.api = api
        self.rules = rules
        self.history = history
        self.sink = sink
        self.clock = clock
        self._history_sem = asyncio.Semaphore(max(1, history_concurrency))

    async def process(
        self,
        items: Iterable[dict[str, Any]],
        *,
        run_id: int | None = None,
        history_cutoff: datetime | None = None,
        with_sellingpoint: bool = True,
    ) -> PageReport:
        now = self.clock()
        report = PageReport()
        lots: dict[str, _Lot] = {}
        out: list[CarLotV1] = []

        for item in items:
            draft = m.from_search(item)
            if draft.id in lots:
                continue
            report.listed += 1
            early = self.rules.evaluate(
                rule_values(draft, None),
                source=m.SOURCE,
                phase=SelectionPhase.PRE,
                only_fields=LIST_STAGE_FIELDS,
            )
            if early.rejects:
                report.early_rejected += 1
                out.append(
                    self._carlot(
                        draft,
                        inspection=None,
                        verdict=early,
                        phase=SelectionPhase.PRE,
                        now=now,
                        history_at=None,
                        run_id=run_id,
                    )
                )
                continue
            lots[draft.id] = _Lot(draft)

        await self._details(lots, report)
        await self._plan(lots, history_cutoff, report)
        await asyncio.gather(*(self._fetch_history(lot, with_sellingpoint) for lot in lots.values()))

        fetched: list[HistoryState] = []
        for lot in lots.values():
            if lot.plan != "stored":
                if lot.history_ok:
                    report.history_fetched += 1
                    fetched.append(history_snapshot(lot.draft, now))
                else:
                    report.history_failed += 1
            values = rule_values(lot.draft, lot.inspection)
            verdict, phase = self._judge(values)
            if verdict.rejects:
                report.rejected += 1
            history_at = now if lot.plan != "stored" and lot.history_ok else None
            out.append(
                self._carlot(
                    lot.draft,
                    inspection=lot.inspection,
                    verdict=verdict,
                    phase=phase,
                    now=now,
                    history_at=history_at,
                    run_id=run_id,
                )
            )

        if fetched:
            await self.history.save(m.SOURCE, fetched)
        if out:
            await self.sink.write(out)
        report.emitted = len(out)
        return report

    # ── steps ───────────────────────────────────────────────────────────────

    async def _details(self, lots: dict[str, _Lot], report: PageReport) -> None:
        ids = list(lots)
        chunks = [ids[i : i + BATCH_SIZE] for i in range(0, len(ids), BATCH_SIZE)]
        results = await asyncio.gather(*(self.api.batch_details(c) for c in chunks))
        for chunk, outcome in zip(chunks, results, strict=True):
            if isinstance(outcome, Ok):
                for detail in outcome.data:
                    manage = detail.get("manage") or {}
                    listing_id = str(
                        manage["dummyVehicleId"]
                        if manage.get("dummy") and manage.get("dummyVehicleId")
                        else detail.get("vehicleId", "")
                    )
                    lot = lots.get(listing_id)
                    if lot is None:
                        continue
                    inner_id = str(detail.get("vehicleId", ""))
                    if inner_id and inner_id != listing_id:
                        lot.draft.raw_data["inspect_vehicle_id"] = inner_id
                    m.apply_detail(lot.draft, detail)
                continue
            for vid in chunk:  # batch failed: one by one, like the old parser
                single = await self.api.detail(vid)
                if isinstance(single, Ok):
                    m.apply_detail(lots[vid].draft, single.data)
                else:
                    report.detail_failed += 1

    async def _plan(self, lots: dict[str, _Lot], cutoff: datetime | None, report: PageReport) -> None:
        if cutoff is None or not lots:
            return  # every lot gets the full history (sample runs, first run)
        states = await self.history.states(m.SOURCE, list(lots))
        for lot_id, lot in lots.items():
            state = states.get(lot_id)
            if state is None:
                continue
            apply_stored(lot.draft, state, DRIVE_FIELDS, DRIVE_RAW_KEYS)
            condition = tuple(sorted(lot.draft.raw_data.get("condition") or []))
            missing = ("Record" in condition or "Inspection" in condition) and all(
                state.fields.get(f) is None for f in _HISTORY_BEARING
            )
            if state.checked_at < cutoff or state.condition != condition or missing:
                lot.plan = "recheck"
            else:
                apply_stored(lot.draft, state, HISTORY_FIELDS, HISTORY_RAW_KEYS)
                lot.plan = "stored"
                report.history_stored += 1

    async def _fetch_history(self, lot: _Lot, with_sellingpoint: bool) -> None:
        if lot.plan == "stored":
            return
        d = lot.draft
        inner_id = str(d.raw_data.get("inspect_vehicle_id") or d.id)
        condition = set(d.raw_data.get("condition") or [])
        ok = True
        async with self._history_sem:
            if "Record" in condition:
                rec = await self.api.record(inner_id, d.plate_number or None)
                if isinstance(rec, Ok):
                    lot.inspection = m.apply_record(d, rec.data)
                ok = ok and not isinstance(rec, Failed)
            if "Inspection" in condition:
                insp = await self.api.inspection(inner_id)
                if isinstance(insp, Ok):
                    lot.inspection = lot.inspection or m.InspectionDraft()
                    m.apply_inspection(d, insp.data, lot.inspection)
                ok = ok and not isinstance(insp, Failed)
            if with_sellingpoint and lot.plan == "full" and not d.drive_type:
                sp = await self.api.sellingpoint(d.id)
                if isinstance(sp, Ok):
                    m.apply_sellingpoint(d, sp.data)
                ok = ok and not isinstance(sp, Failed)
        lot.history_ok = ok

    def _judge(self, values: dict[str, Any]) -> tuple[Verdict, SelectionPhase]:
        pre = self.rules.evaluate(values, source=m.SOURCE, phase=SelectionPhase.PRE)
        if pre.rejects:
            return pre, SelectionPhase.PRE
        post = self.rules.evaluate(values, source=m.SOURCE, phase=SelectionPhase.POST)
        if post.rejects:
            return post, SelectionPhase.POST
        flagged = Verdict(pre.action, pre.matched + post.matched)
        return flagged, SelectionPhase.PRE

    @staticmethod
    def _carlot(
        draft: m.EncarDraft,
        *,
        inspection: m.InspectionDraft | None,
        verdict: Verdict,
        phase: SelectionPhase,
        now: datetime,
        history_at: datetime | None,
        run_id: int | None,
    ) -> CarLotV1:
        return m.to_carlot(
            draft,
            inspection,
            observed_at=now,
            history_observed_at=history_at,
            selection=verdict.selection(phase),
            run_id=run_id,
        )
