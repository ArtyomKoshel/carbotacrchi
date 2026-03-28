from __future__ import annotations

import logging
import time as _time

from config import Config
from models import CarLot
from repository import LotRepository
from ..base import AbstractParser
from .client import KBChaClient
from .detail_parser import KBChaDetailParser
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

    def get_source_key(self) -> str:
        return "kbcha"

    def get_source_name(self) -> str:
        return "KBChacha"

    def run(self) -> int:
        source = self.get_source_key()
        run_start = _time.monotonic()
        stats = {"total": 0, "new": 0, "updated": 0, "detail_fetched": 0, "errors": 0}

        logger.info(f"[{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[{source}] Makers: {len(MAKER_CODES)}, "
                     f"max_pages={Config.KBCHA_MAX_PAGES}, delay={Config.REQUEST_DELAY}s")

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        all_lots: list[CarLot] = []
        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}

        for maker_code, maker_name in MAKER_CODES.items():
            maker_start = _time.monotonic()
            maker_lots = self._fetch_maker(maker_code, maker_name, seen_ids)
            maker_stats[maker_name] = len(maker_lots)
            all_lots.extend(maker_lots)

            if maker_lots:
                elapsed = _time.monotonic() - maker_start
                logger.info(f"[{source}] {maker_name}: {len(maker_lots)} lots in {elapsed:.1f}s")

        new_lots = [lot for lot in all_lots if lot.id not in existing_ids]
        updated_lots = [lot for lot in all_lots if lot.id in existing_ids]
        stats["new"] = len(new_lots)
        stats["updated"] = len(updated_lots)

        logger.info(f"[{source}] Fetched {len(all_lots)} lots total ({len(new_lots)} new, {len(updated_lots)} existing)")

        if new_lots:
            logger.info(f"[{source}] Fetching detail pages for {len(new_lots)} new lots...")
            self._enrich_with_details(new_lots, stats)

        all_enriched = new_lots + updated_lots
        if all_enriched:
            for i in range(0, len(all_enriched), Config.BATCH_SIZE):
                batch = all_enriched[i:i + Config.BATCH_SIZE]
                self.repo.upsert_batch(batch)
                stats["total"] += len(batch)

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

    def _fetch_maker(self, maker_code: str, maker_name: str, seen_ids: set[str]) -> list[CarLot]:
        source = self.get_source_key()
        lots: list[CarLot] = []

        for page in range(1, Config.KBCHA_MAX_PAGES + 1):
            try:
                html = self._client.fetch_list_page(maker_code, page)
            except Exception as e:
                logger.error(f"[{source}] {maker_name} p.{page} fetch error: {type(e).__name__}: {e}")
                break

            page_lots = self._list_parser.parse(html, maker_code)
            if not page_lots:
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

    def _enrich_with_details(self, lots: list[CarLot], stats: dict) -> None:
        source = self.get_source_key()
        delay = max(Config.REQUEST_DELAY, 1.5)

        for i, lot in enumerate(lots):
            car_seq = lot.id.replace("kbcha_", "")
            try:
                html = self._client.fetch_detail_page(car_seq)
                details = self._detail_parser.parse(html)
                lot.merge_details(details)
                stats["detail_fetched"] += 1

                if (i + 1) % 20 == 0:
                    logger.info(f"[{source}] Detail progress: {i + 1}/{len(lots)}")

            except Exception as e:
                logger.warning(f"[{source}] Detail fetch failed for {lot.id}: {type(e).__name__}: {e}")
                stats["errors"] += 1

            _time.sleep(delay)
