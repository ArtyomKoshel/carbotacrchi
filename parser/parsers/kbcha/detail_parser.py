from __future__ import annotations

import logging
import re

from bs4 import BeautifulSoup, Tag

from .normalizer import KBChaNormalizer

logger = logging.getLogger(__name__)


class KBChaDetailParser:
    """Parses detail.kbc HTML page to extract all available car data."""

    def __init__(self, normalizer: KBChaNormalizer):
        self._norm = normalizer

    def parse(self, html: str) -> dict:
        soup = BeautifulSoup(html, "lxml")
        result: dict = {}

        self._parse_info_table_fields(soup, result)
        self._parse_history_section(soup, result)
        self._parse_mileage_analysis(soup, result)
        self._parse_pricing(soup, result)
        self._parse_options(soup, result)
        self._parse_dealer(soup, result)
        self._parse_trim_from_title(soup, result)

        logger.info(f"[kbcha:detail] Parsed {len(result)} fields: {sorted(result.keys())}")
        if not result:
            logger.warning("[kbcha:detail] No fields parsed from detail page!")

        return result

    # ── Info Table (기본정보) ────────────────────────────────────────────────

    def _parse_info_table_fields(self, soup: BeautifulSoup, result: dict) -> None:
        info = self._extract_info_table(soup)
        if not info:
            logger.warning("[kbcha:detail] Info table is empty")
            return

        logger.debug(f"[kbcha:detail] Info table keys: {list(info.keys())}")

        normalized_fields = {
            "연료":   ("fuel",          self._norm.normalize_fuel),
            "변속기":  ("transmission",  self._norm.normalize_transmission),
            "차종":   ("body_type",     self._norm.normalize_body_type),
            "배기량":  ("engine_volume", self._norm.parse_engine_cc),
            "차량색상": ("color",        self._norm.normalize_color),
        }

        for kr_key, (field_name, fn) in normalized_fields.items():
            raw = info.get(kr_key)
            if raw:
                val = fn(raw)
                if val:
                    result[field_name] = val
                    logger.debug(f"[kbcha:detail] {field_name}: '{raw}' -> '{val}'")
                else:
                    logger.debug(f"[kbcha:detail] {field_name}: '{raw}' -> unmapped")

        if "연식" in info:
            year = self._norm.parse_year(info["연식"])
            if year:
                result["year"] = year
            result["registration_date"] = info["연식"]
            logger.debug(f"[kbcha:detail] year/reg: '{info['연식']}' -> {year}")

        if "주행거리" in info:
            mileage = self._norm.parse_mileage(info["주행거리"])
            if mileage:
                result["mileage"] = mileage

        if "차량정보" in info:
            result["plate_number"] = info["차량정보"]
            logger.debug(f"[kbcha:detail] plate: '{info['차량정보']}'")

        if "시트색상" in info:
            result["seat_color"] = info["시트색상"]

        direct_map = {
            "저당":   "lien_status",
            "압류":   "seizure_status",
        }
        for kr_key, field_name in direct_map.items():
            if kr_key in info:
                result[field_name] = info[kr_key]
                logger.debug(f"[kbcha:detail] {field_name}: '{info[kr_key]}'")

        if "세금미납" in info:
            val = info["세금미납"]
            result["tax_paid"] = (val == "없음")
            logger.debug(f"[kbcha:detail] tax_paid: '{val}' -> {result['tax_paid']}")

    def _extract_info_table(self, soup: BeautifulSoup) -> dict[str, str]:
        info: dict[str, str] = {}
        for table in soup.select("table"):
            for row in table.select("tr"):
                cells = row.select("th, td")
                for i in range(0, len(cells) - 1, 2):
                    key = cells[i].get_text(strip=True)
                    val = cells[i + 1].get_text(strip=True)
                    if key and val and val != "정보없음" and len(val) < 200:
                        info[key] = val
        return info

    # ── History (성능점검·보험사고이력) ──────────────────────────────────────

    def _parse_history_section(self, soup: BeautifulSoup, result: dict) -> None:
        text = soup.get_text()

        accident_match = re.search(r"보험사고정보\s*(사고있음|사고없음|없음)", text)
        if accident_match:
            val = accident_match.group(1)
            result["accident_status"] = val
            logger.debug(f"[kbcha:detail] accident_status: '{val}'")

        for label, field, default_false in [
            ("전손이력", "total_loss_history", True),
            ("침수이력", "flood_history", True),
        ]:
            el = soup.find(string=re.compile(label))
            if el and el.parent:
                sibling = el.parent.find_next_sibling()
                if sibling:
                    val = sibling.get_text(strip=True)
                    result[field] = (val != "없음")
                    logger.debug(f"[kbcha:detail] {field}: '{val}' -> {result[field]}")

        owner_el = soup.find(string=re.compile(r"소유자변경"))
        if owner_el and owner_el.parent:
            sibling = owner_el.parent.find_next_sibling()
            if sibling:
                m = re.search(r"(\d+)", sibling.get_text(strip=True))
                if m:
                    result["owners_count"] = int(m.group(1))
                    logger.debug(f"[kbcha:detail] owners_count: {result['owners_count']}")

        insurance_match = re.search(r"보험이력\s*(\d+)\s*건", text)
        if insurance_match:
            result["insurance_count"] = int(insurance_match.group(1))
            logger.debug(f"[kbcha:detail] insurance_count: {result['insurance_count']}")

    # ── Mileage Analysis (주행거리분석) ─────────────────────────────────────

    def _parse_mileage_analysis(self, soup: BeautifulSoup, result: dict) -> None:
        text = soup.get_text()
        grade_match = re.search(r"주행거리.*?대비\s*\[\s*(짧음|보통|많음|매우많음)\s*\]", text)
        if grade_match:
            result["mileage_grade"] = grade_match.group(1)
            logger.debug(f"[kbcha:detail] mileage_grade: '{result['mileage_grade']}'")

    # ── Pricing (AI 시세, 신차 대비) ────────────────────────────────────────

    def _parse_pricing(self, soup: BeautifulSoup, result: dict) -> None:
        text = soup.get_text()

        ratio_match = re.search(r"신차\s*출고\s*가격\s*대비\s*(\d+)\s*%", text)
        if ratio_match:
            result["new_car_price_ratio"] = int(ratio_match.group(1))
            logger.debug(f"[kbcha:detail] new_car_price_ratio: {result['new_car_price_ratio']}%")

        range_match = re.search(r"적정범위\s*([\d,]+)\s*[~～]\s*([\d,]+)\s*만원", text)
        if range_match:
            result["ai_price_min"] = int(range_match.group(1).replace(",", ""))
            result["ai_price_max"] = int(range_match.group(2).replace(",", ""))
            logger.debug(f"[kbcha:detail] ai_price: {result['ai_price_min']}~{result['ai_price_max']}만원")

    # ── Options (주요옵션) ──────────────────────────────────────────────────

    def _parse_options(self, soup: BeautifulSoup, result: dict) -> None:
        option_keywords = {
            "내비게이션", "선루프", "크루즈", "헤드램프", "열선", "통풍시트",
            "주차감지", "헤드업", "어라운드뷰", "후측방", "차선이탈", "전방충돌",
            "스마트키", "자동주차", "블라인드", "후방카메라", "ECM", "HUD",
            "파노라마", "전동시트", "가죽시트", "스티어링", "ABS", "에어백",
            "블루투스", "USB", "LED", "HID", "제논", "오토라이트",
        }
        skip_words = {"검색", "내차", "혜택", "안내", "KB", "로그인", "회원",
                      "중고차", "신차", "이벤트", "매거진", "서비스", "금융",
                      "전체보기", "팝업", "옵션정보", "판매자", "성능점검",
                      "보험이력", "주행거리", "기본정보", "상세보기", "방문예약",
                      "상담요청", "대출", "리스", "보증", "해당없음", "제휴",
                      "차차차", "추천", "테마", "인증", "홈배송", "카메이트"}

        options_header = soup.find(string=re.compile(r"주요옵션"))
        if not options_header:
            return

        section = options_header.parent
        for _ in range(3):
            section = section.parent if section else None
        if not section:
            return

        options = []
        for li in section.find_all("li", recursive=True):
            text = li.get_text(" ", strip=True)
            if not text or len(text) > 40:
                continue
            clean = re.sub(r"\s*\([^)]*\)", "", text).strip()
            if not clean:
                continue
            if any(s in clean for s in skip_words):
                continue
            if any(k in clean for k in option_keywords) or len(clean) <= 15:
                if clean not in options:
                    options.append(clean)

        if options:
            result["options"] = options
            logger.debug(f"[kbcha:detail] options: {len(options)} items: {options[:8]}...")

    # ── Dealer (판매자정보) ─────────────────────────────────────────────────

    def _parse_dealer(self, soup: BeautifulSoup, result: dict) -> None:
        text = soup.get_text()

        phone_match = re.search(r"(0507-\d{4}-\d{4}|0\d{1,2}-\d{3,4}-\d{4})", text)
        if phone_match:
            result["dealer_phone"] = phone_match.group(1)
            logger.debug(f"[kbcha:detail] dealer_phone: '{result['dealer_phone']}'")

        dealer_el = soup.find(string=re.compile(r"딜러$"))
        if dealer_el and dealer_el.parent:
            name_text = dealer_el.parent.get_text(strip=True)
            name = name_text.replace("딜러", "").strip()
            if name and len(name) < 20:
                result["dealer_name"] = name
                logger.debug(f"[kbcha:detail] dealer_name: '{name}'")

        company_el = soup.find(string=re.compile(r"상사명\s*:"))
        if company_el:
            m = re.search(r"상사명\s*:\s*(.+)", company_el.get_text(strip=True))
            if m:
                result["dealer_company"] = m.group(1).strip()
                logger.debug(f"[kbcha:detail] dealer_company: '{result['dealer_company']}'")

        addr_el = soup.find(string=re.compile(r"주소\s*:"))
        if addr_el:
            m = re.search(r"주소\s*:\s*(.+)", addr_el.get_text(strip=True))
            if m:
                result["dealer_location"] = m.group(1).strip()
                logger.debug(f"[kbcha:detail] dealer_location: '{result['dealer_location']}'")

        desc_el = soup.find(string=re.compile(r"판매자\s*설명"))
        if desc_el:
            node = desc_el.parent
            for _ in range(5):
                if not node:
                    break
                sibling = node.find_next_sibling()
                if sibling:
                    desc = sibling.get_text(strip=True)
                    if desc and 5 < len(desc) < 500 and "KB차차차" not in desc:
                        desc = re.sub(r"[？?]{2,}", " ", desc).strip()
                        desc = re.sub(r"\s+", " ", desc).strip()
                        if desc:
                            result["dealer_description"] = desc
                            logger.debug(f"[kbcha:detail] dealer_description: '{desc[:80]}'")
                        break
                node = node.parent

    # ── Trim from title ────────────────────────────────────────────────────

    def _parse_trim_from_title(self, soup: BeautifulSoup, result: dict) -> None:
        for selector in ["h1", "h2", ".car-title", ".detail-title", "strong"]:
            el = soup.select_one(selector)
            if not el:
                continue
            text = el.get_text(strip=True)
            if any(k in text for k in ("기아", "현대", "제네시스", "BMW", "벤츠", "아우디", "도요타", "테슬라", "쉐보레")):
                parts = text.split()
                if len(parts) >= 3:
                    result["trim"] = " ".join(parts[2:])
                    logger.debug(f"[kbcha:detail] trim: '{result['trim']}'")
                break
