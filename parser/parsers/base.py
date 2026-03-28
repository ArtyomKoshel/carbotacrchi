import logging
import time
from abc import ABC, abstractmethod

from config import Config
from db import DBWriter

logger = logging.getLogger(__name__)


class AbstractParser(ABC):
    def __init__(self, db: DBWriter):
        self.db = db

    @abstractmethod
    def get_source_key(self) -> str:
        """Unique key: 'kbcha', 'encar', 'copart'"""

    @abstractmethod
    def get_source_name(self) -> str:
        """Display name: 'KBChacha', 'Encar', 'Copart'"""

    @abstractmethod
    def fetch_listings(self, page: int) -> list[dict]:
        """Fetch one page of raw listings from the source. Return empty list when no more pages."""

    @abstractmethod
    def normalize(self, raw: dict) -> dict | None:
        """Convert raw listing to unified format. Return None to skip."""

    def get_max_pages(self) -> int:
        return 50

    def get_request_delay(self) -> float:
        return Config.REQUEST_DELAY

    def run(self) -> int:
        source = self.get_source_key()
        logger.info(f"[{source}] Starting import...")

        total = 0
        batch: list[dict] = []
        seen_ids: set[str] = set()

        for page in range(1, self.get_max_pages() + 1):
            try:
                raw_listings = self.fetch_listings(page)
            except Exception as e:
                logger.error(f"[{source}] Page {page} fetch error: {e}")
                break

            if not raw_listings:
                logger.info(f"[{source}] No more results at page {page}")
                break

            for raw in raw_listings:
                try:
                    normalized = self.normalize(raw)
                    if normalized and normalized["id"] not in seen_ids:
                        seen_ids.add(normalized["id"])
                        batch.append(normalized)
                except Exception as e:
                    logger.warning(f"[{source}] Normalize error: {e}")
                    continue

            if len(batch) >= Config.BATCH_SIZE:
                self.db.upsert_lots(batch)
                total += len(batch)
                batch = []

            logger.info(f"[{source}] Page {page}: {len(raw_listings)} listings fetched")
            time.sleep(self.get_request_delay())

        if batch:
            self.db.upsert_lots(batch)
            total += len(batch)

        stale = self.db.mark_stale(source, seen_ids)
        logger.info(f"[{source}] Import complete: {total} lots upserted, {stale} marked stale")
        return total
