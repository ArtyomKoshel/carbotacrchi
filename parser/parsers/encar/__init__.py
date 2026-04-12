"""Refactored Encar parser with modular architecture."""

from __future__ import annotations

import logging
import time as _time
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Callable

import httpx

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from ..base import AbstractParser
from .client import EncarClient, _generate_floppy_proxies, check_floppy_balance
from .normalizer import EncarNormalizer
from .constants import (
    SOURCE, PAGE_SIZE, BATCH_SIZE, MAX_SAFE_OFFSET,
    MIN_DELIST_COVERAGE, MAX_PROXY_REGENS
)
from .retry_handler import RetryHandler
from .pagination import PaginationHandler
from .enrichers import DetailEnricher, RecordEnricher, InspectionEnricher

logger = logging.getLogger(__name__)




class EncarParser(AbstractParser):
    """Refactored Encar parser with modular architecture."""
    
    def __init__(self, repo: LotRepository):
        self.repo = repo
        self._client = EncarClient()
        self._norm = EncarNormalizer()
        self._pagination_handler = PaginationHandler(self._client, self._norm)
        
        # Initialize enrichers
        self._detail_enricher = DetailEnricher(self._norm)
        self._record_enricher = RecordEnricher()
        self._inspection_enricher = InspectionEnricher()
    
    def run(
        self,
        max_pages: int | None = None,
        maker_filter: str | None = None,
        on_page_callback: Callable | None = None,
    ) -> int:
        """Run the Encar parser and return statistics."""
        run_start = _time.monotonic()
        stats = {
            "total": 0, "new": 0, "updated": 0, "errors": 0,
            "pause_time": 0.0, "enrich_time": 0.0, "search_time": 0.0,
            "error_types": {}, "error_log": [],
        }

        pages = max_pages or 9999
        logger.info(f"[STAT] [{SOURCE}] ========== IMPORT STARTED ==========")
        logger.info(f"[STAT] [{SOURCE}] Pages: {pages}, page_size: {PAGE_SIZE}")

        check_floppy_balance()

        existing_ids = self.repo.get_existing_ids(SOURCE)
        logger.info(f"[{SOURCE}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()
        api_total: int = 0

        if maker_filter or max_pages:
            query = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker_filter}.)" if maker_filter else "(And.Hidden.N._.CarType.A.)"
            if maker_filter:
                logger.info(f"[{SOURCE}] Maker filter: {maker_filter}")
            api_total = self._pagination_handler.paginate_query(
                query, pages, seen_ids, existing_ids, stats, on_page_callback
            )
        else:
            # Phase 1: global scan to discover manufacturers
            api_total = self._run_phase_1(pages, seen_ids, existing_ids, stats, on_page_callback)
            
            # Phase 2: per-manufacturer pagination
            api_total = self._run_phase_2(seen_ids, existing_ids, stats, on_page_callback)

        elapsed = _time.monotonic() - run_start
        return self._finalize_run(elapsed, api_total, seen_ids, stats)
    
    def _run_phase_1(self, pages: int, seen_ids: set, existing_ids: set, stats: dict, callback) -> int:
        """Phase 1: Global scan to discover manufacturers."""
        base_query = "(And.Hidden.N._.CarType.A.)"
        discovered_models: dict[str, set[str]] = {}
        
        logger.info(f"[{SOURCE}] Phase 1: global scan to discover manufacturers")
        api_total = self._pagination_handler.paginate_query(
            base_query, 100, seen_ids, existing_ids, stats,
            callback, label=" [global]", collect_models=discovered_models,
        )
        
        discovered_makers = sorted(discovered_models.keys())
        logger.info(f"[{SOURCE}] Phase 1 done. Manufacturers found: {discovered_makers}")
        logger.info(f"[{SOURCE}] Models per maker: { {k: len(v) for k, v in discovered_models.items()} }")
        
        # Store for Phase 2
        self._discovered_models = discovered_models
        return api_total
    
    def _run_phase_2(self, seen_ids: set, existing_ids: set, stats: dict, callback) -> int:
        """Phase 2: Per-manufacturer pagination."""
        discovered_makers = sorted(self._discovered_models.keys())
        logger.info(f"[{SOURCE}] Phase 2: per-manufacturer pagination ({len(discovered_makers)} makers)")
        
        consecutive_maker_errors = 0
        proxy_regens = 0
        makers_api_sum = 0
        
        for maker_idx, maker in enumerate(discovered_makers):
            try:
                count_data = RetryHandler.with_retry(
                    self._client.search,
                    query=f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)",
                    offset=0,
                    count=1,
                    client=self._client
                )
                maker_total = count_data.get("Count", 0)
                consecutive_maker_errors = 0
            except Exception as e:
                consecutive_maker_errors += 1
                self._handle_maker_error(maker, e, consecutive_maker_errors, stats)
                
                if consecutive_maker_errors >= 5:
                    if proxy_regens < MAX_PROXY_REGENS:
                        proxy_regens += 1
                        wait = 60 * proxy_regens
                        logger.warning(f"[{SOURCE}] Regenerating proxies (attempt {proxy_regens}/{MAX_PROXY_REGENS}), wait {wait}s")
                        self._regenerate_proxies()
                        consecutive_maker_errors = 0
                        _p = _time.monotonic()
                        _time.sleep(wait)
                        stats["pause_time"] += _time.monotonic() - _p
                        continue
                    logger.error(f"[{SOURCE}] API appears down after {proxy_regens} proxy regenerations")
                    break
                continue
            
            makers_api_sum += maker_total
            logger.info(f"[{SOURCE}] [{maker}]: {maker_total} total (maker {maker_idx+1}/{len(discovered_makers)})")
            
            if maker_total == 0:
                continue
            
            if maker_total <= MAX_SAFE_OFFSET:
                self._pagination_handler.paginate_query(
                    f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)",
                    100, seen_ids, existing_ids, stats, callback, label=f" [{maker}]"
                )
            else:
                self._handle_large_maker(maker, maker_total, seen_ids, existing_ids, stats, callback)
        
        logger.info(f"[{SOURCE}] Phase 2 done. Makers API sum: {makers_api_sum:,}")
        return makers_api_sum
    
    def _handle_large_maker(self, maker: str, maker_total: int, seen_ids: set, existing_ids: set, stats: dict, callback) -> None:
        """Handle manufacturer with too many results (split by year/model)."""
        current_year = _time.localtime().tm_year
        logger.info(f"[{SOURCE}] [{maker}] {maker_total} > {MAX_SAFE_OFFSET}, splitting by year")
        
        for year in range(1990, current_year + 2):
            yq = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99).)"
            try:
                ydata = RetryHandler.with_retry(
                    self._client.search,
                    query=yq,
                    offset=0,
                    count=1,
                    client=self._client
                )
                year_total = ydata.get("Count", 0)
            except Exception as e:
                logger.warning(f"[{SOURCE}] [{maker}/{year}] count query failed: {e}")
                continue
            
            if year_total == 0:
                continue
            
            if year_total <= MAX_SAFE_OFFSET:
                self._pagination_handler.paginate_query(
                    yq, 100, seen_ids, existing_ids, stats, callback, label=f" [{maker}/{year}]"
                )
            else:
                # Split by model for large years
                self._split_by_model(maker, year, seen_ids, existing_ids, stats, callback)
    
    def _split_by_model(self, maker: str, year: int, seen_ids: set, existing_ids: set, stats: dict, callback) -> None:
        """Split year query by model."""
        maker_models = sorted(self._discovered_models.get(maker, []))
        logger.info(f"[{SOURCE}] [{maker}/{year}] splitting by model ({len(maker_models)} models)")
        
        for model in maker_models:
            mq2 = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99)._.Model.{model}.)"
            try:
                mdata = RetryHandler.with_retry(
                    self._client.search,
                    query=mq2,
                    offset=0,
                    count=1,
                    client=self._client
                )
                model_total = mdata.get("Count", 0)
            except Exception as e:
                logger.warning(f"[{SOURCE}] [{maker}/{year}/{model}] count query failed: {e}")
                continue
            
            if model_total > 0:
                self._pagination_handler.paginate_query(
                    mq2, 100, seen_ids, existing_ids, stats, callback, label=f" [{maker}/{year}/{model}]"
                )
    
    def _handle_maker_error(self, maker: str, error: Exception, consecutive: int, stats: dict) -> None:
        """Handle error during manufacturer processing."""
        etype = str(error.response.status_code) if isinstance(error, httpx.HTTPStatusError) else type(error).__name__
        stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
        stats["errors"] += 1
        stats["error_log"].append(f"[{maker}] count: {etype}: {error}")
        logger.warning(f"[{SOURCE}] [{maker}] count query failed ({consecutive} in a row): {error}")
    
    def _regenerate_proxies(self) -> None:
        """Regenerate proxy sessions."""
        from .client import _reset_proxy_cache
        _reset_proxy_cache()
    
    def _finalize_run(self, elapsed: float, api_total: int, seen_ids: set, stats: dict) -> dict:
        """Finalize the run and return statistics."""
        db_count = self.repo.count_active(SOURCE)
        coverage_pct = stats["total"] / api_total * 100 if api_total else 0.0

        if coverage_pct >= MIN_DELIST_COVERAGE:
            stale = self.repo.mark_inactive(SOURCE, seen_ids, grace_hours=1)
        else:
            stale = 0
            logger.warning(
                f"[{SOURCE}] Skipping delist: coverage {coverage_pct:.1f}% < {MIN_DELIST_COVERAGE}%"
            )

        # Log final statistics
        logger.info(f"[STAT] [{SOURCE}] ========== IMPORT COMPLETE ==========")
        logger.info(f"[STAT] [{SOURCE}] API reported: {api_total:,}")
        logger.info(f"[STAT] [{SOURCE}] Processed: {stats['total']:,} ({coverage_pct:.1f}% coverage)")
        logger.info(f"[STAT] [{SOURCE}] In DB now: {db_count:,}")
        logger.info(f"[STAT] [{SOURCE}] New: {stats['new']}")
        logger.info(f"[STAT] [{SOURCE}] Updated: {stats['updated']}")
        logger.info(f"[STAT] [{SOURCE}] Stale: {stale}")
        logger.info(f"[STAT] [{SOURCE}] Errors: {stats['errors']}")
        logger.info(f"[STAT] [{SOURCE}] Time: {elapsed:.1f}s")
        logger.info(f"[STAT] [{SOURCE}] Search: {stats['search_time']:.1f}s")
        logger.info(f"[STAT] [{SOURCE}] Enrich: {stats['enrich_time']:.1f}s")
        logger.info(f"[STAT] [{SOURCE}] Pauses: {stats['pause_time']:.1f}s")

        self._client.close()
        
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
            "api_total": api_total,
            "coverage_pct": round(coverage_pct, 1),
            "db_count": db_count,
            "time": time_str,
            "elapsed_s": round(elapsed, 1),
            "search_time_s": round(stats["search_time"], 1),
            "enrich_time_s": round(stats["enrich_time"], 1),
            "pause_time_s": round(stats["pause_time"], 1),
            "avg_per_lot_s": avg_per_lot,
            "error_types": stats["error_types"],
            "error_log": stats["error_log"][-50:],
        }
