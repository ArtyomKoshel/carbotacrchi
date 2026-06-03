from __future__ import annotations

import logging
import re as _re
import time as _time
import json as _json
import os as _os
from datetime import datetime as _dt, timezone as _tz, timedelta as _td
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Callable



import httpx
try:
    import pymysql as _pymysql
    from pymysql.cursors import DictCursor as _DictCursor
except Exception:  # pragma: no cover - parser may run in reduced env for tests
    _pymysql = None
    _DictCursor = None

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from ..base import AbstractParser, ProgressUpdate
from .._shared import sell_type as _sell
from .client import EncarClient, ProxyBudgetExhausted, _generate_floppy_proxies, _reset_proxy_cache, check_floppy_balance
from .normalizer import EncarNormalizer

logger = logging.getLogger(__name__)

_SOURCE = "encar"
_PAGE_SIZE = 100
_BATCH_SIZE = 20   # batch_details API hard-caps at 20 items
_MAX_SAFE_OFFSET = 9900  # Encar search API (Elasticsearch) caps at ~10k results per query


def ensure_anomaly_file_exists() -> None:
    """No-op stub kept for import compatibility with logging_config.py."""
    pass


def _clean_model_str(model_raw: str, badge: str, model_group: str) -> str:
    """
    Return a clean model/generation string from the raw Encar Model field.

    The Encar Model field is a composite string, e.g.:
      "더 뉴 쏘렌토 4세대 가솔린 2.5T 4WD"

    We strip:
      1. Badge suffix ("가솔린 2.5T 4WD") — already available as a separate field
      2. Known Korean model prefixes (더 뉴, 올 뉴, …) — fixed list, no regex

    Result: "쏘렌토 4세대"
    """
    s = model_raw.strip()

    # 1. Strip badge suffix from end of model string
    if badge:
        badge_s = badge.strip()
        if s.endswith(badge_s):
            s = s[: -len(badge_s)].strip()

    # 2. Strip known model prefixes (fixed list, no regex)
    _PREFIXES = [
        "더 뉴 더 뉴 ", "더 뉴 더뉴 ", "더뉴 더뉴 ",
        "더 뉴 ", "더뉴 ",
        "올 뉴 ", "올뉴 ",
        "완전변경 ", "부분변경 ", "신형 ", "뉴 ",
    ]
    for prefix in _PREFIXES:
        if s.startswith(prefix):
            s = s[len(prefix):]
            break

    return s.strip() or model_raw.strip()


def _lot_from_search(item: dict, norm: EncarNormalizer) -> CarLot:
    """
    Build a CarLot from a single Encar search-list item.

    All fields come DIRECTLY from the API — no regex, no string parsing.
    Tech specs (engine_volume, drive_type) come from the detail API
    (_enrich_from_detail) or catalog_badges fallback (lots:normalize-from-catalog).
    """
    vid          = str(item["Id"])
    make_kr      = (item.get("Manufacturer") or "").strip()
    model_group  = (item.get("ModelGroup") or "").strip()
    model_raw    = (item.get("Model") or "").strip()
    badge        = (item.get("Badge") or "").strip()
    badge_detail = (item.get("BadgeDetail") or "").strip()

    # Clean model string (strip badge suffix + model prefixes, no regex)
    model = _clean_model_str(model_raw, badge, model_group)

    year_raw = item.get("FormYear") or str(item.get("Year") or "")
    year = int(str(year_raw)[:4]) if year_raw and len(str(year_raw)) >= 4 else 0

    price_man = int(item.get("Price") or 0)
    if price_man > 1_000_000_000:
        logger.warning(f"[encar] lot {item.get('Id')}: absurd price {price_man}만원, zeroing")
        price_man = 0
    price_raw = norm.price_from_man(price_man)
    mileage   = int(item.get("Mileage") or 0)

    photos = item.get("Photos") or []
    photo_path = photos[0]["location"] if photos else ""
    image_url  = EncarClient.photo_url(photo_path) if photo_path else None

    location = (item.get("OfficeCityState") or "").strip()

    conditions = item.get("Condition") or []
    sell_type, sell_type_raw = _sell.normalize_encar(
        item.get("SellType"), item.get("AdType"), conditions,
    )

    form_year = item.get("FormYear") or item.get("Year")
    reg_ym: int | None = None
    if form_year:
        try:
            s = str(int(form_year))
            if len(s) == 6 and s.isdigit():
                reg_ym = int(s)
        except (TypeError, ValueError):
            pass

    # Raw data: only fields NOT available as first-class columns
    _raw_data: dict = {
        "model_kr_raw": model_raw,   # original composite for reference
        "ad_type":      item.get("AdType"),
        "condition":    conditions,
    }

    # Seat color: store raw Korean — lots:normalize-from-catalog translates via translations.seat_color
    seat_color_raw = (item.get("SeatColor") or "").strip() or None

    return CarLot(
        id=vid,
        source=_SOURCE,
        make=make_kr,                      # ← raw Korean: "기아", "현대", "KG모빌리티(쌍용)"
        model=model,
        model_group=model_group,           # ← raw Korean from API
        model_en=None,                     # ← catalog fills via catalog_models.model_group_en
        badge=badge,                       # ← raw Korean from API
        trim=badge_detail or None,         # ← raw Korean from API (BadgeDetail)
        year=year,
        price=price_raw,
        mileage=mileage,
        registration_year_month=reg_ym,
        fuel=norm.fuel(item.get("FuelType")),  # ← structured enum from API, safe to map
        transmission=norm.transmission(item.get("Transmission")),
        color=norm.color(item.get("Color")),   # ← structured enum, mostly English already
        seat_color=seat_color_raw,             # ← raw Korean, normalization translates
        # engine_volume, drive_type, body_type, seat_count → from detail API
        # (fallback: lots:normalize-from-catalog via catalog_badges)
        location=location or None,
        image_url=image_url,
        lot_url=f"https://fem.encar.com/cars/detail/{vid}",
        sell_type=sell_type,
        sell_type_raw=sell_type_raw or None,
        raw_data=_raw_data,
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
        lot.color = norm.color(spec["colorName"])
    if spec.get("bodyName"):
        lot.body_type = norm.body(spec["bodyName"])
    if spec.get("displacement"):
        lot.engine_volume = round(spec["displacement"] / 1000, 1)
    if spec.get("drivingMethodName") and not lot.drive_type:
        lot.drive_type = norm.drive(spec["drivingMethodName"])
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

    # NOTE: lien_status/seizure_status are set from the Record API in _enrich_from_record
    # (rec["loan"] / rec["robberCnt"]) which is the authoritative source.
    # The batch detail API's seizing.pledgeCount is unreliable and must not overwrite it.

    outer = [p["path"] for p in photos if p.get("type") == "OUTER"]
    if outer and not lot.image_url:
        lot.image_url = EncarClient.photo_url(outer[0])

    # Photos go to the transit field `lot.photos` — LotRepository.upsert_batch
    # will persist them into the `lot_photos` table. They are NOT serialized
    # into raw_data (see CarLot._RAW_DATA_BLOCKLIST).
    all_photo_urls = [EncarClient.photo_url(p["path"]) for p in photos if p.get("path")]
    if all_photo_urls:
        # Deduplicate while preserving order
        lot.photos = list(dict.fromkeys(all_photo_urls))

    # Inspection uses an inner vehicle ID embedded in photo paths (e.g. /pic4097/40977911_004.jpg)
    # which can differ from the listing ID (lot.id).
    if photos:
        _m = _re.search(r'/(\d+)_\d+\.', photos[0].get("path", ""))
        if _m and _m.group(1) != lot.id:
            lot.raw_data["inspect_vehicle_id"] = _m.group(1)

    std_opts = opts.get("standard", [])
    if std_opts:
        lot.options = std_opts

    # Paid/choice options — separate from standard options
    _paid = []
    for _key in ("choice", "paid", "color", "package"):
        _group = opts.get(_key, [])
        if _group:
            _paid.extend(_group)
    if _paid:
        lot.paid_options = _paid

    # originPrice is MSRP in 만원 units — promote it to the first-class
    # `retail_value` column (in KRW) so filter rules and UI can compare it.
    origin_price_man = cat.get("originPrice")
    if origin_price_man and not lot.retail_value:
        try:
            lot.retail_value = int(origin_price_man) * 10_000
        except (TypeError, ValueError):
            pass

    # Promote English trim name to first-class column (grade_detail_kr duplicates lots.trim)
    grade_detail_en = (cat.get("gradeDetailEnglishName") or "").strip() or None
    if grade_detail_en and not lot.trim_en:
        lot.trim_en = grade_detail_en

    lot.raw_data.update({
        "ad_status": adv.get("status"),
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

    if rec.get("firstDate") and not lot.first_reg_date:
        lot.first_reg_date = rec["firstDate"]

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
        my_accident_cost=my_cost if my_cost else None,
        other_accident_cost=other_cost if other_cost else None,
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
        _cert = str(master["supplyNum"]).strip()
        # Valid cert numbers are typically 8-15 digits; skip short garbage
        if _cert.isdigit() and len(_cert) >= 8:
            record.cert_no = _cert
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
        if not lot.first_reg_date:
            lot.first_reg_date = fr

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
        record.has_recall = True
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
            if not lot.first_reg_date:
                lot.first_reg_date = reg_date
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
    MIN_DELIST_COVERAGE = Config.ENCAR_DELIST_COVERAGE

    def __init__(self, repo: LotRepository):
        super().__init__(repo)
        self._client = EncarClient()
        self._norm = EncarNormalizer()

    def _regenerate_proxies(self):
        """Clear proxy cache, generate fresh sessions, and rebuild the HTTP client."""
        _reset_proxy_cache()
        self._client = EncarClient()
        logger.info(f"[{_SOURCE}] Client rebuilt with fresh proxy sessions")

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
        stop_reason = "max_pages reached"
        consecutive_empty = 0  # pages where ALL items were already in seen_ids
        _MAX_CONSECUTIVE_EMPTY = 5
        pages_done = 0

        for page in range(max_pages):
            _t_page = _time.monotonic()
            offset = page * _PAGE_SIZE
            if offset > _MAX_SAFE_OFFSET:
                stop_reason = f"offset {offset} > API cap {_MAX_SAFE_OFFSET}"
                break
            _t_search = _time.monotonic()
            try:
                data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
            except httpx.HTTPStatusError as e:
                etype = str(e.response.status_code)
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                if e.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504):
                    logger.warning(f"[{source}]{label} p.{page+1}: {e.response.status_code}, rotating proxy and retrying")
                    self._client.rotate_proxy()
                    _p = _time.monotonic(); _time.sleep(2); stats["pause_time"] += _time.monotonic() - _p
                    try:
                        data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
                    except Exception as e2:
                        stop_reason = f"retry failed: {e2}"
                        stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                        logger.error(f"[{source}]{label} p.{page+1} {stop_reason}")
                        break
                else:
                    stop_reason = f"HTTP {e.response.status_code}"
                    stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                    logger.error(f"[{source}]{label} p.{page+1} error: {e}")
                    break
            except ProxyBudgetExhausted as e:
                logger.error(f"[{source}]{label} p.{page+1}: proxy budget exhausted — aborting. {e}")
                self.inc_error(stats, "ProxyBudgetExhausted", f"proxy budget exhausted at p.{page+1}{label}")
                return api_total
            except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout) as e:
                etype = type(e).__name__
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                logger.warning(f"[{source}]{label} p.{page+1}: {etype}: {e}, rotating proxy and retrying")
                self._client.rotate_proxy()
                _p = _time.monotonic(); _time.sleep(3); stats["pause_time"] += _time.monotonic() - _p
                try:
                    data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
                except ProxyBudgetExhausted as e2:
                    logger.error(f"[{source}]{label} p.{page+1}: proxy budget exhausted on retry — aborting. {e2}")
                    self.inc_error(stats, "ProxyBudgetExhausted", f"proxy budget exhausted at p.{page+1}{label}")
                    return api_total
                except Exception as e2:
                    stop_reason = f"proxy retry failed: {e2}"
                    stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                    logger.error(f"[{source}]{label} p.{page+1} {stop_reason}")
                    break
            except Exception as e:
                etype = type(e).__name__
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                stop_reason = f"error: {etype}: {e}"
                stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                logger.error(f"[{source}]{label} p.{page+1} {stop_reason}")
                break
            finally:
                stats["search_time"] += _time.monotonic() - _t_search

            if total_count is None:
                total_count = data.get("Count", 0)
                pass  # total_count captured

            items = data.get("SearchResults", [])
            if not items:
                stop_reason = "empty page (no results)"
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
                    stop_reason = f"API cycling (all {len(items)} in call_seen)"
                    break
                # Phase overlap: IDs seen in a prior phase — advance to next page
                consecutive_empty += 1
                if consecutive_empty >= _MAX_CONSECUTIVE_EMPTY:
                    stop_reason = f"phase overlap stuck ({consecutive_empty} consecutive empty pages)"
                    logger.warning(f"[{source}]{label} {stop_reason} — breaking to avoid waste")
                    break
                logger.info(f"[STAT] [{source}]{label} p.{page+1}: {phase1_skip} seen in prior phase, skipping → next page")
                if offset + _PAGE_SIZE >= (total_count or 0):
                    stop_reason = "reached end (all overlap)"
                    break
                continue

            consecutive_empty = 0  # reset on any page with new data
            pass  # page lots counted

            _t_batch_start = _time.monotonic()
            self._enrich_batch(page_lots, stats)
            stats["search_time"] += _time.monotonic() - _t_batch_start

            self.repo.upsert_batch(page_lots, stats)
            # Photos are auto-upserted by LotRepository.upsert_batch from
            # lot.photos (see parser/models.py). No need to handle them here.
            for lot in page_lots:
                is_new = lot.id not in existing_ids
                if is_new:
                    stats["new"] += 1
                    pass  # new lot
                else:
                    stats["updated"] += 1
                    pass  # updated lot
                stats["total"] += 1

            if on_page_callback:
                stats["proxy_bytes"] = self._client.proxy_bytes
                _progress = stats["total"] / total_count if total_count else 0
                on_page_callback(ProgressUpdate(
                    phase="search",
                    phase_progress=min(_progress, 1.0),
                    total_progress=min(_progress, 1.0),
                    lots_found=total_count or 0,
                    lots_processed=len(page_lots),
                    message=f"p.{page+1}{label} {stats['total']:,}/{total_count or '?'}",
                    stats=stats,
                ))

            _t_after_upsert = _time.monotonic()
            new_lots = [l for l in page_lots if l.id not in existing_ids]
            # Enrich ALL lots (not just new) so has_accident/damage stays current
            if page_lots:
                _t_enr = _time.monotonic()
                self._enrich_accident_data(page_lots, stats)
                stats["enrich_time"] += _time.monotonic() - _t_enr

            _t_total = _time.monotonic() - _t_page
            _t_enrich = _time.monotonic() - _t_after_upsert
            _t_batch = _t_after_upsert - _t_page
            pages_done += 1
            logger.info(
                f"[STAT] [{source}]{label} p.{page+1} done in {_t_total:.1f}s "
                f"(batch+upsert={_t_batch:.1f}s, enrich={_t_enrich:.1f}s, "
                f"new={len(new_lots)}/{len(page_lots)})"
            )

            if offset + _PAGE_SIZE >= (total_count or 0):
                stop_reason = f"reached end ({total_count} total)"
                break

        logger.info(f"[STAT] [{source}]{label} SEGMENT DONE: {stop_reason} | pages={pages_done} seen={len(call_seen)}")
        return total_count or 0

    def run_reparse(self, lot_ids: list[str], on_progress=None) -> dict:
        """Re-enrich specific lots by ID (accident + inspection records)."""
        source = _SOURCE
        run_start = _time.monotonic()
        stats = self.init_stats()

        lots = self.repo.get_lots_by_source(source, ids=lot_ids)
        if not lots:
            msg = f"No lots found for ids={lot_ids}"
            logger.warning(f"[{source}] Reparse: {msg}")
            return {"total": 0, "errors": 1, "error_log": [msg], "error_types": {},
                    "elapsed_s": 0.0, "time": "0m", "reparse": True}

        total = len(lots)
        logger.info(f"[{source}] Reparse: enriching {total} lot(s): {lot_ids}")

        if on_progress:
            on_progress(ProgressUpdate(
                phase="enrich", phase_progress=0.0, total_progress=0.0,
                lots_found=total, lots_processed=0,
                message=f"Fetching accident records for {total} lot(s)...",
            ))

        self._enrich_accident_data(lots, stats)

        elapsed = _time.monotonic() - run_start
        logger.info(
            f"[{source}] Reparse done: {total} lot(s) in {elapsed:.1f}s "
            f"(errors={stats.get('errors', 0)})"
        )

        if on_progress:
            on_progress(ProgressUpdate(
                phase="done", phase_progress=1.0, total_progress=1.0,
                lots_found=total, lots_processed=total,
                message=f"Reparse complete: {total} lot(s)",
            ))

        return {
            "total": total,
            "errors": stats.get("errors", 0),
            "error_log": (stats.get("error_log") or [])[-20:],
            "error_types": stats.get("error_types", {}),
            "elapsed_s": round(elapsed, 1),
            "time": self.format_elapsed(elapsed),
            "reparse": True,
        }

    def run_sample(self, lots_per_model: int = 7) -> dict:
        """
        Sample mode: fetch N lots per (Manufacturer, ModelGroup) pair.

        Phase 1 — single API call to discover all (maker_kr, model_group_kr) pairs.
        Phase 2 — for each pair fetch `lots_per_model` lots using a targeted query,
                   enrich them, and save to DB.

        Designed for integration testing: gives a small, representative dataset
        covering every model group so that lots:normalize-from-catalog can be
        validated before running a full parse.
        """
        source = _SOURCE
        run_start = _time.monotonic()
        stats = self.init_stats()

        logger.info(f"[{source}] ===== SAMPLE MODE: {lots_per_model} lots/model =====")
        check_floppy_balance()

        existing_ids = self.repo.get_existing_ids(source)
        seen_ids: set[str] = set()

        # ── Phase 1: discover all (maker_kr, model_group_kr) pairs ────────────
        logger.info(f"[{source}] Phase 1: discovering makers and model groups…")
        pairs: dict[str, set[str]] = {}  # maker_kr → {model_group_kr}

        try:
            page1 = self._client.search(
                query="(And.Hidden.N._.CarType.A.)",
                offset=0,
                count=_PAGE_SIZE,
            )
            for item in page1.get("SearchResults", []):
                mk = (item.get("Manufacturer") or "").strip()
                mg = (item.get("ModelGroup") or "").strip()
                if mk:
                    pairs.setdefault(mk, set())
                    if mg:
                        pairs[mk].add(mg)
        except Exception as e:
            logger.error(f"[{source}] Phase 1 failed: {e}")
            return {"total": 0, "errors": 1, "error_log": [str(e)]}

        total_pairs = sum(len(mgs) for mgs in pairs.values())
        logger.info(
            f"[{source}] Phase 1 done: {len(pairs)} makers, {total_pairs} model groups"
        )

        # ── Phase 2: N lots per (maker, model_group) pair ─────────────────────
        total_saved = 0
        total_pairs_done = 0

        for maker_kr in sorted(pairs.keys()):
            model_groups = sorted(pairs[maker_kr])
            if not model_groups:
                # maker without model group — fetch with maker filter only
                model_groups = [""]

            for mg_kr in model_groups:
                if mg_kr:
                    query = (
                        f"(And.Hidden.N._.CarType.A."
                        f"_.Manufacturer.{maker_kr}."
                        f"_.ModelGroup.{mg_kr}.)"
                    )
                    label = f"{maker_kr}/{mg_kr}"
                else:
                    query = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker_kr}.)"
                    label = maker_kr

                try:
                    data = self._client.search(
                        query=query, offset=0, count=lots_per_model
                    )
                except ProxyBudgetExhausted as e:
                    logger.error(f"[{source}] [{label}] proxy budget exhausted: {e}")
                    break
                except Exception as e:
                    logger.warning(f"[{source}] [{label}] fetch error: {e}, skipping")
                    stats["errors"] += 1
                    continue

                items = (data.get("SearchResults") or [])[:lots_per_model]
                if not items:
                    logger.debug(f"[{source}] [{label}] no results, skipping")
                    continue

                page_lots: list[CarLot] = []
                for item in items:
                    vid = str(item.get("Id", ""))
                    if not vid or vid in seen_ids:
                        continue
                    seen_ids.add(vid)
                    page_lots.append(_lot_from_search(item, self._norm))

                if not page_lots:
                    continue

                # Enrich with detail API (engine_volume, body_type, drive_type…)
                self._enrich_batch(page_lots, stats)
                # Enrich with accident/record data
                self._enrich_accident_data(page_lots, stats)

                self.repo.upsert_batch(page_lots, stats)

                n_new = sum(1 for l in page_lots if l.id not in existing_ids)
                n_upd = len(page_lots) - n_new
                stats["new"]     += n_new
                stats["updated"] += n_upd
                stats["total"]   += len(page_lots)
                total_saved      += len(page_lots)
                total_pairs_done += 1

                logger.info(
                    f"[{source}] [{label}] "
                    f"{len(page_lots)} lots saved (new={n_new}, upd={n_upd})"
                )

                # Small pause to be polite to the API
                _time.sleep(0.3)

        elapsed = _time.monotonic() - run_start
        logger.info(
            f"[{source}] ===== SAMPLE DONE: {total_saved} lots "
            f"from {total_pairs_done}/{total_pairs} pairs "
            f"in {self.format_elapsed(elapsed)} ====="
        )
        return {
            "total":      stats["total"],
            "new":        stats["new"],
            "updated":    stats["updated"],
            "errors":     stats["errors"],
            "elapsed_s":  round(elapsed, 1),
            "time":       self.format_elapsed(elapsed),
            "mode":       f"sample/{lots_per_model}",
        }

    def run(
        self,
        max_pages: int | None = None,
        maker_filter: str | None = None,
        on_page_callback: Callable | None = None,
        checkpoint: dict | None = None,
        sample: int | None = None,
    ) -> dict:
        # Sample mode: delegate entirely to run_sample()
        if sample:
            return self.run_sample(lots_per_model=sample)

        source = _SOURCE
        run_start = _time.monotonic()
        stats = self.init_stats()

        pages = max_pages or 9999  # 0 / None = all pages

        logger.info(f"[STAT] [{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[STAT] [{source}] Pages: {pages}, page_size: {_PAGE_SIZE}")

        check_floppy_balance()

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()

        api_total: int = 0  # total listings reported by Encar API

        _search_phase = self.start_phase("search")

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
            logger.info(f"[{source}] Phase 2: per-manufacturer pagination ({len(discovered_makers)} makers)")
            consecutive_maker_errors = 0
            proxy_regens = 0
            _MAX_PROXY_REGENS = 5
            maker_idx = 0
            makers_api_sum = 0  # sum of all maker totals from API
            while maker_idx < len(discovered_makers):
                maker = discovered_makers[maker_idx]
                mq = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)"
                try:
                    count_data = self._client.search(query=mq, offset=0, count=1)
                    maker_total = count_data.get("Count", 0)
                    consecutive_maker_errors = 0  # reset on success
                except ProxyBudgetExhausted as e:
                    logger.error(f"[{source}] [{maker}] proxy budget exhausted — aborting Phase 2. {e}")
                    self.inc_error(stats, "ProxyBudgetExhausted", f"budget exhausted at maker {maker}")
                    break
                except Exception as e:
                    consecutive_maker_errors += 1
                    etype = str(e.response.status_code) if isinstance(e, httpx.HTTPStatusError) else type(e).__name__
                    stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                    stats["errors"] += 1
                    stats["error_log"].append(f"[{maker}] count: {etype}: {e}")
                    logger.warning(f"[{source}] [{maker}] count query failed ({consecutive_maker_errors} in a row): {e}")
                    if consecutive_maker_errors >= 5:
                        if proxy_regens < _MAX_PROXY_REGENS:
                            proxy_regens += 1
                            wait = 60 * proxy_regens  # 60s, 120s, 180s, 240s, 300s
                            logger.warning(f"[{source}] Regenerating proxy sessions (attempt {proxy_regens}/{_MAX_PROXY_REGENS}), waiting {wait}s...")
                            self._regenerate_proxies()
                            consecutive_maker_errors = 0
                            _p = _time.monotonic(); _time.sleep(wait); stats["pause_time"] += _time.monotonic() - _p
                            continue  # retry same maker (maker_idx not incremented)
                        logger.error(f"[{source}] API appears down after {proxy_regens} proxy regenerations — aborting Phase 2")
                        break
                    maker_idx += 1
                    continue

                makers_api_sum += maker_total
                logger.info(f"[{source}] [{maker}]: {maker_total} total (maker {maker_idx+1}/{len(discovered_makers)}, sum={makers_api_sum})")
                if maker_total == 0:
                    maker_idx += 1
                    continue

                if maker_total <= _MAX_SAFE_OFFSET:
                    self._paginate_query(
                        mq, 100, seen_ids, existing_ids, stats,
                        on_page_callback, label=f" [{maker}]",
                    )
                else:
                    current_year = _time.localtime().tm_year
                    logger.info(f"[{source}] [{maker}] {maker_total} > {_MAX_SAFE_OFFSET}, splitting by year")
                    for year in range(1990, current_year + 2):
                        yq = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99).)"
                        try:
                            ydata = self._client.search(query=yq, offset=0, count=1)
                            year_total = ydata.get("Count", 0)
                        except Exception as e:
                            logger.warning(f"[{source}] [{maker}/{year}] count query failed: {type(e).__name__}: {e}, retrying...")
                            self._client.rotate_proxy()
                            _p = _time.monotonic(); _time.sleep(2); stats["pause_time"] += _time.monotonic() - _p
                            try:
                                ydata = self._client.search(query=yq, offset=0, count=1)
                                year_total = ydata.get("Count", 0)
                            except Exception as e2:
                                logger.warning(f"[{source}] [{maker}/{year}] count query retry failed: {type(e2).__name__}: {e2}")
                                continue
                        if year_total == 0:
                            continue
                        if year_total <= _MAX_SAFE_OFFSET:
                            self._paginate_query(
                                yq, 100, seen_ids, existing_ids, stats,
                                on_page_callback, label=f" [{maker}/{year}]",
                            )
                        else:
                            # Year still too large — sub-split by model
                            maker_models = sorted(discovered_models.get(maker, []))
                            logger.info(f"[{source}] [{maker}/{year}] {year_total} > cap, sub-splitting by model ({len(maker_models)} models)")
                            for model in maker_models:
                                mq2 = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99)._.Model.{model}.)"
                                try:
                                    mdata = self._client.search(query=mq2, offset=0, count=1)
                                    model_total = mdata.get("Count", 0)
                                except Exception as e:
                                    logger.warning(f"[{source}] [{maker}/{year}/{model}] count query failed: {type(e).__name__}: {e}, retrying...")
                                    self._client.rotate_proxy()
                                    _p = _time.monotonic(); _time.sleep(2); stats["pause_time"] += _time.monotonic() - _p
                                    try:
                                        mdata = self._client.search(query=mq2, offset=0, count=1)
                                        model_total = mdata.get("Count", 0)
                                    except Exception as e2:
                                        logger.warning(f"[{source}] [{maker}/{year}/{model}] count query retry failed: {type(e2).__name__}: {e2}")
                                        continue
                                if model_total > 0:
                                    self._paginate_query(
                                        mq2, 100, seen_ids, existing_ids, stats,
                                        on_page_callback, label=f" [{maker}/{year}/{model}]",
                                    )
                            # Fallback: paginate the year query itself for models not discovered in Phase 1
                            logger.info(f"[{source}] [{maker}/{year}] fallback: paginating year query for undiscovered models")
                            self._paginate_query(
                                yq, 100, seen_ids, existing_ids, stats,
                                on_page_callback, label=f" [{maker}/{year}/fallback]",
                            )
                maker_idx += 1

            logger.info(
                f"[{source}] Phase 2 done. Makers API sum: {makers_api_sum:,} | "
                f"Phase 1 API total: {api_total:,} | Processed so far: {stats['total']:,}"
            )

        self.end_phase(_search_phase, lots_out=stats["total"], errors=stats.get("errors", 0))

        elapsed = _time.monotonic() - run_start

        db_count = self.repo.count_active(source)

        # ── Delist phase ─────────────────────────────────────────────────
        _delist_phase = self.start_phase("delist", lots_in=len(seen_ids))
        stale = self.delist_if_complete(seen_ids, reference_total=api_total, grace_hours=1)
        self.end_phase(_delist_phase, lots_out=stale)

        _proxy_bytes = self._client.proxy_bytes
        self._client.close()

        result = self.finalize_summary(
            elapsed, stats, seen_ids,
            api_total=api_total, stale=stale, db_count=db_count,
        )
        result.extra["proxy_bytes"] = _proxy_bytes
        return result.to_dict()

    def _enrich_batch(self, lots: list[CarLot], stats: dict) -> None:
        if not lots:
            return
        ids = [lot.id for lot in lots]
        id_map = {lot.id: lot for lot in lots}

        for i in range(0, len(ids), _BATCH_SIZE):
            chunk = ids[i: i + _BATCH_SIZE]
            try:
                details = self._client.batch_details(chunk)
                pass  # batch_details received
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
                        pass  # unmatched listing
                pass  # batch enrichment done
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
                        # rotate proxy on block/rate-limit/proxy error
                        if isinstance(e2, httpx.HTTPStatusError) and e2.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504):
                            self._client.rotate_proxy()
                            logger.info(f"[encar] rotated proxy after {e2.response.status_code}")
                        elif isinstance(e2, (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout)):
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

        def _call(fn, *args, _max_retries=3):
            """Call fn(*args), retry up to _max_retries with backoff on rate-limit/block."""
            for attempt in range(_max_retries + 1):
                try:
                    return fn(*args)
                except httpx.HTTPStatusError as e:
                    if e.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504) and attempt < _max_retries:
                        wait = 1 * (2 ** attempt)  # 1s, 2s, 4s
                        logger.warning(f"[{source}] {e.response.status_code} on {lot.id} — retry {attempt+1}/{_max_retries} in {wait}s")
                        _time.sleep(wait)
                        client.rotate_proxy()
                        continue
                    raise
                except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout) as e:
                    if attempt < _max_retries:
                        wait = 1 * (2 ** attempt)
                        logger.warning(f"[{source}] {type(e).__name__} on {lot.id} — retry {attempt+1}/{_max_retries} in {wait}s")
                        _time.sleep(wait)
                        client.rotate_proxy()
                        continue
                    raise

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

        # NOTE: diagnosis endpoint disabled — returns 404 via proxies (requires
        # origin:fem.encar.com header which proxies strip). The /inspection
        # endpoint already provides identical data (outers, inners, master).
        # Removing saves ~10s per page of wasted 404 calls.

        # NOTE: inspection_html removed after field-source audit (called 0/40, 0 unique fields).
        # sellingpoint kept — provides drive_type for ~2.5% of lots not covered by search.

        if not lot.drive_type:
            try:
                sp = _call(client.sellingpoint, lot.id)
                if sp:
                    _enrich_from_sellingpoint(lot, sp, norm)
            except Exception as e:
                logger.warning(f"[{source}] sellingpoint {lot.id}: {e}")

        return lot, insp_record, errors

    def _enrich_accident_data(self, lots: list[CarLot], stats: dict) -> None:
        """Fetch record + inspection in parallel; DB writes on main thread."""
        source = _SOURCE
        workers = min(Config.ENCAR_WORKERS, len(lots))

        proxy_list = _generate_floppy_proxies(count=max(workers, 20)) if Config.FLOPPYDATA_API_KEY else []

        def _task(lot: CarLot, idx: int) -> tuple[CarLot, InspectionRecord | None, int]:
            if proxy_list:
                proxy = proxy_list[idx % len(proxy_list)]
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
                self.repo.upsert_batch([lot], stats)
                pass  # lot enriched
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

        # Post-filters: evaluate rules that depend on inspection data
        enriched_ids = [lot.id for lot, _ in results]
        if enriched_ids:
            try:
                post_deactivated = self.repo.apply_post_filters(enriched_ids, stats)
                if post_deactivated:
                    logger.info(f"[{source}] post-filter deactivated {post_deactivated} lots")
            except Exception as e:
                logger.warning(f"[{source}] post-filter error: {e}")
