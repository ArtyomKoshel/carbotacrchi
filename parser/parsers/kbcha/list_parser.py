from __future__ import annotations

import json
import logging
import re

from bs4 import BeautifulSoup

from models import CarLot
from .normalizer import KBChaNormalizer, MAKER_CODES

logger = logging.getLogger(__name__)

BASE_URL = "https://www.kbchachacha.com"


class KBChaListParser:
    def __init__(self, normalizer: KBChaNormalizer):
        self._norm = normalizer

    def parse(self, html: str, maker_code: str) -> list[CarLot]:
        soup = BeautifulSoup(html, "lxml")
        lots: list[CarLot] = []

        for area in soup.select("div.area[data-car-seq]"):
            try:
                lot = self._parse_one(area, maker_code)
                if lot:
                    lots.append(lot)
            except Exception as e:
                logger.warning(f"[kbcha:list] Parse error: {type(e).__name__}: {e}")

        return lots

    def _parse_one(self, area, maker_code: str) -> CarLot | None:
        car_seq = area.get("data-car-seq", "").strip()
        if not car_seq or car_seq == "0":
            return None

        ga4 = self._extract_ga4(area)
        vehicle_info = ga4.get("vehicle_info", "")

        title_el = area.select_one("strong.tit")
        title = title_el.get_text(strip=True) if title_el else vehicle_info
        if not title:
            return None

        maker_name_kr = MAKER_CODES.get(maker_code, "")
        make = self._norm.normalize_make(title.split()[0] if title else "", maker_code)
        parsed = self._norm.parse_title(title)
        model = parsed["model"]

        if parsed.get("unknown_tokens"):
            if parsed["trim"] is None:
                logger.warning(
                    f"[kbcha:list] unknown title tokens (potential new trim?): "
                    f"{parsed['unknown_tokens']} | title={title!r}"
                )
            else:
                logger.debug(
                    f"[kbcha:list] unclassified tokens: {parsed['unknown_tokens']} "
                    f"| title={title!r}"
                )

        spans = area.select("div.data-line span")
        year_text = spans[0].get_text(strip=True) if len(spans) > 0 else ""
        mileage_text = spans[1].get_text(strip=True) if len(spans) > 1 else ""
        location = spans[2].get_text(strip=True) if len(spans) > 2 else ""

        year = self._norm.parse_year(year_text)
        mileage = self._norm.parse_mileage(mileage_text)

        price_el = area.select_one("span.price")
        price_man = 0
        if price_el:
            price_man = self._norm.parse_price_man(price_el.get_text(strip=True))

        img_el = area.select_one("img[src*='kbchachacha.com']")
        if not img_el:
            img_el = area.select_one("img[data-src*='kbchachacha.com']")
        image_url = (img_el.get("src") or img_el.get("data-src", "")) if img_el else None

        if location and "Korea" not in location:
            location = f"{location}, Korea"

        tags = [t.get_text(strip=True) for t in area.select("span.tag")]

        return CarLot(
            id=f"kbcha_{car_seq}",
            source="kbcha",
            make=make,
            model=model,
            year=year,
            price=price_man * 10000,
            price_krw=price_man * 10000,
            mileage=mileage,
            location=location,
            lot_url=f"{BASE_URL}/public/car/detail.kbc?carSeq={car_seq}",
            image_url=image_url,
            trim=parsed["trim"],
            drive_type=parsed["drive"],
            raw_data={
                "carSeq": car_seq,
                "title": title,
                "vehicle_info": vehicle_info,
                "year_text": year_text,
                "price_man": price_man,
                "tags": tags,
                "makerCode": maker_code,
                "generation": parsed["generation"],
                "engine_str": parsed["engine_str"],
            },
        )

    def _extract_ga4(self, area) -> dict:
        el = area.select_one("a[data-ga4]")
        if not el:
            return {}
        try:
            return json.loads(el.get("data-ga4", "{}")).get("params", {})
        except (json.JSONDecodeError, AttributeError):
            return {}
