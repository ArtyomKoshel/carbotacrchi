from __future__ import annotations

import logging
import re

from bs4 import BeautifulSoup, Tag

from .glossary import (
    INFO_FIELDS, HISTORY_BOOL_LABELS,
    MILEAGE_GRADE_PATTERN, CYLINDERS_PATTERN,
    WARRANTY_PATTERN, PAID_OPTIONS_PATTERN,
)
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
        self._parse_paid_options(soup, result)
        self._parse_warranty(soup, result)
        self._parse_dealer(soup, result)
        self._parse_trim_from_title(soup, result)
        self._parse_autocafe_url(soup, result)

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

        for kr_key, (field_name, method) in INFO_FIELDS.items():
            raw = info.get(kr_key)
            if raw is None:
                continue

            if method is None:
                if field_name == "vin" and "vin" in result:
                    continue
                result[field_name] = raw
                logger.debug(f"[kbcha:detail] {field_name}: '{raw}'")

            elif method == "_parse_year":
                year = self._norm.parse_year(raw)
                if year:
                    result["year"] = year
                result["registration_date"] = raw
                logger.debug(f"[kbcha:detail] year/reg: '{raw}' -> {year}")

            elif method == "_parse_mileage":
                mileage = self._norm.parse_mileage(raw)
                if mileage:
                    result["mileage"] = mileage

            elif method == "_parse_owners":
                m = re.search(r"(\d+)", raw)
                if m:
                    result["owners_count"] = int(m.group(1))
                    logger.debug(f"[kbcha:detail] owners_count: {result['owners_count']}")

            elif hasattr(self._norm, method):
                val = getattr(self._norm, method)(raw)
                if val is not None:
                    result[field_name] = val
                    logger.debug(f"[kbcha:detail] {field_name}: '{raw}' -> '{val}'")
                else:
                    logger.debug(f"[kbcha:detail] {field_name}: '{raw}' -> unmapped")

        # 세금미납: "없음" means tax is paid
        if "세금미납" in info:
            val = info["세금미납"]
            result["tax_paid"] = (val == "없음")
            logger.debug(f"[kbcha:detail] tax_paid: '{val}' -> {result['tax_paid']}")

        # Cylinder count from engine description (e.g. '2.0L 4기통')
        for key, val in info.items():
            m = re.search(CYLINDERS_PATTERN, val)
            if m:
                result["cylinders"] = int(m.group(1))
                logger.debug(f"[kbcha:detail] cylinders: {result['cylinders']} (from '{key}')") 
                break


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
            result["has_accident"] = (val == "사고있음")
            logger.debug(f"[kbcha:detail] has_accident: '{val}' -> {result['has_accident']}")

        for label, field in HISTORY_BOOL_LABELS.items():
            el = soup.find(string=re.compile(label))
            if el and el.parent:
                sibling = el.parent.find_next_sibling()
                if sibling:
                    val = sibling.get_text(strip=True)
                    result[field] = (val != "없음")
                    logger.debug(f"[kbcha:detail] {field}: '{val}' -> {result[field]}")

        owner_el = soup.find(string=re.compile(r"소유자(?:변경|이력)"))
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
        grade_match = re.search(MILEAGE_GRADE_PATTERN, text)
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

        for script in soup.find_all("script"):
            s = script.get_text()
            if "신차" not in s or "가격" not in s:
                continue
            m = re.search(
                r'category["\s:]+신차["\s,}]+[^}]*?value["\s:]+([\d]+)',
                s, re.DOTALL
            ) or re.search(
                r'"신차"\s*,\s*value\s*:\s*([\d]+)',
                s
            ) or re.search(
                r'sellAmt\s*=\s*"(\d+)".*?newCarSellAmt|newCarSellAmt\s*=\s*"(\d+)"',
                s, re.DOTALL
            )
            if m:
                raw = m.group(1) or m.group(2) if m.lastindex and m.lastindex >= 2 else m.group(1)
                try:
                    price_man = int(raw)
                    if 500 < price_man < 50000:
                        result["retail_value"] = self._norm.krw_to_usd(float(price_man))
                        logger.debug(f"[kbcha:detail] retail_value: {price_man}만원")
                except (ValueError, TypeError):
                    pass
                break

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

    # ── Autocafe (성능점검기록부) URL ────────────────────────────────────────

    def _parse_autocafe_url(self, soup: BeautifulSoup, result: dict) -> None:
        full_text = str(soup)

        # Priority 1: carmodoo.com (has real 17-char VIN in the report)
        m = re.search(r'carmodoo\.com[^"\s]*checkNum=(\d+)', full_text, re.I)
        if m:
            result["carmodoo_url"] = (
                f"https://ck.carmodoo.com/carCheck/carmodooPrint.do"
                f"?print=0&checkNum={m.group(1)}"
            )
            result["inspection_no"] = m.group(1)
            logger.debug(f"[kbcha:detail] carmodoo checkNum: '{m.group(1)}'")

        # Priority 2: autocafe.co.kr (fallback)
        found_autocafe = False
        for tag in soup.find_all(href=re.compile(r"autocafe\.co\.kr", re.I)):
            href = tag.get("href", "")
            m2 = re.search(r"OnCarNo=(\d+)", href, re.I)
            if m2:
                url = href if href.startswith("http") else f"https:{href}"
                result["autocafe_url"] = url
                if "inspection_no" not in result:
                    result["inspection_no"] = m2.group(1)
                logger.debug(f"[kbcha:detail] autocafe OnCarNo: '{m2.group(1)}'")
                found_autocafe = True
                break

        if not found_autocafe:
            m2 = re.search(r'autocafe\.co\.kr[^"\s]*OnCarNo=(\d+)', full_text, re.I)
            if m2:
                result["autocafe_url"] = (
                    f"https://autocafe.co.kr/ASSO/CarCheck_Form.asp?OnCarNo={m2.group(1)}"
                )
                if "inspection_no" not in result:
                    result["inspection_no"] = m2.group(1)
                logger.debug(f"[kbcha:detail] autocafe OnCarNo (text): '{m2.group(1)}'")

        if "carmodoo_url" not in result and "autocafe_url" not in result:
            logger.debug("[kbcha:detail] No inspection URL found in page")

    # ── Paid optional packages (선택옵션) ─────────────────────────────────

    def _parse_paid_options(self, soup: BeautifulSoup, result: dict) -> None:
        header = soup.find(string=re.compile(r"\d+개의\s*선택옵션"))
        if not header:
            return

        section = header.parent
        for _ in range(4):
            if not section:
                break
            section = section.parent

        if not section:
            return

        paid = []
        for li in section.find_all("li", recursive=True):
            text = li.get_text(" ", strip=True)
            if re.search(r"\d+만원", text) and len(text) < 60:
                clean = re.sub(r"\s+", " ", text).strip()
                if clean and clean not in paid:
                    paid.append(clean)

        if paid:
            result["paid_options"] = paid
            logger.debug(f"[kbcha:detail] paid_options: {paid}")

    # ── Manufacturer warranty remaining (제조사 보증) ─────────────────────

    def _parse_warranty(self, soup: BeautifulSoup, result: dict) -> None:
        header = soup.find(string=re.compile(r"제조사\s*보증"))
        if not header:
            return

        section = header.parent
        for _ in range(5):
            if not section:
                break
            sibling = section.find_next_sibling()
            if sibling:
                text = sibling.get_text(" ", strip=True)
                m = re.search(r"([\d,]+\s*km\s*/\s*\d+개월\s*남음|만료)", text)
                if m:
                    result["warranty_text"] = m.group(1).strip()
                    logger.debug(f"[kbcha:detail] warranty_text: '{result['warranty_text']}'")
                    return
            section = section.parent

        text = soup.get_text()
        m = re.search(r"제조사\s*보증[^。\n]{0,80}?([\d,]+\s*km\s*/\s*\d+개월\s*남음|만료)", text)
        if m:
            result["warranty_text"] = m.group(1).strip()
            logger.debug(f"[kbcha:detail] warranty_text (text): '{result['warranty_text']}'")

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
