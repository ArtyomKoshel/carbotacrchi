from __future__ import annotations

import logging
import time as _time

import httpx

from config import Config
from models import CarLot
from repository import LotRepository
from ..base import AbstractParser
from .client import KBChaClient
from .detail_parser import KBChaDetailParser
from .enricher import KBChaEnricher
from .inspection_parser import CarmodooInspectionParser
from .list_parser import KBChaListParser
from .normalizer import KBChaNormalizer, MAKER_CODES as _MAKER_CODES_FALLBACK

_CLASS_SPLIT_THRESHOLD = 5000  # makers with more lots use per-class pagination

logger = logging.getLogger(__name__)


class KBChaParser(AbstractParser):
    def __init__(self, repo: LotRepository):
        super().__init__(repo)
        self._client = KBChaClient()
        self._normalizer = KBChaNormalizer()
        self._list_parser = KBChaListParser(self._normalizer)
        self._detail_parser = KBChaDetailParser(self._normalizer)
        self._inspection_parser = CarmodooInspectionParser()
        self._enricher = KBChaEnricher(
            client=self._client,
            detail_parser=self._detail_parser,
            inspection_parser=self._inspection_parser,
            repo=repo,
            source=self.get_source_key(),
        )

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

        self._enricher.enrich_details(lots, stats)
        self._enricher.enrich_inspections(lots, stats)

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
        on_page_callback=None,
    ) -> dict:
        source = self.get_source_key()
        run_start = _time.monotonic()
        stats = {
            "total": 0,
            "new": 0,
            "updated": 0,
            "detail_fetched": 0,
            "errors": 0,
            "pause_time": 0.0,
            "enrich_time": 0.0,
            "search_time": 0.0,
            "inspect_time": 0.0,
            "error_types": {},
            "error_log": [],
        }

        effective_pages = max_pages or 9999  # 0 / None = all pages

        # -- dynamic maker discovery --
        makers_raw: dict[str, tuple[str, int]] = {}
        try:
            makers_raw = self._client.fetch_makers()
            logger.info(f"[STAT] [{source}] Live makers from API: {len(makers_raw)}")
        except Exception as e:
            logger.warning(f"[{source}] carMaker.json failed ({e}), using hardcoded fallback")
            makers_raw = {code: (name, 0) for code, name in _MAKER_CODES_FALLBACK.items()}

        makers: dict[str, str] = {}
        maker_counts: dict[str, int] = {}
        for code, (name, count) in makers_raw.items():
            if maker_filter is None or maker_filter.lower() in name.lower():
                makers[code] = name
                maker_counts[code] = count

        if maker_filter and not makers:
            logger.warning(f"[{source}] No makers matched filter '{maker_filter}'.")
            return stats

        site_api_total = sum(maker_counts.values())
        stats["site_api_total"] = site_api_total

        logger.info(f"[STAT] [{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[STAT] [{source}] Makers: {len(makers)}, site total: {site_api_total:,}, "
                    f"max_pages={effective_pages}, delay={Config.REQUEST_DELAY}s")
        if maker_filter:
            logger.info(f"[STAT] [{source}] Maker filter: '{maker_filter}' -> {list(makers.values())}")

        logger.info(f"[{source}] Warming up session...")
        self._client.warmup()

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[STAT] [{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}
        all_lots: list[CarLot] = []

        for maker_code, maker_name in makers.items():
            if stats.get("_cancelled"):
                break
            maker_start = _time.monotonic()
            maker_count = maker_counts.get(maker_code, 0)

            def _enrich_page(page_lots: list) -> None:
                if not page_lots:
                    return
                _t = _time.monotonic()
                self._enricher.enrich_details(page_lots, stats, on_page_callback=on_page_callback)
                stats["enrich_time"] += _time.monotonic() - _t

            maker_lots = self._fetch_maker(
                maker_code,
                maker_name,
                seen_ids,
                existing_ids,
                stats,
                max_pages=effective_pages,
                on_page_callback=on_page_callback,
                maker_count=maker_count,
                enrich_callback=_enrich_page,
            )

            if maker_lots:
                all_lots.extend(maker_lots)

            maker_elapsed = _time.monotonic() - maker_start
            collected = len(maker_lots)
            api_cnt = maker_count  # from carMaker.json
            if api_cnt > 0:
                cov = collected / api_cnt * 100
                cov_str = f" | {cov:.1f}% of {api_cnt:,} on site"
            else:
                cov_str = ""
            maker_stats[maker_name] = (collected, api_cnt)
            logger.info(
                f"[STAT] [{source}] {maker_name}: {collected:,} lots "
                f"({len(new_lots)} new, {len(updated_lots)} upd){cov_str} in {maker_elapsed:.1f}s"
            )

        # Enrich inspections for ALL lots (new + updated)
        if all_lots:
            logger.info(f"[STAT] [{source}] Enriching inspections for {len(all_lots)} lots...")
            _t_inspect = _time.monotonic()
            self._enricher.enrich_inspections(all_lots, stats, on_page_callback=on_page_callback)
            stats["inspect_time"] += _time.monotonic() - _t_inspect

        _MIN_DELIST_COVERAGE = 80.0
        existing_count = len(existing_ids)
        coverage_pct = stats["total"] / existing_count * 100 if existing_count else 100.0
        if coverage_pct >= _MIN_DELIST_COVERAGE:
            stale = self.repo.mark_inactive(source, seen_ids, grace_hours=1)
        else:
            stale = 0
            logger.warning(
                f"[{source}] Skipping delist: coverage {coverage_pct:.1f}% < {_MIN_DELIST_COVERAGE}% "
                f"(seen {stats['total']:,} / existing {existing_count:,}). "
                f"Lots not seen may be due to incomplete run."
            )
        counts = self.repo.count_by_source(source)
        elapsed = _time.monotonic() - run_start
        db_count = counts.get("active", 0)

        api_total = sum(c for (_, c) in maker_stats.values()) if maker_stats else 0
        api_coverage = stats["total"] / api_total * 100 if api_total else 0.0

        logger.info(f"[STAT] [{source}] ========== IMPORT COMPLETE ==========")
        logger.info(f"[STAT] [{source}] Processed:   {stats['total']:,} (DB coverage {coverage_pct:.1f}%"
                    + (f", site coverage {api_coverage:.1f}% of {api_total:,}" if api_total else "") + ")")
        logger.info(f"[STAT] [{source}] In DB now:   {db_count:,}")
        logger.info(f"[STAT] [{source}] New:     {stats['new']}")
        logger.info(f"[STAT] [{source}] Updated: {stats['updated']}")
        logger.info(f"[STAT] [{source}] Stale:   {stale}")
        logger.info(f"[STAT] [{source}] Errors:  {stats['errors']}")
        logger.info(f"[STAT] [{source}] Time:    {elapsed:.1f}s")
        logger.info(f"[STAT] [{source}] Search:  {stats['search_time']:.1f}s")
        logger.info(f"[STAT] [{source}] Enrich:  {stats['enrich_time']:.1f}s")
        logger.info(f"[STAT] [{source}] Inspect: {stats['inspect_time']:.1f}s")
        logger.info(f"[STAT] [{source}] Pauses:  {stats['pause_time']:.1f}s")
        if stats["error_types"]:
            logger.info(f"[STAT] [{source}] Err types: {stats['error_types']}")

        if maker_stats:
            logger.info(f"[STAT] [{source}] Per-maker breakdown:")
            for name, (collected, api_cnt) in sorted(maker_stats.items(), key=lambda x: -x[1][0]):
                if collected > 0:
                    if api_cnt > 0:
                        pct = collected / api_cnt * 100
                        logger.info(f"[STAT] [{source}]   {name}: {collected:,} / {api_cnt:,} ({pct:.1f}%)")
                    else:
                        logger.info(f"[STAT] [{source}]   {name}: {collected:,}")

        self._client.close()
        if stats.get("_cancel_exc") is not None:
            raise stats["_cancel_exc"]
        hours = int(elapsed // 3600)
        mins = int((elapsed % 3600) // 60)
        time_str = f"{hours}h {mins}m" if hours else f"{mins}m"
        avg_per_lot = round(elapsed / stats["total"], 2) if stats["total"] else 0
        return {
            "total": stats["total"],
            "new": stats["new"],
            "updated": stats["updated"],
            "errors": stats["errors"],
            "stale": stale,
            "api_total": api_total or max(existing_count, stats["total"]),
            "api_coverage_pct": round(api_coverage, 1) if api_total else None,
            "coverage_pct": round(coverage_pct, 1),
            "db_count": db_count,
            "time": time_str,
            "elapsed_s": round(elapsed, 1),
            "search_time_s": round(stats["search_time"], 1),
            "enrich_time_s": round(stats["enrich_time"], 1),
            "inspect_time_s": round(stats["inspect_time"], 1),
            "pause_time_s": round(stats["pause_time"], 1),
            "avg_per_lot_s": avg_per_lot,
            "error_types": stats["error_types"],
            "error_log": stats["error_log"][-50:],
            "maker_breakdown": maker_stats,
        }

    def _fetch_maker(
        self,
        maker_code: str,
        maker_name: str,
        seen_ids: set[str],
        existing_ids: set[str],
        stats: dict,
        max_pages: int | None = None,
        on_page_callback=None,
        maker_count: int = 0,
        enrich_callback=None,
    ) -> list[CarLot]:
        source = self.get_source_key()
        lots: list[CarLot] = []
        pages = max_pages or 9999

        # For large makers: fetch per-class to avoid pagination cap
        if maker_count >= _CLASS_SPLIT_THRESHOLD:
            try:
                classes = self._client.fetch_car_classes(maker_code)
                if classes:
                    logger.info(
                        f"[{source}] {maker_name} ({maker_count:,} lots) → "
                        f"per-class strategy: {len(classes)} models"
                    )
                    for class_code, class_name, class_count in classes:
                        class_lots = self._fetch_pages(
                            maker_code, maker_name, seen_ids, existing_ids, stats,
                            pages, on_page_callback,
                            class_code=class_code, class_label=class_name,
                            enrich_callback=enrich_callback,
                        )
                        lots.extend(class_lots)
                    return lots
            except Exception as e:
                logger.warning(
                    f"[{source}] {maker_name} class fetch failed ({e}), falling back to maker pagination"
                )

        logger.info(f"[{source}] --- {maker_name} ({maker_code}) ---")
        return self._fetch_pages(
            maker_code, maker_name, seen_ids, existing_ids, stats, pages, on_page_callback,
            enrich_callback=enrich_callback,
        )

    def _fetch_pages(
        self,
        maker_code: str,
        maker_name: str,
        seen_ids: set[str],
        existing_ids: set[str],
        stats: dict,
        pages: int,
        on_page_callback=None,
        class_code: str | None = None,
        class_label: str | None = None,
        enrich_callback=None,
    ) -> list[CarLot]:
        source = self.get_source_key()
        lots: list[CarLot] = []
        label = f"{maker_name}/{class_label}" if class_label else maker_name

        for page in range(1, pages + 1):
            _t_search = _time.monotonic()
            try:
                html = self._client.fetch_list_page(maker_code, page, class_code=class_code)
            except httpx.HTTPStatusError as e:
                etype = str(e.response.status_code)
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                stats["errors"] += 1
                msg = f"[{maker_name}] p.{page} HTTP {etype}"
                stats["error_log"].append(msg)
                if e.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504):
                    logger.warning(
                        f"[{source}] {label} p.{page}: {etype}, rotating proxy and retrying"
                    )
                    self._client.rotate_proxy()
                    _p = _time.monotonic()
                    _time.sleep(2)
                    stats["pause_time"] += _time.monotonic() - _p
                    try:
                        html = self._client.fetch_list_page(maker_code, page, class_code=class_code)
                    except Exception as e2:
                        etype2 = type(e2).__name__
                        stats["error_types"][etype2] = stats["error_types"].get(etype2, 0) + 1
                        stats["errors"] += 1
                        stats["error_log"].append(f"[{label}] p.{page} retry failed: {etype2}: {e2}")
                        logger.error(
                            f"[{source}] {label} p.{page} fetch error after retry: {etype2}: {e2}"
                        )
                        break
                else:
                    logger.error(f"[{source}] {label} p.{page} HTTP error: {etype}")
                    break
            except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout, httpx.ConnectTimeout) as e:
                etype = type(e).__name__
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                stats["errors"] += 1
                stats["error_log"].append(f"[{label}] p.{page}: {etype}: {e}")
                logger.warning(
                    f"[{source}] {label} p.{page} {etype}, rotating proxy and retrying"
                )
                self._client.rotate_proxy()
                _p = _time.monotonic()
                _time.sleep(3)
                stats["pause_time"] += _time.monotonic() - _p
                try:
                    html = self._client.fetch_list_page(maker_code, page, class_code=class_code)
                except Exception as e2:
                    etype2 = type(e2).__name__
                    stats["error_types"][etype2] = stats["error_types"].get(etype2, 0) + 1
                    stats["errors"] += 1
                    stats["error_log"].append(f"[{label}] p.{page} retry failed: {etype2}: {e2}")
                    logger.error(
                        f"[{source}] {label} p.{page} fetch error after retry: {etype2}: {e2}"
                    )
                    break
            except Exception as e:
                etype = type(e).__name__
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                stats["errors"] += 1
                stats["error_log"].append(f"[{label}] p.{page}: {etype}: {e}")
                logger.error(f"[{source}] {label} p.{page} fetch error: {etype}: {e}")
                break
            finally:
                stats["search_time"] += _time.monotonic() - _t_search

            page_lots = self._list_parser.parse(html, maker_code)
            if not page_lots:
                logger.debug(f"[{source}] {label} p.{page}: empty -> done")
                break

            new_on_page = 0
            new_page_lots: list[CarLot] = []
            for lot in page_lots:
                if lot.id not in seen_ids:
                    seen_ids.add(lot.id)
                    lots.append(lot)
                    new_page_lots.append(lot)
                    new_on_page += 1
                    if lot.id not in existing_ids:
                        stats["new"] += 1

            stats["total"] += new_on_page

            logger.info(f"[{source}] {label} p.{page}: "
                         f"{len(page_lots)} parsed, {new_on_page} unique")

            if enrich_callback and new_page_lots:
                enrich_callback(new_page_lots)

            if on_page_callback:
                try:
                    on_page_callback(
                        page=page, found=new_on_page,
                        total_pages=stats.get("site_api_total") or len(seen_ids),
                        stats=stats,
                    )
                except BaseException as _cancel_exc:
                    logger.info(f"[{source}] {label}: cancel signal — stopping pagination, "
                                f"returning {len(lots)} collected lots for enrichment")
                    stats["_cancelled"] = True
                    stats["_cancel_exc"] = _cancel_exc
                    break

            _p = _time.monotonic()
            _time.sleep(Config.REQUEST_DELAY)
            stats["pause_time"] += _time.monotonic() - _p

        return lots


