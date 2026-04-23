"""Base normalizer shared by every marketplace parser.

Subclasses supply source-specific mapping dicts (from vocabulary.py) and
inherit a consistent API. This guarantees that the DB stores canonical
lowercase values regardless of parser source.

Usage in subclass:
    from parsers._shared.normalizer_base import BaseNormalizer
    from parsers._shared import vocabulary as V

    class EncarNormalizer(BaseNormalizer):
        FUEL_MAP         = V.ENCAR_FUEL
        TRANSMISSION_MAP = V.ENCAR_TRANSMISSION
        DRIVE_MAP        = V.ENCAR_DRIVE
        BODY_MAP         = V.ENCAR_BODY
        MAKE_MAP         = V.ENCAR_MAKE
"""

from __future__ import annotations

from typing import Any

from . import vocabulary as V


class BaseNormalizer:
    """Generic value normalizer. Override class-level MAPs in subclass."""

    # Subclasses override with source-specific dicts (see vocabulary.py).
    FUEL_MAP: dict[str, str] = {}
    TRANSMISSION_MAP: dict[str, str] = {}
    DRIVE_MAP: dict[str, str] = {}
    BODY_MAP: dict[str, str] = {}
    MAKE_MAP: dict[str, str] = {}
    COLOR_MAP: dict[str, str] = V.COLOR_MAP  # shared by default

    # ── Generic lookup ──────────────────────────────────────────────────────
    @staticmethod
    def _lookup(
        value: str | None,
        mapping: dict[str, str],
        default: str | None = None,
    ) -> str | None:
        """Case-sensitive then case-insensitive lookup against mapping.

        Returns:
            the canonical mapped value, or `default` (the raw value passed
            through as-is) if no mapping hit.
        """
        if value is None:
            return None
        clean = str(value).strip()
        if not clean:
            return None
        if clean in mapping:
            return mapping[clean]
        # Try case-insensitive
        lower = clean.lower()
        for k, v in mapping.items():
            if k.lower() == lower:
                return v
        return default

    # ── Typed normalizers ───────────────────────────────────────────────────
    def fuel(self, value: str | None) -> str | None:
        """Return a canonical FUEL_* or None."""
        return self._lookup(value, self.FUEL_MAP, default=None)

    def transmission(self, value: str | None) -> str | None:
        """Return a canonical TRANS_* or None."""
        return self._lookup(value, self.TRANSMISSION_MAP, default=None)

    def drive(self, value: str | None) -> str | None:
        """Return a canonical DRIVE_* or None."""
        return self._lookup(value, self.DRIVE_MAP, default=None)

    def body(self, value: str | None) -> str | None:
        """Return a canonical BODY_* or None."""
        return self._lookup(value, self.BODY_MAP, default=None)

    def make(self, value: str | None, code: str | None = None) -> str:
        """Return a canonical English brand name.

        `code` is a source-specific numeric id (e.g. KBCha's maker code);
        if provided and mapped, takes precedence over the display name.
        """
        if code:
            mapped = self._lookup(code, self.MAKE_MAP, default=None)
            if mapped:
                return mapped
        # No `default=None` — fall through to raw value for unknown brands so
        # we don't lose data; caller can decide to discard if needed.
        result = self._lookup(value, self.MAKE_MAP, default=value or "Unknown")
        return result or "Unknown"

    def color(self, value: str | None) -> str | None:
        """Return a readable color label (keeps original if unmapped)."""
        if not value:
            return None
        clean = str(value).strip()
        if not clean:
            return None
        mapped = self._lookup(clean, self.COLOR_MAP, default=None)
        if mapped:
            return mapped
        # Try stripping parenthesised suffixes ("스파클링 실버(옵션)")
        import re
        base = re.sub(r"\s*\([^)]*\)", "", clean).strip()
        if base and base != clean:
            mapped = self._lookup(base, self.COLOR_MAP, default=None)
            if mapped:
                return mapped
        return clean  # keep raw label for UI

    # ── Lien / Seizure normalization ────────────────────────────────────────
    # Both Encar and KBCha must produce "clean" | "lien" / "seizure" | raw text.
    _LIEN_CLEAN = {"clean", "없음", "없다", "none", "0", "해당없음"}
    _SEIZURE_CLEAN = {"clean", "없음", "없다", "none", "0", "해당없음"}

    @classmethod
    def normalize_lien(cls, value: str | None) -> str | None:
        if value is None:
            return None
        v = str(value).strip().lower()
        if not v:
            return None
        return "clean" if v in cls._LIEN_CLEAN else "lien"

    @classmethod
    def normalize_seizure(cls, value: str | None) -> str | None:
        if value is None:
            return None
        v = str(value).strip().lower()
        if not v:
            return None
        return "clean" if v in cls._SEIZURE_CLEAN else "seizure"

    # ── Numeric helpers (non-mapping, pure parsing) ────────────────────────
    @staticmethod
    def price_krw_from_man(man_won: Any) -> int:
        """Convert 만원 (10,000 KRW unit) → raw KRW integer."""
        try:
            return int(man_won or 0) * 10_000
        except (TypeError, ValueError):
            return 0

    @staticmethod
    def parse_int(value: Any, default: int = 0) -> int:
        """Strip non-digits and parse. Used for mileage, price-with-commas, etc."""
        if value is None:
            return default
        import re
        s = re.sub(r"[^\d-]", "", str(value))
        try:
            return int(s) if s else default
        except ValueError:
            return default
