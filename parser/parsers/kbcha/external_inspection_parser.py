"""
Parses external inspection reports linked from KBCha detail pages.

Both autocafe (autocafe.co.kr) and carmodoo (ck.carmodoo.com) use the same
standard 중고자동차성능·상태점검기록부 HTML template.

Data is populated two ways:
  1. Pre-rendered checked attributes in HTML (transmission, fuel, accident)
  2. JavaScript setData() / ucAcc*Check variables at bottom of page

Panel damage codes: X=교환, W=판금/용접, A=흠집, U=요철, C=부식, T=손상
"""
from __future__ import annotations

import json
import logging
import re
from urllib.parse import parse_qs, urlparse

from bs4 import BeautifulSoup, Tag

logger = logging.getLogger(__name__)

_VIN_RE = re.compile(r"\b[A-HJ-NPR-Z0-9]{17}\b", re.ASCII)
_KM_RE = re.compile(r"현재\s*주행거리\s*\[\s*(?:<[^>]+>)?([\d,]+)\s*(?:</[^>]+>)?\s*Km\s*\]", re.IGNORECASE)
_KM_STRONG_RE = re.compile(r'class=["\']km["\'][^>]*>([\d,]+)\s*Km', re.IGNORECASE)
_CERT_NUM_RE = re.compile(r"제\s*([\d]+)\s*호")
_DATE_NORM_RE = re.compile(r"(20\d{2})[.\-/](\d{2})[.\-/](\d{2})")
_INSP_DATE_RE = re.compile(r"(20\d{2})년\s*(\d{1,2})월\s*(\d{1,2})일")

_FUEL_MAP = {
    "가솔린": "gasoline", "디젤": "diesel", "LPG": "lpg",
    "하이브리드": "hybrid", "전기": "electric", "수소전기": "hydrogen",
}
_TRANS_MAP = {
    "자동": "automatic", "수동": "manual",
    "세미오토": "semi-auto", "무단변속기": "cvt",
}
_DAMAGE_CODES = {
    "X": "교환", "W": "판금/용접", "A": "흠집", "U": "요철", "C": "부식", "T": "손상",
}
_OUTER_PANELS = {
    "1": "후드", "2": "프론트휀더", "3": "도어", "4": "트렁크리드",
    "5": "라디에이터서포트", "6": "쿼터패널", "7": "루프패널", "8": "사이드실패널",
}
_STRUCT_PANELS = {
    "9": "프론트패널", "10": "크로스멤버", "11": "인사이드패널",
    "12": "사이드멤버", "13": "휠하우스", "14": "필러패널",
    "15": "대쉬패널", "16": "플로어패널",
    "17": "트렁크플로어", "18": "리어패널", "19": "패키지트레이",
}


def _norm_date(raw: str) -> str | None:
    m = _DATE_NORM_RE.search(raw)
    if m:
        return f"{m.group(1)}-{m.group(2).zfill(2)}-{m.group(3).zfill(2)}"
    return None


def _extract_js_json(html: str, var_name: str) -> dict:
    """Extract JSON from a JS variable: var NAME = '{"k":"v"}'; or setData('NAME', '...')"""
    patterns = [
        rf"var\s+{re.escape(var_name)}\s*=\s*'(\{{[^']*\}})'",
        rf'var\s+{re.escape(var_name)}\s*=\s*"(\{{[^"]*\}})"',
        rf"setData\s*\(\s*'{re.escape(var_name)}'\s*,\s*'(\{{[^']*\}})'",
        rf'setData\s*\(\s*"{re.escape(var_name)}"\s*,\s*"(\{{[^"]*\}})"',
    ]
    for pat in patterns:
        m = re.search(pat, html)
        if m:
            try:
                return json.loads(m.group(1))
            except (json.JSONDecodeError, ValueError):
                pass
    return {}


class KBChaExternalInspectionParser:
    """Full parser for carmodoo/autocafe 중고자동차 inspection reports.

    Extracts: VIN, year, plate, transmission, fuel, engine, mileage,
    accident, flood/fire, panel damage, inspection date, notes, cert_no,
    first_registered, validity period.
    """

    def parse(self, report_url: str, html: str) -> dict:
        if not html or len(html) < 500:
            return {"provider": self._detect_provider(report_url), "details": {"parsed_count": 0}}

        provider = self._detect_provider(report_url)
        soup = BeautifulSoup(html, "lxml")

        result: dict = {"provider": provider}
        details: dict = {"provider": provider, "url": report_url}

        # ── 1. JavaScript data blobs ───────────────────────────────────────
        bc = _extract_js_json(html, "bc")
        mac = _extract_js_json(html, "mac")
        acc_out = _extract_js_json(html, "ucAccOutCheck")
        acc_bone = _extract_js_json(html, "ucAccBoneCheck")
        dc = _extract_js_json(html, "dc")

        # ── 2. Car basic info table ────────────────────────────────────────
        self._parse_basic_table(soup, result, details)

        # ── 3. Mileage ────────────────────────────────────────────────────
        km = self._parse_mileage(html, soup)
        if km is not None:
            result["inspection_mileage"] = km

        # ── 4. Transmission + Fuel from HTML checked attrs ─────────────────
        trans = self._parse_checked_label(soup, "⑦", _TRANS_MAP)
        if trans:
            result["report_transmission"] = trans
        fuel = self._parse_checked_label(soup, "⑧", _FUEL_MAP)
        if fuel:
            result["report_fuel"] = fuel

        # ── 5. Accident (⑫ 사고이력) from HTML checked attrs ──────────────
        acc = self._parse_accident_from_html(soup)
        if acc is not None:
            result["has_accident"] = acc

        simple_repair = self._parse_simple_repair(soup)
        if simple_repair is not None:
            details["simple_repair"] = simple_repair

        # ── 6. Flood / Fire from bc setData ───────────────────────────────
        if bc:
            flood, fire = self._parse_flood_fire(bc)
            if flood is not None:
                result["has_flood"] = flood
            if fire is not None:
                details["has_fire"] = fire
            # Usage change (렌트/영업용)
            usage = self._parse_usage(bc)
            if usage:
                details["usage_change"] = usage
            # Mileage grade
            if "12" in bc:
                grades = {"1": "많음", "2": "보통", "3": "적음"}
                details["mileage_grade"] = grades.get(str(bc["12"]))

        # ── 7. Panel damage ───────────────────────────────────────────────
        panels = self._build_panel_damage(mac, acc_out, acc_bone)
        if panels:
            result["damaged_panels"] = panels
            details["panel_details"] = panels

        # ── 8. Mechanical checks summary ──────────────────────────────────
        if dc:
            mech = self._parse_mechanical_issues(dc)
            if mech:
                details["mechanical_issues"] = mech

        # ── 9. Inspector notes ────────────────────────────────────────────
        notes = self._parse_notes(soup)
        if notes:
            result["inspection_notes"] = notes
            details["notes"] = notes

        # ── 10. Inspection date + cert ────────────────────────────────────
        insp_date = self._parse_inspection_date(html)
        if insp_date:
            result["inspection_date"] = insp_date

        cert = self._parse_cert_no(report_url, html)
        if cert:
            result["cert_no"] = cert
            details["cert_no"] = cert

        # ── 11. Count parsed fields ───────────────────────────────────────
        core_fields = ("vin", "inspection_mileage", "has_accident", "has_flood",
                       "cert_no", "valid_from", "valid_until", "report_fuel",
                       "report_transmission", "report_year", "damaged_panels")
        parsed = [k for k in core_fields if result.get(k) is not None]
        details["parsed_fields"] = parsed
        details["parsed_count"] = len(parsed)
        result["details"] = details

        logger.debug(
            f"[ext_insp] {provider} parsed {len(parsed)} fields: {parsed} | "
            f"vin={result.get('vin')} mileage={result.get('inspection_mileage')} "
            f"accident={result.get('has_accident')}"
        )
        return result

    # ── Helpers ──────────────────────────────────────────────────────────

    @staticmethod
    def _detect_provider(url: str) -> str:
        host = (urlparse(url).netloc or "").lower()
        if "autocafe.co.kr" in host:
            return "autocafe"
        if "carmodoo.com" in host:
            return "carmodoo"
        if "checkpaper.iwsp.co.kr" in host:
            return "kb_paper"
        if "carmon.co.kr" in host:
            return "carmon"
        if "m-park.co.kr" in host:
            return "mpark"
        return host or "external"

    def _parse_basic_table(self, soup: BeautifulSoup, result: dict, details: dict) -> None:
        """Extract ①~⑩ fields from the first basic-info table."""
        # VIN: <span class="noBborder">
        vin_span = soup.find("span", class_="noBborder")
        if vin_span:
            raw = vin_span.get_text(strip=True)
            m = _VIN_RE.search(raw)
            if m:
                result["vin"] = m.group(0)

        # Cert number from <span class="num"> — but skip short values (office numbers like "82")
        num_span = soup.find("span", class_="num")
        if num_span:
            m = _CERT_NUM_RE.search(num_span.get_text(strip=True))
            if m and len(m.group(1)) >= 5:
                result["cert_no"] = m.group(1)

        # Walk table rows for labeled fields
        for row in soup.select("tr"):
            ths = row.find_all("th")
            tds = row.find_all("td")
            if not ths or not tds:
                continue
            for th in ths:
                label = th.get_text(strip=True)
                # Find the next <td> sibling
                td = th.find_next_sibling("td")
                if td is None:
                    continue
                val = td.get_text(" ", strip=True)

                if "① 차명" in label or "차명" == label.strip():
                    # Extract Korean model name before "("
                    name = val.split("(")[0].strip()
                    if name:
                        details["report_model_name"] = name

                elif "② 자동차등록번호" in label or "자동차등록번호" == label.strip():
                    plate = val.strip()
                    if plate and len(plate) < 15:
                        result["report_plate"] = plate

                elif "③ 연식" in label:
                    m = re.search(r"(20\d{2}|19\d{2})", val)
                    if m:
                        result["report_year"] = int(m.group(1))

                elif "④ 검사유효기간" in label:
                    dates = _DATE_NORM_RE.findall(val)
                    if len(dates) >= 2:
                        result["valid_from"] = f"{dates[0][0]}-{dates[0][1]}-{dates[0][2]}"
                        result["valid_until"] = f"{dates[1][0]}-{dates[1][1]}-{dates[1][2]}"

                elif "⑤ 최초등록일" in label:
                    nd = _norm_date(val)
                    if nd:
                        result["report_first_registered"] = nd

                elif "⑨ 원동기형식" in label:
                    code = val.strip()
                    if code and len(code) < 12:
                        result["report_engine_code"] = code

    @staticmethod
    def _parse_mileage(html: str, soup: BeautifulSoup) -> int | None:
        # Try <strong class="km"> tag
        km_tag = soup.find("strong", class_="km")
        if km_tag:
            try:
                return int(km_tag.get_text(strip=True).replace(",", "").replace("Km", "").replace("km", "").strip())
            except ValueError:
                pass
        # Regex on raw HTML
        m = _KM_RE.search(html)
        if m:
            try:
                return int(m.group(1).replace(",", ""))
            except ValueError:
                pass
        return None

    @staticmethod
    def _parse_checked_label(soup: BeautifulSoup, section_marker: str, mapping: dict) -> str | None:
        """Find a table row containing section_marker in <th>, then return
        the label text of the first <input checked> inside the row's <td>."""
        for row in soup.select("tr"):
            th_text = " ".join(th.get_text(strip=True) for th in row.find_all("th"))
            if section_marker not in th_text:
                continue
            for inp in row.find_all("input", checked=True):
                label = inp.parent
                if isinstance(label, Tag) and label.name == "label":
                    text = label.get_text(strip=True)
                else:
                    text = (inp.next_sibling or "")
                    if hasattr(text, "get_text"):
                        text = text.get_text(strip=True)
                    else:
                        text = str(text).strip()
                for kr, en in mapping.items():
                    if kr in text:
                        return en
        return None

    @staticmethod
    def _parse_accident_from_html(soup: BeautifulSoup) -> bool | None:
        """Parse ⑫ 사고이력 checkbox state from HTML pre-rendered checked attrs."""
        for row in soup.select("tr"):
            th_text = " ".join(th.get_text(strip=True) for th in row.find_all("th"))
            if "사고이력" not in th_text:
                continue
            # Find first td with checked input
            for td in row.find_all("td"):
                checked_inputs = td.find_all("input", checked=True)
                if not checked_inputs:
                    continue
                for inp in checked_inputs:
                    # Get label text
                    parent = inp.parent
                    if isinstance(parent, Tag) and parent.name == "label":
                        text = parent.get_text(strip=True)
                    else:
                        sib = inp.next_sibling
                        text = str(sib).strip() if sib else ""
                    if "없음" in text:
                        return False
                    if "있음" in text:
                        return True
        return None

    @staticmethod
    def _parse_simple_repair(soup: BeautifulSoup) -> bool | None:
        """Parse 단순수리 checkbox state."""
        for row in soup.select("tr"):
            all_text = row.get_text(strip=True)
            if "단순수리" not in all_text:
                continue
            for inp in row.find_all("input", checked=True):
                parent = inp.parent
                text = parent.get_text(strip=True) if isinstance(parent, Tag) else str(inp.next_sibling or "")
                if "없음" in text:
                    return False
                if "있음" in text:
                    return True
        return None

    @staticmethod
    def _parse_flood_fire(bc: dict) -> tuple[bool | None, bool | None]:
        """Parse flood (침수) and fire (화재) from bc setData JSON.
        bc["4"] = "1" → no special history; "2" → has special history
        bc["41"] = "1" → flood; "2" → fire
        """
        special = str(bc.get("4", ""))
        if special == "1":
            return False, False
        if special == "2":
            sub = str(bc.get("41", ""))
            return (sub == "1"), (sub == "2")
        return None, None

    @staticmethod
    def _parse_usage(bc: dict) -> str | None:
        usage_flag = str(bc.get("5", ""))
        if usage_flag == "1":
            return None
        if usage_flag == "2":
            usage_type = str(bc.get("51", ""))
            return {"1": "렌트", "3": "영업용"}.get(usage_type, "있음")
        return None

    @staticmethod
    def _build_panel_damage(mac: dict, acc_out: dict, acc_bone: dict) -> list[dict]:
        """Build a list of {panel, rank, damage_code, damage_label} dicts."""
        panels = []
        # From ucAccOutCheck: {panelNo: damageCode}
        for panel_no, code in acc_out.items():
            name = _OUTER_PANELS.get(str(panel_no), f"외판#{panel_no}")
            panels.append({
                "panel": name,
                "rank": "outer",
                "damage_code": code,
                "damage": _DAMAGE_CODES.get(code, code),
            })
        # From ucAccBoneCheck: {panelNo: damageCode}
        for panel_no, code in acc_bone.items():
            name = _STRUCT_PANELS.get(str(panel_no), f"골격#{panel_no}")
            panels.append({
                "panel": name,
                "rank": "structural",
                "damage_code": code,
                "damage": _DAMAGE_CODES.get(code, code),
            })
        return panels

    @staticmethod
    def _parse_mechanical_issues(dc: dict) -> list[str]:
        """Return list of mechanical items with bad state (value != '1' for 양호/없음/적정)."""
        _DC_LABELS = {
            "11": "자기진단-원동기", "12": "자기진단-변속기",
            "21": "원동기-작동상태", "221": "오일누유-실린더커버",
            "222": "오일누유-실린더헤드", "223": "오일누유-실린더블록",
            "231": "냉각수누수-실린더헤드", "232": "냉각수누수-워터펌프",
            "233": "냉각수누수-라디에이터",
            "311": "자동변속기-오일누유", "313": "자동변속기-작동상태",
            "51": "조향-동력조향오일누유", "61": "제동-마스터실린더누유",
            "81": "연료누출",
        }
        issues = []
        for key, label in _DC_LABELS.items():
            val = str(dc.get(key, ""))
            if val and val != "1":
                issues.append(label)
        return issues

    # Common boilerplate phrases from autocafe legal disclaimers
    _BOILERPLATE = (
        "성능상태점검자가 발행한",
        "자동차관리법 시행규칙",
        "중고자동차 성능 상태점검",
        "매수인에게 고지하여야",
        "성능상태점검기록부의 내용이",
        "매매계약을 해제할 수 있으며",
        "손해배상을 청구할 수 있습니다",
    )

    @staticmethod
    def _parse_notes(soup: BeautifulSoup) -> str | None:
        """Extract inspector notes from 특기사항 td.wrap, stripping boilerplate."""
        for td in soup.select("td.wrap"):
            text = td.get_text(" ", strip=True)
            if len(text) > 10 and ("비금속" in text or "점검" in text or "이력" in text or "탈착" in text):
                # Strip known boilerplate legal text
                if any(bp in text for bp in KBChaExternalInspectionParser._BOILERPLATE):
                    continue
                return text[:500]
        return None

    @staticmethod
    def _parse_inspection_date(html: str) -> str | None:
        m = _INSP_DATE_RE.search(html)
        if m:
            return f"{m.group(1)}-{m.group(2).zfill(2)}-{m.group(3).zfill(2)}"
        return None

    @staticmethod
    def _parse_cert_no(url: str, html: str) -> str | None:
        # From URL params (OnCarNo is autocafe's inspection ID)
        q = parse_qs(urlparse(url).query)
        for key in ("OnCarNo", "checkNo", "checkNum", "certNo"):
            if q.get(key):
                val = str(q[key][0])
                if len(val) >= 5:  # skip short IDs (office/assoc numbers)
                    return val
        # From HTML: look for 제 NNNNN 호 pattern but skip short numbers
        m = _CERT_NUM_RE.search(html)
        if m and len(m.group(1)) >= 5:
            return m.group(1)
        return None


def compare_report_vs_lot(report: dict, lot_data: dict) -> dict:
    """Compare parsed inspection report fields against lot data.
    Returns dict of {field: {report: val, lot: val, match: bool}}.
    Only includes fields where both sides have a value.
    """
    comparisons = {}

    def _norm_str(val):
        """Normalize string: lowercase and strip."""
        if isinstance(val, str):
            return val.lower().strip()
        return val

    def _cmp(field: str, report_key: str, lot_key: str, normalize=None):
        rv = report.get(report_key)
        lv = lot_data.get(lot_key)
        if rv is None or lv is None:
            return
        if normalize:
            rv = normalize(rv)
            lv = normalize(lv)
        comparisons[field] = {"report": rv, "lot": lv, "match": rv == lv}

    _cmp("vin", "vin", "vin", str.upper)
    _cmp("year", "report_year", "year")
    _cmp("fuel", "report_fuel", "fuel", _norm_str)
    _cmp("transmission", "report_transmission", "transmission", _norm_str)
    _cmp("plate", "report_plate", "plate_number")
    _cmp("accident", "has_accident", "has_accident")
    _cmp("flood", "has_flood", "flood_history")

    insp_km = report.get("inspection_mileage")
    lot_km = lot_data.get("mileage")
    if insp_km and lot_km:
        diff_pct = abs(insp_km - lot_km) / max(lot_km, 1) * 100
        comparisons["mileage"] = {
            "report": insp_km, "lot": lot_km,
            "diff_pct": round(diff_pct, 1),
            "match": diff_pct < 5,
        }

    return comparisons
