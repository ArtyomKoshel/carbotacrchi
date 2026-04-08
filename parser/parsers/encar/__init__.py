from __future__ import annotations

import logging
import re as _re
import time as _time
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Callable

import httpx

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from ..base import AbstractParser
from .client import EncarClient
from .normalizer import EncarNormalizer

logger = logging.getLogger(__name__)

_SOURCE = "encar"
_PAGE_SIZE = 100
_BATCH_SIZE = 20   # batch_details API hard-caps at 20 items
_MAX_SAFE_OFFSET = 9900  # Encar search API (Elasticsearch) caps at ~10k results per query


def _lot_from_search(item: dict, norm: EncarNormalizer) -> CarLot:
    vid = str(item["Id"])
    make_kr = item.get("Manufacturer", "")
    model    = item.get("Model", "")
    badge    = item.get("Badge", "")        # grade/fuel e.g. "디젤 2.2 4WD"
    badge_detail = item.get("BadgeDetail", "")  # trim e.g. "노블레스"

    year_raw = item.get("FormYear") or str(item.get("Year") or "")
    year = int(str(year_raw)[:4]) if year_raw and len(str(year_raw)) >= 4 else 0

    price_man = int(item.get("Price") or 0)
    if price_man > 1_000_000_000:  # > 10 trillion KRW — clearly garbage data
        logger.warning(f"[encar] lot {item.get('Id')}: absurd price {price_man}만원, zeroing")
        price_man = 0
    price_krw = norm.price_krw(price_man)
    mileage   = int(item.get("Mileage") or 0)

    # Drive type: scan badge + model tokens for known keywords (e.g. "4WD", "AWD", "2WD")
    _drive_tokens = f"{model} {badge}".split()
    drive_type = next(
        (norm.drive(t) for t in _drive_tokens if norm.drive(t)),
        None,
    )

    # Main photo: first Photos entry or Photo prefix
    photos = item.get("Photos") or []
    photo_path = photos[0]["location"] if photos else ""
    image_url = EncarClient.photo_url(photo_path) if photo_path else None

    location = item.get("OfficeCityState") or ""

    return CarLot(
        id=vid,
        source=_SOURCE,
        make=norm.make(make_kr),
        model=f"{model} {badge}".strip() if badge else model,
        trim=badge_detail or None,
        year=year,
        price=price_krw,
        price_krw=price_krw,
        mileage=mileage,
        fuel=norm.fuel(item.get("FuelType")),
        transmission=norm.transmission(item.get("Transmission")),
        color=item.get("Color") or None,
        seat_color=item.get("SeatColor") or None,
        drive_type=drive_type,
        location=location or None,
        image_url=image_url,
        lot_url=f"https://fem.encar.com/cars/detail/{vid}",
        raw_data={
            "manufacturer_kr":   make_kr,
            "model_kr":          model,
            "model_group_kr":    item.get("ModelGroup"),
            "badge_kr":          badge,
            "badge_detail_kr":   badge_detail,
            "year_month":        item.get("Year"),
            "sell_type":         item.get("SellType"),
            "ad_type":           item.get("AdType"),
            "photo_path":        photo_path or None,
            "condition":         item.get("Condition") or [],
        },
    )


def _enrich_from_detail(lot: CarLot, detail: dict, norm: EncarNormalizer) -> None:
    # Detail API returns flat structure (not nested under 'base')
    cat     = detail.get("category", {})
    spec    = detail.get("spec", {})
    adv     = detail.get("advertisement", {})
    contact = detail.get("contact", {})
    manage  = detail.get("manage", {})
    photos  = detail.get("photos", [])
    opts    = detail.get("options", {})
    cond    = detail.get("condition", {})
    partner = detail.get("partnership", {})

    if spec.get("transmissionName"):
        lot.transmission = norm.transmission(spec["transmissionName"])
    if spec.get("fuelName"):
        lot.fuel = norm.fuel(spec["fuelName"])
    if spec.get("colorName"):
        lot.color = spec["colorName"]
    if spec.get("bodyName"):
        lot.body_type = norm.body(spec["bodyName"])
    if spec.get("displacement"):
        lot.engine_volume = round(spec["displacement"] / 1000, 1)
    if spec.get("seatCount"):
        lot.raw_data["seat_count"] = spec["seatCount"]

    if detail.get("vin"):
        lot.vin = detail["vin"]
    if detail.get("vehicleNo"):
        lot.plate_number = detail["vehicleNo"]

    if contact.get("address"):
        lot.location = contact["address"]
    if contact.get("no"):
        lot.dealer_phone = contact["no"]
    if contact.get("userId"):
        lot.dealer_name = contact["userId"]
    dealer = (partner or {}).get("dealer") or {}
    firm = dealer.get("firm") or {}
    if firm.get("name"):
        lot.dealer_company = firm["name"]

    if manage.get("registDateTime"):
        lot.registration_date = manage["registDateTime"][:10]

    # NOTE: lien_status/seizure_status are set from the Record API in _enrich_from_record
    # (rec["loan"] / rec["robberCnt"]) which is the authoritative source.
    # The batch detail API's seizing.pledgeCount is unreliable and must not overwrite it.

    outer = [p["path"] for p in photos if p.get("type") == "OUTER"]
    if outer and not lot.image_url:
        lot.image_url = EncarClient.photo_url(outer[0])

    all_photo_urls = [EncarClient.photo_url(p["path"]) for p in photos if p.get("path")]
    if all_photo_urls:
        lot.raw_data["photos"] = all_photo_urls

    # Inspection uses an inner vehicle ID embedded in photo paths (e.g. /pic4097/40977911_004.jpg)
    # which can differ from the listing ID (lot.id).
    if photos:
        _m = _re.search(r'/(\d+)_\d+\.', photos[0].get("path", ""))
        if _m and _m.group(1) != lot.id:
            lot.raw_data["inspect_vehicle_id"] = _m.group(1)

    std_opts = opts.get("standard", [])
    if std_opts:
        lot.options = std_opts

    lot.raw_data.update({
        "grade_detail_kr": cat.get("gradeDetailName"),
        "grade_detail_en": cat.get("gradeDetailEnglishName"),
        "domestic":        cat.get("domestic"),
        "import_type":     cat.get("importType"),
        "ad_status":       adv.get("status"),
        "origin_price":    cat.get("originPrice"),
        "photo_count":     len(photos),
    })


_ACCIDENT_TYPE = {"1": "my-fault", "2": "my-fault", "3": "other-fault"}
_OUTER_STATUS  = {"W": "panel", "X": "replaced", "A": "scratch", "U": "damaged", "C": "corrosion"}


def _parse_outer_damage(outers: list) -> tuple[bool, str]:
    if not outers:
        return False, ""
    parts = []
    for o in outers:
        title    = (o.get("type") or {}).get("title", "")
        statuses = [(s.get("title") or "") for s in o.get("statusTypes") or []]
        if title and statuses:
            parts.append(f"{title}: {', '.join(statuses)}")
    return len(parts) > 0, "\n".join(parts)


def _enrich_from_record(lot: CarLot, rec: dict) -> InspectionRecord:
    """Update CarLot from accident-history record API and return InspectionRecord."""
    my_cnt    = int(rec.get("myAccidentCnt") or 0)
    other_cnt = int(rec.get("otherAccidentCnt") or 0)
    lot.has_accident    = (my_cnt + other_cnt) > 0
    lot.insurance_count = int(rec.get("accidentCnt") or (my_cnt + other_cnt))
    lot.owners_count    = rec.get("ownerChangeCnt")

    flood = int(rec.get("floodTotalLossCnt") or 0) + int(rec.get("floodPartLossCnt") or 0)
    lot.flood_history      = flood > 0
    lot.total_loss_history = int(rec.get("totalLossCnt") or 0) > 0

    lot.lien_status    = "lien"    if int(rec.get("loan") or 0)     > 0 else "clean"
    lot.seizure_status = "seizure" if int(rec.get("robberCnt") or 0) > 0 else "clean"

    my_cost    = int(rec.get("myAccidentCost") or 0)
    other_cost = int(rec.get("otherAccidentCost") or 0)
    if my_cost + other_cost > 0:
        lot.repair_cost = my_cost + other_cost

    if rec.get("firstDate") and not lot.registration_date:
        lot.registration_date = rec["firstDate"]

    accidents = rec.get("accidents") or []
    acc_lines = [
        f"{a.get('date', '')} [{_ACCIDENT_TYPE.get(a.get('type',''),'?')}] ₩{int(a.get('insuranceBenefit',0)):,}"
        for a in accidents
    ]

    return InspectionRecord(
        lot_id=lot.id,
        source="encar",
        first_registration=rec.get("firstDate"),
        has_accident=lot.has_accident,
        has_flood=lot.flood_history,
        accident_detail="\n".join(acc_lines) if acc_lines else None,
        details={
            "accidents":           accidents,
            "owner_changes":       rec.get("ownerChanges"),
            "plate_changes":       rec.get("carInfoChanges"),
            "plate_change_cnt":    rec.get("carNoChangeCnt"),
            "robber_cnt":          rec.get("robberCnt"),
            "total_loss_cnt":      rec.get("totalLossCnt"),
            "loan":                rec.get("loan"),
            "my_accident_cost":    my_cost,
            "other_accident_cost": other_cost,
            "government":          rec.get("government"),
            "business":            rec.get("business"),
        },
    )


def _enrich_from_inspection(
    lot: CarLot, insp: dict, record: InspectionRecord
) -> None:
    """Merge inspection API data into CarLot and update InspectionRecord in place."""
    master = insp.get("master") or {}
    detail = master.get("detail") or {}

    if master.get("accdient") is not None:
        # master.accdient = structural accident (성능점검 판단), not insurance claims.
        # Only update lot.has_accident if not already set by the record API.
        if lot.has_accident is None:
            lot.has_accident = master["accdient"]
        record.has_accident = master["accdient"]

    if detail.get("waterlog") is not None:
        lot.flood_history = detail["waterlog"]
        record.has_flood  = detail["waterlog"]

    if detail.get("tuning") is not None:
        record.has_tuning = detail["tuning"]

    if detail.get("vin") and not lot.vin:
        lot.vin = detail["vin"]

    outers = insp.get("outers") or []
    has_outer, outer_text = _parse_outer_damage(outers)
    record.has_outer_damage = has_outer
    if outer_text:
        record.outer_detail = outer_text

    if master.get("supplyNum"):
        record.cert_no = str(master["supplyNum"])[:100]
    if master.get("registrationDate"):
        record.inspection_date = master["registrationDate"][:10]
    record.report_url = (
        f"https://www.encar.com/md/sl/mdsl_regcar.do"
        f"?method=inspectionViewNew&carid={lot.id}"
    )

    def _parse_date8(s: str | None) -> str | None:
        if not s or len(s) != 8 or not s.isdigit():
            return None
        m, d = int(s[4:6]), int(s[6:8])
        if not (1 <= m <= 12 and 1 <= d <= 31):
            return None
        return f"{s[:4]}-{s[4:6]}-{s[6:8]}"

    if vs := _parse_date8(detail.get("validityStartDate")):
        record.valid_from = vs
    if ve := _parse_date8(detail.get("validityEndDate")):
        record.valid_until = ve
    if fr := _parse_date8(detail.get("firstRegistrationDate")):
        record.first_registration = fr
        if not lot.registration_date:
            lot.registration_date = fr

    if detail.get("mileage"):
        record.inspection_mileage = int(detail["mileage"])

    # Engine model code (e.g. "D4CB", "G4KE") and warranty type
    if detail.get("motorType"):
        lot.raw_data["engine_code"] = detail["motorType"]
    if detail.get("guarantyType"):
        lot.raw_data["warranty_type"] = (detail["guarantyType"] or {}).get("title")

    # Recall status
    recall_flag = detail.get("recall")
    recall_types = [(r.get("title") or "") for r in (detail.get("recallFullFillTypes") or [])]
    if recall_flag:
        lot.raw_data["recall"] = True
        lot.raw_data["recall_status"] = recall_types or ["미확인"]

    # Overall car state
    if detail.get("carStateType"):
        lot.raw_data["car_state"] = (detail["carStateType"] or {}).get("title")

    # Mechanical anomalies from inners (engine / transmission / etc.)
    _BAD_INNER = {"누유", "누수", "미세누수", "불량", "부족", "과다", "누유있음", "미세누유"}
    def _collect_inner_issues(node: dict, path: str = "") -> list[str]:
        title     = (node.get("type") or {}).get("title", "")
        full_path = f"{path}/{title}" if path else title
        st_title  = (node.get("statusType") or {}).get("title", "")
        issues: list[str] = []
        if st_title and st_title in _BAD_INNER:
            issues.append(f"{full_path} → {st_title}")
        for ch in node.get("children") or []:
            issues.extend(_collect_inner_issues(ch, full_path))
        return issues

    mech_issues: list[str] = []
    for inner in (insp.get("inners") or []):
        mech_issues.extend(_collect_inner_issues(inner))
    if mech_issues:
        lot.raw_data["mechanical_issues"] = mech_issues

    record.details = record.details or {}
    record.details.update({
        "simple_repair":       master.get("simpleRepair"),
        "engine_check":        detail.get("engineCheck"),
        "trns_check":          detail.get("trnsCheck"),
        "recall":              recall_flag,
        "recall_types":        recall_types,
        "mechanical_issues":   mech_issues or None,
        "serious_types":       [(s.get("title") or "") for s in (detail.get("seriousTypes") or [])],
        "car_state":           (detail.get("carStateType") or {}).get("title"),
        "outer_parts":         [{"part": (o.get("type") or {}).get("title"), "status": [(s.get("title")) for s in o.get("statusTypes") or []]} for o in outers],
    })


def _enrich_from_inspection_html(
    lot: CarLot, html: str, record: InspectionRecord
) -> None:
    """Parse the human-readable inspection report (www.encar.com/md/sl/mdsl_regcar.do).

    Used as a fallback when the JSON inspection API is unavailable.
    Extracts: VIN, plate, first-registration date, engine code, mileage,
    accident/simple-repair flags, recall status, tuning, and flood history.
    """
    from bs4 import BeautifulSoup
    soup = BeautifulSoup(html, "lxml")

    # ── Helper: find <td> immediately after a <th> whose text contains `label` ─
    def _td_after(label: str) -> str | None:
        for th in soup.find_all("th", scope="row"):
            if label in th.get_text():
                td = th.find_next_sibling("td")
                return td.get_text(strip=True) if td else None
        return None

    # ── Helper: for a status row, return the text of the selected span (on/active) ─
    def _selected_state(row_label: str) -> str | None:
        for th in soup.find_all("th", scope="row"):
            if th.get_text(strip=True).startswith(row_label):
                td = th.find_next_sibling("td")
                if td:
                    sel = td.find("span", class_=lambda c: c and ("active" in c or " on" in c or c.endswith("on")))
                    return sel.get_text(strip=True) if sel else None
        return None

    # ── Basic info table ──────────────────────────────────────────────────────
    vin = _td_after("차대번호")
    if vin and not lot.vin:
        lot.vin = vin

    plate = _td_after("차량번호")
    if plate and not lot.plate_number:
        lot.plate_number = plate

    reg_raw = _td_after("최초등록일")
    if reg_raw:
        m = _re.search(r"(\d{4})년\s*(\d{1,2})월\s*(\d{1,2})일", reg_raw)
        if m:
            reg_date = f"{m.group(1)}-{int(m.group(2)):02d}-{int(m.group(3)):02d}"
            if not lot.registration_date:
                lot.registration_date = reg_date
            if not record.first_registration:
                record.first_registration = reg_date

    engine_code = _td_after("원동기형식")
    if engine_code and not lot.raw_data.get("engine_code"):
        lot.raw_data["engine_code"] = engine_code

    warranty = _td_after("보증유형")
    if warranty and not lot.raw_data.get("warranty_type"):
        lot.raw_data["warranty_type"] = warranty

    # ── Cert / performance number from .ckdate span ───────────────────────────
    ckdate = soup.find("span", class_="ckdate")
    if ckdate and not record.cert_no:
        m2 = _re.search(r"성능번호\s*제\s*([\d]+)\s*호", ckdate.get_text())
        if m2:
            record.cert_no = m2.group(1)

    # ── Mileage at inspection ─────────────────────────────────────────────────
    for th in soup.find_all("th", scope="row"):
        if "주행거리" in th.get_text() and "계기" not in th.get_text():
            # mileage value is in 2nd <td> sibling (first has 많음/보통/적음 spans)
            for td in th.find_next_siblings("td"):
                detail = td.find("span", class_="txt_detail")
                if detail:
                    km_m = _re.search(r"([\d,]+)\s*km", detail.get_text())
                    if km_m and not record.inspection_mileage:
                        record.inspection_mileage = int(km_m.group(1).replace(",", ""))
                    break
            break

    # ── Status flags ──────────────────────────────────────────────────────────
    def _is_selected(row_label: str, value: str) -> bool:
        for th in soup.find_all("th", scope="row"):
            if th.get_text(strip=True).startswith(row_label):
                td = th.find_next_sibling("td")
                if not td:
                    continue
                for span in td.find_all("span", class_="txt_state"):
                    if value in span.get_text(strip=True):
                        classes = span.get("class", [])
                        return "on" in classes or "active" in classes
        return False

    # Accident history (사고이력): 있음 selected → has structural accident
    if lot.has_accident is None:
        if _is_selected("사고이력", "있음"):
            lot.has_accident = True
            record.has_accident = True
        elif _is_selected("사고이력", "없음"):
            lot.has_accident = False
            record.has_accident = False

    # Simple repair (단순수리): store in record.details
    simple_repair = _is_selected("단순수리", "있음")
    record.details = record.details or {}
    record.details["simple_repair"] = simple_repair

    # Flood (침수): 있음 = True
    if _is_selected("침수", "있음"):
        lot.flood_history = True
        record.has_flood = True
    elif _is_selected("침수", "없음"):
        lot.flood_history = False
        record.has_flood = False

    # Tuning (튜닝): 있음 = True
    if not record.details.get("tuning_set"):
        if _is_selected("튜닝", "있음"):
            record.has_tuning = True
        elif _is_selected("튜닝", "없음"):
            record.has_tuning = False
        record.details["tuning_set"] = True

    # Recall (리콜대상): 해당 = True (exact match — avoid matching inside 해당없음)
    def _is_selected_exact(row_label: str, value: str) -> bool:
        for th in soup.find_all("th", scope="row"):
            if th.get_text(strip=True).startswith(row_label):
                td = th.find_next_sibling("td")
                if not td:
                    continue
                for span in td.find_all("span", class_="txt_state"):
                    if span.get_text(strip=True) == value:
                        classes = span.get("class", [])
                        return "on" in classes or "active" in classes
        return False

    if _is_selected_exact("리콜대상", "해당"):
        lot.raw_data["recall"] = True

    # Report URL
    if not record.report_url:
        record.report_url = (
            f"https://www.encar.com/md/sl/mdsl_regcar.do"
            f"?method=inspectionViewNew&carid={lot.id}"
        )


_DIAG_RESULT_MAP = {
    "NORMAL":      "정상",
    "REPLACEMENT": "교환",
    "PANEL":       "판금",
    "SCRATCH":     "스크래치",
    "CORROSION":   "부식",
}

_DIAG_PART_MAP = {
    "HOOD":               "후드",
    "FRONT_FENDER_LEFT":  "프론트 휀더(좌)",
    "FRONT_FENDER_RIGHT": "프론트 휀더(우)",
    "FRONT_DOOR_LEFT":    "앞 도어(좌)",
    "FRONT_DOOR_RIGHT":   "앞 도어(우)",
    "BACK_DOOR_LEFT":     "뒤 도어(좌)",
    "BACK_DOOR_RIGHT":    "뒤 도어(우)",
    "TRUNK_LID":          "트렁크 리드",
    "QUARTER_PANEL_LEFT": "쿼터패널(좌)",
    "QUARTER_PANEL_RIGHT":"쿼터패널(우)",
    "ROOF_PANEL":         "루프 패널",
    "SIDE_SILL_LEFT":     "사이드실(좌)",
    "SIDE_SILL_RIGHT":    "사이드실(우)",
}


def _enrich_from_diagnosis(
    lot: CarLot, diag: dict, record: InspectionRecord
) -> None:
    """Parse Encar internal diagnosis (body panel inspection) into InspectionRecord."""
    items = diag.get("items") or []
    non_normal = []
    checker_comment = None
    outer_comment   = None

    for it in items:
        name   = it.get("name", "")
        result = it.get("result", "")
        code   = it.get("resultCode")
        if name == "CHECKER_COMMENT":
            checker_comment = result
        elif name == "OUTER_PANEL_COMMENT":
            outer_comment = result
        elif code and code != "NORMAL":
            part_kr = _DIAG_PART_MAP.get(name, name)
            non_normal.append(f"{part_kr}: {result}")

    has_damage = bool(non_normal)
    if has_damage:
        record.has_outer_damage = True
        damage_text = "\n".join(non_normal)
        if outer_comment:
            damage_text += f"\n\n[Encar 진단]\n{outer_comment}"
        record.outer_detail = damage_text

    if diag.get("diagnosisDate") and not record.inspection_date:
        record.inspection_date = diag["diagnosisDate"][:10]

    record.details = record.details or {}
    record.details["diagnosis"] = {
        "diagnosisNo":   diag.get("diagnosisNo"),
        "center":        diag.get("reservationCenterName"),
        "date":          diag.get("diagnosisDate", "")[:10],
        "checker_comment": checker_comment,
        "items":         [{"part": it.get("name"), "result": it.get("resultCode")} for it in items
                          if it.get("resultCode")],
    }
    lot.raw_data["diagnosis_center"] = diag.get("reservationCenterName")


_DRIVE_PART_CODE  = "SPEC_drivingMethodNm"
_OPT_KEYS         = 10    # 차량 키 수량
_OPT_TINT         = 16    # 틴팅 (정면 유리)
_OPT_TIRE_FL      = 330   # 동승석(앞) tread
_OPT_TIRE_FR      = 327   # 운전석(앞) tread
_OPT_TIRE_RL      = 329   # 동승석(뒤) tread
_OPT_TIRE_RR      = 328   # 운전석(뒤) tread


def _enrich_from_verification(lot: CarLot, vdata: dict) -> None:
    """Parse /verification/{id}/simple response into CarLot fields."""
    items = vdata.get("items") or []
    opt_map: dict[int, str] = {}
    for item in items:
        opt_id = (item.get("option") or {}).get("id")
        val    = item.get("value")
        if opt_id is not None and val is not None:
            opt_map[opt_id] = val

    # Keys count
    if _OPT_KEYS in opt_map:
        try:
            keys_count = int(opt_map[_OPT_KEYS])
            lot.has_keys = keys_count > 0
            lot.raw_data["keys_count"] = keys_count
        except ValueError:
            pass

    # Tire tread depth (mm) for all 4 positions
    tire_map = {
        "fl": _OPT_TIRE_FL, "fr": _OPT_TIRE_FR,
        "rl": _OPT_TIRE_RL, "rr": _OPT_TIRE_RR,
    }
    tire_depths: dict[str, int] = {}
    for pos, opt_id in tire_map.items():
        if opt_id in opt_map:
            try:
                tire_depths[pos] = int(opt_map[opt_id])
            except ValueError:
                pass
    if tire_depths:
        lot.raw_data["tire_depth_mm"] = tire_depths

    # Tinting
    if _OPT_TINT in opt_map:
        lot.raw_data["front_tinting"] = opt_map[_OPT_TINT] == "INCLUDE"

    # Extra photos from itemPictures (add to raw_data for display)
    pics = vdata.get("itemPictures") or []
    extra_photos: list[str] = []
    for pic in pics:
        for att in pic.get("attachments") or []:
            key = att.get("key")
            if key:
                extra_photos.append(EncarClient.verify_photo_url(key))
    if extra_photos:
        lot.raw_data["verify_photos"] = extra_photos
        if not lot.image_url:
            lot.image_url = extra_photos[0]


def _enrich_from_sellingpoint(lot: CarLot, sp: dict, norm: EncarNormalizer) -> None:
    """Extract drive_type from uniqueOptionPhotos; store sellingPoint sentence in raw_data."""
    for photo in sp.get("uniqueOptionPhotos") or []:
        if photo.get("partCode") == _DRIVE_PART_CODE:
            part_name = photo.get("partName", "")  # e.g. "구동방식(전륜)"
            # Extract value inside parentheses: "구동방식(전륜)" → "전륜"
            if "(" in part_name and ")" in part_name:
                raw = part_name[part_name.index("(") + 1: part_name.rindex(")")]
                lot.drive_type = norm.drive(raw)
            break

    selling = sp.get("sellingPoint") or {}
    sentence = selling.get("sentence")
    if sentence:
        lot.raw_data["selling_point"] = sentence


class EncarParser(AbstractParser):
    def __init__(self, repo: LotRepository):
        super().__init__(repo)
        self._client = EncarClient()
        self._norm = EncarNormalizer()

    def get_source_key(self) -> str:
        return _SOURCE

    def get_source_name(self) -> str:
        return "Encar"

    def _paginate_query(
        self,
        query: str,
        max_pages: int,
        seen_ids: set[str],
        existing_ids: set[str],
        stats: dict,
        on_page_callback: Callable | None,
        label: str = "",
        collect_models: dict[str, set[str]] | None = None,
    ) -> int:
        """Paginate one Encar search query. Returns API total count.
        Stops early when API cycles (all results already in seen_ids)."""
        source = _SOURCE
        total_count: int | None = None
        call_seen: set[str] = set()  # IDs first encountered in THIS call — for cycling detection only

        for page in range(max_pages):
            _t_page = _time.monotonic()
            offset = page * _PAGE_SIZE
            if offset > _MAX_SAFE_OFFSET:
                logger.info(f"[{source}]{label} offset {offset} reached API cap, stopping segment")
                break
            try:
                data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
            except httpx.HTTPStatusError as e:
                if e.response.status_code in (403, 407, 429, 503):
                    logger.warning(f"[{source}]{label} p.{page+1}: {e.response.status_code}, rotating proxy and retrying")
                    self._client.rotate_proxy()
                    _time.sleep(1)
                    try:
                        data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
                    except Exception as e2:
                        logger.error(f"[{source}]{label} p.{page+1} retry failed: {e2}")
                        break
                else:
                    logger.error(f"[{source}]{label} p.{page+1} error: {e}")
                    break
            except Exception as e:
                logger.error(f"[{source}]{label} p.{page+1} error: {e}")
                break

            if total_count is None:
                total_count = data.get("Count", 0)
                logger.debug(f"[{source}]{label} total: {total_count}")

            items = data.get("SearchResults", [])
            if not items:
                logger.debug(f"[{source}]{label} p.{page+1}: empty, stopping")
                break

            page_lots: list[CarLot] = []
            phase1_skip = 0
            for item in items:
                vid = str(item.get("Id", ""))
                if not vid:
                    continue
                if vid in seen_ids:
                    phase1_skip += 1
                    continue
                seen_ids.add(vid)
                call_seen.add(vid)
                lot = _lot_from_search(item, self._norm)
                page_lots.append(lot)
                if collect_models is not None:
                    mk = item.get("Manufacturer", "")
                    mo = item.get("Model", "")
                    if mk:
                        collect_models.setdefault(mk, set())
                        if mo:
                            collect_models[mk].add(mo)

            if not page_lots and items:
                # True cycling: API returned IDs we already saw in THIS call
                truly_cycling = all(str(i.get("Id", "")) in call_seen for i in items)
                if truly_cycling:
                    logger.info(f"[{source}]{label} p.{page+1}: all {len(items)} results already seen in this run — API cycling, stopping")
                    break
                # Phase overlap: IDs seen in a prior phase — advance to next page
                logger.info(f"[STAT] [{source}]{label} p.{page+1}: {phase1_skip} seen in prior phase, skipping → next page")
                if offset + _PAGE_SIZE >= (total_count or 0):
                    break
                continue

            logger.debug(f"[{source}]{label} p.{page+1}: {len(page_lots)} lots from search")

            self._enrich_batch(page_lots, stats)

            self.repo.upsert_batch(page_lots)
            for lot in page_lots:
                photos = lot.raw_data.get("photos") or []
                if photos:
                    self.repo.upsert_photos(lot.id, photos)
                is_new = lot.id not in existing_ids
                if is_new:
                    stats["new"] += 1
                    logger.debug(
                        f"[{source}] NEW {lot.id} | "
                        f"{lot.make} {lot.model} {lot.year} | "
                        f"{lot.price // 10000:,}만원 | {lot.mileage:,}km | {lot.fuel or '-'}"
                    )
                else:
                    stats["updated"] += 1
                    logger.debug(f"[{source}] UPD {lot.id} | {lot.make} {lot.model} {lot.year}")
                stats["total"] += 1

            if on_page_callback:
                on_page_callback(
                    page=stats["total"] // _PAGE_SIZE,
                    found=len(page_lots),
                    total_pages=None,
                )

            _t_after_upsert = _time.monotonic()
            new_lots = [l for l in page_lots if l.id not in existing_ids]
            if new_lots:
                self._enrich_accident_data(new_lots, stats)

            _t_total = _time.monotonic() - _t_page
            _t_enrich = _time.monotonic() - _t_after_upsert
            _t_batch = _t_after_upsert - _t_page
            logger.info(
                f"[STAT] [{source}]{label} p.{page+1} done in {_t_total:.1f}s "
                f"(batch+upsert={_t_batch:.1f}s, enrich={_t_enrich:.1f}s, "
                f"new={len(new_lots)}/{len(page_lots)})"
            )

            if offset + _PAGE_SIZE >= (total_count or 0):
                logger.debug(f"[{source}]{label} reached end ({total_count} total)")
                break

        return total_count or 0

    def run(
        self,
        max_pages: int | None = None,
        maker_filter: str | None = None,
        on_page_callback: Callable | None = None,
    ) -> int:
        source = _SOURCE
        run_start = _time.monotonic()
        stats = {"total": 0, "new": 0, "updated": 0, "errors": 0}

        pages = max_pages or 9999  # 0 / None = all pages

        logger.info(f"[STAT] [{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[STAT] [{source}] Pages: {pages}, page_size: {_PAGE_SIZE}")

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()

        api_total: int = 0  # total listings reported by Encar API

        if maker_filter or max_pages:
            query = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker_filter}.)" if maker_filter else "(And.Hidden.N._.CarType.A.)"
            if maker_filter:
                logger.info(f"[{source}] Maker filter: {maker_filter}")
            api_total = self._paginate_query(query, pages, seen_ids, existing_ids, stats, on_page_callback)
        else:
            # Phase 1: global scan to discover all manufacturers (capped at 10k)
            base_query = "(And.Hidden.N._.CarType.A.)"
            discovered_models: dict[str, set[str]] = {}
            logger.info(f"[{source}] Phase 1: global scan to discover manufacturers and models")
            api_total = self._paginate_query(
                base_query, 100, seen_ids, existing_ids, stats,
                on_page_callback, label=" [global]", collect_models=discovered_models,
            )
            discovered_makers = sorted(discovered_models.keys())
            logger.info(f"[{source}] Phase 1 done. Manufacturers found: {discovered_makers}")
            logger.info(f"[{source}] Phase 1 done. Models per maker: { {k: len(v) for k, v in discovered_models.items()} }")

            # Phase 2: per-manufacturer queries to bypass 10k pagination cap
            logger.info(f"[{source}] Phase 2: per-manufacturer pagination")
            for maker in discovered_makers:
                mq = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)"
                try:
                    count_data = self._client.search(query=mq, offset=0, count=1)
                    maker_total = count_data.get("Count", 0)
                except Exception as e:
                    logger.warning(f"[{source}] [{maker}] count query failed: {e}")
                    continue

                logger.debug(f"[{source}] [{maker}]: {maker_total} total")
                if maker_total == 0:
                    continue

                if maker_total <= _MAX_SAFE_OFFSET:
                    self._paginate_query(
                        mq, 100, seen_ids, existing_ids, stats,
                        on_page_callback, label=f" [{maker}]",
                    )
                else:
                    maker_models = sorted(discovered_models.get(maker, []))
                    logger.info(f"[{source}] [{maker}] {maker_total} > {_MAX_SAFE_OFFSET}, splitting by model ({len(maker_models)} models discovered)")
                    if not maker_models:
                        logger.warning(f"[{source}] [{maker}] no models from Phase 1, paginating up to cap")
                        self._paginate_query(mq, 100, seen_ids, existing_ids, stats, on_page_callback, label=f" [{maker}]")
                        continue
                    for model in maker_models:
                        model_q = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Model.{model}.)"
                        try:
                            mdata = self._client.search(query=model_q, offset=0, count=1)
                            model_total = mdata.get("Count", 0)
                        except Exception as e:
                            logger.warning(f"[{source}] [{maker}/{model}] count query failed: {e}")
                            continue
                        if model_total > 0:
                            self._paginate_query(
                                model_q, 100, seen_ids, existing_ids, stats,
                                on_page_callback, label=f" [{maker}/{model}]",
                            )

        stale = self.repo.mark_inactive(source, seen_ids, grace_hours=24)
        elapsed = _time.monotonic() - run_start

        db_count = self.repo.count_active(source)
        coverage_pct = stats["total"] / api_total * 100 if api_total else 0.0

        logger.info(f"[STAT] [{source}] ========== IMPORT COMPLETE ==========")
        logger.info(f"[STAT] [{source}] API reported: {api_total:,}")
        logger.info(f"[STAT] [{source}] Processed:   {stats['total']:,} ({coverage_pct:.1f}% coverage)")
        logger.info(f"[STAT] [{source}] In DB now:   {db_count:,}")
        logger.info(f"[STAT] [{source}] New:     {stats['new']}")
        logger.info(f"[STAT] [{source}] Updated: {stats['updated']}")
        logger.info(f"[STAT] [{source}] Stale:   {stale}")
        logger.info(f"[STAT] [{source}] Errors:  {stats['errors']}")
        logger.info(f"[STAT] [{source}] Time:    {elapsed:.1f}s")

        self._client.close()
        return stats["total"]

    def _enrich_batch(self, lots: list[CarLot], stats: dict) -> None:
        if not lots:
            return
        ids = [lot.id for lot in lots]
        id_map = {lot.id: lot for lot in lots}

        for i in range(0, len(ids), _BATCH_SIZE):
            chunk = ids[i: i + _BATCH_SIZE]
            try:
                details = self._client.batch_details(chunk)
                logger.debug(f"[encar] batch_details: API returned {len(details)} items for {len(chunk)} requested")
                enriched = 0
                for detail in details:
                    manage = detail.get("manage") or {}
                    # dummy=True: inner vehicleId differs from listing Id;
                    # dummyVehicleId holds the actual listing Id we requested.
                    if manage.get("dummy") and manage.get("dummyVehicleId"):
                        listing_id = str(manage["dummyVehicleId"])
                    else:
                        listing_id = str(detail.get("vehicleId", ""))
                    # Also store inner vehicleId for inspection API calls
                    inner_id = str(detail.get("vehicleId", ""))
                    lot = id_map.get(listing_id)
                    if lot:
                        if inner_id and inner_id != listing_id:
                            lot.raw_data["inspect_vehicle_id"] = inner_id
                        _enrich_from_detail(lot, detail, self._norm)
                        enriched += 1
                    else:
                        logger.debug(f"[encar] batch_details: listing_id={listing_id!r} unmatched")
                logger.debug(f"[encar] batch_details: enriched {enriched}/{len(chunk)} lots (API returned {len(details)})")
            except Exception as e:
                logger.warning(f"[encar] batch_details failed ({type(e).__name__}: {e}), falling back to single fetch")
                ok = 0
                for vid in chunk:
                    try:
                        detail = self._client.detail(vid)
                        if vid in id_map:
                            _enrich_from_detail(id_map[vid], detail, self._norm)
                            ok += 1
                    except Exception as e2:
                        logger.error(f"[encar] detail {vid} error: {type(e2).__name__}: {e2}")
                        stats["errors"] += 1
                        # rotate proxy on block/rate-limit
                        if isinstance(e2, httpx.HTTPStatusError) and e2.response.status_code in (403, 429, 503):
                            if self._client.rotate_proxy():
                                logger.info(f"[encar] rotated proxy after {e2.response.status_code}")
                        elif isinstance(e2, (httpx.ProxyError, httpx.ConnectError)):
                            self._client.rotate_proxy()
                    _time.sleep(0.5)
                logger.info(f"[encar] single fallback: enriched {ok}/{len(chunk)} lots")

    @staticmethod
    def _fetch_lot_enrichment(
        lot: CarLot, client: EncarClient, norm: EncarNormalizer
    ) -> tuple[CarLot, InspectionRecord | None, int]:
        """HTTP-only enrichment for one lot. Safe to run in a thread — no DB access."""
        source = _SOURCE
        insp_record: InspectionRecord | None = None
        is_certified = False
        errors = 0

        _inner_id = lot.raw_data.get("inspect_vehicle_id") or lot.id
        condition = set(lot.raw_data.get("condition") or [])
        has_record     = "Record"     in condition
        has_inspection = "Inspection" in condition

        def _call(fn, *args):
            """Call fn(*args), retry once with fresh proxy on rate-limit/block."""
            try:
                return fn(*args)
            except httpx.HTTPStatusError as e:
                if e.response.status_code in (403, 429, 503):
                    logger.debug(f"[{source}] {e.response.status_code} on {lot.id} — rotating proxy, retrying")
                    client.rotate_proxy()
                    return fn(*args)
                raise
            except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout):
                client.rotate_proxy()
                return fn(*args)

        # Record API — only if car has record data
        if has_record:
            try:
                rec = _call(client.record, _inner_id, lot.plate_number or None)
                if rec and rec.get("openData"):
                    is_certified = True
                    insp_record = _enrich_from_record(lot, rec)
            except Exception as e:
                logger.warning(f"[{source}] record {lot.id}: {e}")
                errors += 1

        # Inspection JSON API — only if car has inspection data
        insp_api_ok = False
        if has_inspection:
            try:
                insp = _call(client.inspection, _inner_id)
                if insp:
                    if insp_record is None:
                        insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                    _enrich_from_inspection(lot, insp, insp_record)
                    is_certified = True
                    insp_api_ok = True
            except Exception as e:
                logger.warning(f"[{source}] inspection {lot.id}: {e}")

        # HTML inspection fallback — only if JSON API returned nothing
        if has_inspection and not insp_api_ok:
            try:
                html = _call(client.inspection_html, _inner_id)
                if html:
                    if insp_record is None:
                        insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                    _enrich_from_inspection_html(lot, html, insp_record)
                    logger.debug(f"[{source}] inspection_html {lot.id}: ok (fallback)")
            except Exception as e:
                logger.warning(f"[{source}] inspection_html {lot.id}: {e}")

        # Diagnosis — certified cars only
        if is_certified:
            try:
                diag = _call(client.diagnosis, lot.id)
                if diag:
                    if insp_record is None:
                        insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                    _enrich_from_diagnosis(lot, diag, insp_record)
            except Exception as e:
                logger.warning(f"[{source}] diagnosis {lot.id}: {e}")

        # Selling point — skip if drive_type already known from title
        if not lot.drive_type:
            try:
                sp = _call(client.sellingpoint, lot.id)
                if sp:
                    _enrich_from_sellingpoint(lot, sp, norm)
            except Exception as e:
                logger.warning(f"[{source}] sellingpoint {lot.id}: {e}")

        return lot, insp_record, errors

    def _enrich_accident_data(self, lots: list[CarLot], stats: dict) -> None:
        """Fetch record + inspection + diagnosis in parallel; DB writes on main thread."""
        source = _SOURCE
        workers = min(Config.ENCAR_WORKERS, len(lots))

        proxy_list = Config.ENCAR_PROXY_LIST

        def _task(lot: CarLot, idx: int) -> tuple[CarLot, InspectionRecord | None, int]:
            if proxy_list:
                # Each worker gets its own proxy URL from the list by index
                proxy = proxy_list[idx % len(proxy_list)]
            elif Config.ENCAR_PROXY:
                # Single rotating proxy — bump session to get a new IP
                proxy = EncarClient._bump_session(Config.ENCAR_PROXY)
            else:
                proxy = None
            client = EncarClient(proxy=proxy)
            try:
                return self._fetch_lot_enrichment(lot, client, self._norm)
            finally:
                client.close()

        results: list[tuple[CarLot, InspectionRecord | None, int]] = []
        with ThreadPoolExecutor(max_workers=workers) as pool:
            future_map = {pool.submit(_task, lot, idx): lot for idx, lot in enumerate(lots)}
            for i, future in enumerate(as_completed(future_map)):
                try:
                    lot, insp_record, errors = future.result()
                except Exception as e:
                    orig_lot = future_map[future]
                    logger.error(f"[{source}] worker failed for {orig_lot.id}: {e}")
                    errors = 1
                    lot, insp_record = orig_lot, None
                stats["errors"] += errors
                results.append((lot, insp_record))

        # DB writes — main thread only
        n_accident = n_flood = n_insp = 0
        for lot, insp_record in results:
            try:
                self.repo.upsert_batch([lot])
                logger.debug(
                    f"[{source}] ENRICHED {lot.id} | "
                    f"vin={lot.vin or '-'} | accident={lot.has_accident} | "
                    f"flood={lot.flood_history} | insp={'ok' if insp_record else 'none'}"
                )
                if lot.has_accident:    n_accident += 1
                if lot.flood_history:   n_flood    += 1
            except Exception as e:
                logger.warning(f"[{source}] upsert lot {lot.id} after accident enrich: {e}")
            if insp_record is not None:
                n_insp += 1
                try:
                    self.repo.upsert_inspection(insp_record)
                except Exception as e:
                    logger.warning(f"[{source}] upsert_inspection {lot.id}: {e}")
        logger.info(
            f"[STAT] [{source}] enriched {len(results)} lots: "
            f"accident={n_accident}, flood={n_flood}, insp={n_insp}"
        )
