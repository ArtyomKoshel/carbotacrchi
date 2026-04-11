from __future__ import annotations

import logging
import threading
import time as _time
import traceback as _tb
from concurrent.futures import ThreadPoolExecutor, as_completed

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from .client import KBChaClient, _generate_kbcha_proxies
from .detail_parser import KBChaDetailParser
from .external_inspection_parser import KBChaExternalInspectionParser, compare_report_vs_lot
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
        self._external_parser = KBChaExternalInspectionParser()
        self._repo = repo
        self._source = source

    @staticmethod
    def _inc_error(stats: dict, etype: str, message: str) -> None:
        stats["errors"] = stats.get("errors", 0) + 1
        stats.setdefault("error_types", {})
        stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
        stats.setdefault("error_log", [])
        stats["error_log"].append(message)

    # ── Detail enrichment ──────────────────────────────────────────────────

    def enrich_details(self, lots: list[CarLot], stats: dict, on_page_callback=None) -> None:
        workers = min(Config.KBCHA_WORKERS, len(lots))
        delay = max(Config.REQUEST_DELAY, 1.5)
        enriched_fields: dict[str, int] = {}
        proxy_pool = _generate_kbcha_proxies()
        _stats_lock = threading.Lock()

        logger.info(f"[{self._source}] Detail enrichment: {len(lots)} lots, {workers} workers")

        def _task(lot: CarLot, idx: int) -> tuple[CarLot, dict, int]:
            proxy = proxy_pool[idx % len(proxy_pool)] if proxy_pool else None
            client = KBChaClient(proxy=proxy)
            try:
                client.warmup()
                car_seq = lot.id.replace("kbcha_", "")
                combined = self._fetch_combined_with(car_seq, lot, client, stats, _stats_lock, delay)
                return lot, combined, 0
            except Exception as e:
                logger.warning(
                    f"[{self._source}] Detail fetch failed for {lot.id}: {type(e).__name__}: {e}\n"
                    + _tb.format_exc(limit=6)
                )
                return lot, {}, 1
            finally:
                client.close()

        FLUSH_EVERY = 1  # write each lot immediately as its detail fetch completes
        pending: list[CarLot] = []
        all_valid_lots: list[CarLot] = []
        total_saved = 0
        total_skipped = 0

        def _flush(force: bool = False) -> None:
            nonlocal total_saved
            if not pending:
                return
            if not force and len(pending) < FLUSH_EVERY:
                return
            logger.info(f"[{self._source}] Writing {len(pending)} lots to DB...")
            try:
                self._repo.upsert_batch(pending, stats=stats)
                saved = sum(1 for l in pending if not l.raw_data.get("_db_skip"))
                total_saved += saved
                logger.info(f"[{self._source}] DB write done: {saved}/{len(pending)} lots saved "
                            f"(total so far: {total_saved})")
            except Exception as e:
                etype = type(e).__name__
                self._inc_error(stats, etype, f"batch upsert failed ({len(pending)} lots): {etype}: {e}")
                logger.warning(f"[{self._source}] batch upsert failed: {etype}: {e}")
            pending.clear()

        with ThreadPoolExecutor(max_workers=workers) as pool:
            future_map = {pool.submit(_task, lot, idx): lot for idx, lot in enumerate(lots)}
            for i, future in enumerate(as_completed(future_map)):
                lot, combined, errors = future.result()
                stats["errors"] += errors
                if errors:
                    self._inc_error(stats, "detail_fetch", f"detail fetch failed {lot.id}")
                if combined:
                    self._apply_combined(lot, combined, enriched_fields)
                    pending.append(lot)
                    all_valid_lots.append(lot)
                    _flush()
                else:
                    total_skipped += 1
                if on_page_callback:
                    try:
                        on_page_callback(page=i + 1, found=1, total_pages=len(lots), stats=stats)
                    except Exception:
                        pass

        _flush(force=True)  # write remaining lots

        if total_saved == 0 and total_skipped == len(lots):
            logger.warning(
                f"[{self._source}] No lots written — all {len(lots)} detail fetches returned empty. "
                f"Possible bot-block or site error."
            )
        else:
            logger.info(
                f"[{self._source}] Enrichment complete: {total_saved} saved, "
                f"{total_skipped} skipped, {stats.get('errors', 0)} errors"
            )

        for lot in all_valid_lots:
            if lot.raw_data.get("_db_skip"):
                continue
            photos = lot.raw_data.get("photos") or []
            if photos:
                try:
                    self._repo.upsert_photos(lot.id, photos)
                except Exception as e:
                    etype = type(e).__name__
                    self._inc_error(stats, etype, f"upsert photos {lot.id}: {etype}: {e}")
            stats["detail_fetched"] += 1
            self._log_lot_dump(lot)

        if enriched_fields:
            logger.info(f"[{self._source}] Detail enrichment summary:")
            for field, count in sorted(enriched_fields.items(), key=lambda x: -x[1]):
                pct = count / len(lots) * 100 if lots else 0
                logger.info(f"[{self._source}]   {field}: {count}/{len(lots)} ({pct:.0f}%)")

    def _fetch_combined_with(
        self, car_seq: str, lot: CarLot,
        client: KBChaClient, stats: dict, lock: threading.Lock, delay: float
    ) -> dict:
        def _inc_stat(key: str, val: int | float = 1) -> None:
            with lock:
                stats[key] = stats.get(key, 0) + val

        # 1. Primary: full detail page
        detail_html = client.fetch_detail_page(car_seq)
        bot_blocked = self._detail_parser.is_bot_check_page(detail_html)
        if bot_blocked:
            _inc_stat("bot_checks")
            if client.rotate_proxy():
                logger.info(f"[{self._source}] {lot.id}: bot-check — rotated proxy, retrying...")
                client.warmup()
                _time.sleep(1.0)
                detail_html = client.fetch_detail_page(car_seq)
                bot_blocked = self._detail_parser.is_bot_check_page(detail_html)
        if bot_blocked:
            logger.warning(f"[{self._source}] {lot.id}: bot-check — going directly to popups")
        combined = {} if bot_blocked else self._detail_parser.parse(detail_html)

        # 2. Fallback: basic-info popup if spec fields are missing
        if not any(f in combined for f in _SPEC_FIELDS):
            logger.debug(f"[{self._source}] {lot.id}: missing specs, fetching basic_info popup")
            _time.sleep(delay)
            try:
                basic_html = client.fetch_basic_info(car_seq)
                combined = {**self._detail_parser.parse_basic_info(basic_html), **combined}
            except Exception as e:
                logger.warning(f"[{self._source}] {lot.id}: basic_info popup failed: {e}")

        # km-analysis popup disabled — endpoint returns 500 server-side

        return combined

    def _fetch_combined(self, car_seq: str, lot: CarLot, stats: dict, delay: float) -> dict:
        """Legacy single-client wrapper (used by run_reenrich)."""
        _lock = threading.Lock()
        return self._fetch_combined_with(car_seq, lot, self._client, stats, _lock, delay)

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
        fill = {"vin": 0, "accident": 0, "flood": 0, "panels": 0, "mileage": 0}

        for i, lot in enumerate(lots):
            insp_type = lot.raw_data.get("inspection_type")
            car_seq = lot.id.replace("kbcha_", "")

            if insp_type in _INSP_URL_KEYS:
                report_url = lot.raw_data.get(_INSP_URL_KEYS[insp_type])
                parsed_external = False
                if insp_type in {"kb_paper", "carmon", "mpark", "autocafe", "moldeoncar"} and report_url:
                    try:
                        html = self._client.fetch_external_report(report_url, referer=lot.lot_url)
                        parsed = self._external_parser.parse(report_url, html)
                        if parsed.get("details", {}).get("parsed_count", 0) > 0:
                            self._upsert_external_inspection(lot, insp_type, report_url, parsed, insp_stats, stats)
                            self._bump_fill(fill, lot)
                            parsed_external = True
                    except Exception as e:
                        etype = type(e).__name__
                        logger.warning(
                            f"[{self._source}] {lot.id}: external inspection parse failed ({insp_type}): {etype}: {e}"
                        )
                        self._inc_error(
                            stats,
                            etype,
                            f"external inspection parse failed {lot.id}/{insp_type}: {etype}: {e}",
                        )
                if not parsed_external:
                    self._save_inspection_url(lot, insp_type, insp_stats, stats)
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
                if len(html.strip()) < 1024:
                    logger.warning(f"[{self._source}] {lot.id}: inspection HTML too small, retrying once")
                    self._client.rotate_proxy()
                    _p = _time.monotonic()
                    _time.sleep(1.5)
                    stats["pause_time"] = stats.get("pause_time", 0.0) + (_time.monotonic() - _p)
                    html = fetch_fn()
                if _PHOTO_ONLY_MARKER in html:
                    insp_stats["photo_only"] += 1
                    logger.debug(f"[{self._source}] {lot.id}: photo-only inspection report")
                    continue
                self._parse_and_save_inspection(lot, car_seq, html, insp_stats)
                self._bump_fill(fill, lot)

                _p = _time.monotonic()
                _time.sleep(delay)
                stats["pause_time"] = stats.get("pause_time", 0.0) + (_time.monotonic() - _p)
            except Exception as e:
                insp_stats["errors"] += 1
                logger.warning(f"[{self._source}] Inspection fetch failed for {lot.id}: {type(e).__name__}: {e}")
                self._inc_error(
                    stats,
                    type(e).__name__,
                    f"inspection fetch failed {lot.id}: {type(e).__name__}: {e}",
                )

            if on_page_callback:
                try:
                    on_page_callback(page=i + 1, found=1, total_pages=len(lots), stats=stats)
                except Exception:
                    pass

        logger.info(
            f"[{self._source}] Inspection summary ({len(lots)} lots): "
            f"parsed={insp_stats['parsed']} url_saved={insp_stats['url_saved']} "
            f"photo_only={insp_stats['photo_only']} no_button={insp_stats['no_button']} "
            f"other={insp_stats['other']} errors={insp_stats['errors']}"
        )
        total = len(lots) or 1
        logger.info(f"[STAT] [{self._source}] Inspection fill rate ({len(lots)} lots):")
        logger.info(f"[STAT] [{self._source}]   VIN:      {fill['vin']}/{len(lots)} ({fill['vin'] / total * 100:.0f}%)")
        logger.info(f"[STAT] [{self._source}]   accident: {fill['accident']}/{len(lots)} ({fill['accident'] / total * 100:.0f}%)")
        logger.info(f"[STAT] [{self._source}]   flood:    {fill['flood']}/{len(lots)} ({fill['flood'] / total * 100:.0f}%)")
        logger.info(f"[STAT] [{self._source}]   panels:   {fill['panels']}/{len(lots)} ({fill['panels'] / total * 100:.0f}%)")
        logger.info(f"[STAT] [{self._source}]   mileage:  {fill['mileage']}/{len(lots)} ({fill['mileage'] / total * 100:.0f}%)")

    @staticmethod
    def _bump_fill(fill: dict[str, int], lot: CarLot) -> None:
        if lot.vin:
            fill["vin"] += 1
        if lot.has_accident is not None:
            fill["accident"] += 1
        if lot.flood_history is not None:
            fill["flood"] += 1
        if lot.damage or lot.secondary_damage:
            fill["panels"] += 1
        if lot.raw_data.get("inspection_mileage"):
            fill["mileage"] += 1

    def _upsert_external_inspection(
        self,
        lot: CarLot,
        insp_type: str,
        report_url: str,
        parsed: dict,
        insp_stats: dict,
        stats: dict,
    ) -> None:
        # ── Compare report vs lot and log discrepancies ─────────────────
        lot_snapshot = {
            "vin": lot.vin,
            "year": lot.year,
            "fuel": lot.fuel,
            "transmission": lot.transmission,
            "plate_number": lot.plate_number,
            "has_accident": lot.has_accident,
            "flood_history": lot.flood_history,
            "mileage": lot.mileage,
        }
        cmp = compare_report_vs_lot(parsed, lot_snapshot)
        for field, entry in cmp.items():
            if not entry["match"]:
                logger.warning(
                    f"[{self._source}] {lot.id} MISMATCH [{insp_type}] {field}: "
                    f"report={entry['report']!r} lot={entry['lot']!r}"
                )

        # ── Apply high-confidence fields from report to lot ─────────────
        if parsed.get("vin"):
            if not lot.vin:
                lot.vin = parsed["vin"]
                logger.debug(f"[{self._source}] {lot.id}: VIN set from report")
            elif lot.vin.upper() != parsed["vin"].upper():
                logger.warning(
                    f"[{self._source}] {lot.id}: VIN override report={parsed['vin']!r} lot={lot.vin!r}"
                )
                lot.vin = parsed["vin"]

        if parsed.get("has_accident") is not None:
            lot.has_accident = parsed["has_accident"]

        if parsed.get("has_flood") is not None:
            lot.flood_history = parsed["has_flood"]

        if parsed.get("inspection_mileage"):
            lot.raw_data["inspection_mileage"] = parsed["inspection_mileage"]

        # Fill missing lot fields from report (non-overriding)
        if parsed.get("report_fuel") and not lot.fuel:
            lot.fuel = parsed["report_fuel"]
            logger.debug(f"[{self._source}] {lot.id}: fuel set from report: {lot.fuel}")
        if parsed.get("report_transmission") and not lot.transmission:
            lot.transmission = parsed["report_transmission"]
            logger.debug(f"[{self._source}] {lot.id}: transmission set from report: {lot.transmission}")
        if parsed.get("report_first_registered") and not lot.raw_data.get("first_registration"):
            lot.raw_data["first_registration"] = parsed["report_first_registered"]

        # ── Damaged panels ──────────────────────────────────────────────
        damaged = parsed.get("damaged_panels") or []
        structural = [p["panel"] for p in damaged if p.get("rank") == "structural"]
        outer = [p["panel"] for p in damaged if p.get("rank") == "outer"]
        if structural and not lot.damage:
            lot.damage = ", ".join(structural)
        if outer and not lot.secondary_damage:
            lot.secondary_damage = ", ".join(outer)

        # ── Build InspectionRecord ──────────────────────────────────────
        details_payload = dict(parsed.get("details") or {"provider": insp_type})
        details_payload["comparison"] = cmp
        details_payload["report_plate"] = parsed.get("report_plate")
        details_payload["report_year"] = parsed.get("report_year")
        details_payload["report_engine_code"] = parsed.get("report_engine_code")
        details_payload["report_model_name"] = details_payload.get("report_model_name")

        rec = InspectionRecord(
            lot_id=lot.id,
            source=insp_type,
            cert_no=parsed.get("cert_no"),
            inspection_date=parsed.get("inspection_date"),
            valid_from=parsed.get("valid_from"),
            valid_until=parsed.get("valid_until"),
            report_url=report_url,
            first_registration=parsed.get("report_first_registered"),
            inspection_mileage=parsed.get("inspection_mileage"),
            has_accident=parsed.get("has_accident"),
            has_outer_damage=bool(outer),
            has_flood=parsed.get("has_flood"),
            has_fire=parsed.get("details", {}).get("has_fire"),
            accident_detail=", ".join(structural) if structural else None,
            outer_detail=", ".join(outer) if outer else None,
            details=details_payload,
        )
        try:
            self._repo.upsert_inspection(rec)
            self._repo.upsert_batch([lot])
            insp_stats["parsed"] += 1
        except Exception as e:
            insp_stats["errors"] += 1
            etype = type(e).__name__
            logger.warning(f"[{self._source}] {lot.id}: external upsert failed: {etype}: {e}")
            self._inc_error(
                stats,
                etype,
                f"external inspection upsert failed {lot.id}/{insp_type}: {etype}: {e}",
            )

    def _save_inspection_url(self, lot: CarLot, insp_type: str, insp_stats: dict, stats: dict) -> None:
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
                self._inc_error(
                    stats,
                    type(e).__name__,
                    f"inspection url save failed {lot.id}/{insp_type}: {type(e).__name__}: {e}",
                )
        else:
            logger.debug(f"[{self._source}] {lot.id}: {insp_type} — no URL in raw_data")

    def _parse_and_save_inspection(
        self, lot: CarLot, car_seq: str, html: str, insp_stats: dict
    ) -> None:
        insp = self._inspection_parser.parse(html)

        if insp.get("vin"):
            lot.vin = insp["vin"]
            logger.debug(f"[{self._source}] {lot.id}: VIN -> '{lot.vin}'")
        elif not lot.vin:
            raw_info = lot.raw_data.get("raw_info") or {}
            fallback_vin = raw_info.get("차대번호") or raw_info.get("차시번호")
            if fallback_vin:
                lot.vin = str(fallback_vin).strip()
                logger.debug(f"[{self._source}] {lot.id}: VIN fallback from detail raw_info")
        if "inspection_accident" in insp:
            lot.has_accident = insp["inspection_accident"]
        if "inspection_flood" in insp:
            lot.flood_history = insp["inspection_flood"]
        if insp.get("inspection_mileage"):
            lot.raw_data["inspection_mileage"] = insp.get("inspection_mileage")

        structural = insp.get("damaged_structural_panels", [])
        outer = insp.get("damaged_outer_panels", [])

        # Set damage fields on the lot
        if structural:
            lot.damage = ", ".join(structural)
            logger.debug(f"[{self._source}] {lot.id}: damage from structural panels")
        if outer:
            lot.secondary_damage = ", ".join(outer)
            logger.debug(f"[{self._source}] {lot.id}: secondary_damage from outer panels")
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
