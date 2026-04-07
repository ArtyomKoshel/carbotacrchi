from __future__ import annotations

import logging
import re as _re
import time as _time
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
_PAGE_SIZE = 20
_BATCH_SIZE = 20


def _lot_from_search(item: dict, norm: EncarNormalizer) -> CarLot:
    vid = str(item["Id"])
    make_kr = item.get("Manufacturer", "")
    model    = item.get("Model", "")
    badge    = item.get("Badge", "")        # grade/fuel e.g. "디젤 2.2 4WD"
    badge_detail = item.get("BadgeDetail", "")  # trim e.g. "노블레스"

    year_raw = item.get("FormYear") or str(item.get("Year") or "")
    year = int(str(year_raw)[:4]) if year_raw and len(str(year_raw)) >= 4 else 0

    price_man = int(item.get("Price") or 0)
    price_krw = norm.price_krw(price_man)
    mileage   = int(item.get("Mileage") or 0)

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
        price=price_man,
        price_krw=price_krw,
        mileage=mileage,
        fuel=norm.fuel(item.get("FuelType")),
        transmission=norm.transmission(item.get("Transmission")),
        color=item.get("Color") or None,
        seat_color=item.get("SeatColor") or None,
        location=location or None,
        image_url=image_url,
        lot_url=f"https://fem.encar.com/cars/detail/{vid}",
        raw_data={
            "manufacturer_kr": make_kr,
            "model_kr":        model,
            "model_group_kr":  item.get("ModelGroup"),
            "badge_kr":        badge,
            "badge_detail_kr": badge_detail,
            "year_month":      item.get("Year"),
            "sell_type":       item.get("SellType"),
            "ad_type":         item.get("AdType"),
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

    seizing = cond.get("seizing", {})
    if seizing:
        lot.lien_status    = "lien"   if seizing.get("pledgeCount", 0) > 0  else "clean"
        lot.seizure_status = "seized" if seizing.get("seizingCount", 0) > 0 else "clean"

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
        record.cert_no = master["supplyNum"]
    if master.get("registrationDate"):
        record.inspection_date = master["registrationDate"][:10]
    record.report_url = (
        f"https://www.encar.com/md/sl/mdsl_regcar.do"
        f"?method=inspectionViewNew&carid={lot.id}"
    )

    def _parse_date8(s: str | None) -> str | None:
        return f"{s[:4]}-{s[4:6]}-{s[6:8]}" if s and len(s) == 8 else None

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

        query = "(And.Hidden.N._.CarType.A.)"
        if maker_filter:
            query = f"(And.Hidden.N._.Manufacturer.{maker_filter}.)"
            logger.info(f"[{source}] Maker filter: {maker_filter}")

        logger.info(f"[{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[{source}] Pages: {pages}, page_size: {_PAGE_SIZE}")

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()
        total_count: int | None = None

        for page in range(pages):
            offset = page * _PAGE_SIZE
            try:
                data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
            except Exception as e:
                logger.error(f"[{source}] Search p.{page+1} error: {e}")
                break

            if total_count is None:
                total_count = data.get("Count", 0)
                logger.info(f"[{source}] Total available: {total_count}")

            items = data.get("SearchResults", [])
            if not items:
                logger.info(f"[{source}] p.{page+1}: empty, stopping")
                break

            page_lots: list[CarLot] = []
            for item in items:
                vid = str(item.get("Id", ""))
                if not vid or vid in seen_ids:
                    continue
                seen_ids.add(vid)
                lot = _lot_from_search(item, self._norm)
                page_lots.append(lot)

            logger.info(f"[{source}] p.{page+1}: {len(page_lots)} lots from search")

            # Batch-enrich with detail API (VIN, plate, engine, etc.)
            self._enrich_batch(page_lots, stats)

            # For new lots only: fetch accident record + inspection
            new_lots = [l for l in page_lots if l.id not in existing_ids]
            if new_lots:
                self._enrich_accident_data(new_lots, stats)

            # Upsert to DB + save photos
            self.repo.upsert_batch(page_lots)
            for lot in page_lots:
                photos = lot.raw_data.get("photos") or []
                if photos:
                    self.repo.upsert_photos(lot.id, photos)
                if lot.id in existing_ids:
                    stats["updated"] += 1
                else:
                    stats["new"] += 1
                stats["total"] += 1

            if on_page_callback:
                on_page_callback(
                    page=page + 1,
                    found=len(page_lots),
                    total_pages=pages,
                )

            _time.sleep(Config.REQUEST_DELAY)

            if offset + _PAGE_SIZE >= (total_count or 0):
                logger.info(f"[{source}] Reached end of results")
                break

        stale = self.repo.mark_inactive(source, seen_ids, grace_hours=24)
        elapsed = _time.monotonic() - run_start

        logger.info(f"[{source}] ========== IMPORT COMPLETE ==========")
        logger.info(f"[{source}] Total:   {stats['total']}")
        logger.info(f"[{source}] New:     {stats['new']}")
        logger.info(f"[{source}] Updated: {stats['updated']}")
        logger.info(f"[{source}] Stale:   {stale}")
        logger.info(f"[{source}] Errors:  {stats['errors']}")
        logger.info(f"[{source}] Time:    {elapsed:.1f}s")

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
                enriched = 0
                for detail in details:
                    vid = str(detail.get("vehicleId", ""))
                    if vid in id_map:
                        _enrich_from_detail(id_map[vid], detail, self._norm)
                        enriched += 1
                logger.info(f"[encar] batch_details: enriched {enriched}/{len(chunk)} lots")
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

    def _enrich_accident_data(self, lots: list[CarLot], stats: dict) -> None:
        """Fetch record + inspection + diagnosis for new lots and upsert to lot_inspections."""
        source = _SOURCE
        for lot in lots:
            insp_record: InspectionRecord | None = None
            is_certified = False

            # Record API — use inner vehicle ID (from photo paths), not listing ID
            _inner_id = lot.raw_data.get("inspect_vehicle_id") or lot.id
            try:
                rec = self._client.record(_inner_id, lot.plate_number or None)
                if rec and rec.get("openData"):
                    is_certified = True
                    insp_record = _enrich_from_record(lot, rec)
            except Exception as e:
                logger.warning(f"[{source}] record {lot.id}: {e}")
                stats["errors"] += 1
            _time.sleep(0.3)

            # Inspection (performance check form)
            # Use inner vehicle ID from photo paths — may differ from listing ID
            try:
                _insp_id = lot.raw_data.get("inspect_vehicle_id") or lot.id
                insp = self._client.inspection(_insp_id)
                if insp:
                    if insp_record is None:
                        insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                    _enrich_from_inspection(lot, insp, insp_record)
                    is_certified = True
            except Exception as e:
                logger.warning(f"[{source}] inspection {lot.id}: {e}")
            _time.sleep(0.3)

            # Encar internal diagnosis (body panel check) — certified cars only
            if is_certified:
                try:
                    diag = self._client.diagnosis(lot.id)
                    if diag:
                        if insp_record is None:
                            insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                        _enrich_from_diagnosis(lot, diag, insp_record)
                except Exception as e:
                    logger.warning(f"[{source}] diagnosis {lot.id}: {e}")
                _time.sleep(0.3)

            # Selling point (drive_type for certified cars)
            try:
                sp = self._client.sellingpoint(lot.id)
                if sp:
                    _enrich_from_sellingpoint(lot, sp, self._norm)
            except Exception as e:
                logger.warning(f"[{source}] sellingpoint {lot.id}: {e}")
            _time.sleep(0.3)

            if insp_record is not None:
                try:
                    self.repo.upsert_inspection(insp_record)
                except Exception as e:
                    logger.warning(f"[{source}] upsert_inspection {lot.id}: {e}")
