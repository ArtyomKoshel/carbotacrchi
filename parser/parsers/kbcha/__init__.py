from __future__ import annotations

import logging
import time as _time

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from ..base import AbstractParser
from .client import KBChaClient
from .detail_parser import KBChaDetailParser
from .inspection_parser import CarmodooInspectionParser
from .list_parser import KBChaListParser
from .normalizer import KBChaNormalizer, MAKER_CODES

logger = logging.getLogger(__name__)


class KBChaParser(AbstractParser):
    def __init__(self, repo: LotRepository):
        super().__init__(repo)
        self._client = KBChaClient()
        self._normalizer = KBChaNormalizer()
        self._list_parser = KBChaListParser(self._normalizer)
        self._detail_parser = KBChaDetailParser(self._normalizer)
        self._inspection_parser = CarmodooInspectionParser()

    def get_source_key(self) -> str:
        return "kbcha"

    def get_source_name(self) -> str:
        return "KBChacha"

    def run_reenrich(self, limit: int | None = None) -> int:
        """Re-fetch detail pages for lots already in DB and update their fields."""
        source = self.get_source_key()
        run_start = _time.monotonic()
        stats = {"total": 0, "detail_fetched": 0, "errors": 0}

        lots = self.repo.get_lots_by_source(source, limit=limit)
        if not lots:
            logger.info(f"[{source}] No active lots found in DB")
            return 0

        logger.info(f"[{source}] Re-enriching {len(lots)} existing lots...")

        # Step 1: re-parse title → model, generation, engine_str, trim
        model_fixes = 0
        for lot in lots:
            title = lot.raw_data.get("title") or lot.raw_data.get("vehicle_info", "")
            if not title:
                continue
            parsed = self._normalizer.parse_title(title)
            if parsed["model"] and parsed["model"] != lot.model:
                logger.info(f"[{source}] {lot.id}: model  '{lot.model}' → '{parsed['model']}'")
                lot.model = parsed["model"]
                model_fixes += 1
            if parsed["generation"] and not lot.raw_data.get("generation"):
                lot.raw_data["generation"] = parsed["generation"]
            if parsed["engine_str"] and not lot.raw_data.get("engine_str"):
                lot.raw_data["engine_str"] = parsed["engine_str"]
            if parsed["trim"]:
                lot.trim = parsed["trim"]
            if parsed.get("drive") and not lot.drive_type:
                lot.drive_type = parsed["drive"]
        logger.info(f"[{source}] Model fixes from title: {model_fixes}")

        # Step 2: capture key fields before detail re-fetch
        _DIFF_KEYS = ("inspection_type", "mpark_url", "autocafe_url", "inspection_url",
                      "inspection_no", "mileage_grade")
        before: dict[str, dict] = {
            lot.id: {
                "model": lot.model,
                "trim": lot.trim,
                **{k: lot.raw_data.get(k) for k in _DIFF_KEYS},
                "fuel": lot.fuel, "body_type": lot.body_type,
                "options_count": len(lot.options) if lot.options else 0,
            }
            for lot in lots
        }

        self._client.warmup()
        _time.sleep(max(Config.REQUEST_DELAY, 1.5))
        self._enrich_with_details(lots, stats)
        self._enrich_with_inspection(lots, stats)

        # Step 3: log per-lot diff
        changed_lots = 0
        for lot in lots:
            b = before[lot.id]
            after_snap = {
                "model": lot.model,
                "trim": lot.trim,
                **{k: lot.raw_data.get(k) for k in _DIFF_KEYS},
                "fuel": lot.fuel, "body_type": lot.body_type,
                "options_count": len(lot.options) if lot.options else 0,
            }
            diffs = {k: (b[k], after_snap[k]) for k in after_snap if b[k] != after_snap[k]}
            if diffs:
                changed_lots += 1
                diff_str = ", ".join(f"{k}: '{v[0]}' → '{v[1]}'" for k, v in diffs.items())
                logger.info(f"[{source}] {lot.id}: CHANGED — {diff_str}")

        elapsed = _time.monotonic() - run_start
        logger.info(f"[{source}] Re-enrich complete: {stats['detail_fetched']} fetched, "
                    f"{changed_lots} changed, {stats['errors']} errors in {elapsed:.1f}s")
        return stats["detail_fetched"]

    def run(
        self,
        max_pages: int | None = None,
        maker_filter: str | None = None,
    ) -> int:
        source = self.get_source_key()
        run_start = _time.monotonic()
        stats = {"total": 0, "new": 0, "updated": 0, "detail_fetched": 0, "errors": 0}

        effective_pages = max_pages or Config.KBCHA_MAX_PAGES
        makers = {
            code: name for code, name in MAKER_CODES.items()
            if maker_filter is None or maker_filter.lower() in name.lower()
        }
        if maker_filter and not makers:
            logger.warning(f"[{source}] No makers matched filter '{maker_filter}'. "
                           f"Available: {', '.join(MAKER_CODES.values())}")
            return 0

        logger.info(f"[{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[{source}] Makers: {len(makers)}, "
                    f"max_pages={effective_pages}, delay={Config.REQUEST_DELAY}s")
        if maker_filter:
            logger.info(f"[{source}] Maker filter: '{maker_filter}' -> {list(makers.values())}")

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}

        for maker_code, maker_name in makers.items():
            maker_start = _time.monotonic()

            maker_lots = self._fetch_maker(maker_code, maker_name, seen_ids,
                                               max_pages=effective_pages)
            if not maker_lots:
                continue

            new_lots = [lot for lot in maker_lots if lot.id not in existing_ids]
            updated_lots = [lot for lot in maker_lots if lot.id in existing_ids]

            if maker_lots:
                logger.info(f"[{source}] {maker_name}: enriching {len(maker_lots)} lots with details "
                            f"({len(new_lots)} new, {len(updated_lots)} existing)...")
                self._enrich_with_details(maker_lots, stats)

            if new_lots:
                self._enrich_with_inspection(new_lots, stats)

            if maker_lots:
                stats["total"] += len(maker_lots)
                stats["new"] += len(new_lots)
                stats["updated"] += len(updated_lots)

            maker_elapsed = _time.monotonic() - maker_start
            maker_stats[maker_name] = len(maker_lots)
            logger.info(f"[{source}] {maker_name}: {len(maker_lots)} lots "
                         f"({len(new_lots)} new, {len(updated_lots)} upd) "
                         f"in {maker_elapsed:.1f}s -> DB OK")

        stale = self.repo.mark_inactive(source, seen_ids, grace_hours=24)
        counts = self.repo.count_by_source(source)
        run_elapsed = _time.monotonic() - run_start

        logger.info(f"[{source}] ========== IMPORT COMPLETE ==========")
        logger.info(f"[{source}] Upserted:       {stats['total']}")
        logger.info(f"[{source}] New lots:        {stats['new']}")
        logger.info(f"[{source}] Updated lots:    {stats['updated']}")
        logger.info(f"[{source}] Details fetched: {stats['detail_fetched']}")
        logger.info(f"[{source}] Marked inactive: {stale}")
        logger.info(f"[{source}] Errors:          {stats['errors']}")
        logger.info(f"[{source}] DB totals:       {counts['active']} active, {counts['inactive']} inactive")
        logger.info(f"[{source}] Total time:      {run_elapsed:.1f}s ({run_elapsed / 60:.1f} min)")

        if maker_stats:
            logger.info(f"[{source}] Per-maker breakdown:")
            for name, count in sorted(maker_stats.items(), key=lambda x: -x[1]):
                if count > 0:
                    logger.info(f"[{source}]   {name}: {count}")

        self._client.close()
        return stats["total"]

    def _fetch_maker(
        self, maker_code: str, maker_name: str, seen_ids: set[str],
        max_pages: int | None = None,
    ) -> list[CarLot]:
        source = self.get_source_key()
        lots: list[CarLot] = []
        pages = max_pages or Config.KBCHA_MAX_PAGES

        logger.info(f"[{source}] --- {maker_name} ({maker_code}) ---")

        for page in range(1, pages + 1):
            try:
                html = self._client.fetch_list_page(maker_code, page)
            except Exception as e:
                logger.error(f"[{source}] {maker_name} p.{page} fetch error: {type(e).__name__}: {e}")
                break

            page_lots = self._list_parser.parse(html, maker_code)
            if not page_lots:
                logger.debug(f"[{source}] {maker_name} p.{page}: empty -> done")
                break

            new_on_page = 0
            for lot in page_lots:
                if lot.id not in seen_ids:
                    seen_ids.add(lot.id)
                    lots.append(lot)
                    new_on_page += 1

            logger.info(f"[{source}] {maker_name} p.{page}: "
                         f"{len(page_lots)} parsed, {new_on_page} unique")

            _time.sleep(Config.REQUEST_DELAY)

        return lots

    _PHOTO_ONLY_MARKER = "딜러가 사진으로 등록한 성능점검기록부입니다"

    # Maps inspection_type → raw_data key that holds the URL
    _INSP_URL_KEYS: dict[str, str] = {
        "mpark":      "mpark_url",
        "autocafe":   "autocafe_url",
        "moldeoncar": "moldeoncar_url",
        "kb_paper":   "inspection_url",
        "encar":      "inspection_url",
        "carmon":     "inspection_url",
    }

    def _enrich_with_inspection(self, lots: list[CarLot], stats: dict) -> None:
        source = self.get_source_key()
        delay = max(Config.REQUEST_DELAY, 1.5)
        insp_stats: dict[str, int] = {"parsed": 0, "photo_only": 0, "no_button": 0,
                                       "url_saved": 0, "other": 0, "errors": 0}

        for lot in lots:
            insp_type = lot.raw_data.get("inspection_type")
            car_seq = lot.id.replace("kbcha_", "")

            if insp_type in self._INSP_URL_KEYS:
                url_key = self._INSP_URL_KEYS[insp_type]
                report_url = lot.raw_data.get(url_key)
                if report_url:
                    rec = InspectionRecord(
                        lot_id=lot.id,
                        source=insp_type,
                        report_url=report_url,
                    )
                    try:
                        self.repo.upsert_inspection(rec)
                        insp_stats["url_saved"] += 1
                        logger.debug(f"[{source}] {lot.id}: saved inspection URL "
                                     f"type={insp_type} url='{report_url}'")
                    except Exception as e:
                        insp_stats["errors"] += 1
                        logger.warning(f"[{source}] {lot.id}: upsert_inspection failed: {e}")
                else:
                    logger.debug(f"[{source}] {lot.id}: {insp_type} — no URL found in raw_data")
                continue

            if insp_type == "kb_popup":
                fetch_fn = lambda seq=car_seq: self._client.fetch_kb_inspection(seq)
            elif insp_type == "other":
                insp_stats["other"] += 1
                logger.debug(f"[{source}] {lot.id}: unknown inspection type, skipping")
                continue
            else:
                insp_stats["no_button"] += 1
                logger.debug(f"[{source}] {lot.id}: no inspection button detected")
                continue

            try:
                html = fetch_fn()

                if self._PHOTO_ONLY_MARKER in html:
                    insp_stats["photo_only"] += 1
                    logger.debug(f"[{source}] {lot.id}: photo-only inspection report")
                    continue

                insp = self._inspection_parser.parse(html)

                # ── Promote filterable fields to lots ─────────────────────
                if insp.get("vin"):
                    lot.vin = insp["vin"]
                    logger.debug(f"[{source}] {lot.id}: VIN -> '{lot.vin}'")
                if "inspection_accident" in insp:
                    lot.has_accident = insp["inspection_accident"]
                if "inspection_flood" in insp:
                    lot.flood_history = insp["inspection_flood"]

                # ── Build InspectionRecord for lot_inspections table ───────
                structural = insp.get("damaged_structural_panels", [])
                outer = insp.get("damaged_outer_panels", [])
                kb_insp_url = (
                    f"https://www.kbchachacha.com/public/layer/car/check/info.kbc"
                    f"?layerId=layerCarCheckInfo&carSeq={car_seq}"
                    f"&diagCarYn=N&diagCarSeq=&premiumCarYn=N"
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
                self.repo.upsert_inspection(rec)

                self.repo.upsert_batch([lot])
                insp_stats["parsed"] += 1
                _time.sleep(delay)
            except Exception as e:
                insp_stats["errors"] += 1
                logger.warning(f"[{source}] Inspection fetch failed for {lot.id}: "
                               f"{type(e).__name__}: {e}")

        total = len(lots)
        logger.info(
            f"[{source}] Inspection summary ({total} lots): "
            f"parsed={insp_stats['parsed']} "
            f"url_saved={insp_stats['url_saved']} "
            f"photo_only={insp_stats['photo_only']} "
            f"no_button={insp_stats['no_button']} "
            f"other={insp_stats['other']} "
            f"errors={insp_stats['errors']}"
        )

    def _enrich_with_details(self, lots: list[CarLot], stats: dict) -> None:
        source = self.get_source_key()
        delay = max(Config.REQUEST_DELAY, 1.5)
        enriched_fields: dict[str, int] = {}

        # Warm up session: visit homepage + search page to avoid bot-check on detail pages
        logger.debug(f"[{source}] Warming up session before detail fetches...")
        self._client.warmup()
        _time.sleep(delay)

        _SPEC_FIELDS = {"fuel", "year", "mileage", "engine_volume", "color"}

        for i, lot in enumerate(lots):
            car_seq = lot.id.replace("kbcha_", "")
            try:
                # 1. Primary: full detail page (has all data in one request)
                detail_html = self._client.fetch_detail_page(car_seq)
                bot_blocked = self._detail_parser.is_bot_check_page(detail_html)
                if bot_blocked:
                    rotated = self._client.rotate_proxy()
                    if rotated:
                        logger.info(f"[{source}] {lot.id}: bot-check — rotated proxy, retrying...")
                        self._client.warmup()
                        _time.sleep(1.0)
                        detail_html = self._client.fetch_detail_page(car_seq)
                        bot_blocked = self._detail_parser.is_bot_check_page(detail_html)
                if bot_blocked:
                    logger.warning(f"[{source}] {lot.id}: bot-check on detail page — going directly to popups")
                combined = {} if bot_blocked else self._detail_parser.parse(detail_html)

                # 2. Fallback: basic-info popup if spec fields are missing
                if not any(f in combined for f in _SPEC_FIELDS):
                    logger.debug(f"[{source}] {lot.id}: detail page missing specs, fetching basic_info popup")
                    _time.sleep(delay)
                    try:
                        basic_html = self._client.fetch_basic_info(car_seq)
                        basic_details = self._detail_parser.parse_basic_info(basic_html)
                        combined = {**basic_details, **combined}
                    except Exception as popup_err:
                        logger.warning(f"[{source}] {lot.id}: basic_info popup failed: {popup_err}")

                # 3. Fallback: km-analysis popup if mileage_grade is missing
                if "mileage_grade" not in combined:
                    logger.debug(f"[{source}] {lot.id}: mileage_grade missing, fetching km_analysis popup")
                    _time.sleep(delay)
                    try:
                        km_html = self._client.fetch_km_analysis(car_seq)
                        km_details = self._detail_parser.parse_km_analysis(km_html)
                        combined.update(km_details)
                    except Exception as popup_err:
                        logger.warning(f"[{source}] {lot.id}: km_analysis popup failed: {popup_err}")

                for field in combined:
                    enriched_fields[field] = enriched_fields.get(field, 0) + 1

                raw_info = combined.pop("_raw_info", None)
                if raw_info:
                    lot.raw_data["raw_info"] = raw_info

                lot.merge_details(combined)
                for extra_key in (
                    "inspection_type", "inspection_no",
                    "autocafe_url", "carmodoo_url", "moldeoncar_url", "mpark_url", "inspection_url",
                    "_ai_price_min", "_ai_price_max", "_original_msrp_man",
                ):
                    if extra_key in combined:
                        lot.raw_data[extra_key] = combined[extra_key]
                self.repo.upsert_batch([lot])
                stats["detail_fetched"] += 1

                if logger.isEnabledFor(logging.DEBUG):
                    title = lot.raw_data.get("title", "")
                    logger.debug(
                        f"[{source}] LOT_DUMP {lot.id} | title={title!r} | "
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

                if (i + 1) % 10 == 0:
                    logger.info(f"[{source}] Detail progress: {i + 1}/{len(lots)} "
                                 f"({stats['detail_fetched']} ok, {stats['errors']} err)")

            except Exception as e:
                logger.warning(f"[{source}] Detail fetch failed for {lot.id}: {type(e).__name__}: {e}")
                stats["errors"] += 1

            _time.sleep(delay)

        if enriched_fields:
            logger.info(f"[{source}] Detail enrichment summary:")
            for field, count in sorted(enriched_fields.items(), key=lambda x: -x[1]):
                pct = count / len(lots) * 100 if lots else 0
                logger.info(f"[{source}]   {field}: {count}/{len(lots)} ({pct:.0f}%)")
