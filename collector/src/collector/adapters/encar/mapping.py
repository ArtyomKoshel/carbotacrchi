"""Encar API JSON → CarLot v1 (refactor stage 3).

The field logic is moved unchanged from parser/parsers/encar/__init__.py
(_lot_from_search, _enrich_from_detail, _enrich_from_record,
_enrich_from_inspection, _enrich_from_sellingpoint) onto a mutable draft, so
the golden master (parser/tools/golden.py) can prove parity. `to_carlot` is
new: it turns the draft into the validated contract and moves values the
contract refuses (junk VINs, impossible engine volumes …) into `extra`
instead of dropping the lot.
"""

from __future__ import annotations

import contextlib
import re
from dataclasses import dataclass, field
from datetime import date, datetime
from typing import Any, Final
from zoneinfo import ZoneInfo

from pydantic import BaseModel, ValidationError

from collector.adapters.encar import sell_type, vocabulary
from collector.domain.carlot import (
    CarLotV1,
    History,
    Inspection,
    Listing,
    Selection,
    SellType,
    Vehicle,
)

SOURCE: Final = "encar"
KST: Final = ZoneInfo("Asia/Seoul")
PHOTO_CDN: Final = "https://ci.encar.com"
PHOTO_QUERY: Final = "impolicy=heightRate&rh=480&cw=640&ch=480&cg=Center"
DRIVE_PART_CODE: Final = "SPEC_drivingMethodNm"
ABSURD_PRICE_MAN: Final = 1_000_000_000

_MODEL_PREFIXES: Final = (
    "더 뉴 더 뉴 ",
    "더 뉴 더뉴 ",
    "더뉴 더뉴 ",
    "더 뉴 ",
    "더뉴 ",
    "올 뉴 ",
    "올뉴 ",
    "완전변경 ",
    "부분변경 ",
    "신형 ",
    "뉴 ",
)
_ACCIDENT_TYPE: Final = {"1": "my-fault", "2": "my-fault", "3": "other-fault"}
_BAD_INNER: Final = frozenset({"누유", "누수", "미세누수", "불량", "부족", "과다", "누유있음", "미세누유"})
_PHOTO_ID_RE: Final = re.compile(r"/(\d+)_\d+\.")
# Keys the old CarLot kept out of raw_data (own column or duplicate).
_EXTRA_BLOCKLIST: Final = frozenset(
    {
        "photos", "photo_path", "photo_count", "sell_type", "manufacturer_kr", "model_en", "model_group_kr",
        "badge_group_kr", "badge_kr", "badge_detail_kr", "origin_price", "seat_count", "grade_detail_en",
        "grade_detail_kr",
    }
)  # fmt: skip


@dataclass
class EncarDraft:
    """What the old parser's CarLot held for an Encar lot, before contract validation."""

    id: str
    make: str
    model: str
    year: int
    price: int
    mileage: int = 0
    model_group: str | None = None
    badge_group: str | None = None
    badge: str | None = None
    trim: str | None = None
    trim_en: str | None = None
    fuel: str | None = None
    transmission: str | None = None
    body_type: str | None = None
    drive_type: str | None = None
    engine_volume: float | None = None
    color: str | None = None
    seat_color: str | None = None
    seat_count: int | None = None
    location: str | None = None
    lot_url: str = ""
    image_url: str | None = None
    photos: list[str] | None = None
    vin: str | None = None
    plate_number: str | None = None
    first_reg_date: str | None = None
    listed_at: str | None = None
    lien_status: str | None = None
    seizure_status: str | None = None
    has_accident: bool | None = None
    flood_history: bool | None = None
    total_loss_history: bool | None = None
    owners_count: int | None = None
    insurance_count: int | None = None
    retail_value: int | None = None
    repair_cost: int | None = None
    options: list[str] | None = None
    paid_options: list[Any] | None = None
    sell_type: SellType | None = None
    sell_type_raw: str | None = None
    raw_data: dict[str, Any] = field(default_factory=dict)


@dataclass
class InspectionDraft:
    cert_no: str | None = None
    inspection_date: str | None = None
    valid_from: str | None = None
    valid_until: str | None = None
    report_url: str | None = None
    first_registration: str | None = None
    inspection_mileage: int | None = None
    has_accident: bool | None = None
    has_outer_damage: bool | None = None
    has_flood: bool | None = None
    has_tuning: bool | None = None
    has_recall: bool = False
    my_accident_cost: int | None = None
    other_accident_cost: int | None = None
    accident_detail: str | None = None
    outer_detail: str | None = None
    details: dict[str, Any] = field(default_factory=dict)


# ── normalization helpers (parser/_shared/normalizer_base.py) ───────────────


def _lookup(value: object, mapping: dict[str, str]) -> str | None:
    """Case-sensitive, then case-insensitive lookup; None when unmapped."""
    if value is None:
        return None
    clean = str(value).strip()
    if not clean:
        return None
    if clean in mapping:
        return mapping[clean]
    lower = clean.lower()
    for key, mapped in mapping.items():
        if key.lower() == lower:
            return mapped
    return None


def fuel(value: object) -> str | None:
    return _lookup(value, vocabulary.ENCAR_FUEL)


def transmission(value: object) -> str | None:
    return _lookup(value, vocabulary.ENCAR_TRANSMISSION)


def drive(value: object) -> str | None:
    return _lookup(value, vocabulary.ENCAR_DRIVE)


def body(value: object) -> str | None:
    return _lookup(value, vocabulary.ENCAR_BODY)


def color(value: object) -> str | None:
    """Canonical English color, else the raw label (kept for the UI)."""
    if not value:
        return None
    clean = str(value).strip()
    if not clean:
        return None
    mapped = _lookup(clean, vocabulary.COLOR_MAP)
    if mapped:
        return mapped
    base = re.sub(r"\s*\([^)]*\)", "", clean).strip()
    if base and base != clean:
        mapped = _lookup(base, vocabulary.COLOR_MAP)
        if mapped:
            return mapped
    return clean


def price_krw_from_man(man_won: object) -> int:
    """만원 (10 000 KRW) → KRW."""
    try:
        return int(float(str(man_won)) * 10_000)
    except (TypeError, ValueError):
        return 0


def photo_url(path: str) -> str:
    return f"{PHOTO_CDN}{path}?{PHOTO_QUERY}"


def clean_model(model_raw: str, badge: str, model_group: str) -> str:
    """'더 뉴 쏘렌토 4세대 가솔린 2.5T 4WD' → '쏘렌토 4세대' (badge suffix and marketing prefix removed)."""
    s = model_raw.strip()
    if badge:
        badge_s = badge.strip()
        if s.endswith(badge_s):
            s = s[: -len(badge_s)].strip()
    for prefix in _MODEL_PREFIXES:
        if s.startswith(prefix):
            s = s[len(prefix) :]
            break
    return s.strip() or model_raw.strip()


def _parse_date8(s: str | None) -> str | None:
    if not s or len(s) != 8 or not s.isdigit():
        return None
    m, d = int(s[4:6]), int(s[6:8])
    if not (1 <= m <= 12 and 1 <= d <= 31):
        return None
    return f"{s[:4]}-{s[4:6]}-{s[6:8]}"


# ── API → draft (moved from the old parser) ─────────────────────────────────


def from_search(item: dict[str, Any]) -> EncarDraft:
    vid = str(item["Id"])
    model_group = (item.get("ModelGroup") or "").strip()
    model_raw = (item.get("Model") or "").strip()
    badge = (item.get("Badge") or "").strip()
    badge_detail = (item.get("BadgeDetail") or "").strip()

    year_raw = str(item.get("FormYear") or item.get("Year") or "")
    # The old parser crashed on a non-numeric year (found by Hypothesis); 0 = unknown.
    year = int(year_raw[:4]) if year_raw[:4].isdigit() and len(year_raw) >= 4 else 0

    price_man = int(item.get("Price") or 0)
    if price_man > ABSURD_PRICE_MAN:
        price_man = 0

    photos = item.get("Photos") or []
    photo_path = photos[0]["location"] if photos else ""
    conditions = item.get("Condition") or []
    st, st_raw = sell_type.normalize(item.get("SellType"), item.get("AdType"), conditions)

    return EncarDraft(
        id=vid,
        make=(item.get("Manufacturer") or "").strip(),
        model=clean_model(model_raw, badge, model_group),
        model_group=model_group,
        badge_group=(item.get("BadgeGroup") or "").strip() or None,
        badge=badge,
        trim=badge_detail or None,
        year=year,
        price=price_krw_from_man(price_man),
        mileage=int(item.get("Mileage") or 0),
        fuel=fuel(item.get("FuelType")),
        transmission=transmission(item.get("Transmission")),
        color=color(item.get("Color")),
        seat_color=(item.get("SeatColor") or "").strip() or None,
        location=(item.get("OfficeCityState") or "").strip() or None,
        image_url=photo_url(photo_path) if photo_path else None,
        lot_url=f"https://fem.encar.com/cars/detail/{vid}",
        sell_type=st,
        sell_type_raw=st_raw or None,
        raw_data={"model_kr_raw": model_raw, "ad_type": item.get("AdType"), "condition": conditions},
    )


def apply_detail(lot: EncarDraft, detail: dict[str, Any]) -> None:
    cat = detail.get("category") or {}
    spec = detail.get("spec") or {}
    adv = detail.get("advertisement") or {}
    contact = detail.get("contact") or {}
    manage = detail.get("manage") or {}
    photos = detail.get("photos") or []
    opts = detail.get("options") or {}

    if spec.get("transmissionName"):
        lot.transmission = transmission(spec["transmissionName"])
    if spec.get("fuelName"):
        lot.fuel = fuel(spec["fuelName"])
    if spec.get("colorName"):
        lot.color = color(spec["colorName"])
    if spec.get("bodyName"):
        lot.body_type = body(spec["bodyName"])
    if spec.get("displacement"):
        lot.engine_volume = round(spec["displacement"] / 1000, 1)
    if spec.get("drivingMethodName") and not lot.drive_type:
        lot.drive_type = drive(spec["drivingMethodName"])
    if spec.get("seatCount"):
        lot.seat_count = int(spec["seatCount"])

    if detail.get("vin"):
        lot.vin = detail["vin"]
    if detail.get("vehicleNo"):
        lot.plate_number = detail["vehicleNo"]
    if contact.get("address"):
        lot.location = contact["address"]
    if manage.get("registDateTime"):
        lot.listed_at = manage["registDateTime"][:10]

    outer = [p["path"] for p in photos if p.get("type") == "OUTER"]
    if outer and not lot.image_url:
        lot.image_url = photo_url(outer[0])
    urls = [photo_url(p["path"]) for p in photos if p.get("path")]
    if urls:
        lot.photos = list(dict.fromkeys(urls))
    if photos:
        m = _PHOTO_ID_RE.search(photos[0].get("path", ""))
        if m and m.group(1) != lot.id:
            lot.raw_data["inspect_vehicle_id"] = m.group(1)

    if opts.get("standard"):
        lot.options = list(opts["standard"])
    paid: list[Any] = []
    for key in ("choice", "paid", "color", "package"):
        paid.extend(opts.get(key) or [])
    if paid:
        lot.paid_options = paid

    origin_price_man = cat.get("originPrice")
    if origin_price_man and not lot.retail_value:
        with contextlib.suppress(TypeError, ValueError):
            lot.retail_value = int(origin_price_man) * 10_000
    grade_detail_en = (cat.get("gradeDetailEnglishName") or "").strip() or None
    if grade_detail_en and not lot.trim_en:
        lot.trim_en = grade_detail_en

    lot.raw_data["ad_status"] = adv.get("status")


def apply_record(lot: EncarDraft, rec: dict[str, Any]) -> InspectionDraft:
    """Accident / ownership history (KIDI) → lot fields and an inspection draft."""
    my_cnt = int(rec.get("myAccidentCnt") or 0)
    other_cnt = int(rec.get("otherAccidentCnt") or 0)
    lot.has_accident = (my_cnt + other_cnt) > 0
    lot.insurance_count = int(rec.get("accidentCnt") or (my_cnt + other_cnt))
    lot.owners_count = rec.get("ownerChangeCnt")

    flood = int(rec.get("floodTotalLossCnt") or 0) + int(rec.get("floodPartLossCnt") or 0)
    lot.flood_history = flood > 0
    lot.total_loss_history = int(rec.get("totalLossCnt") or 0) > 0
    lot.lien_status = "lien" if int(rec.get("loan") or 0) > 0 else "clean"
    lot.seizure_status = "seizure" if int(rec.get("robberCnt") or 0) > 0 else "clean"

    # myAccidentCost: repairs of this car (accident types 1, 2); otherAccidentCost: the damage it
    # did to other cars (type 3), not a repair of this one. 0 when the history has none.
    my_cost = int(rec.get("myAccidentCost") or 0)
    other_cost = int(rec.get("otherAccidentCost") or 0)
    lot.repair_cost = my_cost
    if rec.get("firstDate") and not lot.first_reg_date:
        lot.first_reg_date = rec["firstDate"]

    accidents = rec.get("accidents") or []
    lines = [_accident_line(a) for a in accidents]
    return InspectionDraft(
        first_registration=rec.get("firstDate"),
        has_accident=lot.has_accident,
        has_flood=lot.flood_history,
        my_accident_cost=my_cost or None,
        other_accident_cost=other_cost or None,
        accident_detail="\n".join(lines) if lines else None,
        details={
            "accidents": accidents,
            "owner_changes": rec.get("ownerChanges"),
            "plate_changes": rec.get("carInfoChanges"),
            "plate_change_cnt": rec.get("carNoChangeCnt"),
            "robber_cnt": rec.get("robberCnt"),
            "total_loss_cnt": rec.get("totalLossCnt"),
            "loan": rec.get("loan"),
            "my_accident_cost": my_cost,
            "other_accident_cost": other_cost,
            "government": rec.get("government"),
            "business": rec.get("business"),
        },
    )


def _accident_line(a: dict[str, Any]) -> str:
    kind = _ACCIDENT_TYPE.get(a.get("type", ""), "?")
    return f"{a.get('date', '')} [{kind}] ₩{int(a.get('insuranceBenefit', 0)):,}"


def _outer_damage(outers: list[dict[str, Any]]) -> tuple[bool, str]:
    parts = []
    for o in outers:
        title = (o.get("type") or {}).get("title", "")
        statuses = [(s.get("title") or "") for s in o.get("statusTypes") or []]
        if title and statuses:
            parts.append(f"{title}: {', '.join(statuses)}")
    return bool(parts), "\n".join(parts)


def _inner_issues(node: dict[str, Any], path: str = "") -> list[str]:
    title = (node.get("type") or {}).get("title", "")
    full_path = f"{path}/{title}" if path else title
    status = (node.get("statusType") or {}).get("title", "")
    issues = [f"{full_path} → {status}"] if status and status in _BAD_INNER else []
    for child in node.get("children") or []:
        issues.extend(_inner_issues(child, full_path))
    return issues


def apply_inspection(lot: EncarDraft, insp: dict[str, Any], record: InspectionDraft) -> None:
    master = insp.get("master") or {}
    detail = master.get("detail") or {}

    if master.get("accdient") is not None:  # sic: the API's spelling
        # An undamaged frame is not «no claims»: only an accident the inspection found reaches the
        # lot. A false here would also overwrite the record's accident once the seller hides the
        # record (lot 41436905: 3 claims, ₩16M); the inspection keeps its own answer.
        if lot.has_accident is None and master["accdient"]:
            lot.has_accident = True
        record.has_accident = master["accdient"]
    if detail.get("waterlog") is not None:
        lot.flood_history = detail["waterlog"]
        record.has_flood = detail["waterlog"]
    if detail.get("tuning") is not None:
        record.has_tuning = detail["tuning"]
    if detail.get("vin") and not lot.vin:
        lot.vin = detail["vin"]

    outers = insp.get("outers") or []
    record.has_outer_damage, outer_text = _outer_damage(outers)
    if outer_text:
        record.outer_detail = outer_text

    if master.get("supplyNum"):
        cert = str(master["supplyNum"]).strip()
        if cert.isdigit() and len(cert) >= 8:
            record.cert_no = cert
    if master.get("registrationDate"):
        record.inspection_date = master["registrationDate"][:10]
    record.report_url = f"https://www.encar.com/md/sl/mdsl_regcar.do?method=inspectionViewNew&carid={lot.id}"

    if vs := _parse_date8(detail.get("validityStartDate")):
        record.valid_from = vs
    if ve := _parse_date8(detail.get("validityEndDate")):
        record.valid_until = ve
    if fr := _parse_date8(detail.get("firstRegistrationDate")):
        record.first_registration = fr
        if not lot.first_reg_date:
            lot.first_reg_date = fr
    if detail.get("mileage"):
        record.inspection_mileage = int(detail["mileage"])

    if detail.get("motorType"):
        lot.raw_data["engine_code"] = detail["motorType"]
    if detail.get("guarantyType"):
        lot.raw_data["warranty_type"] = (detail["guarantyType"] or {}).get("title")
    recall_flag = detail.get("recall")
    recall_types = [(r.get("title") or "") for r in (detail.get("recallFullFillTypes") or [])]
    if recall_flag:
        record.has_recall = True
        lot.raw_data["recall"] = True
        lot.raw_data["recall_status"] = recall_types or ["미확인"]
    if detail.get("carStateType"):
        lot.raw_data["car_state"] = (detail["carStateType"] or {}).get("title")

    mech: list[str] = []
    for inner in insp.get("inners") or []:
        mech.extend(_inner_issues(inner))
    if mech:
        lot.raw_data["mechanical_issues"] = mech

    record.details.update(
        {
            "simple_repair": master.get("simpleRepair"),
            "engine_check": detail.get("engineCheck"),
            "trns_check": detail.get("trnsCheck"),
            "recall": recall_flag,
            "recall_types": recall_types,
            "mechanical_issues": mech or None,
            "serious_types": [(s.get("title") or "") for s in (detail.get("seriousTypes") or [])],
            "car_state": (detail.get("carStateType") or {}).get("title"),
            "outer_parts": [
                {
                    "part": (o.get("type") or {}).get("title"),
                    "status": [s.get("title") for s in o.get("statusTypes") or []],
                }
                for o in outers
            ],
        }
    )


def apply_sellingpoint(lot: EncarDraft, sp: dict[str, Any]) -> None:
    for photo in sp.get("uniqueOptionPhotos") or []:
        if photo.get("partCode") == DRIVE_PART_CODE:
            part_name = photo.get("partName", "")  # "구동방식(전륜)"
            if "(" in part_name and ")" in part_name:
                lot.drive_type = drive(part_name[part_name.index("(") + 1 : part_name.rindex(")")])
            break
    sentence = (sp.get("sellingPoint") or {}).get("sentence")
    if sentence:
        lot.raw_data["selling_point"] = sentence


# ── draft → contract (new) ──────────────────────────────────────────────────


def _date(value: str | None) -> date | None:
    if not value:
        return None
    try:
        return date.fromisoformat(value[:10])
    except ValueError:
        return None


def _kst_midnight(value: str | None) -> datetime | None:
    """The old parser kept only the listing date; it is the date in Korea."""
    d = _date(value)
    return datetime(d.year, d.month, d.day, tzinfo=KST) if d else None


def _sanitized[M: BaseModel](cls: type[M], data: dict[str, Any], extra: dict[str, Any], prefix: str) -> M:
    """Validate a contract section; a field the contract refuses goes to `extra` instead.

    Optional fields become None; a required one gets its fallback (_REQUIRED_FALLBACK).
    Never drops the lot because of one junk value from the source.
    """
    for _ in range(len(data) + 1):
        try:
            return cls.model_validate(data)
        except ValidationError as exc:
            names = {str(e["loc"][0]) for e in exc.errors() if e["loc"] and str(e["loc"][0]) in data}
            if not names:
                raise
            for name in names:
                extra[f"{prefix}{name}_raw"] = data[name]
                fallback = _REQUIRED_FALLBACK.get((cls.__name__, name))
                if fallback is None:
                    del data[name]
                else:
                    data[name] = fallback
    raise AssertionError("unreachable")  # pragma: no cover


_REQUIRED_FALLBACK: Final[dict[tuple[str, str], Any]] = {
    ("Listing", "price"): {"amount": 0},
    ("Listing", "mileage_km"): 0,
}


def _money_dict(amount: int | None) -> dict[str, int] | None:
    return None if amount is None else {"amount": amount}


def to_carlot(
    lot: EncarDraft,
    inspection: InspectionDraft | None,
    *,
    observed_at: datetime,
    history_observed_at: datetime | None = None,
    selection: Selection | None = None,
    run_id: int | None = None,
) -> CarLotV1:
    extra = {k: v for k, v in lot.raw_data.items() if k not in _EXTRA_BLOCKLIST and not k.startswith("_")}

    vehicle = _sanitized(
        Vehicle,
        {
            "make": lot.make,
            "model": lot.model,
            "model_group": lot.model_group or None,
            "badge_group": lot.badge_group,
            "badge": lot.badge or None,
            "trim": lot.trim,
            "trim_en": lot.trim_en,
            "year": lot.year or None,
            "first_registration": _date(lot.first_reg_date),
            "vin": lot.vin,
            "plate_number": lot.plate_number,
            "fuel": lot.fuel,
            "transmission": lot.transmission,
            "drive_type": lot.drive_type,
            "body_type": lot.body_type,
            "engine_volume_l": lot.engine_volume,
            "seat_count": lot.seat_count,
            "color": lot.color,
            "seat_color": lot.seat_color,
        },
        extra,
        "",
    )
    if lot.year == 0:
        extra["year_raw"] = 0
    listing = _sanitized(
        Listing,
        {
            "price": _money_dict(lot.price),
            "mileage_km": lot.mileage,
            "location": lot.location,
            "listed_at": _kst_midnight(lot.listed_at),
            "sell_type": lot.sell_type,
            "sell_type_raw": lot.sell_type_raw,
            "retail_value": _money_dict(lot.retail_value),
            "image_url": lot.image_url,
            "photos": tuple(lot.photos or ()),
            "options": tuple(str(o) for o in lot.options or ()),
            "paid_options": tuple(str(o) for o in lot.paid_options or ()),
        },
        extra,
        "",
    )
    history = _sanitized(
        History,
        {
            "has_accident": lot.has_accident,
            "flood": lot.flood_history,
            "total_loss": lot.total_loss_history,
            "owners_count": lot.owners_count,
            "insurance_count": lot.insurance_count,
            "repair_cost": _money_dict(lot.repair_cost),
            "lien_status": lot.lien_status,
            "seizure_status": lot.seizure_status,
            "observed_at": history_observed_at,
        },
        extra,
        "",
    )
    insp = None
    if inspection is not None:
        insp = _sanitized(
            Inspection,
            {
                "cert_no": inspection.cert_no,
                "inspected_on": _date(inspection.inspection_date),
                "valid_from": _date(inspection.valid_from),
                "valid_until": _date(inspection.valid_until),
                "report_url": inspection.report_url,
                "first_registration": _date(inspection.first_registration),
                "mileage_km": inspection.inspection_mileage,
                "has_accident": inspection.has_accident,
                "has_outer_damage": inspection.has_outer_damage,
                "has_flood": inspection.has_flood,
                "has_tuning": inspection.has_tuning,
                "has_recall": inspection.has_recall,
                "own_damage_cost": _money_dict(inspection.my_accident_cost),
                "other_damage_cost": _money_dict(inspection.other_accident_cost),
                "accident_detail": inspection.accident_detail,
                "outer_detail": inspection.outer_detail,
                "details": inspection.details,
            },
            extra,
            "inspection_",
        )
    return CarLotV1(
        source=SOURCE,
        source_id=lot.id,
        url=lot.lot_url,
        observed_at=observed_at,
        run_id=run_id,
        vehicle=vehicle,
        listing=listing,
        history=history,
        inspection=insp,
        selection=selection or Selection(passed=True),
        extra=extra,
    )
