from __future__ import annotations

import re

from config import Config
from .glossary import (
    FUEL, TRANSMISSION, BODY_TYPE, COLOR, DRIVE,
    MAKE_NAME, MAKER_CODE,
)

# Back-compat aliases (used by list_parser and other modules)
MAKER_CODES = MAKER_CODE


class KBChaNormalizer:
    def normalize_make(self, korean_name: str, maker_code: str = "") -> str:
        if maker_code and maker_code in MAKER_CODE:
            return MAKER_CODE[maker_code]
        return MAKE_NAME.get(korean_name, korean_name)

    def normalize_model(self, title: str, make_korean: str) -> str:
        remaining = title
        for kr in MAKE_NAME:
            if remaining.startswith(kr):
                remaining = remaining[len(kr):].strip()
                break

        parts = remaining.split()
        if not parts:
            return ""

        model = parts[0]
        if len(parts) > 1 and not re.match(r"^\d", parts[1]) and parts[1] not in ("가솔린", "디젤", "전기", "하이브리드", "터보"):
            model = f"{parts[0]} {parts[1]}"
        return model

    def normalize_fuel(self, value: str | None) -> str | None:
        if not value:
            return None
        clean = value.strip()
        result = FUEL.get(clean) or FUEL.get(clean.lower())
        if result:
            return result
        if "하이브리드" in clean or "hybrid" in clean.lower() or "+전기" in clean:
            return "Hybrid"
        for key, mapped in FUEL.items():
            if key in clean:
                return mapped
        return None

    def normalize_transmission(self, value: str | None) -> str | None:
        if not value:
            return None
        return TRANSMISSION.get(value.strip(), TRANSMISSION.get(value.strip().lower()))

    def normalize_body_type(self, value: str | None) -> str | None:
        if not value:
            return None
        return BODY_TYPE.get(value.strip(), BODY_TYPE.get(value.strip().lower()))

    def normalize_drive_type(self, value: str | None) -> str | None:
        if not value:
            return None
        clean = value.strip()
        return DRIVE.get(clean, DRIVE.get(clean.upper()))

    def normalize_color(self, value: str | None) -> str | None:
        if not value:
            return None
        clean = value.strip()
        return COLOR.get(clean, clean.capitalize() if clean else None)

    def parse_engine_cc(self, value: str | None) -> float | None:
        if not value:
            return None
        try:
            cc = float(re.sub(r"[^\d.]", "", value))
            return round(cc / 1000, 1) if cc > 100 else round(cc, 1)
        except (ValueError, TypeError):
            return None

    def parse_year(self, text: str) -> int:
        m = re.search(r"(\d{2})년형", text)
        if m:
            y = int(m.group(1))
            return 2000 + y if y < 90 else 1900 + y
        m = re.search(r"(\d{2})/\d{2}식", text)
        if m:
            y = int(m.group(1))
            return 2000 + y if y < 90 else 1900 + y
        return 0

    def parse_mileage(self, text: str) -> int:
        return int(re.sub(r"[^\d]", "", text) or 0)

    def parse_fuel_economy(self, value: str | None) -> float | None:
        if not value:
            return None
        m = re.search(r"([\d.]+)", value)
        if m:
            try:
                return float(m.group(1))
            except ValueError:
                return None
        return None

    def parse_price_man(self, text: str) -> int:
        m = re.search(r"([\d,]+)", text)
        return int(m.group(1).replace(",", "")) if m else 0

    def krw_to_usd(self, price_man: float) -> int:
        return int(price_man * 10000 / Config.USD_KRW_RATE)
