from __future__ import annotations

import re

from config import Config

FUEL_MAP: dict[str, str] = {
    "가솔린": "Gasoline", "gasoline": "Gasoline", "petrol": "Gasoline",
    "디젤": "Diesel", "diesel": "Diesel",
    "전기": "Electric", "electric": "Electric",
    "가솔린+전기": "Hybrid", "디젤+전기": "Hybrid",
    "hybrid": "Hybrid", "하이브리드": "Hybrid",
    "가솔린 하이브리드": "Hybrid", "디젤 하이브리드": "Hybrid",
    "플러그인 하이브리드": "Hybrid", "PHEV": "Hybrid", "HEV": "Hybrid",
    "LPG": "LPG", "lpg": "LPG",
    "수소": "Hydrogen", "hydrogen": "Hydrogen",
}

TRANSMISSION_MAP: dict[str, str] = {
    "오토": "Automatic", "auto": "Automatic", "automatic": "Automatic",
    "수동": "Manual", "manual": "Manual",
    "CVT": "CVT", "cvt": "CVT",
}

BODY_TYPE_MAP: dict[str, str] = {
    "SUV": "SUV", "suv": "SUV",
    "세단": "Sedan", "sedan": "Sedan",
    "해치백": "Hatchback", "hatchback": "Hatchback",
    "왜건": "Wagon", "wagon": "Wagon",
    "쿠페": "Coupe", "coupe": "Coupe",
    "컨버터블": "Convertible", "convertible": "Convertible",
    "밴": "Van", "van": "Van",
    "트럭": "Truck", "truck": "Truck",
    "RV": "SUV", "MPV": "Van",
    "경차": "Hatchback",
    "대형": "Sedan",
    "준대형": "Sedan",
    "중형": "Sedan",
    "소형": "Sedan",
    "준중형": "Sedan",
}

COLOR_MAP: dict[str, str] = {
    "검정색": "Black", "검정": "Black", "블랙": "Black",
    "흰색": "White", "흰": "White", "화이트": "White", "백색": "White",
    "은색": "Silver", "실버": "Silver",
    "회색": "Gray", "그레이": "Gray", "그래파이트": "Gray",
    "파란색": "Blue", "파랑": "Blue", "블루": "Blue", "청색": "Blue",
    "빨간색": "Red", "빨강": "Red", "레드": "Red", "적색": "Red",
    "녹색": "Green", "그린": "Green",
    "갈색": "Brown", "브라운": "Brown",
    "금색": "Gold", "골드": "Gold",
    "주황색": "Orange", "오렌지": "Orange",
    "노란색": "Yellow", "옐로우": "Yellow",
    "보라색": "Purple", "퍼플": "Purple",
    "베이지": "Beige", "아이보리": "Beige",
    "쥐색": "Gray", "진주색": "White", "진주": "White",
}

KOREAN_MAKE_MAP: dict[str, str] = {
    "현대": "Hyundai", "기아": "Kia", "제네시스": "Genesis",
    "르노코리아": "Renault Korea", "쌍용": "SsangYong", "KG모빌리티": "SsangYong",
    "쉐보레": "Chevrolet", "한국GM": "Chevrolet",
    "BMW": "BMW", "벤츠": "Mercedes-Benz", "메르세데스-벤츠": "Mercedes-Benz",
    "아우디": "Audi", "폭스바겐": "Volkswagen", "볼보": "Volvo",
    "랜드로버": "Land Rover", "포르쉐": "Porsche",
    "도요타": "Toyota", "혼다": "Honda", "닛산": "Nissan",
    "렉서스": "Lexus", "테슬라": "Tesla", "링컨": "Lincoln",
    "재규어": "Jaguar", "마세라티": "Maserati", "지프": "Jeep",
    "닷지": "Dodge", "포드": "Ford", "푸조": "Peugeot",
    "마쓰다": "Mazda", "미쓰비시": "Mitsubishi", "대우": "Daewoo",
    "미니": "MINI", "페라리": "Ferrari", "벤틀리": "Bentley",
    "롤스로이스": "Rolls-Royce", "람보르기니": "Lamborghini",
}

MAKER_CODES: dict[str, str] = {
    "101": "Hyundai", "102": "Kia", "189": "Genesis",
    "103": "Renault Korea", "104": "SsangYong", "105": "Chevrolet",
    "107": "BMW", "108": "Mercedes-Benz", "109": "Audi",
    "112": "Volkswagen", "114": "Volvo", "116": "Land Rover",
    "117": "Porsche", "124": "Toyota", "125": "Honda",
    "128": "Nissan", "133": "Lexus", "143": "Tesla",
    "136": "Lincoln", "111": "Jaguar", "120": "Maserati",
    "115": "Jeep", "130": "Dodge", "110": "Ford",
    "113": "Peugeot", "126": "Mazda", "127": "Mitsubishi",
    "106": "Daewoo", "160": "MINI",
}


class KBChaNormalizer:
    def normalize_make(self, korean_name: str, maker_code: str = "") -> str:
        if maker_code and maker_code in MAKER_CODES:
            return MAKER_CODES[maker_code]
        return KOREAN_MAKE_MAP.get(korean_name, korean_name)

    def normalize_model(self, title: str, make_korean: str) -> str:
        remaining = title
        for kr in KOREAN_MAKE_MAP:
            if remaining.startswith(kr):
                remaining = remaining[len(kr):].strip()
                break

        parts = remaining.split()
        if not parts:
            return ""

        model = parts[0]
        if len(parts) > 1 and not re.match(r"^\d", parts[1]) and parts[1] not in ("가솔린", "디젤", "전기", "하이브리드", "터보"):
            model = f"{parts[0]} {parts[1]}"
        return model

    def normalize_fuel(self, value: str | None) -> str | None:
        if not value:
            return None
        clean = value.strip()
        result = FUEL_MAP.get(clean) or FUEL_MAP.get(clean.lower())
        if result:
            return result
        if "하이브리드" in clean or "hybrid" in clean.lower() or "+전기" in clean:
            return "Hybrid"
        for key, mapped in FUEL_MAP.items():
            if key in clean:
                return mapped
        return None

    def normalize_transmission(self, value: str | None) -> str | None:
        if not value:
            return None
        return TRANSMISSION_MAP.get(value.strip(), TRANSMISSION_MAP.get(value.strip().lower()))

    def normalize_body_type(self, value: str | None) -> str | None:
        if not value:
            return None
        return BODY_TYPE_MAP.get(value.strip(), BODY_TYPE_MAP.get(value.strip().lower()))

    def normalize_color(self, value: str | None) -> str | None:
        if not value:
            return None
        clean = value.strip()
        return COLOR_MAP.get(clean, clean.capitalize() if clean else None)

    def parse_engine_cc(self, value: str | None) -> float | None:
        if not value:
            return None
        try:
            cc = float(re.sub(r"[^\d.]", "", value))
            return round(cc / 1000, 1) if cc > 100 else round(cc, 1)
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

    def parse_price_man(self, text: str) -> int:
        m = re.search(r"([\d,]+)", text)
        return int(m.group(1).replace(",", "")) if m else 0

    def krw_to_usd(self, price_man: float) -> int:
        return int(price_man * 10000 / Config.USD_KRW_RATE)
