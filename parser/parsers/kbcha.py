import json
import logging
import re
import time as _time

import httpx
from bs4 import BeautifulSoup

from config import Config
from normalizer import FUEL_MAP, TRANSMISSION_MAP, krw_to_usd, map_value
from .base import AbstractParser

logger = logging.getLogger(__name__)

BASE_URL = "https://www.kbchachacha.com"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html, */*; q=0.01",
    "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
    "Referer": f"{BASE_URL}/public/search/main.kbc",
    "X-Requested-With": "XMLHttpRequest",
}

MAKER_CODES = {
    "101": "Hyundai",
    "102": "Kia",
    "189": "Genesis",
    "103": "Renault Korea",
    "104": "SsangYong",
    "105": "Chevrolet",
    "107": "BMW",
    "108": "Mercedes-Benz",
    "109": "Audi",
    "112": "Volkswagen",
    "114": "Volvo",
    "116": "Land Rover",
    "117": "Porsche",
    "124": "Toyota",
    "125": "Honda",
    "128": "Nissan",
    "133": "Lexus",
    "143": "Tesla",
    "136": "Lincoln",
    "111": "Jaguar",
    "120": "Maserati",
    "115": "Jeep",
    "130": "Dodge",
    "110": "Ford",
    "113": "Peugeot",
    "126": "Mazda",
    "127": "Mitsubishi",
    "106": "Daewoo",
}


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
        self._stats = {"parsed": 0, "skipped": 0, "errors": 0}

    def get_source_key(self) -> str:
        return "kbcha"

    def get_source_name(self) -> str:
        return "KBChacha"

    def get_max_pages(self) -> int:
        return Config.KBCHA_MAX_PAGES

    def get_request_delay(self) -> float:
        return max(Config.REQUEST_DELAY, 2.0)

    def fetch_listings(self, page: int) -> list[dict]:
        """Not used — run() overrides the flow."""
        return []

    def normalize(self, raw: dict) -> dict | None:
        """Not used — parsing done in _parse_car_element."""
        return raw

    def _fetch_page(self, maker_code: str, page: int) -> str:
        """Fetch one page of HTML from list.empty endpoint."""
        url = f"{BASE_URL}/public/search/list.empty"
        params = {"makerCode": maker_code, "page": str(page)}

        t0 = _time.monotonic()
        resp = self.client.get(url, params=params)
        elapsed = _time.monotonic() - t0

        resp.raise_for_status()
        logger.debug(f"[kbcha] GET list.empty makerCode={maker_code} page={page} -> "
                      f"{resp.status_code} in {elapsed:.2f}s, {len(resp.content)} bytes")
        return resp.text

    def _parse_page(self, html: str, maker_code: str) -> list[dict]:
        """Parse HTML page and extract car listings."""
        soup = BeautifulSoup(html, "lxml")
        cars = []

        for area in soup.select("div.area[data-car-seq]"):
            try:
                car = self._parse_car_element(area, maker_code)
                if car:
                    cars.append(car)
                    self._stats["parsed"] += 1
                else:
                    self._stats["skipped"] += 1
            except Exception as e:
                logger.warning(f"[kbcha] Parse error: {type(e).__name__}: {e}")
                self._stats["errors"] += 1

        return cars

    def _parse_car_element(self, area, maker_code: str) -> dict | None:
        car_seq = area.get("data-car-seq", "").strip()
        if not car_seq or car_seq == "0":
            return None

        ga4_data = {}
        ga4_el = area.select_one("a[data-ga4]")
        if ga4_el:
            try:
                ga4_data = json.loads(ga4_el.get("data-ga4", "{}")).get("params", {})
            except (json.JSONDecodeError, AttributeError):
                pass

        vehicle_info = ga4_data.get("vehicle_info", "")
        title_el = area.select_one("strong.tit")
        title = title_el.get_text(strip=True) if title_el else vehicle_info

        if not title:
            return None

        make_name = MAKER_CODES.get(maker_code, "")
        make, model = self._parse_make_model(title, make_name)

        data_spans = area.select("div.data-line span")
        year_month = data_spans[0].get_text(strip=True) if len(data_spans) > 0 else ""
        mileage_str = data_spans[1].get_text(strip=True) if len(data_spans) > 1 else ""
        location = data_spans[2].get_text(strip=True) if len(data_spans) > 2 else ""

        year = self._parse_year(year_month)
        mileage = int(re.sub(r"[^\d]", "", mileage_str) or 0)

        price_el = area.select_one("span.price")
        price_man = 0
        if price_el:
            price_text = price_el.get_text(strip=True)
            price_match = re.search(r"([\d,]+)", price_text)
            if price_match:
                price_man = int(price_match.group(1).replace(",", ""))

        price_krw = price_man * 10000
        price_usd = krw_to_usd(float(price_man), Config.USD_KRW_RATE)

        img_el = area.select_one("img[src*='kbchachacha.com']")
        image_url = img_el.get("src", "") if img_el else ""
        if not image_url:
            img_el = area.select_one("img[data-src*='kbchachacha.com']")
            image_url = img_el.get("data-src", "") if img_el else ""

        tags = [t.get_text(strip=True) for t in area.select("span.tag")]

        if location and "Korea" not in location:
            location = f"{location}, Korea"

        logger.debug(
            f"[kbcha] Parsed: kbcha_{car_seq} | {make} {model} {year} | "
            f"${price_usd} ({price_man}만원) | {mileage:,} km | {location}"
        )

        return {
            "id": f"kbcha_{car_seq}",
            "source": "kbcha",
            "make": make,
            "model": model,
            "year": year,
            "price": price_usd,
            "price_krw": price_krw,
            "mileage": mileage,
            "fuel": None,
            "transmission": None,
            "body_type": None,
            "drive_type": None,
            "engine_volume": None,
            "color": None,
            "location": location,
            "lot_url": f"{BASE_URL}/public/car/detail.kbc?carSeq={car_seq}",
            "image_url": image_url or None,
            "vin": None,
            "damage": None,
            "secondary_damage": None,
            "title": "Clean",
            "document": None,
            "trim": None,
            "cylinders": None,
            "has_keys": None,
            "retail_value": None,
            "repair_cost": None,
            "raw_data": {
                "carSeq": car_seq,
                "title": title,
                "vehicle_info": vehicle_info,
                "year_month": year_month,
                "mileage_str": mileage_str,
                "price_man": price_man,
                "tags": tags,
                "makerCode": maker_code,
            },
        }

    def _parse_make_model(self, title: str, maker_name: str) -> tuple[str, str]:
        korean_to_english = {
            "현대": "Hyundai", "기아": "Kia", "제네시스": "Genesis",
            "르노코리아": "Renault Korea", "쌍용": "SsangYong", "쉐보레": "Chevrolet",
            "BMW": "BMW", "벤츠": "Mercedes-Benz", "아우디": "Audi",
            "폭스바겐": "Volkswagen", "볼보": "Volvo", "랜드로버": "Land Rover",
            "포르쉐": "Porsche", "도요타": "Toyota", "혼다": "Honda",
            "닛산": "Nissan", "렉서스": "Lexus", "테슬라": "Tesla",
            "링컨": "Lincoln", "재규어": "Jaguar", "마세라티": "Maserati",
            "지프": "Jeep", "닷지": "Dodge", "포드": "Ford",
            "푸조": "Peugeot", "마쓰다": "Mazda", "미쓰비시": "Mitsubishi",
            "대우": "Daewoo",
        }

        parts = title.split()
        make = maker_name
        model_start = 0

        for kr, en in korean_to_english.items():
            if title.startswith(kr):
                make = en
                model_start = len(kr)
                remaining = title[model_start:].strip()
                parts = remaining.split()
                break

        if not parts:
            return make, ""

        model = parts[0] if parts else ""
        if len(parts) > 1 and not re.match(r"^\d", parts[1]):
            model = f"{parts[0]} {parts[1]}"

        return make, model

    def _parse_year(self, text: str) -> int:
        m = re.search(r"(\d{2})년형", text)
        if m:
            y = int(m.group(1))
            return 2000 + y if y < 90 else 1900 + y

        m = re.search(r"(\d{2})/\d{2}식", text)
        if m:
            y = int(m.group(1))
            return 2000 + y if y < 90 else 1900 + y

        return 0

    def run(self) -> int:
        source = self.get_source_key()
        run_start = _time.monotonic()
        self._stats = {"parsed": 0, "skipped": 0, "errors": 0}

        logger.info(f"[{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[{source}] Config: max_pages={self.get_max_pages()}, delay={self.get_request_delay()}s, "
                     f"batch_size={Config.BATCH_SIZE}, proxy={'yes' if Config.KBCHA_PROXY else 'no'}")
        logger.info(f"[{source}] Endpoint: list.empty (HTML parsing)")

        total = 0
        batch: list[dict] = []
        seen_ids: set[str] = set()
        maker_stats: dict[str, int] = {}

        for maker_code, maker_name in MAKER_CODES.items():
            maker_start = _time.monotonic()
            maker_count = 0

            logger.info(f"[{source}] --- Maker: {maker_name} ({maker_code}) ---")

            for page in range(1, self.get_max_pages() + 1):
                try:
                    html = self._fetch_page(maker_code, page)
                except httpx.HTTPStatusError as e:
                    logger.error(f"[{source}] HTTP {e.response.status_code} for {maker_name} page {page}")
                    break
                except Exception as e:
                    logger.error(f"[{source}] Fetch error {maker_name} page {page}: {type(e).__name__}: {e}")
                    self._stats["errors"] += 1
                    break

                cars = self._parse_page(html, maker_code)
                if not cars:
                    logger.debug(f"[{source}] {maker_name} page {page}: no cars found — done")
                    break

                for car in cars:
                    if car["id"] not in seen_ids:
                        seen_ids.add(car["id"])
                        batch.append(car)
                        maker_count += 1

                if len(batch) >= Config.BATCH_SIZE:
                    db_start = _time.monotonic()
                    self.db.upsert_lots(batch)
                    db_elapsed = _time.monotonic() - db_start
                    logger.info(f"[{source}] DB batch: {len(batch)} lots in {db_elapsed:.2f}s")
                    total += len(batch)
                    batch = []

                logger.info(f"[{source}] {maker_name} p.{page}: {len(cars)} cars parsed")
                _time.sleep(self.get_request_delay())

            maker_elapsed = _time.monotonic() - maker_start
            maker_stats[maker_name] = maker_count
            if maker_count > 0:
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
        logger.info(f"[{source}] Parsed:         {self._stats['parsed']}")
        logger.info(f"[{source}] Skipped:        {self._stats['skipped']}")
        logger.info(f"[{source}] Errors:         {self._stats['errors']}")
        logger.info(f"[{source}] Total time:     {run_elapsed:.1f}s ({run_elapsed/60:.1f} min)")
        logger.info(f"[{source}] Per-maker breakdown:")
        for name, count in sorted(maker_stats.items(), key=lambda x: -x[1]):
            if count > 0:
                logger.info(f"[{source}]   {name}: {count} lots")

        return total
