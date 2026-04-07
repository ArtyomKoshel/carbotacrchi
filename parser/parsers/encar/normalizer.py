from __future__ import annotations

FUEL_MAP: dict[str, str] = {
    "가솔린":              "gasoline",
    "디젤":                "diesel",
    "LPG(일반인 구입)":    "lpg",
    "LPG(개인 구입 가능)": "lpg",
    "LPG":                 "lpg",
    "전기":                "electric",
    "하이브리드":           "hybrid",
    "가솔린+전기":          "hybrid",
    "디젤+전기":            "hybrid",
    "LPG+전기":             "hybrid",
    "플러그인 하이브리드":   "plugin_hybrid",
    "가솔린+전기(플러그인)": "plugin_hybrid",
    "수소":                "hydrogen",
}

TRANSMISSION_MAP: dict[str, str] = {
    "오토": "automatic",
    "수동": "manual",
    "CVT":  "cvt",
    "DCT":  "dct",
}

DRIVE_MAP: dict[str, str] = {
    "전륜":  "fwd",
    "후륜":  "rwd",
    "4WD":  "4wd",
    "AWD":  "awd",
    "사륜":  "4wd",
    "4륜":  "4wd",
}

BODY_MAP: dict[str, str] = {
    "세단":   "sedan",
    "RV":    "suv",
    "SUV":   "suv",
    "해치백":  "hatchback",
    "쿠페":   "coupe",
    "컨버터블": "convertible",
    "픽업트럭": "pickup",
    "밴":    "van",
    "승합":   "minivan",
    "왜건":   "wagon",
    "카고":   "cargo",
    "트럭":   "truck",
}

# Encar manufacturer names → clean English names
MAKER_MAP: dict[str, str] = {
    "현대":           "Hyundai",
    "기아":           "Kia",
    "쉐보레(GM대우)":  "Chevrolet",
    "GM대우":          "Chevrolet",
    "르노코리아(삼성)":  "Renault Korea",
    "르노코리아":       "Renault Korea",
    "르노삼성":        "Renault Samsung",
    "쌍용":           "SsangYong",
    "KG모빌리티":      "KG Mobility",
    "제네시스":        "Genesis",
    "BMW":            "BMW",
    "벤츠":           "Mercedes-Benz",
    "Mercedes-Benz":  "Mercedes-Benz",
    "아우디":          "Audi",
    "Audi":           "Audi",
    "폭스바겐":        "Volkswagen",
    "볼보":           "Volvo",
    "포드":           "Ford",
    "지프":           "Jeep",
    "랜드로버":        "Land Rover",
    "재규어":          "Jaguar",
    "포르쉐":          "Porsche",
    "렉서스":          "Lexus",
    "토요타":          "Toyota",
    "혼다":           "Honda",
    "닛산":           "Nissan",
    "인피니티":        "Infiniti",
    "마쯔다":          "Mazda",
    "미쯔비시":        "Mitsubishi",
    "스바루":          "Subaru",
    "테슬라":          "Tesla",
    "페라리":          "Ferrari",
    "람보르기니":       "Lamborghini",
    "마세라티":        "Maserati",
    "벤틀리":          "Bentley",
    "롤스로이스":       "Rolls-Royce",
}


class EncarNormalizer:
    def make(self, korean: str | None) -> str:
        if not korean:
            return "Unknown"
        return MAKER_MAP.get(korean, korean)

    def fuel(self, v: str | None) -> str | None:
        return FUEL_MAP.get(v, v) if v else None

    def transmission(self, v: str | None) -> str | None:
        return TRANSMISSION_MAP.get(v, v) if v else None

    def body(self, v: str | None) -> str | None:
        return BODY_MAP.get(v, v) if v else None

    def drive(self, v: str | None) -> str | None:
        return DRIVE_MAP.get(v, v) if v else None

    def price_krw(self, price_man_won: int | None) -> int:
        """Convert 만원 (10,000 KRW units) to KRW."""
        return (price_man_won or 0) * 10_000
