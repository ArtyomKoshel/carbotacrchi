from __future__ import annotations

import re

from config import Config
from .glossary import (
    FUEL, TRANSMISSION, BODY_TYPE, COLOR, DRIVE,
    MAKE_NAME, MAKER_CODE,
    GEN_PREFIXES, MODEL_FUEL_STOP, MODEL_TRIM_STOP, MODEL_DRIVE_STOP,
    TRIM_BLOCKLIST, ENGINE_DESC_TOKENS,
)

_ENGINE_RE     = re.compile(r'^\d+(\.\d+)?(T|D|L)?$', re.IGNORECASE)
_ENGINE_STR_RE = re.compile(r'(\d\.\d+)(T|D)?', re.IGNORECASE)
_YEAR_RE       = re.compile(r'^\d{2,4}\ub144?$')
_GEN_PAREN_RE  = re.compile(r'\(([A-Z0-9]{2,5})\)')
_GEN_PLAIN_RE  = re.compile(r'^[A-Z]{2,3}\d?$')
_GEN_NUMGEN_RE = re.compile(r'^\d+\uc138\ub300$')  # e.g. 3세대, 4세대
_DRIVE_RE      = re.compile(r'^(2WD|4WD|AWD|FWD|RWD)$', re.IGNORECASE)

# Stop-set union for gen-code exclusion
_ALL_STOPS: frozenset[str] = MODEL_FUEL_STOP | MODEL_TRIM_STOP | MODEL_DRIVE_STOP

# Back-compat aliases (used by list_parser and other modules)
MAKER_CODES = MAKER_CODE


class KBChaNormalizer:
    def normalize_make(self, korean_name: str, maker_code: str = "") -> str:
        if maker_code and maker_code in MAKER_CODE:
            return MAKER_CODE[maker_code]
        return MAKE_NAME.get(korean_name, korean_name)

    def _tokenize_title(self, title: str) -> list[str]:
        """Sanitize, strip make name and generation prefix; return remaining tokens."""
        title = re.sub(r'[\n\r\t,]+', ' ', title)
        title = re.sub(r'\[[^\]]*\]', '', title)
        title = re.sub(r'[^\uAC00-\uD7A3\u3130-\u318Fa-zA-Z0-9\s\-().]', ' ', title)
        title = re.sub(r'\s+', ' ', title.strip())
        for kr in MAKE_NAME:
            if title.startswith(kr):
                title = title[len(kr):].strip()
                break
            idx = title.find(kr)
            if 0 < idx <= 15:
                title = title[idx + len(kr):].strip()
                break
        parts = title.split()
        for prefix in GEN_PREFIXES:
            n = len(prefix)
            if len(parts) > n and tuple(parts[:n]) == prefix:
                return parts[n:]
        return parts

    def _extract_model_parts(self, parts: list[str]) -> list[str]:
        """Left-to-right model extraction — stop at hard triggers."""
        model_parts: list[str] = []
        for token in parts:
            if token in MODEL_TRIM_STOP:
                break
            if token in MODEL_DRIVE_STOP:
                break
            if _ENGINE_RE.match(token):
                break
            if _YEAR_RE.match(token):
                break
            base = re.split(r'[\d(]', token)[0]
            if base in MODEL_FUEL_STOP:
                break
            model_parts.append(token)
        return model_parts

    def normalize_model(self, title: str, make_korean: str = "") -> str:
        parts = self._tokenize_title(title)
        return " ".join(self._extract_model_parts(parts))

    def parse_title(self, title: str) -> dict:
        """Extract model, generation, engine_str, trim, and drive from a Korean car title."""
        parts = self._tokenize_title(title)
        if not parts:
            return {"model": "", "generation": None, "engine_str": None,
                    "trim": None, "drive": None, "unknown_tokens": None}

        model_parts = self._extract_model_parts(parts)

        generation: str | None = None
        gen_remove_idx: int | None = None
        for i, token in enumerate(model_parts):
            m = _GEN_PAREN_RE.search(token)
            if m:
                generation = m.group(1)
                break
            if _GEN_NUMGEN_RE.match(token):
                generation = token
                gen_remove_idx = i
                break
            if i > 0 and _GEN_PLAIN_RE.match(token) and token not in _ALL_STOPS:
                generation = token
                break
        if generation is None:
            for token in parts:
                if token in model_parts:
                    continue
                m = _GEN_PAREN_RE.search(token)
                if m:
                    generation = m.group(1)
                    break

        model_final = (
            [t for i, t in enumerate(model_parts) if i != gen_remove_idx]
            if gen_remove_idx is not None else model_parts
        )

        engine_str: str | None = None
        m = _ENGINE_STR_RE.search(" ".join(parts))
        if m:
            engine_str = m.group(0)

        trim_parts: list[str] = []
        in_trim = False
        for token in parts:
            if in_trim:
                if (token in MODEL_FUEL_STOP or token in MODEL_DRIVE_STOP
                        or _ENGINE_RE.match(token) or _YEAR_RE.match(token)):
                    break
                trim_parts.append(token)
            elif token in MODEL_TRIM_STOP:
                in_trim = True
                trim_parts.append(token)
        trim_joined = " ".join(trim_parts) if trim_parts else None
        trim = trim_joined if trim_joined and trim_joined not in TRIM_BLOCKLIST else None

        drive: str | None = None
        for token in parts:
            if _DRIVE_RE.match(token):
                drive = self.normalize_drive_type(token)
                break

        _known: frozenset[str] = (
            frozenset(model_final)
            | frozenset(trim_parts)
            | MODEL_TRIM_STOP | MODEL_DRIVE_STOP | MODEL_FUEL_STOP
            | TRIM_BLOCKLIST | ENGINE_DESC_TOKENS
            | ({generation} if generation else frozenset())
        )
        unknown_tokens = [
            t for t in parts
            if t not in _known
            and not _ENGINE_RE.match(t)
            and not _ENGINE_STR_RE.fullmatch(t)
            and not _YEAR_RE.match(t)
            and not _GEN_NUMGEN_RE.match(t)
            and not _DRIVE_RE.match(t)
            and len(t) >= 2
        ]

        return {
            "model":           " ".join(model_final),
            "generation":      generation,
            "engine_str":      engine_str,
            "trim":            trim,
            "drive":           drive,
            "unknown_tokens":  unknown_tokens or None,
        }

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
        if clean in COLOR:
            return COLOR[clean]
        base = re.sub(r"\s*\([^)]*\)", "", clean).strip()
        if base in COLOR:
            return COLOR[base]
        if base:
            return COLOR.get(base, clean)
        return clean

    def parse_engine_cc(self, value: str | None) -> float | None:
        if not value:
            return None
        try:
            cc = float(re.sub(r"[^\d.]", "", value))
            if cc < 250:
                return None
            return round(cc / 1000, 1)
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
