"""Encar sale type → contract SellType (refactor stage 3, from parser/parsers/_shared/sell_type.py)."""

from __future__ import annotations

from typing import Final

from collector.domain.carlot import SellType

SELL_TYPE: Final[dict[str, str]] = {
    "일반": "sale",
    "직거래": "sale",
    "리스": "lease",
    "렌트": "rental",
    "업자매입": "business",
    "영업용": "business",
    "계약중": "under_contract",
}

CONDITION: Final[dict[str, str]] = {
    "Lease": "lease",
    "Rent": "rental",
    "UnderContract": "under_contract",
    "InsuranceHide": "insurance_hide",
    "Business": "business",
    "Auction": "auction",
}


def normalize(
    sell_type_raw: str | None, ad_type: str | None, conditions: list[str] | None
) -> tuple[SellType, str]:
    """(sale type, debug string) for a search item.

    Precedence: Condition tags (strongest) → AdType markers → SellType label → sale.
    """
    raw_parts: list[str] = []
    if sell_type_raw:
        raw_parts.append(f"sell={sell_type_raw}")
    if ad_type:
        raw_parts.append(f"ad={ad_type}")
    if conditions:
        raw_parts.append(f"cond={','.join(str(c) for c in conditions)}")
    raw = " | ".join(raw_parts)

    for tag in conditions or []:
        mapped = CONDITION.get(str(tag))
        if mapped:
            return SellType(mapped), raw

    if ad_type:
        for marker, value in (
            ("계약", SellType.UNDER_CONTRACT),
            ("렌트", SellType.RENTAL),
            ("리스", SellType.LEASE),
        ):
            if marker in ad_type:
                return value, raw

    if sell_type_raw:
        label = sell_type_raw.strip()
        if label in SELL_TYPE:
            return SellType(SELL_TYPE[label]), raw
        for key, mapped_type in SELL_TYPE.items():
            if key in sell_type_raw:
                return SellType(mapped_type), raw

    return SellType.SALE, raw
