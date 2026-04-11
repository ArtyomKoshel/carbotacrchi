from __future__ import annotations

import logging
import re

from bs4 import BeautifulSoup, Tag

from .glossary import (
    INFO_FIELDS, HISTORY_BOOL_LABELS,
    MILEAGE_GRADE_PATTERN,
    WARRANTY_PATTERN, PAID_OPTIONS_PATTERN,
)
from .normalizer import KBChaNormalizer

logger = logging.getLogger(__name__)


class KBChaDetailParser:
    """Parses detail.kbc HTML page to extract all available car data."""

    _BOT_CHECK_MARKERS = ("로봇여부 확인", "robot check", "captcha")

    def __init__(self, normalizer: KBChaNormalizer):
        self._norm = normalizer

    @staticmethod
    def is_bot_check_page(html: str) -> bool:
        """Return True if the server returned a bot-verification page instead of car data."""
        if len(html) < 8000:
            lower = html.lower()
            return any(m in lower for m in ("로봇여부 확인", "robot check", "captcha"))
        return False

    def parse(self, html: str) -> dict:
        if self.is_bot_check_page(html):
            logger.warning("[kbcha:detail] Bot-check page detected — skipping detail parse")
            return {}
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
        self._parse_inspection_button(soup, result)
        self._parse_photos(soup, result)

        logger.debug(f"[kbcha:detail] Parsed {len(result)} fields from detail page: {sorted(result.keys())}")
        if not result:
            logger.debug("[kbcha:detail] No fields from detail page (options/inspection expected here)")
            return result

        if logger.isEnabledFor(logging.DEBUG):
            insp_type = result.get("inspection_type", "none")
            logger.debug(f"[kbcha:detail] inspection_type={insp_type} "
                         f"| inspection_no={result.get('inspection_no')} "
                         f"| warranty={result.get('warranty_text')} "
                         f"| paid_options={bool(result.get('paid_options'))}")

        return result

    # ── Info Table (기본정보) ────────────────────────────────────────────────

    def _parse_info_table_fields(self, soup: BeautifulSoup, result: dict) -> None:
        info = self._extract_info_table(soup)
        if not info:
            logger.debug("[kbcha:detail] Legacy info table: nothing found (expected for current page structure)")
            return
        logger.debug(f"[kbcha:detail] Legacy info table keys: {list(info.keys())}")
        self._apply_info_fields(info, result)


    # ── Basic Info popup parser ──────────────────────────────────────────────
    # Parses /public/layer/car/detail/basic/info/view.kbc HTML
    # Structure: <dl class="claerFix"><dt>key</dt><dd>val</dd>...</dl>

    def parse_basic_info(self, html: str) -> dict:
        soup = BeautifulSoup(html, "lxml")
        result: dict = {}
        info = self._extract_flat_dl(soup)
        if not info:
            logger.warning("[kbcha:basic_info] No fields extracted from popup")
            return result
        logger.debug(f"[kbcha:basic_info] Raw fields: {list(info.keys())}")
        self._apply_info_fields(info, result)
        return result

    def _extract_flat_dl(self, soup: BeautifulSoup) -> dict[str, str]:
        """Extract key→value from flat <dl><dt>k</dt><dd>v</dd>...</dl> structure."""
        info: dict[str, str] = {}
        for dl in soup.find_all("dl"):
            tags = dl.find_all(["dt", "dd"], recursive=False)
            key = None
            for tag in tags:
                text = tag.get_text(strip=True)
                if tag.name == "dt":
                    key = text
                elif tag.name == "dd" and key:
                    if text and len(text) < 200:
                        info[key] = text
                    key = None
        return info

    def _extract_info_table(self, soup: BeautifulSoup) -> dict[str, str]:
        info: dict[str, str] = {}

        # Primary: table.detail-info-table — 4-column rows (th td th td)
        table = soup.select_one("table.detail-info-table")
        if table:
            for row in table.select("tr"):
                cells = row.select("th, td")
                for i in range(0, len(cells) - 1, 2):
                    key = cells[i].get_text(strip=True)
                    val = cells[i + 1].get_text(strip=True)
                    if key and val and val != "정보없음" and len(val) < 200:
                        info[key] = val
            if info:
                logger.debug(f"[kbcha:detail] detail-info-table: {len(info)} fields")
                return info
        else:
            html_str = str(soup)
            has_car_detail = "car-detail-info" in html_str
            has_car_option = "car-option" in html_str
            logger.warning(
                f"[kbcha:detail] table.detail-info-table NOT FOUND "
                f"| html_size={len(html_str)} "
                f"| car-detail-info={has_car_detail} "
                f"| car-option={has_car_option} "
                f"| head_snippet={html_str[:200]!r}"
            )

        # Fallback: any table with th/td pairs
        for tbl in soup.select("table"):
            for row in tbl.select("tr"):
                cells = row.select("th, td")
                for i in range(0, len(cells) - 1, 2):
                    key = cells[i].get_text(strip=True)
                    val = cells[i + 1].get_text(strip=True)
                    if key and val and val != "정보없음" and len(val) < 200:
                        info[key] = val
        return info

    def _apply_info_fields(self, info: dict[str, str], result: dict) -> None:
        """Apply INFO_FIELDS mapping from raw key→value dict to result."""
        if logger.isEnabledFor(logging.DEBUG) and info:
            raw_dump = " | ".join(f"'{k}'='{v}'" for k, v in info.items())
            logger.debug(f"[kbcha:info_raw] {raw_dump}")
        for kr_key, (field_name, method) in INFO_FIELDS.items():
            raw = info.get(kr_key)
            if raw is None or raw == "정보없음":
                continue

            if method is None:
                if field_name.startswith("_"):
                    continue
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

        result["_raw_info"] = dict(info)

        if "세금미납" in info:
            result["tax_paid"] = (info["세금미납"] == "없음")

        # Parse cylinders from engine_str if present in raw_data
        engine_str = result.get("engine_str")
        if engine_str:
            cylinders = self._norm.parse_cylinders(engine_str)
            if cylinders:
                result["cylinders"] = cylinders
                logger.debug(f"[kbcha:detail] cylinders: '{cylinders}' from engine_str")

    # ── History (성능점검·보험사고이력) ──────────────────────────────────────

    def _parse_history_section(self, soup: BeautifulSoup, result: dict) -> None:
        # Primary: .detail-info02 section (structured HTML)
        info02 = soup.select_one(".detail-info02")
        if info02:
            # Accident info from btnCarHistoryView span text
            for hist_id in ("btnCarHistoryView2", "btnCarHistoryView1"):
                btn = info02.find(id=hist_id)
                if btn:
                    val = btn.get_text(strip=True)
                    if val in ("사고있음", "사고없음", "없음"):
                        result["has_accident"] = (val == "사고있음")
                        logger.debug(f"[kbcha:detail] has_accident: '{val}'")
                    break

            # History dl: 전손이력, 침수이력, 소유자변경
            dl = info02.find("dl")
            if dl:
                tags = dl.find_all(["dt", "dd"], recursive=False)
                key = None
                for tag in tags:
                    text = tag.get_text(strip=True)
                    if tag.name == "dt":
                        key = text
                    elif tag.name == "dd" and key:
                        val = text
                        key_clean = key.strip()
                        if key_clean in HISTORY_BOOL_LABELS:
                            field = HISTORY_BOOL_LABELS[key_clean]
                            result[field] = (val != "없음")
                            logger.debug(f"[kbcha:detail] {field}: '{val}' -> {result[field]}")
                        elif "소유자변경" in key_clean and "owners_count" not in result:
                            m = re.search(r"(\d+)", val)
                            if m:
                                result["owners_count"] = int(m.group(1))
                                logger.debug(f"[kbcha:detail] owners_count: {result['owners_count']}")
                        key = None

        # Fallback: text-based regex
        text = soup.get_text()
        if "has_accident" not in result:
            m = re.search(r"보험사고정보\s*(사고있음|사고없음|없음)", text)
            if m:
                val = m.group(1)
                result["has_accident"] = (val == "사고있음")
                logger.debug(f"[kbcha:detail] has_accident (fallback): '{val}'")

        insurance_match = re.search(r"보험이력\s*(\d+)\s*건", text)
        if insurance_match:
            result["insurance_count"] = int(insurance_match.group(1))
            logger.debug(f"[kbcha:detail] insurance_count: {result['insurance_count']}")

    # ── Mileage Analysis (주행거리분석) ─────────────────────────────────────
    # Primary source: /public/layer/car/km/analysis/info.kbc popup
    # Fallback: main detail page text (legacy)

    _KM_GRADES = frozenset(["많이짧음", "짧음", "보통", "긴", "많이긴"])

    def parse_km_analysis(self, html: str) -> dict:
        soup = BeautifulSoup(html, "lxml")
        result: dict = {}
        compare = soup.find(class_="mileage-compare")
        if compare:
            for strong in compare.find_all("strong"):
                text = strong.get_text(strip=True)
                if text in self._KM_GRADES:
                    result["mileage_grade"] = text
                    logger.debug(f"[kbcha:km] mileage_grade: '{text}'")
                    break
        if "mileage_grade" not in result:
            logger.debug("[kbcha:km] mileage_grade not found in popup")
        return result

    def _parse_mileage_analysis(self, soup: BeautifulSoup, result: dict) -> None:
        if "mileage_grade" in result:
            return
        # Primary: .detail-info03 section — grade is in p.txt-1 inside a span
        txt1 = soup.select_one(".detail-info03 p.txt-1, .detail-info03 .txt-1")
        if txt1:
            for span in txt1.find_all("span"):
                text = span.get_text(strip=True)
                if text in self._KM_GRADES:
                    result["mileage_grade"] = text
                    logger.debug(f"[kbcha:detail] mileage_grade: '{text}'")
                    return
        # Fallback: regex on full page text
        text = soup.get_text()
        m = re.search(MILEAGE_GRADE_PATTERN, text)
        if m:
            result["mileage_grade"] = m.group(1)
            logger.debug(f"[kbcha:detail] mileage_grade (regex): '{result['mileage_grade']}'")

    # ── Pricing (AI 시세, 신차 대비) ────────────────────────────────────────

    def _parse_pricing(self, soup: BeautifulSoup, result: dict) -> None:
        text = soup.get_text()

        ratio_match = re.search(r"신차\s*출고\s*가격\s*대비\s*(\d+)\s*%", text)
        if ratio_match:
            result["new_car_price_ratio"] = int(ratio_match.group(1))
            logger.debug(f"[kbcha:detail] new_car_price_ratio: {result['new_car_price_ratio']}%")

        range_match = re.search(r"적정범위\s*([\d,]+)\s*[~～]\s*([\d,]+)\s*만원", text)
        if range_match:
            result["_ai_price_min"] = int(range_match.group(1).replace(",", ""))
            result["_ai_price_max"] = int(range_match.group(2).replace(",", ""))
            logger.debug(f"[kbcha:detail] ai_price: {result['_ai_price_min']}~{result['_ai_price_max']}만원")

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
                        result["_original_msrp_man"] = price_man
                        logger.debug(f"[kbcha:detail] original_msrp: {price_man}만원")
                except (ValueError, TypeError):
                    pass
                break

    # ── Options (주요옵션) ──────────────────────────────────────────────────

    def _parse_options(self, soup: BeautifulSoup, result: dict) -> None:
        # Primary: div.car-option ul.car-option-list — active items don't have 'disable' class
        option_list = soup.select_one("div.car-option ul.car-option-list")
        if not option_list:
            return

        options = []
        for li in option_list.find_all("li"):
            classes = li.get("class", [])
            # Skip disabled options and the "전체보기" more-button item
            if "disable" in classes or li.get("id") == "btnCarOptionMore":
                continue
            span = li.select_one("span.text")
            if not span:
                continue
            # Use get_text with separator to collapse inline elements,
            # then strip the option-type suffix like (순정), (LED), (어댑티드)
            raw = span.get_text(" ", strip=True)
            name = re.sub(r"\s*\([^)]*\)", "", raw).strip()
            name = re.sub(r"\s+", " ", name).strip()
            if name and name not in options:
                options.append(name)

        if options:
            result["options"] = options
            logger.debug(f"[kbcha:detail] options ({len(options)}): {options}")

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

    # ── Inspection button (성능점검 vs-pill) ─────────────────────────────────
    # Three sub-types depending on data-link-url:
    #   ""              → KB's own popup (info.kbc) — structured or photo-only
    #   autocafe.co.kr  → external autocafe report
    #   moldeoncar.com  → external moldeoncar report (skip parsing)

    def _parse_inspection_button(self, soup: BeautifulSoup, result: dict) -> None:
        btn = (soup.find(id="btnCarCheckView1") or
               soup.find(id="btnCarCheckView2"))
        if not btn:
            logger.debug("[kbcha:detail] No inspection button found")
            return

        link = (btn.get("data-link-url") or "").strip()

        if not link:
            result["inspection_type"] = "kb_popup"
            logger.debug("[kbcha:detail] inspection_type=kb_popup (KB's own popup)")

        elif "autocafe.co.kr" in link.lower():
            url = link if link.startswith("http") else f"http:{link}"
            result["autocafe_url"] = url
            result["inspection_type"] = "autocafe"
            m = re.search(r"OnCarNo=(\d+)", link, re.I)
            if m and "inspection_no" not in result:
                result["inspection_no"] = m.group(1)
            logger.debug(f"[kbcha:detail] inspection_type=autocafe url='{url}'")

        elif "moldeoncar.com" in link.lower():
            result["moldeoncar_url"] = link
            result["inspection_type"] = "moldeoncar"
            logger.debug(f"[kbcha:detail] inspection_type=moldeoncar url='{link}'")

        elif "m-park.co.kr" in link.lower():
            result["mpark_url"] = link
            result["inspection_type"] = "mpark"
            logger.debug(f"[kbcha:detail] inspection_type=mpark url='{link}'")

        elif link == "카모두":
            result["inspection_type"] = "moldeoncar"
            logger.debug("[kbcha:detail] inspection_type=moldeoncar (카모두 marker)")

        elif "checkpaper.iwsp.co.kr" in link.lower():
            result["inspection_url"] = link
            result["inspection_type"] = "kb_paper"
            logger.debug(f"[kbcha:detail] inspection_type=kb_paper url='{link}'")

        elif "encar.com" in link.lower():
            result["inspection_url"] = link
            result["inspection_type"] = "encar"
            logger.debug(f"[kbcha:detail] inspection_type=encar url='{link}'")

        elif "carmon.co.kr" in link.lower():
            result["inspection_url"] = link
            result["inspection_type"] = "carmon"
            logger.debug(f"[kbcha:detail] inspection_type=carmon url='{link}'")

        else:
            result["inspection_url"] = link
            result["inspection_type"] = "other"
            logger.debug(f"[kbcha:detail] inspection_type=other url='{link}'")

    # ── Paid optional packages (선택옵션) ─────────────────────────────────

    def _parse_paid_options(self, soup: BeautifulSoup, result: dict) -> None:
        # Primary: div.select-option-area ul.option-list — span.txt (name) + span.price
        option_area = soup.select_one("div.select-option-area ul.option-list")
        if not option_area:
            return

        paid = []
        for li in option_area.find_all("li"):
            name_span = li.select_one("span.txt")
            price_span = li.select_one("span.price")
            if not name_span:
                continue
            name = name_span.get_text(strip=True)
            price = re.sub(r"\s+", " ", price_span.get_text(strip=True)).strip() if price_span else ""
            entry = f"{name} {price}".strip() if price else name
            if entry and entry not in paid:
                paid.append(entry)

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

    # ── Photo gallery ──────────────────────────────────────────────────────

    def _parse_photos(self, soup: BeautifulSoup, result: dict) -> None:
        """Collect gallery image URLs from the bxslider into result['photos'].

        Structure: ul#btnCarPhotoView > li:not(.bx-clone) > div.slide-img > a.slide-img__link[href]
        The href contains the full-size URL with no ?width= suffix.
        bx-clone items are duplicated slides for the infinite-scroll loop — skip them.
        """
        slider = soup.select_one("ul#btnCarPhotoView")
        if not slider:
            return

        seen: set[str] = set()
        photos: list[str] = []
        for li in slider.find_all("li", recursive=False):
            if "bx-clone" in li.get("class", []):
                continue
            a = li.select_one("a.slide-img__link")
            if not a:
                continue
            url = (a.get("href") or "").strip()
            if url and url not in seen:
                seen.add(url)
                photos.append(url)

        if photos:
            result["photos"] = photos
            logger.debug(f"[kbcha:detail] photos: {len(photos)} images")

    # ── Trim from title ────────────────────────────────────────────────────

    def _parse_trim_from_title(self, soup: BeautifulSoup, result: dict) -> None:
        for selector in ["h1", "h2", ".car-title", ".detail-title", "strong"]:
            el = soup.select_one(selector)
            if not el:
                continue
            text = el.get_text(strip=True)
            if any(k in text for k in ("기아", "현대", "제네시스", "BMW", "벤츠", "아우디", "도요타", "테슬라", "쉐보레")):
                parsed = self._norm.parse_title(text)
                trim = parsed.get("trim")
                if trim:
                    result["trim"] = trim
                    logger.debug(f"[kbcha:detail] trim: '{trim}'")
                break
