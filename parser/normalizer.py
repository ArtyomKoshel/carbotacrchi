"""Value mapping tables shared across all parsers."""

FUEL_MAP: dict[str, str] = {
    "가솔린": "Gasoline",
    "gasoline": "Gasoline",
    "petrol": "Gasoline",
    "디젤": "Diesel",
    "diesel": "Diesel",
    "전기": "Electric",
    "electric": "Electric",
    "가솔린+전기": "Hybrid",
    "디젤+전기": "Hybrid",
    "hybrid": "Hybrid",
    "하이브리드": "Hybrid",
    "LPG": "LPG",
    "lpg": "LPG",
}

TRANSMISSION_MAP: dict[str, str] = {
    "오토": "Automatic",
    "auto": "Automatic",
    "automatic": "Automatic",
    "수동": "Manual",
    "manual": "Manual",
    "CVT": "CVT",
    "cvt": "CVT",
}

DRIVE_MAP: dict[str, str] = {
    "전륜": "FWD",
    "front": "FWD",
    "fwd": "FWD",
    "후륜": "RWD",
    "rear": "RWD",
    "rwd": "RWD",
    "사륜": "AWD",
    "all": "AWD",
    "awd": "AWD",
    "4wd": "4WD",
}

BODY_TYPE_MAP: dict[str, str] = {
    "sedan": "Sedan",
    "세단": "Sedan",
    "suv": "SUV",
    "SUV": "SUV",
    "truck": "Truck",
    "트럭": "Truck",
    "coupe": "Coupe",
    "쿠페": "Coupe",
    "hatchback": "Hatchback",
    "해치백": "Hatchback",
    "wagon": "Wagon",
    "왜건": "Wagon",
    "van": "Van",
    "밴": "Van",
    "convertible": "Convertible",
    "컨버터블": "Convertible",
    "crossover": "Crossover",
}


def map_value(value: str | None, mapping: dict[str, str]) -> str | None:
    if not value:
        return None
    key = value.strip()
    return mapping.get(key, mapping.get(key.lower()))


def krw_to_usd(price_man_won: float, rate: float = 1350.0) -> int:
    """Convert price from 만원 (10,000 KRW units) to USD."""
    return int(price_man_won * 10000 / rate)
