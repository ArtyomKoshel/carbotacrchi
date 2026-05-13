from __future__ import annotations

import logging
import threading
import time as _time
import traceback as _tb
from concurrent.futures import ThreadPoolExecutor, as_completed

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from ..base import ProgressUpdate
from .._shared import sell_type as _sell
from .client import KBChaClient, ProxyBudgetExhausted, _generate_kbcha_proxies, _reset_proxy_cache
from .detail_parser import KBChaDetailParser
from .external_inspection_parser import KBChaExternalInspectionParser, compare_report_vs_lot
from .inspection_parser import CarmodooInspectionParser

logger = logging.getLogger(__name__)

_SPEC_FIELDS = {"fuel", "year", "mileage", "engine_volume", "color"}

_RAW_DATA_KEYS = (
    "inspection_type", "inspection_no",
    "autocafe_url", "carmodoo_url", "moldeoncar_url", "mpark_url", "inspection_url",
    "_original_msrp_man",
    # NOTE: "photos" intentionally NOT in this list — they go to lot.photos
    # transit field (see _apply_combined) and are auto-upserted by
    # LotRepository.upsert_batch into the `lot_photos` table.
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
        # Reset proxy cache so each batch gets fresh random sessions (new IPs).
        # Without this, autocafe bans the same 20 cached IPs across all batches.
        _reset_proxy_cache()
        proxy_pool = _generate_kbcha_proxies()
        _stats_lock = threading.Lock()
        logger.info(f"[{self._source}] Detail enrichment: {len(lots)} lots, {workers} workers")

        _thread_local = threading.local()
        _thread_clients: list[KBChaClient] = []
        _thread_clients_lock = threading.Lock()

        def _get_thread_client(proxy_idx: int) -> KBChaClient:
            """Return a warmed-up client for this thread; create+warmup only on first call."""
            if not getattr(_thread_local, "client", None):
                proxy = proxy_pool[proxy_idx % len(proxy_pool)] if proxy_pool else None
                c = KBChaClient(proxy=proxy)
                c.warmup()
                _thread_local.client = c
                _thread_local.proxy_idx = proxy_idx
                with _thread_clients_lock:
                    _thread_clients.append(c)
            return _thread_local.client

        def _task(lot: CarLot, idx: int) -> tuple[CarLot, dict, tuple | None, int, str]:
            try:
                client = _get_thread_client(idx % max(len(proxy_pool), 1) if proxy_pool else 0)
                car_seq = lot.id.replace("kbcha_", "")
                combined = self._fetch_combined_with(car_seq, lot, client, stats, _stats_lock, delay)
                insp_raw = self._fetch_inspection_html(lot, combined, client, delay) if combined else None
                return lot, combined, insp_raw, 0, ""
            except ProxyBudgetExhausted:
                raise  # fatal — must propagate to abort the entire run
            except Exception as e:
                etype = type(e).__name__
                logger.warning(
                    f"[{self._source}] Detail fetch failed for {lot.id}: {etype}: {e}\n"
                    + _tb.format_exc(limit=6)
                )
                return lot, {}, None, 1, etype

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
            try:
                self._repo.upsert_batch(pending, stats=stats)
                saved = sum(1 for l in pending if not l.raw_data.get("_db_skip"))
                total_saved += saved
            except Exception as e:
                etype = type(e).__name__
                self._inc_error(stats, etype, f"batch upsert failed ({len(pending)} lots): {etype}: {e}")
                logger.warning(f"[{self._source}] batch upsert failed: {etype}: {e}")
            pending.clear()

        with ThreadPoolExecutor(max_workers=workers) as pool:
            future_map = {pool.submit(_task, lot, idx): lot for idx, lot in enumerate(lots)}
            for i, future in enumerate(as_completed(future_map)):
                try:
                    lot, combined, insp_raw, errors, etype = future.result()
                except ProxyBudgetExhausted as e:
                    logger.error(f"[{self._source}] Proxy budget exhausted — aborting enrichment. "
                                 f"Processed {i}/{len(lots)} lots so far. {e}")
                    # Cancel remaining futures
                    for f in future_map:
                        f.cancel()
                    self._inc_error(stats, "ProxyBudgetExhausted",
                                    f"Proxy budget exhausted after {i}/{len(lots)} lots")
                    break
                if errors:
                    self._inc_error(stats, etype or "detail_fetch", f"detail fetch failed {lot.id}: {etype}")
                if combined:
                    self._apply_combined(lot, combined, enriched_fields)
                    pending.append(lot)
                    all_valid_lots.append(lot)
                    _flush()  # lot must be in DB before inspection FK write
                    if insp_raw:
                        self._apply_inspection_result(lot, insp_raw, stats)
                else:
                    total_skipped += 1
                if on_page_callback:
                    try:
                        _progress = (i + 1) / len(lots)
                        live_proxy = stats.get("proxy_bytes", 0) + sum(
                            c.proxy_bytes for c in _thread_clients
                        )
                        on_page_callback(ProgressUpdate(
                            phase="enrich",
                            phase_progress=min(_progress, 1.0),
                            total_progress=min(0.7 + _progress * 0.15, 0.85),  # enrich ≈ 70-85%
                            lots_found=len(lots),
                            lots_processed=1,
                            message=f"enrich {i+1}/{len(lots)}",
                            stats={**stats, "proxy_bytes": live_proxy},
                        ))
                    except Exception:
                        pass

        _flush(force=True)  # write remaining lots
        # Aggregate proxy traffic from all thread-local clients into stats
        thread_proxy_bytes = sum(c.proxy_bytes for c in _thread_clients)
        stats["proxy_bytes"] = stats.get("proxy_bytes", 0) + thread_proxy_bytes

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

        # Photos are auto-upserted into lot_photos by LotRepository.upsert_batch
        # from the lot.photos transit field set in _apply_combined. We only
        # need to log final stats here.
        for lot in all_valid_lots:
            if lot.raw_data.get("_db_skip"):
                continue
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
            pass  # fetching basic_info popup
            _time.sleep(delay)
            try:
                basic_html = client.fetch_basic_info(car_seq)
                combined = {**self._detail_parser.parse_basic_info(basic_html), **combined}
            except Exception as e:
                logger.warning(f"[{self._source}] {lot.id}: basic_info popup failed: {e}")

        # km-analysis popup disabled — endpoint returns 500 server-side

        return combined

    def _fetch_inspection_html(
        self, lot: CarLot, combined: dict, client: KBChaClient, delay: float
    ) -> tuple[str, str, str] | None:
        """Fetch inspection HTML in worker thread. Returns (insp_type, report_url, html) or None."""
        insp_delay = max(delay * 0.5, 0.5)
        insp_type = combined.get("inspection_type")
        if not insp_type or insp_type in ("other", None):
            return None
        if insp_type in _INSP_URL_KEYS:
            url_key = _INSP_URL_KEYS[insp_type]
            report_url = combined.get(url_key)
            if report_url:
                try:
                    _time.sleep(insp_delay)
                    html = client.fetch_external_report(report_url, referer=lot.lot_url or "")
                    if _PHOTO_ONLY_MARKER in html:
                        return None
                    return (insp_type, report_url, html)
                except Exception as e:
                    logger.warning(f"[{self._source}] {lot.id}: inspection fetch failed ({insp_type}): {e}")
                    return None
        if insp_type == "kb_popup":
            try:
                car_seq = lot.id.replace("kbcha_", "")
                _time.sleep(insp_delay)
                html = client.fetch_kb_inspection(car_seq)
                if len(html.strip()) < 1024:
                    return None
                if _PHOTO_ONLY_MARKER in html:
                    return None
                return ("kb_popup", "", html)
            except Exception as e:
                logger.warning(f"[{self._source}] {lot.id}: kb_popup inspection fetch failed: {e}")
                return None
        return None

    def _apply_inspection_result(
        self, lot: CarLot, insp_raw: tuple[str, str, str], stats: dict
    ) -> None:
        """Parse inspection HTML and apply to lot. Runs on main thread (no DB writes here)."""
        insp_type, report_url, html = insp_raw
        insp_stats: dict[str, int] = {"parsed": 0, "url_saved": 0, "photo_only": 0,
                                       "no_button": 0, "other": 0, "errors": 0}
        try:
            if insp_type == "kb_popup":
                self._parse_and_save_inspection(lot, lot.id.replace("kbcha_", ""), html, insp_stats)
            elif insp_type in _INSP_URL_KEYS and report_url:
                parsed = self._external_parser.parse(report_url, html)
                if parsed.get("details", {}).get("parsed_count", 0) > 0:
                    self._upsert_external_inspection(lot, insp_type, report_url, parsed, insp_stats, stats)
                else:
                    self._save_inspection_url(lot, insp_type, insp_stats, stats)
        except Exception as e:
            etype = type(e).__name__
            logger.warning(f"[{self._source}] {lot.id}: inspection apply failed ({insp_type}): {e}")
            self._inc_error(stats, etype, f"inspection apply failed {lot.id}: {e}")

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

        # Photos go to the transit field (auto-upserted into `lot_photos`).
        photos = combined.pop("photos", None)
        if photos:
            lot.photos = list(photos)

        # Convert MSRP from 만원 to retail_value (KRW) before merge
        msrp_man = combined.pop("_original_msrp_man", None)
        if msrp_man and not combined.get("retail_value"):
            combined["retail_value"] = int(msrp_man) * 10_000

        lot.merge_details(combined)
        for key in _RAW_DATA_KEYS:
            if key in combined:
                lot.raw_data[key] = combined[key]
        # Also keep original MSRP in raw_data for debugging
        if msrp_man:
            lot.raw_data["_original_msrp_man"] = msrp_man

    def _log_lot_dump(self, lot: CarLot) -> None:
        pass  # disabled: per-lot dumps removed in logging cleanup

    # ── Inspection enrichment ──────────────────────────────────────────────

    def enrich_inspections(self, lots: list[CarLot], stats: dict, on_page_callback=None) -> None:
        delay = max(Config.REQUEST_DELAY, 1.5)
        insp_stats: dict[str, int] = {
            "parsed": 0, "photo_only": 0, "no_button": 0,
            "url_saved": 0, "other": 0, "errors": 0,
        }
        fill = {"vin": 0, "accident": 0, "flood": 0, "mileage": 0}

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
                    except ProxyBudgetExhausted as e:
                        logger.error(f"[{self._source}] Proxy budget exhausted during inspection enrichment — aborting. {e}")
                        self._inc_error(stats, "ProxyBudgetExhausted",
                                        f"Proxy budget exhausted during inspections at lot {i+1}/{len(lots)}")
                        return
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
                pass  # unknown inspection type
                continue
            else:
                insp_stats["no_button"] += 1
                pass  # no inspection button
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
                    pass  # photo-only inspection
                    continue
                self._parse_and_save_inspection(lot, car_seq, html, insp_stats)
                self._bump_fill(fill, lot)

                _p = _time.monotonic()
                _time.sleep(delay)
                stats["pause_time"] = stats.get("pause_time", 0.0) + (_time.monotonic() - _p)
            except ProxyBudgetExhausted as e:
                logger.error(f"[{self._source}] Proxy budget exhausted during inspection enrichment — aborting. {e}")
                self._inc_error(stats, "ProxyBudgetExhausted",
                                f"Proxy budget exhausted during inspections at lot {i+1}/{len(lots)}")
                return
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
                    _progress = (i + 1) / len(lots)
                    on_page_callback(ProgressUpdate(
                        phase="inspect",
                        phase_progress=min(_progress, 1.0),
                        total_progress=min(0.85 + _progress * 0.1, 0.95),  # inspect ≈ 85-95%
                        lots_found=len(lots),
                        lots_processed=1,
                        message=f"inspect {i+1}/{len(lots)}",
                        stats=stats,
                    ))
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
        logger.info(f"[STAT] [{self._source}]   mileage:  {fill['mileage']}/{len(lots)} ({fill['mileage'] / total * 100:.0f}%)")

        # Post-filters: evaluate rules that depend on inspection data
        enriched_ids = [lot.id for lot in lots]
        if enriched_ids:
            try:
                post_deactivated = self._repo.apply_post_filters(enriched_ids, stats)
                if post_deactivated:
                    logger.info(f"[{self._source}] post-filter deactivated {post_deactivated} lots")
            except Exception as e:
                logger.warning(f"[{self._source}] post-filter error: {e}")

    @staticmethod
    def _bump_fill(fill: dict[str, int], lot: CarLot) -> None:
        if lot.vin:
            fill["vin"] += 1
        if lot.has_accident is not None:
            fill["accident"] += 1
        if lot.flood_history is not None:
            fill["flood"] += 1
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
                pass  # VIN set from report
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
            pass  # fuel set from report
        if parsed.get("report_transmission") and not lot.transmission:
            lot.transmission = parsed["report_transmission"]
            pass  # transmission set from report
        if parsed.get("report_first_registered") and not lot.raw_data.get("first_registration"):
            lot.raw_data["first_registration"] = parsed["report_first_registered"]

        # Sell type from inspection usage_change (rental / business / lease)
        usage_change = (parsed.get("details") or {}).get("usage_change")
        if usage_change and lot.sell_type in (None, _sell.SALE):
            mapped, raw = _sell.normalize_kbcha_usage(
                usage_change, title=lot.raw_data.get("title")
            )
            if mapped:
                lot.sell_type = mapped
                lot.sell_type_raw = raw
                pass  # sell_type mapped

        # ── Damaged panels ──────────────────────────────────────────────
        damaged = parsed.get("damaged_panels") or []
        structural = [p["panel"] for p in damaged if p.get("rank") == "structural"]
        outer = [p["panel"] for p in damaged if p.get("rank") == "outer"]

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
        except Exception as e:
            etype = type(e).__name__
            logger.warning(f"[{self._source}] {lot.id}: upsert_inspection failed: {etype}: {e}")
            self._inc_error(stats, etype, f"inspection record save failed {lot.id}/{insp_type}: {etype}: {e}")

        try:
            self._repo.upsert_batch([lot])
            insp_stats["parsed"] += 1
        except Exception as e:
            insp_stats["errors"] += 1
            etype = type(e).__name__
            logger.warning(f"[{self._source}] {lot.id}: lot vin upsert failed: {etype}: {e}")
            self._inc_error(stats, etype, f"lot update after inspection failed {lot.id}/{insp_type}: {etype}: {e}")

    def _save_inspection_url(self, lot: CarLot, insp_type: str, insp_stats: dict, stats: dict) -> None:
        url_key = _INSP_URL_KEYS[insp_type]
        report_url = lot.raw_data.get(url_key)
        if report_url:
            rec = InspectionRecord(lot_id=lot.id, source=insp_type, report_url=report_url)
            try:
                self._repo.upsert_inspection(rec)
                insp_stats["url_saved"] += 1
                pass  # saved inspection URL
            except Exception as e:
                insp_stats["errors"] += 1
                logger.warning(f"[{self._source}] {lot.id}: upsert_inspection failed: {e}")
                self._inc_error(
                    stats,
                    type(e).__name__,
                    f"inspection url save failed {lot.id}/{insp_type}: {type(e).__name__}: {e}",
                )
        else:
            pass  # no URL in raw_data

    def _parse_and_save_inspection(
        self, lot: CarLot, car_seq: str, html: str, insp_stats: dict
    ) -> None:
        insp = self._inspection_parser.parse(html)

        if insp.get("vin"):
            lot.vin = insp["vin"]
            pass  # VIN assigned
        elif not lot.vin:
            raw_info = lot.raw_data.get("raw_info") or {}
            fallback_vin = raw_info.get("차대번호") or raw_info.get("차시번호")
            if fallback_vin:
                lot.vin = str(fallback_vin).strip()
                pass  # VIN fallback from raw_info
        if "inspection_accident" in insp:
            lot.has_accident = insp["inspection_accident"]
        if "inspection_flood" in insp:
            lot.flood_history = insp["inspection_flood"]
        if insp.get("inspection_mileage"):
            lot.raw_data["inspection_mileage"] = insp.get("inspection_mileage")

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
        try:
            self._repo.upsert_inspection(rec)
        except Exception as e:
            etype = type(e).__name__
            logger.warning(f"[{self._source}] {lot.id}: kb_popup upsert_inspection failed: {etype}: {e}")
            self._inc_error(stats, etype, f"kb_popup inspection save failed {lot.id}: {etype}: {e}")
        self._repo.upsert_batch([lot])
        insp_stats["parsed"] += 1
