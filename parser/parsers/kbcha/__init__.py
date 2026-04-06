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

        logger.info(f"[{source}] Warming up session...")
        self._client.warmup()

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}

        for maker_code, maker_name in makers.items():
            maker_start = _time.monotonic()

            maker_lots = self._fetch_maker(maker_code, maker_name, seen_ids,
                                               max_pages=effective_pages,
                                               on_page_callback=on_page_callback)
            if not maker_lots:
                continue

            new_lots = [lot for lot in maker_lots if lot.id not in existing_ids]
            updated_lots = [lot for lot in maker_lots if lot.id in existing_ids]

            if maker_lots:
                logger.info(f"[{source}] {maker_name}: enriching {len(maker_lots)} lots with details "
                            f"({len(new_lots)} new, {len(updated_lots)} existing)...")
                self._enricher.enrich_details(maker_lots, stats)

            if new_lots:
                self._enricher.enrich_inspections(new_lots, stats)

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
        on_page_callback=None,
    ) -> list[CarLot]:
        source = self.get_source_key()
        lots: list[CarLot] = []
        pages = max_pages or Config.KBCHA_MAX_PAGES

        logger.info(f"[{source}] --- {maker_name} ({maker_code}) ---")

        for page in range(1, pages + 1):
            try:
                html = self._client.fetch_list_page(maker_code, page)
            except (httpx.ReadTimeout, httpx.ConnectTimeout) as e:
                logger.warning(f"[{source}] {maker_name} p.{page} timeout ({type(e).__name__}), retrying in 5s...")
                _time.sleep(5)
                try:
                    html = self._client.fetch_list_page(maker_code, page)
                except Exception as e2:
                    logger.error(f"[{source}] {maker_name} p.{page} fetch error after retry: {type(e2).__name__}: {e2}")
                    break
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

            if on_page_callback:
                try:
                    on_page_callback(page=page, found=new_on_page, total_pages=pages)
                except Exception:
                    pass

            _time.sleep(Config.REQUEST_DELAY)

        return lots

