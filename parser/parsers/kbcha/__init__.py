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

    def run(self) -> int:
        source = self.get_source_key()
        run_start = _time.monotonic()
        stats = {"total": 0, "new": 0, "updated": 0, "detail_fetched": 0, "errors": 0}

        logger.info(f"[{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[{source}] Makers: {len(MAKER_CODES)}, "
                     f"max_pages={Config.KBCHA_MAX_PAGES}, delay={Config.REQUEST_DELAY}s")

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}

        for maker_code, maker_name in MAKER_CODES.items():
            maker_start = _time.monotonic()

            maker_lots = self._fetch_maker(maker_code, maker_name, seen_ids)
            if not maker_lots:
                continue

            new_lots = [lot for lot in maker_lots if lot.id not in existing_ids]
            updated_lots = [lot for lot in maker_lots if lot.id in existing_ids]

            if new_lots:
                logger.info(f"[{source}] {maker_name}: enriching {len(new_lots)} new lots with details...")
                self._enrich_with_details(new_lots, stats)
                self._enrich_with_inspection(new_lots, stats)

            all_maker_lots = new_lots + updated_lots
            if all_maker_lots:
                self.repo.upsert_batch(all_maker_lots)
                stats["total"] += len(all_maker_lots)
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

    def _fetch_maker(self, maker_code: str, maker_name: str, seen_ids: set[str]) -> list[CarLot]:
        source = self.get_source_key()
        lots: list[CarLot] = []

        logger.info(f"[{source}] --- {maker_name} ({maker_code}) ---")

        for page in range(1, Config.KBCHA_MAX_PAGES + 1):
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

    def _enrich_with_inspection(self, lots: list[CarLot], stats: dict) -> None:
        source = self.get_source_key()
        delay = max(Config.REQUEST_DELAY, 1.5)
        found = 0

        for lot in lots:
            check_num = lot.raw_data.get("inspection_no")
            carmodoo_url = lot.raw_data.get("carmodoo_url")
            if not (check_num and carmodoo_url):
                continue
            try:
                html = self._client.fetch_carmodoo(check_num)
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
                rec = InspectionRecord(
                    lot_id=lot.id,
                    source="carmodoo",
                    cert_no=insp.get("inspection_cert_no"),
                    valid_from=insp.get("inspection_valid_from"),
                    valid_until=insp.get("inspection_valid_until"),
                    report_url=lot.raw_data.get("carmodoo_url"),
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

                found += 1
                _time.sleep(delay)
            except Exception as e:
                logger.warning(f"[{source}] Inspection fetch failed for {lot.id}: "
                               f"{type(e).__name__}: {e}")

        if found:
            logger.info(f"[{source}] Inspection enrichment: {found}/{len(lots)} lots got real VIN")

    def _enrich_with_details(self, lots: list[CarLot], stats: dict) -> None:
        source = self.get_source_key()
        delay = max(Config.REQUEST_DELAY, 1.5)
        enriched_fields: dict[str, int] = {}

        for i, lot in enumerate(lots):
            car_seq = lot.id.replace("kbcha_", "")
            try:
                html = self._client.fetch_detail_page(car_seq)
                details = self._detail_parser.parse(html)

                for field in details:
                    enriched_fields[field] = enriched_fields.get(field, 0) + 1

                lot.merge_details(details)
                for extra_key in ("autocafe_url", "carmodoo_url", "inspection_no"):
                    if extra_key in details:
                        lot.raw_data[extra_key] = details[extra_key]
                stats["detail_fetched"] += 1

                logger.debug(f"[{source}] Detail {lot.id}: {lot.make} {lot.model} -> "
                              f"fuel={lot.fuel}, trans={lot.transmission}, body={lot.body_type}, "
                              f"engine={lot.engine_volume}L, color={lot.color}")

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
