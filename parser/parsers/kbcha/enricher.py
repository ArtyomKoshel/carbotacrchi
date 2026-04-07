from __future__ import annotations

import logging
import time as _time

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from .client import KBChaClient
from .detail_parser import KBChaDetailParser
from .inspection_parser import CarmodooInspectionParser

logger = logging.getLogger(__name__)

_SPEC_FIELDS = {"fuel", "year", "mileage", "engine_volume", "color"}

_RAW_DATA_KEYS = (
    "inspection_type", "inspection_no",
    "autocafe_url", "carmodoo_url", "moldeoncar_url", "mpark_url", "inspection_url",
    "_ai_price_min", "_ai_price_max", "_original_msrp_man",
    "photos",
)

_PHOTO_ONLY_MARKER = "딜러가 사진으로 등록한 성능점검기록부입니다"

_INSP_URL_KEYS: dict[str, str] = {
    "mpark":      "mpark_url",
    "autocafe":   "autocafe_url",
    "moldeoncar": "moldeoncar_url",
    "kb_paper":   "inspection_url",
    "encar":      "inspection_url",
    "carmon":     "inspection_url",
}


class KBChaEnricher:
    """Handles detail-page and inspection enrichment for a batch of CarLot objects."""

    def __init__(
        self,
        client: KBChaClient,
        detail_parser: KBChaDetailParser,
        inspection_parser: CarmodooInspectionParser,
        repo: LotRepository,
        source: str,
    ) -> None:
        self._client = client
        self._detail_parser = detail_parser
        self._inspection_parser = inspection_parser
        self._repo = repo
        self._source = source

    # ── Detail enrichment ──────────────────────────────────────────────────

    def enrich_details(self, lots: list[CarLot], stats: dict, on_page_callback=None) -> None:
        delay = max(Config.REQUEST_DELAY, 1.5)
        enriched_fields: dict[str, int] = {}

        logger.debug(f"[{self._source}] Warming up session before detail fetches...")
        self._client.warmup()
        _time.sleep(delay)

        for i, lot in enumerate(lots):
            car_seq = lot.id.replace("kbcha_", "")
            try:
                combined = self._fetch_combined(car_seq, lot, stats, delay)
                self._apply_combined(lot, combined, enriched_fields)
                self._repo.upsert_batch([lot])
                photos = lot.raw_data.get("photos") or []
                if photos:
                    self._repo.upsert_photos(lot.id, photos)
                stats["detail_fetched"] += 1
                self._log_lot_dump(lot)

                if (i + 1) % 10 == 0:
                    logger.info(
                        f"[{self._source}] Detail progress: {i + 1}/{len(lots)} "
                        f"({stats['detail_fetched']} ok, {stats['errors']} err, "
                        f"{stats.get('bot_checks', 0)} bot-checks)"
                    )
            except Exception as e:
                logger.warning(f"[{self._source}] Detail fetch failed for {lot.id}: {type(e).__name__}: {e}")
                stats["errors"] += 1

            if on_page_callback:
                on_page_callback(page=i + 1, found=1, total_pages=len(lots))

            _time.sleep(delay)

        if enriched_fields:
            logger.info(f"[{self._source}] Detail enrichment summary:")
            for field, count in sorted(enriched_fields.items(), key=lambda x: -x[1]):
                pct = count / len(lots) * 100 if lots else 0
                logger.info(f"[{self._source}]   {field}: {count}/{len(lots)} ({pct:.0f}%)")

    def _fetch_combined(self, car_seq: str, lot: CarLot, stats: dict, delay: float) -> dict:
        # 1. Primary: full detail page
        detail_html = self._client.fetch_detail_page(car_seq)
        bot_blocked = self._detail_parser.is_bot_check_page(detail_html)
        if bot_blocked:
            stats["bot_checks"] = stats.get("bot_checks", 0) + 1
            if self._client.rotate_proxy():
                logger.info(f"[{self._source}] {lot.id}: bot-check — rotated proxy, retrying...")
                self._client.warmup()
                _time.sleep(1.0)
                detail_html = self._client.fetch_detail_page(car_seq)
                bot_blocked = self._detail_parser.is_bot_check_page(detail_html)
        if bot_blocked:
            logger.warning(f"[{self._source}] {lot.id}: bot-check — going directly to popups")
        combined = {} if bot_blocked else self._detail_parser.parse(detail_html)

        # 2. Fallback: basic-info popup if spec fields are missing
        if not any(f in combined for f in _SPEC_FIELDS):
            logger.debug(f"[{self._source}] {lot.id}: missing specs, fetching basic_info popup")
            _time.sleep(delay)
            try:
                basic_html = self._client.fetch_basic_info(car_seq)
                combined = {**self._detail_parser.parse_basic_info(basic_html), **combined}
            except Exception as e:
                logger.warning(f"[{self._source}] {lot.id}: basic_info popup failed: {e}")

        # 3. Fallback: km-analysis popup if mileage_grade is missing
        if "mileage_grade" not in combined:
            logger.debug(f"[{self._source}] {lot.id}: mileage_grade missing, fetching km_analysis popup")
            _time.sleep(delay)
            try:
                km_html = self._client.fetch_km_analysis(car_seq)
                combined.update(self._detail_parser.parse_km_analysis(km_html))
            except Exception as e:
                logger.warning(f"[{self._source}] {lot.id}: km_analysis popup failed: {e}")

        return combined

    def _apply_combined(self, lot: CarLot, combined: dict, enriched_fields: dict) -> None:
        for field in combined:
            enriched_fields[field] = enriched_fields.get(field, 0) + 1

        raw_info = combined.pop("_raw_info", None)
        if raw_info:
            lot.raw_data["raw_info"] = raw_info

        lot.merge_details(combined)
        for key in _RAW_DATA_KEYS:
            if key in combined:
                lot.raw_data[key] = combined[key]

    def _log_lot_dump(self, lot: CarLot) -> None:
        if not logger.isEnabledFor(logging.DEBUG):
            return
        title = lot.raw_data.get("title", "")
        logger.debug(
            f"[{self._source}] LOT_DUMP {lot.id} | title={title!r} | "
            f"make={lot.make} | model={lot.model!r} | trim={lot.trim!r} | "
            f"year={lot.year} | price={lot.price} | mileage={lot.mileage} | "
            f"fuel={lot.fuel!r} | trans={lot.transmission!r} | body={lot.body_type!r} | "
            f"drive={lot.drive_type!r} | engine_vol={lot.engine_volume} | "
            f"color={lot.color!r} | seat_color={lot.seat_color!r} | "
            f"owners={lot.owners_count} | vin={lot.vin!r} | plate={lot.plate_number!r} | "
            f"insp_type={lot.raw_data.get('inspection_type')!r} | "
            f"generation={lot.raw_data.get('generation')!r} | "
            f"engine_str={lot.raw_data.get('engine_str')!r} | "
            f"options_n={len(lot.options) if lot.options else 0}"
        )

    # ── Inspection enrichment ──────────────────────────────────────────────

    def enrich_inspections(self, lots: list[CarLot], stats: dict, on_page_callback=None) -> None:
        delay = max(Config.REQUEST_DELAY, 1.5)
        insp_stats: dict[str, int] = {
            "parsed": 0, "photo_only": 0, "no_button": 0,
            "url_saved": 0, "other": 0, "errors": 0,
        }

        for i, lot in enumerate(lots):
            insp_type = lot.raw_data.get("inspection_type")
            car_seq = lot.id.replace("kbcha_", "")

            if insp_type in _INSP_URL_KEYS:
                self._save_inspection_url(lot, insp_type, insp_stats)
                continue

            if insp_type == "kb_popup":
                fetch_fn = lambda seq=car_seq: self._client.fetch_kb_inspection(seq)
            elif insp_type == "other":
                insp_stats["other"] += 1
                logger.debug(f"[{self._source}] {lot.id}: unknown inspection type, skipping")
                continue
            else:
                insp_stats["no_button"] += 1
                logger.debug(f"[{self._source}] {lot.id}: no inspection button detected")
                continue

            try:
                html = fetch_fn()
                if _PHOTO_ONLY_MARKER in html:
                    insp_stats["photo_only"] += 1
                    logger.debug(f"[{self._source}] {lot.id}: photo-only inspection report")
                    continue
                self._parse_and_save_inspection(lot, car_seq, html, insp_stats)
                _time.sleep(delay)
            except Exception as e:
                insp_stats["errors"] += 1
                logger.warning(f"[{self._source}] Inspection fetch failed for {lot.id}: {type(e).__name__}: {e}")

            if on_page_callback:
                on_page_callback(page=i + 1, found=1, total_pages=len(lots))

        logger.info(
            f"[{self._source}] Inspection summary ({len(lots)} lots): "
            f"parsed={insp_stats['parsed']} url_saved={insp_stats['url_saved']} "
            f"photo_only={insp_stats['photo_only']} no_button={insp_stats['no_button']} "
            f"other={insp_stats['other']} errors={insp_stats['errors']}"
        )

    def _save_inspection_url(self, lot: CarLot, insp_type: str, insp_stats: dict) -> None:
        url_key = _INSP_URL_KEYS[insp_type]
        report_url = lot.raw_data.get(url_key)
        if report_url:
            rec = InspectionRecord(lot_id=lot.id, source=insp_type, report_url=report_url)
            try:
                self._repo.upsert_inspection(rec)
                insp_stats["url_saved"] += 1
                logger.debug(f"[{self._source}] {lot.id}: saved inspection URL type={insp_type}")
            except Exception as e:
                insp_stats["errors"] += 1
                logger.warning(f"[{self._source}] {lot.id}: upsert_inspection failed: {e}")
        else:
            logger.debug(f"[{self._source}] {lot.id}: {insp_type} — no URL in raw_data")

    def _parse_and_save_inspection(
        self, lot: CarLot, car_seq: str, html: str, insp_stats: dict
    ) -> None:
        insp = self._inspection_parser.parse(html)

        if insp.get("vin"):
            lot.vin = insp["vin"]
            logger.debug(f"[{self._source}] {lot.id}: VIN -> '{lot.vin}'")
        if "inspection_accident" in insp:
            lot.has_accident = insp["inspection_accident"]
        if "inspection_flood" in insp:
            lot.flood_history = insp["inspection_flood"]

        structural = insp.get("damaged_structural_panels", [])
        outer = insp.get("damaged_outer_panels", [])
        kb_insp_url = (
            f"https://www.kbchachacha.com/public/layer/car/check/info.kbc"
            f"?layerId=layerCarCheckInfo&carSeq={car_seq}&diagCarYn=N&diagCarSeq=&premiumCarYn=N"
        )
        rec = InspectionRecord(
            lot_id=lot.id,
            source="kb_chacha",
            cert_no=insp.get("inspection_cert_no"),
            valid_from=insp.get("inspection_valid_from"),
            valid_until=insp.get("inspection_valid_until"),
            report_url=kb_insp_url,
            first_registration=insp.get("first_registration"),
            inspection_mileage=insp.get("inspection_mileage"),
            insurance_fee=insp.get("inspection_fee"),
            has_accident=insp.get("inspection_accident"),
            has_outer_damage=bool(outer),
            has_flood=insp.get("inspection_flood"),
            has_fire=insp.get("inspection_fire"),
            has_tuning=insp.get("inspection_tuning"),
            accident_detail=", ".join(structural) if structural else None,
            outer_detail=", ".join(outer) if outer else None,
            details={
                "damaged_structural_panels": structural,
                "damaged_outer_panels": outer,
                "bad_components": insp.get("bad_components", []),
                "inspector_note": insp.get("inspector_note"),
            },
        )
        self._repo.upsert_inspection(rec)
        self._repo.upsert_batch([lot])
        insp_stats["parsed"] += 1
