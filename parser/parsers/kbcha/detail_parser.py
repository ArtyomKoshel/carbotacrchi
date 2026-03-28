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
        """Extract detailed fields from detail page HTML.

        Returns dict with keys: fuel, transmission, body_type, engine_volume,
        color, drive_type, trim, owners_count, accident_history.
        Only non-None values are included.
        """
        soup = BeautifulSoup(html, "lxml")
        result: dict = {}

        info = self._parse_info_table(soup)

        if "연료" in info:
            fuel = self._norm.normalize_fuel(info["연료"])
            if fuel:
                result["fuel"] = fuel

        if "변속기" in info:
            trans = self._norm.normalize_transmission(info["변속기"])
            if trans:
                result["transmission"] = trans

        if "차종" in info:
            body = self._norm.normalize_body_type(info["차종"])
            if body:
                result["body_type"] = body

        if "배기량" in info:
            engine = self._norm.parse_engine_cc(info["배기량"])
            if engine:
                result["engine_volume"] = engine

        if "차량색상" in info:
            color = self._norm.normalize_color(info["차량색상"])
            if color:
                result["color"] = color

        trim = self._parse_trim(soup)
        if trim:
            result["trim"] = trim

        logger.debug(f"[kbcha:detail] Parsed fields: {list(result.keys())}")
        return result

    def _parse_info_table(self, soup: BeautifulSoup) -> dict[str, str]:
        """Parse the 기본정보 table into a flat dict."""
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
        title_el = soup.select_one("h1, h2, .car-title, .detail-title")
        if not title_el:
            return None

        text = title_el.get_text(strip=True)
        parts = text.split()
        if len(parts) >= 3:
            return " ".join(parts[2:])
        return None
