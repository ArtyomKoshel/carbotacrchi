"""Encar pagination and query handling."""

from __future__ import annotations

import logging
import time as _time
from typing import Callable, Dict, Set

import httpx

from .constants import MAX_SAFE_OFFSET, PAGE_SIZE, SOURCE
from .retry_handler import RetryHandler

logger = logging.getLogger(__name__)


class PaginationHandler:
    """Handles Encar API pagination and query splitting."""
    
    def __init__(self, client, normalizer):
        self.client = client
        self.normalizer = normalizer
    
    def paginate_query(
        self,
        query: str,
        max_pages: int,
        seen_ids: Set[str],
        existing_ids: Set[str],
        stats: Dict,
        on_page_callback: Callable | None = None,
        label: str = "",
        collect_models: Dict[str, Set[str]] | None = None
    ) -> int:
        """Paginate through query results and process lots."""
        pages_done = 0
        total_count = 0
        stop_reason = "max_pages"
        
        for page in range(max_pages):
            offset = page * PAGE_SIZE
            if offset > MAX_SAFE_OFFSET:
                stop_reason = f"offset limit ({MAX_SAFE_OFFSET})"
                break
            
            try:
                data = RetryHandler.with_retry(
                    self.client.search,
                    query=query,
                    offset=offset,
                    count=PAGE_SIZE,
                    client=self.client
                )
            except Exception as e:
                logger.error(f"[{SOURCE}] Search failed on page {page+1}: {e}")
                stats["errors"] += 1
                stop_reason = f"search_error: {type(e).__name__}"
                break
            
            if not data or not data.get("Results"):
                stop_reason = "no_results"
                break
            
            # Process page results
            page_lots = self._process_search_results(
                data["Results"], seen_ids, existing_ids, collect_models
            )
            
            # Upsert to database
            if page_lots:
                self._upsert_page_lots(page_lots, stats)
            
            # Callback and stats
            if on_page_callback:
                on_page_callback(
                    page=page,
                    found=len(page_lots),
                    total_pages=max_pages,
                    stats=stats
                )
            
            pages_done += 1
            total_count = data.get("Count", 0)
            
            # Stop if we've seen all results
            if offset + PAGE_SIZE >= total_count:
                stop_reason = f"reached end ({total_count} total)"
                break
        
        logger.info(f"[{SOURCE}]{label} SEGMENT DONE: {stop_reason} | pages={pages_done} seen={len(seen_ids)}")
        return total_count
    
    def _process_search_results(
        self,
        results: list,
        seen_ids: Set[str],
        existing_ids: Set[str],
        collect_models: Dict[str, Set[str]] | None = None
    ) -> list:
        """Process search results and convert to CarLot objects."""
        lots = []
        for item in results:
            lot = create_lot_from_search(item, self.normalizer)
            if lot.id not in seen_ids:
                lots.append(lot)
                seen_ids.add(lot.id)
                
                # Collect model data for manufacturer discovery
                if collect_models is not None:
                    make = item.get("Manufacturer", "")
                    model = item.get("Model", "")
                    if make and model:
                        collect_models.setdefault(make, set()).add(model)
        
        return lots
    
    def _upsert_page_lots(self, lots: list, stats: Dict) -> None:
        """Upsert lots to database and update stats."""
        if not lots:
            return
        
        # This would need repository injection
        # For now, just update stats
        stats["total"] += len(lots)
        stats["new"] += len(lots)  # Simplified - actual logic would check existing_ids


def create_lot_from_search(item: dict, norm) -> CarLot:
    """Create CarLot from search API item."""
    from models import CarLot
    from .constants import SOURCE
    
    vid = str(item["Id"])
    make_kr = item.get("Manufacturer", "")
    model = item.get("Model", "")
    badge = item.get("Badge", "")
    badge_detail = item.get("BadgeDetail", "")

    year_raw = item.get("FormYear") or str(item.get("Year") or "")
    year = int(str(year_raw)[:4]) if year_raw and len(str(year_raw)) >= 4 else 0

    price_man = int(item.get("Price") or 0)
    if price_man > 1_000_000_000:  # > 10 trillion KRW
        import logging
        logger = logging.getLogger(__name__)
        logger.warning(f"[encar] lot {item.get('Id')}: absurd price {price_man}won, zeroing")
        price_man = 0
    price_krw = norm.price_krw(price_man)
    mileage = int(item.get("Mileage") or 0)

    # Drive type detection
    drive_tokens = f"{model} {badge}".split()
    drive_type = next(
        (norm.drive(t) for t in drive_tokens if norm.drive(t)),
        None,
    )

    # Photo handling
    photos = item.get("Photos") or []
    photo_path = photos[0]["location"] if photos else ""
    image_url = None  # Will be set by client if needed

    location = item.get("OfficeCityState") or ""

    return CarLot(
        id=vid,
        source=SOURCE,
        make=norm.make(make_kr),
        model=f"{model} {badge}".strip() if badge else model,
        trim=badge_detail or None,
        year=year,
        price=price_krw,
        price_krw=price_krw,
        mileage=mileage,
        fuel=norm.fuel(item.get("FuelType")),
        transmission=norm.transmission(item.get("Transmission")),
        color=item.get("Color") or None,
        seat_color=item.get("SeatColor") or None,
        drive_type=drive_type,
        location=location or None,
        image_url=image_url,
        lot_url=f"https://fem.encar.com/cars/detail/{vid}",
        raw_data={
            "manufacturer_kr": make_kr,
            "model_kr": model,
            "model_group_kr": item.get("ModelGroup"),
            "badge_kr": badge,
            "badge_detail_kr": badge_detail,
            "year_month": item.get("Year"),
            "sell_type": item.get("SellType"),
            "ad_type": item.get("AdType"),
            "photo_path": photo_path or None,
            "condition": item.get("Condition") or [],
        },
    )
    
    def split_by_manufacturer(self, manufacturers: list, max_pages: int) -> Dict[str, int]:
        """Split query by manufacturer and get counts."""
        manufacturer_counts = {}
        
        for maker in manufacturers:
            query = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)"
            try:
                data = RetryHandler.with_retry(
                    self.client.search,
                    query=query,
                    offset=0,
                    count=1,
                    client=self.client
                )
                manufacturer_counts[maker] = data.get("Count", 0)
            except Exception as e:
                logger.warning(f"[{SOURCE}] Failed to get count for {maker}: {e}")
                manufacturer_counts[maker] = 0
        
        return manufacturer_counts
    
    def split_by_year(self, manufacturer: str, max_pages: int) -> Dict[int, int]:
        """Split manufacturer query by year."""
        current_year = _time.localtime().tm_year
        year_counts = {}
        
        for year in range(1990, current_year + 2):
            query = f"(And.Hidden.N._.CarType.A._.Manufacturer.{manufacturer}._.Year.range({year}00..{year}99).)"
            try:
                data = RetryHandler.with_retry(
                    self.client.search,
                    query=query,
                    offset=0,
                    count=1,
                    client=self.client
                )
                year_counts[year] = data.get("Count", 0)
            except Exception as e:
                logger.warning(f"[{SOURCE}] Failed to get count for {manufacturer}/{year}: {e}")
                year_counts[year] = 0
        
        return year_counts
