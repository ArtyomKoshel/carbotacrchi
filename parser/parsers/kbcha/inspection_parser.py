from __future__ import annotations

import json
import logging
import re

from bs4 import BeautifulSoup

from .glossary import (
    PANEL_NAMES, DAMAGE_SYMBOLS,
    OUTER_PANEL_CODES, STRUCTURAL_PANEL_CODES,
    BC_SPECIAL_HISTORY_KEY, BC_FLOOD_KEY, BC_FIRE_KEY, BC_TUNING_KEY,
    INSP_LABEL_ACCIDENT, INSP_LABEL_FIRST_REG, INSP_LABEL_FIRST_REG_NUM,
    INSP_LABEL_VALID_PERIOD, INSP_LABEL_VALID_PERIOD_NUM,
    INSP_LABEL_INSPECTOR, INSP_LABEL_INSPECTOR2,
)

logger = logging.getLogger(__name__)

_VIN_RE = re.compile(r"[A-HJ-NPR-Z0-9]{17}", re.ASCII)


class CarmodooInspectionParser:
    """Parses 중고자동차성능·상태점검기록부 from ck.carmodoo.com."""

    def parse(self, html: str) -> dict:
        soup = BeautifulSoup(html, "lxml")
        result: dict = {}

        self._parse_basic_info(soup, result)
        self._parse_condition_summary(soup, result)
        self._parse_damage_panels(soup, result)
        self._parse_component_conditions(soup, result)

        logger.info(f"[kbcha:inspection] Parsed {len(result)} fields: {sorted(result.keys())}")
        return result

    # ── Basic Info (자동차 기본정보) ─────────────────────────────────────────

    def _parse_basic_info(self, soup: BeautifulSoup, result: dict) -> None:
        # ── VIN (차대번호) – <span class="noBborder"> in ⑥ row ────────────
        vin_span = soup.find("span", class_="noBborder")
        if vin_span:
            candidate = vin_span.get_text(strip=True).replace(" ", "")
            if len(candidate) >= 10:
                result["vin"] = candidate
                logger.debug(f"[kbcha:inspection] vin (noBborder): '{candidate}'")

        if "vin" not in result:
            m = _VIN_RE.search(soup.get_text())
            if m:
                result["vin"] = m.group(0)
                logger.debug(f"[kbcha:inspection] vin (regex): '{result['vin']}'")

        # ── Certificate number ─────────────────────────────────────────────
        cert_span = soup.find("span", class_="num")
        if cert_span:
            m = re.search(r"(\d{8,12})", cert_span.get_text())
            if m:
                result["inspection_cert_no"] = m.group(1)

        # ── Table row extraction ───────────────────────────────────────────
        self._extract_table_rows(soup, result)

        # ── Insurance fee ──────────────────────────────────────────────────
        price_span = soup.find("span", class_="repair_price")
        if price_span:
            m = re.search(r"([\d,]+)\s*원", price_span.get_text())
            if m:
                try:
                    result["inspection_fee"] = int(m.group(1).replace(",", ""))
                except ValueError:
                    pass

        # ── Inspector's note (특기사항) ────────────────────────────────────
        for th in soup.find_all("th"):
            if INSP_LABEL_INSPECTOR in th.get_text() and INSP_LABEL_INSPECTOR2 in th.get_text():
                td = th.find_next_sibling("td")
                if td:
                    note = td.get_text(strip=True)
                    if note:
                        result["inspector_note"] = note[:500]
                break

    def _extract_table_rows(self, soup: BeautifulSoup, result: dict) -> None:
        for th in soup.find_all("th"):
            label = th.get_text(strip=True)

            # ── ⑤ 최초등록일 ───────────────────────────────────────────────
            if INSP_LABEL_FIRST_REG in label or label.startswith(INSP_LABEL_FIRST_REG_NUM):
                td = th.find_next_sibling("td")
                if td:
                    val = td.get_text(strip=True)
                    m = re.search(r"(\d{4}[-./]\d{2}[-./]\d{2})", val)
                    if m:
                        result["first_registration"] = m.group(1)
                        logger.debug(f"[kbcha:inspection] first_registration: '{m.group(1)}'")

            # ── ④ 검사유효기간 ─────────────────────────────────────────────
            elif INSP_LABEL_VALID_PERIOD in label or label.startswith(INSP_LABEL_VALID_PERIOD_NUM):
                td = th.find_next_sibling("td")
                if td:
                    spans = td.find_all("span")
                    dates = [re.search(r"(\d{4}-\d{2}-\d{2})", s.get_text()) for s in spans]
                    dates = [d.group(1) for d in dates if d]
                    if len(dates) >= 2:
                        result["inspection_valid_from"] = dates[0]
                        result["inspection_valid_until"] = dates[1]
                        logger.debug(f"[kbcha:inspection] valid: {dates[0]} ~ {dates[1]}")

        # ── Mileage from <strong class="km"> ──────────────────────────────
        km_el = soup.find("strong", class_="km")
        if km_el:
            m = re.search(r"([\d,]+)", km_el.get_text())
            if m:
                try:
                    result["inspection_mileage"] = int(m.group(1).replace(",", ""))
                    logger.debug(f"[kbcha:inspection] mileage: {result['inspection_mileage']}")
                except ValueError:
                    pass

    # ── Condition Summary (자동차 종합상태) ──────────────────────────────────

    def _parse_condition_summary(self, soup: BeautifulSoup, result: dict) -> None:
        for th in soup.find_all("th"):
            label = th.get_text(strip=True)

            # ⑫ 사고이력
            if INSP_LABEL_ACCIDENT in label:
                parent_row = th.find_parent("tr")
                if parent_row:
                    checked_labels = []
                    for inp in parent_row.find_all("input", {"type": "checkbox"}):
                        if inp.get("checked") is not None:
                            nxt = inp.next_sibling
                            if nxt and hasattr(nxt, "strip"):
                                checked_labels.append(nxt.strip())
                    result["inspection_accident"] = any("있음" in l for l in checked_labels)
                    logger.debug(f"[kbcha:inspection] accident: {result['inspection_accident']} "
                                 f"(checked: {checked_labels})")
                break

        bc_data = self._extract_setdata(soup, "bc")
        if bc_data:
            special = bc_data.get(BC_SPECIAL_HISTORY_KEY)
            if special == "2":
                if bc_data.get(BC_FLOOD_KEY) == "1":
                    result["inspection_flood"] = True
                if bc_data.get(BC_FIRE_KEY) == "1":
                    result["inspection_fire"] = True
            elif special == "1":
                result["inspection_flood"] = False

            if bc_data.get(BC_TUNING_KEY) == "1":
                result["inspection_tuning"] = False
            elif bc_data.get(BC_TUNING_KEY) == "2":
                result["inspection_tuning"] = True

    # ── Damage Panels ────────────────────────────────────────────────────────

    def _parse_damage_panels(self, soup: BeautifulSoup, result: dict) -> None:
        script_text = " ".join(s.get_text() for s in soup.find_all("script"))

        damaged_outer: list[str] = []
        damaged_structural: list[str] = []

        # ucAccOutCheck — outer panel damage symbols
        m = re.search(r"ucAccOutCheck\s*=\s*'(\{[^']+\})'", script_text)
        if m:
            try:
                outer = json.loads(m.group(1))
                for panel_id, symbol in outer.items():
                    name = PANEL_NAMES.get(panel_id, f"패널{panel_id}")
                    label = DAMAGE_SYMBOLS.get(symbol.upper(), symbol)
                    damaged_outer.append(f"{name}({label})")
            except (json.JSONDecodeError, KeyError):
                pass

        # ucAccBoneCheck — structural panel damage symbols
        m = re.search(r"ucAccBoneCheck\s*=\s*'(\{[^']+\})'", script_text)
        if m:
            try:
                bone = json.loads(m.group(1))
                for panel_id, symbol in bone.items():
                    if panel_id in ("14A", "14B", "14C"):
                        damaged_structural.append(f"필러패널-{panel_id[-1]}")
                    else:
                        name = PANEL_NAMES.get(panel_id, f"패널{panel_id}")
                        label = DAMAGE_SYMBOLS.get(symbol.upper(), symbol)
                        damaged_structural.append(f"{name}({label})")
            except (json.JSONDecodeError, KeyError):
                pass

        if damaged_outer:
            result["damaged_outer_panels"] = damaged_outer
            logger.debug(f"[kbcha:inspection] outer damage: {damaged_outer}")
        if damaged_structural:
            result["damaged_structural_panels"] = damaged_structural
            logger.debug(f"[kbcha:inspection] structural damage: {damaged_structural}")

    # ── Component Conditions (자동차 세부상태) ────────────────────────────────

    def _parse_component_conditions(self, soup: BeautifulSoup, result: dict) -> None:  # noqa: C901
        dc_data = self._extract_setdata(soup, "dc")
        if not dc_data:
            return

        bad_components: list[str] = []
        # Map dc keys to component names; value "2" = 불량 (bad)
        component_map = {
            "11": "자기진단-원동기", "12": "자기진단-변속기",
            "21": "원동기작동", "24": "커먼레일",
            "311": "자동변속기오일누유", "312": "자동변속기오일유량", "313": "자동변속기작동",
            "41": "클러치", "42": "등속죠인트",
            "51": "동력조향오일누유", "61": "브레이크마스터오일",
            "62": "브레이크오일", "63": "배력장치",
            "71": "발전기", "72": "시동모터",
        }
        for key, name in component_map.items():
            val = dc_data.get(key)
            if val == "2":
                bad_components.append(name)

        if bad_components:
            result["bad_components"] = bad_components
            logger.debug(f"[kbcha:inspection] bad components: {bad_components}")

    # ── Helpers ──────────────────────────────────────────────────────────────

    @staticmethod
    def _extract_setdata(soup: BeautifulSoup, prefix: str) -> dict | None:
        """Extract data from setData('prefix', '{...}') JS call."""
        script_text = " ".join(s.get_text() for s in soup.find_all("script"))
        pattern = re.compile(
            r"setData\(\s*'" + re.escape(prefix) + r"'\s*,\s*'(\{[^']+\})'\s*\)"
        )
        m = pattern.search(script_text)
        if m:
            try:
                return json.loads(m.group(1))
            except json.JSONDecodeError:
                pass
        return None
