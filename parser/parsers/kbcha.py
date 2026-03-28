import logging
import re
import time as _time

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
        self._stats = {"new": 0, "updated": 0, "skipped": 0, "errors": 0}

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

        logger.info("[kbcha] Fetching maker list from API...")
        try:
            t0 = _time.monotonic()
            resp = self.client.get(f"{BASE_URL}/public/search/carMaker.json")
            elapsed = _time.monotonic() - t0
            resp.raise_for_status()
            data = resp.json()

            logger.debug(f"[kbcha] carMaker.json response type: {type(data).__name__}, "
                         f"keys: {list(data.keys())[:10] if isinstance(data, dict) else f'list[{len(data)}]' if isinstance(data, list) else '?'}")

            maker_list = []
            if isinstance(data, list):
                maker_list = data
            elif isinstance(data, dict):
                for key in ("makerList", "data", "list", "makers", "result"):
                    if key in data and isinstance(data[key], list):
                        maker_list = data[key]
                        break
                if not maker_list:
                    for val in data.values():
                        if isinstance(val, list) and val and isinstance(val[0], dict):
                            maker_list = val
                            break

            for group in maker_list:
                if isinstance(group, dict):
                    code = str(group.get("makerCode", group.get("code", group.get("makerCd", ""))))
                    name = group.get("makerName", group.get("name", group.get("makerNm", "")))
                    if code and name:
                        self._makers[code] = name

            if maker_list and not self._makers:
                sample = maker_list[0] if maker_list else {}
                logger.warning(f"[kbcha] Makers parsed 0 from {len(maker_list)} items. Sample keys: {list(sample.keys())[:10]}")

            logger.info(f"[kbcha] Loaded {len(self._makers)} makers in {elapsed:.1f}s")
            logger.debug(f"[kbcha] Makers: {self._makers}")
        except Exception as e:
            logger.warning(f"[kbcha] Failed to fetch makers ({type(e).__name__}: {e}), using defaults")
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
        maker_name = self._makers.get(maker_code, maker_code)
        url = f"{BASE_URL}/public/main/car/recommend/car/model/search/list/v3.json"

        logger.debug(f"[kbcha] POST {url} makerCode={maker_code} page={page}")

        try:
            t0 = _time.monotonic()
            resp = self.client.post(
                url,
                data={
                    "makerCode": maker_code,
                    "page": page,
                    "pageSize": self._page_size,
                    "sort": "ModifiedDate",
                    "order": "desc",
                    "countryOrder": "0",
                },
            )
            elapsed = _time.monotonic() - t0

            logger.debug(f"[kbcha] Response: HTTP {resp.status_code} in {elapsed:.2f}s, {len(resp.content)} bytes")

            resp.raise_for_status()
            data = resp.json()

            logger.debug(f"[kbcha] Response keys: {list(data.keys()) if isinstance(data, dict) else type(data).__name__}")

            results = self._extract_listings(data)
            if results:
                return results

            if isinstance(data, dict):
                logger.info(f"[kbcha] No listings found for {maker_name} page {page}. "
                            f"Top keys: {list(data.keys())[:10]}. "
                            f"Sample values: {{{', '.join(f'{k}: {type(v).__name__}' for k, v in list(data.items())[:5])}}}")
            return []
        except httpx.HTTPStatusError as e:
            status = e.response.status_code
            body_preview = e.response.text[:200] if e.response.text else ""
            if status == 404:
                logger.debug(f"[kbcha] 404 for {maker_name} page {page} — no more results")
                return []
            logger.error(f"[kbcha] HTTP {status} for {maker_name} ({maker_code}) page {page}. Body: {body_preview}")
            self._stats["errors"] += 1
            return []
        except httpx.TimeoutException:
            logger.error(f"[kbcha] Timeout for {maker_name} ({maker_code}) page {page} (>{self.client.timeout}s)")
            self._stats["errors"] += 1
            return []
        except Exception as e:
            logger.error(f"[kbcha] Fetch error {maker_name} ({maker_code}) page {page}: {type(e).__name__}: {e}")
            self._stats["errors"] += 1
            return []

    def _extract_listings(self, data) -> list[dict]:
        """Try multiple paths to find car listings in the response."""
        if isinstance(data, list):
            if data and isinstance(data[0], dict) and any(k in data[0] for k in ("carSeq", "carId", "carNo", "makerName")):
                return data
            return []

        if not isinstance(data, dict):
            return []

        for key in ("searchList", "data", "list", "carList", "resultList", "items", "cars", "result", "records"):
            val = data.get(key)
            if isinstance(val, list) and val:
                if isinstance(val[0], dict) and any(k in val[0] for k in ("carSeq", "carId", "carNo", "makerName", "price", "modelName")):
                    logger.debug(f"[kbcha] Found listings under key '{key}': {len(val)} items")
                    return val

        for key, val in data.items():
            if isinstance(val, dict):
                nested = self._extract_listings(val)
                if nested:
                    logger.debug(f"[kbcha] Found listings nested under '{key}'")
                    return nested

        return []

    def normalize(self, raw: dict) -> dict | None:
        car_id = raw.get("carSeq") or raw.get("carId") or raw.get("id")
        if not car_id:
            logger.debug(f"[kbcha] Skipping lot without ID: keys={list(raw.keys())[:5]}")
            self._stats["skipped"] += 1
            return None

        car_id = str(car_id)
        make = raw.get("makerName", raw.get("maker", ""))
        model = raw.get("modelName", raw.get("model", ""))
        if not make:
            logger.debug(f"[kbcha] Skipping lot {car_id}: no make")
            self._stats["skipped"] += 1
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

        logger.debug(
            f"[kbcha] Normalized: kbcha_{car_id} | {make} {model} {year} | "
            f"${price_usd} ({price_krw:,} KRW) | {mileage:,} km | "
            f"{map_value(fuel_raw, FUEL_MAP) or '?'} | {map_value(trans_raw, TRANSMISSION_MAP) or '?'}"
        )

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
        """Override to iterate makers properly with detailed logging."""
        source = self.get_source_key()
        run_start = _time.monotonic()
        self._stats = {"new": 0, "updated": 0, "skipped": 0, "errors": 0}

        logger.info(f"[{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[{source}] Config: max_pages={self.get_max_pages()}, delay={self.get_request_delay()}s, "
                     f"batch_size={Config.BATCH_SIZE}, proxy={'yes' if Config.KBCHA_PROXY else 'no'}")

        total = 0
        batch: list[dict] = []
        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}

        self.fetch_makers()

        for maker_code in POPULAR_MAKERS:
            maker_name = self._makers.get(maker_code, maker_code)
            maker_start = _time.monotonic()
            maker_count = 0

            logger.info(f"[{source}] --- Maker: {maker_name} ({maker_code}) ---")

            for page in range(1, 6):
                try:
                    raw_listings = self._fetch_maker_page(maker_code, page)
                except Exception as e:
                    logger.error(f"[{source}] {maker_name} page {page} FAILED: {type(e).__name__}: {e}")
                    self._stats["errors"] += 1
                    break

                if not raw_listings:
                    logger.debug(f"[{source}] {maker_name} page {page}: empty — done with this maker")
                    break

                normalized_count = 0
                for raw in raw_listings:
                    try:
                        normalized = self.normalize(raw)
                        if normalized and normalized["id"] not in seen_ids:
                            seen_ids.add(normalized["id"])
                            batch.append(normalized)
                            normalized_count += 1
                            maker_count += 1
                    except Exception as e:
                        logger.warning(f"[{source}] Normalize error for raw lot: {type(e).__name__}: {e}")
                        self._stats["skipped"] += 1

                if len(batch) >= Config.BATCH_SIZE:
                    db_start = _time.monotonic()
                    self.db.upsert_lots(batch)
                    db_elapsed = _time.monotonic() - db_start
                    logger.info(f"[{source}] DB batch write: {len(batch)} lots in {db_elapsed:.2f}s")
                    total += len(batch)
                    batch = []

                logger.info(f"[{source}] {maker_name} p.{page}: {len(raw_listings)} fetched, "
                             f"{normalized_count} normalized, {len(raw_listings) - normalized_count} skipped")
                _time.sleep(self.get_request_delay())

            maker_elapsed = _time.monotonic() - maker_start
            maker_stats[maker_name] = maker_count
            logger.info(f"[{source}] {maker_name}: {maker_count} lots in {maker_elapsed:.1f}s")

        if batch:
            db_start = _time.monotonic()
            self.db.upsert_lots(batch)
            db_elapsed = _time.monotonic() - db_start
            logger.info(f"[{source}] DB final batch: {len(batch)} lots in {db_elapsed:.2f}s")
            total += len(batch)

        stale_start = _time.monotonic()
        stale = self.db.mark_stale(source, seen_ids)
        stale_elapsed = _time.monotonic() - stale_start

        run_elapsed = _time.monotonic() - run_start

        logger.info(f"[{source}] ========== IMPORT COMPLETE ==========")
        logger.info(f"[{source}] Total lots:     {total}")
        logger.info(f"[{source}] Unique IDs:     {len(seen_ids)}")
        logger.info(f"[{source}] Marked stale:   {stale} (in {stale_elapsed:.2f}s)")
        logger.info(f"[{source}] Skipped:        {self._stats['skipped']}")
        logger.info(f"[{source}] Errors:         {self._stats['errors']}")
        logger.info(f"[{source}] Total time:     {run_elapsed:.1f}s ({run_elapsed/60:.1f} min)")
        logger.info(f"[{source}] Per-maker breakdown:")
        for name, count in sorted(maker_stats.items(), key=lambda x: -x[1]):
            logger.info(f"[{source}]   {name}: {count} lots")

        return total
