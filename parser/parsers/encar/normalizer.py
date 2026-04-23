"""Encar-specific normalizer.

Delegates vocabulary to the shared canonical mapping dicts so that fuel /
transmission / drive / body / make values are consistent with KBCha.

The legacy module-level *_MAP dicts are preserved as import-time aliases
for back-compat with any code still importing them directly.
"""

from __future__ import annotations

from .._shared.normalizer_base import BaseNormalizer
from .._shared import vocabulary as V


class EncarNormalizer(BaseNormalizer):
    FUEL_MAP         = V.ENCAR_FUEL
    TRANSMISSION_MAP = V.ENCAR_TRANSMISSION
    DRIVE_MAP        = V.ENCAR_DRIVE
    BODY_MAP         = V.ENCAR_BODY
    MAKE_MAP         = V.ENCAR_MAKE

    # Alias: convert 만원 → raw KRW integer (column is now just `price`)
    def price_krw(self, price_man_won):
        return self.price_krw_from_man(price_man_won)

    price_from_man = price_krw  # preferred name going forward


# ── Back-compat aliases for the old module-level constants ─────────────────
FUEL_MAP         = V.ENCAR_FUEL
TRANSMISSION_MAP = V.ENCAR_TRANSMISSION
DRIVE_MAP        = V.ENCAR_DRIVE
BODY_MAP         = V.ENCAR_BODY
MAKER_MAP        = V.ENCAR_MAKE
