"""Option code definitions for decoding Encar option IDs to human-readable names.

Encar returns options as numeric IDs (e.g., {"option": {"id": 10}}). This file
provides a mapping from those IDs to readable Korean/English names for UI display.

This is a partial, extensible dictionary. Add mappings as they are discovered
from production data or Encar documentation.
"""

from __future__ import annotations

# Encar option ID → human-readable name (Korean primary, English fallback)
ENCAR_OPTION_MAP: dict[int, str] = {
    # Common option IDs (incomplete - extend as needed)
    1: "열선스티어링",
    2: "가죽시트",
    3: "썬루프",
    4: "자동변속기",
    5: "네비게이션",
    6: "후방카메라",
    7: "블루투스",
    8: "스마트키",
    9: "주차보조",
    10: "열선시트",
    16: "썬팅",
    # Add more as discovered from production data
}


def get_option_name(option_id: int | str | None) -> str | None:
    """Return human-readable name for an Encar option ID."""
    if option_id is None:
        return None
    try:
        oid = int(option_id)
    except (ValueError, TypeError):
        return None
    return ENCAR_OPTION_MAP.get(oid)


def decode_options(options_list: list[dict]) -> list[str]:
    """Decode a list of Encar option dicts to readable names.

    Args:
        options_list: List of {"option": {"id": N}, "value": V} dicts

    Returns:
        List of human-readable option names (or original IDs if unknown)
    """
    if not options_list:
        return []
    decoded = []
    for item in options_list:
        opt = item.get("option", {})
        oid = opt.get("id")
        name = get_option_name(oid)
        if name:
            decoded.append(name)
        elif oid is not None:
            decoded.append(f"ID:{oid}")
    return decoded
