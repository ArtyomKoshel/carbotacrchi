"""
Korean (KBCha / Encar) model name → canonical English name.

Single source of truth used by:
  - parsers (kbcha, encar) to populate CarLot.model_en at parse time
  - Laravel migration to back-fill lots.model_en for existing rows

Rules:
  - Keys are lowercase substrings to match against model string.
  - First match wins (order matters for ambiguous prefixes).
  - If model is already ASCII, it is returned as-is (no lookup needed).
"""
from __future__ import annotations

# Korean substring → canonical English model name.
# Substrings are checked case-insensitively against the raw model string.
_KR_TO_EN: dict[str, str] = {
    # Hyundai
    "투싼": "Tucson",
    "투산": "Tucson",
    "소나타": "Sonata",
    "쏘나타": "Sonata",
    "아반떼": "Avante",
    "아반테": "Avante",
    "그랜저": "Grandeur",
    "팰리세이드": "Palisade",
    "싼타페": "Santa Fe",
    "산타페": "Santa Fe",
    "아이오닉": "Ioniq",
    "코나": "Kona",
    "베뉴": "Venue",
    "스타리아": "Staria",
    "캐스퍼": "Casper",
    "넥쏘": "Nexo",
    "포터": "Porter",
    "스타렉스": "Starex",
    # Kia
    "스포티지": "Sportage",
    "쏘렌토": "Sorento",
    "카니발": "Carnival",
    "셀토스": "Seltos",
    "텔루라이드": "Telluride",
    "모하비": "Mohave",
    "스팅어": "Stinger",
    "레이": "Ray",
    "모닝": "Morning",
    "니로": "Niro",
    "봉고": "Bongo",
    # Genesis
    "gv80": "GV80",
    "gv70": "GV70",
    "gv60": "GV60",
    "gv50": "GV50",
    "g90": "G90",
    "g80": "G80",
    "g70": "G70",
    # Electric
    "ev9": "EV9",
    "ev6": "EV6",
    "ev3": "EV3",
    # Chevrolet (Korea)
    "스파크": "Spark",
    "말리부": "Malibu",
    "트렉스": "Trax",
    "이쿼녹스": "Equinox",
    "트래버스": "Traverse",
    "타호": "Tahoe",
}

# English alias substrings that map back to canonical name (for cases where
# model string contains English but in non-canonical casing/form).
_EN_ALIASES: dict[str, str] = {
    "elantra": "Avante",
    "azera": "Grandeur",
    "cadenza": "K8",
    "optima": "K5",
    "cerato": "K3",
    "forte": "K3",
    "picanto": "Morning",
}


def resolve_model_en(model_raw: str | None) -> str | None:
    """
    Return canonical English model name for a raw model string from the parser.

    Returns None if the model cannot be resolved (unknown Korean string that
    doesn't match any known mapping, and is not plain ASCII).
    """
    if not model_raw:
        return None

    raw_lower = model_raw.lower()

    # Korean substring match (most common path for KBCha / Encar)
    for kr, en in _KR_TO_EN.items():
        if kr in raw_lower:
            return en

    # English alias match
    for alias, canonical in _EN_ALIASES.items():
        if alias in raw_lower:
            return canonical

    # Already plain ASCII — return stripped original (Encar sometimes stores
    # model names directly in English, e.g. "K5", "EV6").
    if all(ord(c) < 128 for c in model_raw.strip()):
        return model_raw.strip() or None

    return None
