"""A Chaboshi (查博士) inspection report → what the lot keeps of it: the verdicts, the findings, the battery.

Only what the report states, in its own (Chinese) words; the core names them. Nothing that points
to a person or an order is kept: no VIN, phone, inspector, address, order numbers or links.

An inspection is not an insurance history: «no major structural accident» by Chaboshi's standard is
not «never in an accident», so none of it fills the lot's has_accident (docs/03-database.md).
"""

from __future__ import annotations

from datetime import date
from typing import Any

from collector.domain.carlot import Inspection

# per-system marks that are not findings: nothing abnormal / no such part on this car
NOT_FINDINGS = frozenset({"未见异常", "未见此项"})
UNCHECKED = "受限不可检"  # the inspector could not reach the part
ACCIDENT_SYSTEM = "事故车检测"
BATTERY_RANGES = ("mileageTempRange1", "mileageTempRange2", "mileageTempRange3")


def _text(value: Any, limit: int = 300) -> str | None:
    return value.strip()[:limit] or None if isinstance(value, str) else None


def _flag(value: Any) -> bool | None:
    return value if isinstance(value, bool) else None


def _number(value: Any) -> float | None:
    if isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if number > 0 else None


def _day(value: Any) -> str | None:
    text = _text(value, 40)
    if text is None:
        return None
    try:
        return date.fromisoformat(text[:10]).isoformat()
    except ValueError:
        return None


def _mileage(info: dict[str, Any]) -> int | None:
    for key in ("displayMileage", "watchMile"):
        value = info.get(key)
        if type(value) is int and 0 < value <= 2_000_000:
            return value
        if isinstance(value, str) and value.isdigit() and 0 < int(value) <= 2_000_000:
            return int(value)
    return None


def _system(item: dict[str, Any]) -> dict[str, Any] | None:
    name = _text(item.get("systemName"), 40)
    if name is None:
        return None
    findings, unchecked = [], []
    for part in item.get("flawComponentListV2") or []:
        if not isinstance(part, dict):
            continue
        component = _text(part.get("componentName"), 60)
        notes = []
        # systemDescList is this system's own verdict on the part; the other lists repeat other systems'
        for mark in part.get("systemDescList") or []:
            first = _text(mark.get("firDescName"), 40) if isinstance(mark, dict) else None
            if first is None or first in NOT_FINDINGS:
                continue
            if first == UNCHECKED:
                unchecked.append(component)
                continue
            second = _text(mark.get("secDescName"), 40)
            notes.append([first, second if second != first else None])
        if notes and component:
            findings.append(
                {"group": _text(part.get("secSystemName"), 40), "part": component, "notes": notes}
            )
    total, flaws = item.get("totalCount"), item.get("flawCount")
    return {
        "name": name,
        "checked": total if type(total) is int else None,
        "flaws": flaws if type(flaws) is int else None,
        "findings": findings,
        "unchecked": sorted({u for u in unchecked if u}),
    }


def _battery(info: dict[str, Any]) -> dict[str, Any] | None:
    soh = _number(info.get("batteryHealthScore"))
    if soh is None or soh > 100:
        return None  # SOH only where the report states it
    ranges = [r for r in (_text(info.get(k), 60) for k in BATTERY_RANGES) if r]
    battery = {
        "soh": soh,
        "health_level": _text(info.get("batteryHealthLevel"), 20),
        "level": _text(info.get("batteryLevel"), 10),
        "data_date": _day(info.get("lastDataDate")),
        "type": _text(info.get("batteryType"), 40),
        "capacity_kwh": _number(info.get("batteryPower")),
        "voltage": _number(info.get("batteryVoltage")),
        "pack_maker": _text(info.get("batteryPackManufacturer"), 40),
        "cell_brand": _text(info.get("qczjBatteryBrandName"), 40),
        "fast_charge_soc": _text(info.get("fastChrgRange"), 20),
        "ranges": ranges,
        "remark": _text(info.get("batteryRemark"), 400),
    }
    return {k: v for k, v in battery.items() if v not in (None, [])}


def chaboshi_report(kind: str, info: dict[str, Any]) -> dict[str, Any]:
    """What the lot keeps of a report (stored with the card, sent in inspection.details.chaboshi)."""
    systems = [
        s for s in (_system(i) for i in info.get("detectionItemList") or [] if isinstance(i, dict)) if s
    ]
    report = {
        "kind": kind,
        "report_no": _text(info.get("reportNo"), 60),
        "inspected_on": _day(info.get("detectionFinishTimeCH")),
        "product": _text(info.get("shopSpuName"), 40),
        "grade": _text(info.get("reportLevel"), 10),
        "major_accident": _flag(info.get("isSG")),
        "flood": _flag(info.get("isPS")),
        "fire": _flag(info.get("isHS")),
        "remark": _text(info.get("reportRemark"), 400),
        "mileage_km": _mileage(info),
        "systems": systems,
        "battery": _battery(info),
    }
    return {k: v for k, v in report.items() if v not in (None, [])}


def inspection(report: dict[str, Any]) -> Inspection:
    """The lot's inspection from a kept report: fields meaning what Encar's mean; the rest in details."""
    accident = next((s for s in report.get("systems", []) if s.get("name") == ACCIDENT_SYSTEM), None)
    inspected = report.get("inspected_on")
    return Inspection(
        cert_no=report.get("report_no"),
        inspected_on=date.fromisoformat(inspected) if inspected else None,
        mileage_km=report.get("mileage_km"),
        # Chaboshi's own classification: a major structural accident, a flooded car, a burnt car
        has_accident=report.get("major_accident"),
        has_flood=report.get("flood"),
        has_fire=report.get("fire"),
        # panels the accident check marked (repaint, deformation, …), as Encar's outer damage
        has_outer_damage=bool(accident["findings"]) if accident else None,
        details={"chaboshi": report},
    )
