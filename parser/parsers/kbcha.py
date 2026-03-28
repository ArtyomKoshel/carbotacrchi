import logging
import re

import httpx

from config import Config
from normalizer import (
    BODY_TYPE_MAP,
    DRIVE_MAP,
    FUEL_MAP,
    TRANSMISSION_MAP,
    krw_to_usd,
    map_value,
)
from .base import AbstractParser

logger = logging.getLogger(__name__)

BASE_URL = "https://www.kbchachacha.com"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "application/json, text/plain, */*",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "Origin": BASE_URL,
}

POPULAR_MAKERS = [
    "015",  # Hyundai
    "016",  # Kia
    "017",  # Genesis
    "018",  # Renault Korea (Samsung)
    "019",  # SsangYong/KG Mobility
    "044",  # BMW
    "045",  # Mercedes-Benz
    "046",  # Audi
    "048",  # Volkswagen
    "050",  # Volvo
    "053",  # Toyota
    "054",  # Honda
    "055",  # Nissan
    "058",  # Tesla
    "060",  # Porsche
    "047",  # Land Rover
]


class KBChaParser(AbstractParser):
    def __init__(self, db):
        super().__init__(db)
        proxy = Config.KBCHA_PROXY or None
        transport = httpx.HTTPTransport(proxy=proxy) if proxy else None
        self.client = httpx.Client(
            headers=HEADERS,
            timeout=30.0,
            follow_redirects=True,
            transport=transport,
        )
        self._makers: dict[str, str] = {}
        self._current_maker_code: str = ""
        self._page_size = 20

    def get_source_key(self) -> str:
        return "kbcha"

    def get_source_name(self) -> str:
        return "KBChacha"

    def get_max_pages(self) -> int:
        return Config.KBCHA_MAX_PAGES

    def get_request_delay(self) -> float:
        return max(Config.REQUEST_DELAY, 2.0)

    def fetch_makers(self) -> dict[str, str]:
        """Fetch maker code -> name mapping."""
        if self._makers:
            return self._makers

        try:
            resp = self.client.get(f"{BASE_URL}/public/search/carMaker.json")
            resp.raise_for_status()
            data = resp.json()

            for group in data if isinstance(data, list) else data.get("makerList", data.get("data", [])):
                if isinstance(group, dict):
                    code = str(group.get("makerCode", group.get("code", "")))
                    name = group.get("makerName", group.get("name", ""))
                    if code and name:
                        self._makers[code] = name
        except Exception as e:
            logger.warning(f"[kbcha] Failed to fetch makers: {e}, using defaults")
            self._makers = {c: c for c in POPULAR_MAKERS}

        return self._makers

    def fetch_listings(self, page: int) -> list[dict]:
        """Fetch one page of listings. Iterates through popular makers."""
        maker_idx = (page - 1) // 5
        maker_page = (page - 1) % 5 + 1

        makers = list(POPULAR_MAKERS)
        if maker_idx >= len(makers):
            return []

        maker_code = makers[maker_idx]
        return self._fetch_maker_page(maker_code, maker_page)

    def _fetch_maker_page(self, maker_code: str, page: int) -> list[dict]:
        try:
            resp = self.client.post(
                f"{BASE_URL}/public/main/car/recommend/car/model/search/list/v3.json",
                data={
                    "makerCode": maker_code,
                    "page": page,
                    "pageSize": self._page_size,
                    "sort": "ModifiedDate",
                    "order": "desc",
                    "countryOrder": "0",
                },
            )
            resp.raise_for_status()
            data = resp.json()

            results = data.get("searchList", data.get("data", data.get("list", [])))
            if isinstance(results, list):
                return results
            return []
        except httpx.HTTPStatusError as e:
            if e.response.status_code == 404:
                return []
            logger.error(f"[kbcha] HTTP {e.response.status_code} for maker {maker_code} page {page}")
            return []
        except Exception as e:
            logger.error(f"[kbcha] Fetch error maker={maker_code} page={page}: {e}")
            return []

    def normalize(self, raw: dict) -> dict | None:
        car_id = raw.get("carSeq") or raw.get("carId") or raw.get("id")
        if not car_id:
            return None

        car_id = str(car_id)
        make = raw.get("makerName", raw.get("maker", ""))
        model = raw.get("modelName", raw.get("model", ""))
        if not make:
            return None

        year = self._extract_year(raw)
        price_man = raw.get("price", raw.get("salePrice", 0))
        price_krw = int(float(price_man) * 10000) if price_man else 0
        price_usd = krw_to_usd(float(price_man), Config.USD_KRW_RATE) if price_man else 0

        mileage = raw.get("mileage", raw.get("mile", raw.get("distance", 0)))
        if isinstance(mileage, str):
            mileage = int(re.sub(r"[^\d]", "", mileage) or 0)

        fuel_raw = raw.get("fuelName", raw.get("fuelType", raw.get("fuel", "")))
        trans_raw = raw.get("missionName", raw.get("transmission", ""))
        color = raw.get("colorName", raw.get("color", ""))

        photo = raw.get("photoUrl", raw.get("photo", raw.get("imgUrl", "")))
        if photo and not photo.startswith("http"):
            photo = f"https://ci.kbchachacha.com{photo}" if photo.startswith("/") else f"https://ci.kbchachacha.com/{photo}"

        engine_cc = raw.get("engineVolume", raw.get("displacement", raw.get("engineCC")))
        engine_vol = None
        if engine_cc:
            try:
                cc = float(re.sub(r"[^\d.]", "", str(engine_cc)))
                engine_vol = round(cc / 1000, 1) if cc > 100 else round(cc, 1)
            except (ValueError, TypeError):
                pass

        location = raw.get("regionName", raw.get("location", "Korea"))
        if location and "Korea" not in location:
            location = f"{location}, Korea"

        return {
            "id": f"kbcha_{car_id}",
            "source": "kbcha",
            "make": make.strip(),
            "model": model.strip(),
            "year": year,
            "price": price_usd,
            "price_krw": price_krw,
            "mileage": int(mileage) if mileage else 0,
            "fuel": map_value(fuel_raw, FUEL_MAP),
            "transmission": map_value(trans_raw, TRANSMISSION_MAP),
            "body_type": None,
            "drive_type": None,
            "engine_volume": engine_vol,
            "color": color.strip().capitalize() if color else None,
            "location": location,
            "lot_url": f"{BASE_URL}/public/car/detail.kbc?carSeq={car_id}",
            "image_url": photo or None,
            "vin": None,
            "damage": None,
            "secondary_damage": None,
            "title": "Clean",
            "document": None,
            "trim": raw.get("gradeName", raw.get("trim")),
            "cylinders": None,
            "has_keys": None,
            "retail_value": None,
            "repair_cost": None,
            "raw_data": raw,
        }

    def _extract_year(self, raw: dict) -> int:
        year_val = raw.get("year", raw.get("yearModel", raw.get("regDate", "")))
        if isinstance(year_val, (int, float)) and year_val > 1990:
            if year_val > 190000:
                return int(str(int(year_val))[:4])
            return int(year_val)
        if isinstance(year_val, str):
            m = re.search(r"((?:19|20)\d{2})", year_val)
            if m:
                return int(m.group(1))
        return 0

    def run(self) -> int:
        """Override to iterate makers properly."""
        source = self.get_source_key()
        logger.info(f"[{source}] Starting import...")

        total = 0
        batch: list[dict] = []
        seen_ids: set[str] = set()

        self.fetch_makers()

        import time
        for maker_code in POPULAR_MAKERS:
            maker_name = self._makers.get(maker_code, maker_code)
            logger.info(f"[{source}] Fetching maker: {maker_name} ({maker_code})")

            for page in range(1, 6):
                try:
                    raw_listings = self._fetch_maker_page(maker_code, page)
                except Exception as e:
                    logger.error(f"[{source}] Maker {maker_code} page {page} error: {e}")
                    break

                if not raw_listings:
                    break

                for raw in raw_listings:
                    try:
                        normalized = self.normalize(raw)
                        if normalized and normalized["id"] not in seen_ids:
                            seen_ids.add(normalized["id"])
                            batch.append(normalized)
                    except Exception as e:
                        logger.warning(f"[{source}] Normalize error: {e}")

                if len(batch) >= Config.BATCH_SIZE:
                    self.db.upsert_lots(batch)
                    total += len(batch)
                    batch = []

                logger.info(f"[{source}] {maker_name} page {page}: {len(raw_listings)} listings")
                time.sleep(self.get_request_delay())

        if batch:
            self.db.upsert_lots(batch)
            total += len(batch)

        stale = self.db.mark_stale(source, seen_ids)
        logger.info(f"[{source}] Import complete: {total} lots, {stale} marked stale")
        return total
