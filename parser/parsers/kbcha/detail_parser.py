from __future__ import annotations

import logging
import re

from bs4 import BeautifulSoup

from .normalizer import KBChaNormalizer

logger = logging.getLogger(__name__)


class KBChaDetailParser:
    """Parses detail.kbc HTML page to extract full car specifications."""

    def __init__(self, normalizer: KBChaNormalizer):
        self._norm = normalizer

    def parse(self, html: str) -> dict:
        soup = BeautifulSoup(html, "lxml")
        result: dict = {}

        info = self._parse_info_table(soup)
        logger.debug(f"[kbcha:detail] Info table raw: {info}")

        field_map = {
            "연료": ("fuel", self._norm.normalize_fuel),
            "변속기": ("transmission", self._norm.normalize_transmission),
            "차종": ("body_type", self._norm.normalize_body_type),
            "배기량": ("engine_volume", self._norm.parse_engine_cc),
            "차량색상": ("color", self._norm.normalize_color),
        }

        for kr_key, (field_name, normalizer_fn) in field_map.items():
            raw_value = info.get(kr_key)
            if raw_value:
                normalized = normalizer_fn(raw_value)
                if normalized:
                    result[field_name] = normalized
                    logger.debug(f"[kbcha:detail] {field_name}: '{raw_value}' -> '{normalized}'")
                else:
                    logger.warning(f"[kbcha:detail] {field_name}: '{raw_value}' -> UNMAPPED (normalizer returned None)")
            else:
                logger.debug(f"[kbcha:detail] {field_name}: key '{kr_key}' not found in info table")

        if "연식" in info:
            year = self._norm.parse_year(info["연식"])
            if year:
                result["year"] = year
                logger.debug(f"[kbcha:detail] year: '{info['연식']}' -> {year}")

        if "주행거리" in info:
            mileage = self._norm.parse_mileage(info["주행거리"])
            if mileage:
                result["mileage"] = mileage
                logger.debug(f"[kbcha:detail] mileage: '{info['주행거리']}' -> {mileage}")

        trim = self._parse_trim(soup)
        if trim:
            result["trim"] = trim
            logger.debug(f"[kbcha:detail] trim: '{trim}'")

        owners = self._parse_owners(soup)
        if owners is not None:
            result["owners_count"] = owners
            logger.debug(f"[kbcha:detail] owners: {owners}")

        logger.info(f"[kbcha:detail] Parsed {len(result)} fields: {list(result.keys())}")
        if not result:
            logger.warning(f"[kbcha:detail] No fields parsed! Info table had {len(info)} entries: {list(info.keys())}")

        return result

    def _parse_info_table(self, soup: BeautifulSoup) -> dict[str, str]:
        info: dict[str, str] = {}

        for table in soup.select("table"):
            for row in table.select("tr"):
                cells = row.select("th, td")
                for i in range(0, len(cells) - 1, 2):
                    key = cells[i].get_text(strip=True)
                    val = cells[i + 1].get_text(strip=True)
                    if key and val and val != "정보없음":
                        info[key] = val

        if not info:
            logger.debug("[kbcha:detail] No table data found, trying dl/div fallback")
            for dl in soup.select("dl, div.info-list"):
                dts = dl.select("dt, span.label, th")
                dds = dl.select("dd, span.value, td")
                for dt, dd in zip(dts, dds):
                    key = dt.get_text(strip=True)
                    val = dd.get_text(strip=True)
                    if key and val and val != "정보없음":
                        info[key] = val

        return info

    def _parse_trim(self, soup: BeautifulSoup) -> str | None:
        for selector in ["h1", "h2", ".car-title", ".detail-title", "strong"]:
            el = soup.select_one(selector)
            if el:
                text = el.get_text(strip=True)
                if any(k in text for k in ("기아", "현대", "제네시스", "BMW", "벤츠")):
                    parts = text.split()
                    if len(parts) >= 3:
                        return " ".join(parts[2:])
        return None

    def _parse_owners(self, soup: BeautifulSoup) -> int | None:
        owner_el = soup.find(string=re.compile(r"소유자변경"))
        if owner_el:
            parent = owner_el.parent
            if parent:
                next_el = parent.find_next_sibling()
                if next_el:
                    text = next_el.get_text(strip=True)
                    m = re.search(r"(\d+)", text)
                    if m:
                        return int(m.group(1))
        return None
