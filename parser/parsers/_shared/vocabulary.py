"""Canonical vocabulary — single source of truth for normalized values.

All parsers MUST map their raw values to one of these canonical constants
(always lowercase). This eliminates the current divergence where Encar
produces 'awd' while KBCha produces 'AWD' for the same concept, breaking
filter rules and database queries across sources.

How to add a new source:
  1. Pick the right canonical constant from below.
  2. Add an entry to the source-specific dict at the bottom of this file.
  3. If the concept doesn't exist yet, add a new canonical constant and
     document its meaning.
"""

from __future__ import annotations


# ── FUEL ─────────────────────────────────────────────────────────────────────
FUEL_GASOLINE      = "gasoline"
FUEL_DIESEL        = "diesel"
FUEL_LPG           = "lpg"
FUEL_CNG           = "cng"
FUEL_ELECTRIC      = "electric"
FUEL_HYBRID        = "hybrid"
FUEL_PLUGIN_HYBRID = "plugin_hybrid"
FUEL_HYDROGEN      = "hydrogen"

FUEL_VALUES: frozenset[str] = frozenset({
    FUEL_GASOLINE, FUEL_DIESEL, FUEL_LPG, FUEL_CNG,
    FUEL_ELECTRIC, FUEL_HYBRID, FUEL_PLUGIN_HYBRID, FUEL_HYDROGEN,
})


# ── TRANSMISSION ─────────────────────────────────────────────────────────────
TRANS_AUTOMATIC = "automatic"
TRANS_MANUAL    = "manual"
TRANS_CVT       = "cvt"
TRANS_DCT       = "dct"

TRANSMISSION_VALUES: frozenset[str] = frozenset({
    TRANS_AUTOMATIC, TRANS_MANUAL, TRANS_CVT, TRANS_DCT,
})


# ── DRIVE ────────────────────────────────────────────────────────────────────
DRIVE_FWD = "fwd"
DRIVE_RWD = "rwd"
DRIVE_AWD = "awd"
DRIVE_4WD = "4wd"
DRIVE_2WD = "2wd"

DRIVE_VALUES: frozenset[str] = frozenset({
    DRIVE_FWD, DRIVE_RWD, DRIVE_AWD, DRIVE_4WD, DRIVE_2WD,
})


# ── BODY TYPE ────────────────────────────────────────────────────────────────
BODY_SEDAN       = "sedan"
BODY_SUV         = "suv"
BODY_HATCHBACK   = "hatchback"
BODY_COUPE       = "coupe"
BODY_CONVERTIBLE = "convertible"
BODY_WAGON       = "wagon"
BODY_VAN         = "van"
BODY_MINIVAN     = "minivan"
BODY_PICKUP      = "pickup"
BODY_TRUCK       = "truck"
BODY_CARGO       = "cargo"
BODY_KEI         = "kei"
BODY_TWO_TONE    = "two_tone"  # used for color, not body — placeholder

BODY_VALUES: frozenset[str] = frozenset({
    BODY_SEDAN, BODY_SUV, BODY_HATCHBACK, BODY_COUPE, BODY_CONVERTIBLE,
    BODY_WAGON, BODY_VAN, BODY_MINIVAN, BODY_PICKUP, BODY_TRUCK,
    BODY_CARGO, BODY_KEI,
})


# ── MAKE (brand) ─────────────────────────────────────────────────────────────
# Canonical English names. Keys in source-specific dicts (Korean, codes)
# must map to these.
MAKE_VALUES: frozenset[str] = frozenset({
    "Hyundai", "Kia", "Genesis", "SsangYong", "KG Mobility", "Chevrolet",
    "Renault Korea", "Renault Samsung", "Daewoo",
    "BMW", "Mercedes-Benz", "Audi", "Volkswagen", "Volvo", "Porsche",
    "Land Rover", "Jaguar", "MINI", "Ferrari", "Lamborghini", "Maserati",
    "Bentley", "Rolls-Royce",
    "Toyota", "Honda", "Nissan", "Infiniti", "Lexus", "Mazda",
    "Mitsubishi", "Subaru",
    "Ford", "Jeep", "Dodge", "Lincoln", "Tesla", "Peugeot",
})


# ── COLOR ────────────────────────────────────────────────────────────────────
# Colors intentionally kept capitalized for readability (they are
# user-visible in UI), not used by filters as strict enums.
COLOR_BLACK    = "Black"
COLOR_WHITE    = "White"
COLOR_SILVER   = "Silver"
COLOR_GRAY     = "Gray"
COLOR_BLUE     = "Blue"
COLOR_RED      = "Red"
COLOR_GREEN    = "Green"
COLOR_BROWN    = "Brown"
COLOR_GOLD     = "Gold"
COLOR_ORANGE   = "Orange"
COLOR_YELLOW   = "Yellow"
COLOR_PURPLE   = "Purple"
COLOR_BEIGE    = "Beige"
COLOR_TWO_TONE = "Two-tone"


# ── Source-specific mapping dicts ────────────────────────────────────────────
# Each dict maps raw source value → canonical constant from above.
# Keep these sorted by canonical target for readability.

# --- Encar (API-based marketplace) ------------------------------------------
ENCAR_FUEL: dict[str, str] = {
    "가솔린":              FUEL_GASOLINE,
    "디젤":                FUEL_DIESEL,
    "LPG(일반인 구입)":    FUEL_LPG,
    "LPG(개인 구입 가능)": FUEL_LPG,
    "LPG":                 FUEL_LPG,
    "전기":                FUEL_ELECTRIC,
    "하이브리드":           FUEL_HYBRID,
    "가솔린+전기":          FUEL_HYBRID,
    "디젤+전기":            FUEL_HYBRID,
    "LPG+전기":             FUEL_HYBRID,
    "플러그인 하이브리드":   FUEL_PLUGIN_HYBRID,
    "가솔린+전기(플러그인)": FUEL_PLUGIN_HYBRID,
    "수소":                FUEL_HYDROGEN,
}

ENCAR_TRANSMISSION: dict[str, str] = {
    "오토":       TRANS_AUTOMATIC,
    "자동":       TRANS_AUTOMATIC,
    "수동":       TRANS_MANUAL,
    "CVT":        TRANS_CVT,
    "DCT":        TRANS_DCT,
    "자동(DCT)":  TRANS_DCT,
}

ENCAR_DRIVE: dict[str, str] = {
    "전륜":     DRIVE_FWD,
    "전륜구동":  DRIVE_FWD,
    "FWD":     DRIVE_FWD,
    "2WD":     DRIVE_FWD,
    "후륜":     DRIVE_RWD,
    "후륜구동":  DRIVE_RWD,
    "RWD":     DRIVE_RWD,
    # All "4+ wheels driven" variants collapse to AWD for consistency with KBCha.
    # The Korean market treats 4WD / AWD / 사륜 / 사륜구동 interchangeably.
    "AWD":     DRIVE_AWD,
    "4WD":     DRIVE_AWD,
    "4륜":      DRIVE_AWD,
    "4륜구동":  DRIVE_AWD,
    "사륜":     DRIVE_AWD,
    "사륜구동":  DRIVE_AWD,
}

ENCAR_BODY: dict[str, str] = {
    "세단":     BODY_SEDAN,
    "대형차":    BODY_SEDAN,
    "중형차":    BODY_SEDAN,
    "준중형차":   BODY_SEDAN,
    "소형차":    BODY_SEDAN,
    "RV":      BODY_SUV,
    "SUV":     BODY_SUV,
    "해치백":    BODY_HATCHBACK,
    "쿠페":     BODY_COUPE,
    "스포츠카":   BODY_COUPE,
    "컨버터블":   BODY_CONVERTIBLE,
    "픽업트럭":   BODY_PICKUP,
    "밴":      BODY_VAN,
    "승합":     BODY_MINIVAN,
    "왜건":     BODY_WAGON,
    "카고":     BODY_CARGO,
    "트럭":     BODY_TRUCK,
    "경차":     BODY_KEI,
}

ENCAR_MAKE: dict[str, str] = {
    "현대":           "Hyundai",
    "기아":           "Kia",
    "제네시스":        "Genesis",
    "쉐보레(GM대우)":  "Chevrolet",
    "GM대우":          "Chevrolet",
    "르노코리아(삼성)":  "Renault Korea",
    "르노코리아":       "Renault Korea",
    "르노삼성":        "Renault Samsung",
    "쌍용":           "SsangYong",
    "KG모빌리티":      "KG Mobility",
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


# --- KBCha (HTML scraping) --------------------------------------------------
KBCHA_FUEL: dict[str, str] = {
    "가솔린":             FUEL_GASOLINE,
    "petrol":            FUEL_GASOLINE,
    "디젤":               FUEL_DIESEL,
    "diesel":            FUEL_DIESEL,
    "LPG":               FUEL_LPG,
    "lpg":               FUEL_LPG,
    "전기":               FUEL_ELECTRIC,
    "electric":          FUEL_ELECTRIC,
    "가솔린+전기":         FUEL_HYBRID,
    "디젤+전기":           FUEL_HYBRID,
    "하이브리드":          FUEL_HYBRID,
    "가솔린 하이브리드":    FUEL_HYBRID,
    "디젤 하이브리드":      FUEL_HYBRID,
    "hybrid":            FUEL_HYBRID,
    "HEV":               FUEL_HYBRID,
    "플러그인 하이브리드":   FUEL_PLUGIN_HYBRID,
    "PHEV":              FUEL_PLUGIN_HYBRID,
    "수소":               FUEL_HYDROGEN,
    "수소전기":            FUEL_HYDROGEN,
    "수소전기차":           FUEL_HYDROGEN,
    "연료전지":            FUEL_HYDROGEN,
    "FCEV":              FUEL_HYDROGEN,
    "hydrogen":          FUEL_HYDROGEN,
}

KBCHA_TRANSMISSION: dict[str, str] = {
    "오토":       TRANS_AUTOMATIC,
    "auto":      TRANS_AUTOMATIC,
    "automatic": TRANS_AUTOMATIC,
    "수동":       TRANS_MANUAL,
    "manual":    TRANS_MANUAL,
    "CVT":       TRANS_CVT,
    "cvt":       TRANS_CVT,
    "DCT":       TRANS_DCT,
    "dct":       TRANS_DCT,
}

KBCHA_DRIVE: dict[str, str] = {
    "전륜":      DRIVE_FWD,
    "전륜구동":   DRIVE_FWD,
    "FF":       DRIVE_FWD,
    "FWD":      DRIVE_FWD,
    "후륜":      DRIVE_RWD,
    "후륜구동":   DRIVE_RWD,
    "FR":       DRIVE_RWD,
    "RWD":      DRIVE_RWD,
    "사륜":      DRIVE_AWD,
    "사륜구동":   DRIVE_AWD,
    "상시사륜":   DRIVE_AWD,
    "풀타임사륜":  DRIVE_AWD,
    "파트타임사륜": DRIVE_AWD,
    "4WD":      DRIVE_AWD,  # KBCha uses 4WD/AWD interchangeably for AWD
    "AWD":      DRIVE_AWD,
    "2WD":      DRIVE_FWD,
}

KBCHA_BODY: dict[str, str] = {
    "세단":     BODY_SEDAN,
    "sedan":   BODY_SEDAN,
    "대형":     BODY_SEDAN,
    "준대형":    BODY_SEDAN,
    "중형":     BODY_SEDAN,
    "소형":     BODY_SEDAN,
    "준중형":    BODY_SEDAN,
    "SUV":     BODY_SUV,
    "suv":     BODY_SUV,
    "RV":      BODY_SUV,
    "해치백":    BODY_HATCHBACK,
    "hatchback": BODY_HATCHBACK,
    "왜건":     BODY_WAGON,
    "wagon":   BODY_WAGON,
    "쿠페":     BODY_COUPE,
    "coupe":   BODY_COUPE,
    "스포츠카":   BODY_COUPE,
    "컨버터블":   BODY_CONVERTIBLE,
    "convertible": BODY_CONVERTIBLE,
    "밴":      BODY_VAN,
    "van":     BODY_VAN,
    "MPV":     BODY_VAN,
    "승합":     BODY_MINIVAN,
    "트럭":     BODY_TRUCK,
    "truck":   BODY_TRUCK,
    "카고":     BODY_CARGO,
    "경차":     BODY_KEI,
    "픽업트럭":   BODY_PICKUP,
    "픽업":     BODY_PICKUP,
}

KBCHA_MAKE: dict[str, str] = {
    "현대":       "Hyundai",
    "기아":       "Kia",
    "제네시스":    "Genesis",
    "르노코리아":  "Renault Korea",
    "쌍용":       "SsangYong",
    "KG모빌리티": "SsangYong",  # KG Mobility rebrand — map to the canonical legacy name
    "쉐보레":     "Chevrolet",
    "한국GM":     "Chevrolet",
    "BMW":       "BMW",
    "벤츠":       "Mercedes-Benz",
    "메르세데스-벤츠": "Mercedes-Benz",
    "아우디":     "Audi",
    "폭스바겐":    "Volkswagen",
    "볼보":       "Volvo",
    "랜드로버":    "Land Rover",
    "포르쉐":     "Porsche",
    "도요타":     "Toyota",
    "혼다":       "Honda",
    "닛산":       "Nissan",
    "렉서스":     "Lexus",
    "테슬라":     "Tesla",
    "링컨":       "Lincoln",
    "재규어":     "Jaguar",
    "마세라티":    "Maserati",
    "지프":       "Jeep",
    "닷지":       "Dodge",
    "포드":       "Ford",
    "푸조":       "Peugeot",
    "마쓰다":     "Mazda",
    "미쓰비시":    "Mitsubishi",
    "대우":       "Daewoo",
    "미니":       "MINI",
    "페라리":     "Ferrari",
    "벤틀리":     "Bentley",
    "롤스로이스":  "Rolls-Royce",
    "람보르기니": "Lamborghini",
}

# KBCha's internal numeric maker code → canonical name. Used when the
# marketplace returns ID references instead of Korean strings.
KBCHA_MAKER_CODE: dict[str, str] = {
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

# Shared color mapping (reused by both parsers — colors are user-visible labels).
COLOR_MAP: dict[str, str] = {
    # Blacks
    "검정색": COLOR_BLACK, "검정": COLOR_BLACK, "블랙": COLOR_BLACK,
    "오로라 블랙": COLOR_BLACK, "팬텀 블랙": COLOR_BLACK, "카본 블랙": COLOR_BLACK,
    "어비스 블랙": COLOR_BLACK,
    # Whites
    "흰색": COLOR_WHITE, "흰": COLOR_WHITE, "화이트": COLOR_WHITE, "백색": COLOR_WHITE,
    "진주색": COLOR_WHITE, "진주": COLOR_WHITE,
    "크리스탈 화이트": COLOR_WHITE, "퍼플리쉬 화이트": COLOR_WHITE, "어반 화이트": COLOR_WHITE,
    # Silvers
    "은색": COLOR_SILVER, "실버": COLOR_SILVER,
    "스파클링 실버": COLOR_SILVER, "문라이트 실버": COLOR_SILVER,
    "실버리 실버": COLOR_SILVER, "쉬머링 실버": COLOR_SILVER,
    # Grays
    "회색": COLOR_GRAY, "그레이": COLOR_GRAY, "그래파이트": COLOR_GRAY,
    "쥐색": COLOR_GRAY, "그라파이트 그레이": COLOR_GRAY,
    "빌트인 그레이": COLOR_GRAY, "메탈릭 그레이": COLOR_GRAY,
    "티타늄": COLOR_GRAY,
    # Blues
    "파란색": COLOR_BLUE, "파랑": COLOR_BLUE, "블루": COLOR_BLUE, "청색": COLOR_BLUE,
    "인텐스 블루": COLOR_BLUE, "네이비": COLOR_BLUE, "코발트 블루": COLOR_BLUE,
    "미드나잇 블루": COLOR_BLUE,
    # Reds
    "빨간색": COLOR_RED, "빨강": COLOR_RED, "레드": COLOR_RED, "적색": COLOR_RED,
    "버건디": COLOR_RED, "마룬": COLOR_RED, "와인": COLOR_RED,
    # Greens
    "녹색": COLOR_GREEN, "그린": COLOR_GREEN,
    "딥 포레스트 그린": COLOR_GREEN, "에머랄드 그린": COLOR_GREEN,
    "올리브 그린": COLOR_GREEN, "카키": COLOR_GREEN,
    # Browns / golds / others
    "갈색": COLOR_BROWN, "브라운": COLOR_BROWN,
    "금색": COLOR_GOLD, "골드": COLOR_GOLD, "샴페인": COLOR_GOLD, "브론즈": COLOR_GOLD,
    "주황색": COLOR_ORANGE, "오렌지": COLOR_ORANGE,
    "노란색": COLOR_YELLOW, "옐로우": COLOR_YELLOW,
    "보라색": COLOR_PURPLE, "퍼플": COLOR_PURPLE,
    "베이지": COLOR_BEIGE, "아이보리": COLOR_BEIGE,
    # Two-tone
    "투톤": COLOR_TWO_TONE, "듀얼톤": COLOR_TWO_TONE,
}
