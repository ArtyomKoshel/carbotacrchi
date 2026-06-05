"""Parser-side taxonomy localizer.

Parser writes canonical values to DB and can use this localizer for
human-readable diagnostics/logs without duplicating label dictionaries in
other parser modules.
"""

from __future__ import annotations

from typing import Iterable


class ParserTaxonomyLocalizer:
    _RU: dict[str, dict[str, str]] = {
        "body_type": {
            "sedan": "Седан",
            "suv": "SUV",
            "hatchback": "Хэтчбек",
            "coupe": "Купе",
            "convertible": "Кабриолет",
            "wagon": "Универсал",
            "van": "Фургон",
            "minivan": "Минивэн",
            "pickup": "Пикап",
            "truck": "Грузовой",
            "cargo": "Грузовой фургон",
            "kei": "Малолитражка",
        },
        "transmission": {
            "automatic": "Автомат",
            "manual": "Механика",
            "cvt": "Вариатор",
            "dct": "Робот (DCT)",
        },
        "fuel": {
            "gasoline": "Бензин",
            "diesel": "Дизель",
            "lpg": "LPG",
            "cng": "CNG",
            "electric": "Электро",
            "hybrid": "Гибрид",
            "plugin_hybrid": "Plug-in гибрид",
            "hydrogen": "Водород",
        },
        "drive_type": {
            "fwd": "Передний",
            "rwd": "Задний",
            "awd": "Полный",
            "4wd": "4WD",
            "2wd": "2WD",
        },
    }

    _EN: dict[str, dict[str, str]] = {
        "body_type": {
            "sedan": "Sedan",
            "suv": "SUV",
            "hatchback": "Hatchback",
            "coupe": "Coupe",
            "convertible": "Convertible",
            "wagon": "Wagon",
            "van": "Van",
            "minivan": "Minivan",
            "pickup": "Pickup",
            "truck": "Truck",
            "cargo": "Cargo Van",
            "kei": "Kei Car",
        },
        "transmission": {
            "automatic": "Automatic",
            "manual": "Manual",
            "cvt": "CVT",
            "dct": "DCT",
        },
        "fuel": {
            "gasoline": "Gasoline",
            "diesel": "Diesel",
            "lpg": "LPG",
            "cng": "CNG",
            "electric": "Electric",
            "hybrid": "Hybrid",
            "plugin_hybrid": "Plug-in Hybrid",
            "hydrogen": "Hydrogen",
        },
        "drive_type": {
            "fwd": "FWD",
            "rwd": "RWD",
            "awd": "AWD",
            "4wd": "4WD",
            "2wd": "2WD",
        },
    }

    @classmethod
    def label(cls, field: str, value: str | None, locale: str = "ru") -> str:
        raw = (value or "").strip()
        if not raw:
            return ""
        key = raw.lower()
        maps = cls._RU if locale.lower() == "ru" else cls._EN
        return maps.get(field, {}).get(key, raw)

    @classmethod
    def labels(cls, field: str, values: Iterable[str], locale: str = "ru") -> list[str]:
        return [cls.label(field, v, locale=locale) for v in values]
