"""Unified sell type constants and normalization across parsers.

Every parser must map its native sale-type values into one of the canonical
constants below so that downstream filtering, reporting, and deduplication
can treat all sources uniformly.
"""

from __future__ import annotations


# ── Canonical sell-type values (store these in lots.sell_type) ───────────────
SALE = "sale"                       # ordinary retail listing
AUCTION = "auction"                 # auction listing
LEASE = "lease"                     # leased vehicle (monthly payment model)
RENTAL = "rental"                   # rental-car disposal (fleet-returned)
BUSINESS = "business"               # commercial / taxi / 영업용
UNDER_CONTRACT = "under_contract"   # reserved / pending sale
INSURANCE_HIDE = "insurance_hide"   # insurance history intentionally hidden

ALL_TYPES: frozenset[str] = frozenset({
    SALE, AUCTION, LEASE, RENTAL, BUSINESS, UNDER_CONTRACT, INSURANCE_HIDE,
})


# ── Encar mappings ───────────────────────────────────────────────────────────
# Encar API returns `SellType`, `AdType`, and `Condition[]` on each search item.
# `Condition` is an array of tags like ["Record", "Inspection", "Lease", ...].
#
# Known SellType values (Korean UI strings):
#   "일반"        → sale      (generic sale)
#   "리스"        → lease
#   "렌트"        → rental
#   "직거래"      → sale      (direct deal)
#   "업자매입"    → business
#   "계약중"      → under_contract  (also appears via AdType)
_ENCAR_SELL_TYPE: dict[str, str] = {
    "일반":   SALE,
    "직거래": SALE,
    "리스":   LEASE,
    "렌트":   RENTAL,
    "업자매입": BUSINESS,
    "영업용": BUSINESS,
    "계약중": UNDER_CONTRACT,
}

# Encar Condition[] tags that indicate non-standard sell types
_ENCAR_CONDITION: dict[str, str] = {
    "Lease":         LEASE,
    "Rent":          RENTAL,
    "UnderContract": UNDER_CONTRACT,
    "InsuranceHide": INSURANCE_HIDE,
    "Business":      BUSINESS,
    "Auction":       AUCTION,
}


def normalize_encar(sell_type_raw: str | None,
                    ad_type: str | None,
                    conditions: list | None) -> tuple[str, str]:
    """Return (normalized_sell_type, raw_debug_string) for an Encar search item.

    Precedence:
      1. Condition[] tags (Lease/Rent/UnderContract/InsuranceHide) — strongest signal
      2. AdType when it carries a sale-type marker
      3. SellType Korean label
      4. default → SALE
    """
    raw_parts: list[str] = []
    if sell_type_raw:
        raw_parts.append(f"sell={sell_type_raw}")
    if ad_type:
        raw_parts.append(f"ad={ad_type}")
    if conditions:
        raw_parts.append(f"cond={','.join(str(c) for c in conditions)}")
    raw = " | ".join(raw_parts)

    if conditions:
        for tag in conditions:
            mapped = _ENCAR_CONDITION.get(str(tag))
            if mapped:
                return mapped, raw

    if ad_type:
        if "계약" in ad_type:
            return UNDER_CONTRACT, raw
        if "렌트" in ad_type:
            return RENTAL, raw
        if "리스" in ad_type:
            return LEASE, raw

    if sell_type_raw:
        mapped = _ENCAR_SELL_TYPE.get(sell_type_raw.strip())
        if mapped:
            return mapped, raw
        for key, value in _ENCAR_SELL_TYPE.items():
            if key in sell_type_raw:
                return value, raw

    return SALE, raw


# ── KBCha mappings ───────────────────────────────────────────────────────────
# KBCha list pages expose only the sale price; lease/rental status is surfaced
# in the detail page or inspection report (usage_change field).
_KBCHA_USAGE: dict[str, str] = {
    "렌트":   RENTAL,
    "영업용": BUSINESS,
    "리스":   LEASE,
    "임대":   RENTAL,
    "관용":   BUSINESS,
}


def normalize_kbcha_usage(usage_change: str | None,
                          title: str | None = None) -> tuple[str | None, str | None]:
    """Return (normalized_sell_type, raw) for KBCha usage_change/title.

    Returns (None, None) if no sale-type signal is found — caller should
    default to SALE only if no other source indicated otherwise.
    """
    if usage_change:
        mapped = _KBCHA_USAGE.get(usage_change.strip())
        if mapped:
            return mapped, usage_change
        for key, value in _KBCHA_USAGE.items():
            if key in usage_change:
                return value, usage_change

    if title:
        for key, value in _KBCHA_USAGE.items():
            if key in title:
                return value, f"title:{key}"

    return None, None
