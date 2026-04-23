"""
KBChacha Korean domain glossary.
Single source of truth for ALL Korean ↔ English mappings, field keys,
panel codes and inspection constants used across all kbcha parsers.
"""
from __future__ import annotations

# ── Model name generation prefixes (stripped before extracting model) ──────────

GEN_PREFIXES: tuple[tuple[str, ...], ...] = (
    ("디", "올", "뉴"),
    ("더", "뉴"),
    ("올", "뉴"),
    ("디", "올"),
    ("뉴",),
)

MODEL_FUEL_STOP: frozenset[str] = frozenset({
    "디젤", "가솔린", "LPG", "LPi", "휘발유",
    "전기", "일렉트릭", "수소",
})

MODEL_TRIM_STOP: frozenset[str] = frozenset({
    # ── Korean trim names ───────────────────────────────────────────────────
    "프레스티지", "모던", "럭셔리", "스마트", "인스퍼레이션",
    "노블레스", "시그니처", "시그니쳐", "트렌디", "컴포트", "스타일",
    "프리미엄", "익스클루시브", "스탠다드", "밸류플러스",
    "캘리그래피", "그래비티", "GL", "GLS", "GLX",
    "고급형", "일반형", "기본형",
    # Hyundai/Kia trim labels seen in production data
    "르블랑", "에센셜", "라이트",
    "모던초이스", "블루세이버", "블랙잉크",
    "모던스페셜", "셀러브리티", "프리미어",
    "아너스", "모범형", "크로스",
    "케어플러스", "디럭스", "로얄",
    "익스트림", "아이언맨", "에디션",
    "유니크", "오리지널", "코어",
    "스펙", "수출형", "특장",
    "모던플러스", "셀렉션", "파이니스트",
    "얼티밋", "이그젝큐티브", "장애인용",
    # ── English / mixed-case trim labels ───────────────────────────────────
    "Value", "Premium", "Exclusive", "Luxury", "Style", "Smart",
    "Prestige", "Signature", "Modern", "Comfort", "Trend", "Special",
    "Standard", "Noblesse", "Plus",
    # Uppercase variants (older Hyundai/Santa Fe/Veracruz use ALL-CAPS trims)
    "VALUE", "PREMIUM", "EXCLUSIVE", "LUXURY", "SMART", "MODERN",
    # English trims used by specific models
    "VIP", "FLUX", "PYL", "DCT",
    "Turbo", "Extreme", "Premier",
    "Top", "Inspiration", "Business",
    "N20", "S16",
    "D-spec", "Line",
})

MODEL_DRIVE_STOP: frozenset[str] = frozenset({
    "AWD", "2WD", "4WD", "FWD", "RWD",
})

# Engine/powertrain descriptor tokens that legitimately appear between engine volume
# and trim — NOT trim names, NOT fuel stops, but known non-classifiable tokens
ENGINE_DESC_TOKENS: frozenset[str] = frozenset({
    "터보", "GDi", "T-GDi", "GDI", "MPI", "VGT", "CRDI", "CRDi",
    "HEV", "PHEV", "EV", "FCEV", "e-VGT",
    "VVT", "DOHC", "SOHC",
    "4기통", "6기통", "8기통", "V6", "V8", "V12",
    # Passenger-count tokens (N인승) — appear mid-title for vans/MPVs
    "7인승", "9인승", "11인승", "12인승", "15인승",
    "Sport", "SPORT", "스포츠",
    "하이브리드",
    # Model-variant alphanumeric codes (Veracruz 300X/300VX/300VXL, Grandeur HG300 etc.)
    "HG300", "300X", "300VX", "300VXL",
    # Commercial-vehicle body & configuration tokens (Porter2, Starex, Solati)
    "내장탑차", "저상내장탑차", "하이내장탑차",
    "파워게이트", "윙바디", "전동식윙바디",
    "장축", "더블캡", "슈퍼캡", "초장축",
    "슈퍼", "골드", "하이슈퍼",
    "어린이버스", "어린이보호차",
    "사업자용",
    # Audi-specific tokens to reduce unknown warnings
    "quattro", "TFSI", "TDI", "e-tron", "g-tron", "S", "RS", "Audi",
    "Sportback", "Avant", "allroad", "plus", "matrix", "virtual",
})

# Tokens that look like trims on the site but are actually fuel/type descriptors
TRIM_BLOCKLIST: frozenset[str] = frozenset({
    "전기차", "전기", "하이브리드", "하이브리드(가솔린)", "하이브리드(디젤)",
    "플러그인", "수소", "LPG", "디젤", "가솔린",
})

# ── Vocabulary aliases ──────────────────────────────────────────────────────
# Fuel/transmission/drive/body/make dicts now live in parsers/_shared/vocabulary.py
# (unified canonical lowercase values shared with Encar). Re-exported here only
# for back-compat so legacy `from .glossary import FUEL` imports keep working.
from .._shared import vocabulary as _V

FUEL        = _V.KBCHA_FUEL
TRANSMISSION = _V.KBCHA_TRANSMISSION
BODY_TYPE   = _V.KBCHA_BODY
COLOR       = _V.COLOR_MAP
DRIVE       = _V.KBCHA_DRIVE
MAKE_NAME   = _V.KBCHA_MAKE
MAKER_CODE  = _V.KBCHA_MAKER_CODE

# ── Info-table field mapping ──────────────────────────────────────────────────
# Korean label → (CarLot field name, normalizer method or None)
# normalizer method None means: store raw value as-is
# special values: "_parse_mileage", "_parse_year", "_tax_fn"

INFO_FIELDS: dict[str, tuple[str, str | None]] = {
    # Normalized (via normalizer method)
    "연료":   ("fuel",          "normalize_fuel"),
    "변속기":  ("transmission",  "normalize_transmission"),
    "차종":   ("body_type",     "normalize_body_type"),
    "배기량":  ("engine_volume", "parse_engine_cc"),
    "차량색상": ("color",        "normalize_color"),
    "구동":   ("drive_type",    "normalize_drive_type"),
    "연비":   ("fuel_economy",  "parse_fuel_economy"),
    # Parsed with custom logic (handled separately in _parse_info_table_fields)
    "연식":   ("year",          "_parse_year"),          # also sets registration_date
    "주행거리": ("mileage",       "_parse_mileage"),
    "소유자변경": ("owners_count", "_parse_owners"),       # e.g. '2회' → 2
    # Raw string fields
    "차량정보": ("plate_number",  None),   # main detail page label
    "차량번호": ("plate_number",  None),   # basic-info popup label
    "차대번호": ("vin",           None),
    "차시번호": ("vin",           None),
    "제시번호": ("_inspection_no", None),   # stored in raw_data only
    "시트색상": ("seat_color",    "normalize_color"),
    "저당":   ("lien_status",   "normalize_lien"),
    "압류":   ("seizure_status", "normalize_seizure"),
    "세금미납": ("tax_paid",      "_parse_tax"),
}

# ── History-section labels ────────────────────────────────────────────────────
# Korean label → CarLot bool field (value "없음" → False, else True)
HISTORY_BOOL_LABELS: dict[str, str] = {
    "전손이력": "total_loss_history",
    "침수이력": "flood_history",
}

# ── Mileage grade values ──────────────────────────────────────────────────────
MILEAGE_GRADE_PATTERN = r"주행거리.*?대비\s*(많이짧음|짧음|보통|긴|많이긴)"

# ── Warranty remaining pattern ────────────────────────────────────────────────
# Matches e.g. '42,990km / 1개월 남음' near '제조사 보증'
WARRANTY_PATTERN = r"(\d[\d,]*\s*km\s*/\s*\d+개월\s*남음|만료)"

# ── Paid optional packages pattern ───────────────────────────────────────────
# Matches section header then list items with price '패키지 380만원'
PAID_OPTIONS_PATTERN = r"선택옵션\s*[^\n]*\n([\s\S]{0,600}?)(?=\n\n|제조사|보증|이력|주요)"

# ── Inspection panel codes ────────────────────────────────────────────────────
PANEL_NAMES: dict[str, str] = {
    "1": "후드", "2": "프론트휀더", "3": "도어", "4": "트렁크리드",
    "5": "라디에이터서포트", "6": "쿼터패널", "7": "루프패널", "8": "사이드실패널",
    "9": "프론트패널", "10": "크로스멤버", "11": "인사이드패널",
    "12": "사이드멤버", "13": "휠하우스", "14": "필러패널",
    "15": "대쉬패널", "16": "플로어패널", "17": "트렁크플로어",
    "18": "리어패널", "19": "패키지트레이",
}

DAMAGE_SYMBOLS: dict[str, str] = {
    "X": "교환", "W": "판금/용접", "A": "흠집",
    "U": "요철", "C": "부식", "T": "손상",
}

OUTER_PANEL_CODES: frozenset[str] = frozenset({"1", "2", "3", "4", "5", "6", "7", "8"})
STRUCTURAL_PANEL_CODES: frozenset[str] = frozenset(
    {"9", "10", "11", "12", "13", "14", "15", "16", "17", "18", "19"}
)

# ── Inspection condition summary BC/DC constants ──────────────────────────────
# bc_data (setData('bc', ...)) keys
BC_SPECIAL_HISTORY_KEY = "5"   # special history: "1"=없음 "2"=있음
BC_FLOOD_KEY          = "41"   # flood sub-flag: "1"=있음
BC_FIRE_KEY           = "42"   # fire sub-flag:  "1"=있음
BC_TUNING_KEY         = "3"    # tuning: "1"=없음 "2"=있음

# Inspection HTML labels
INSP_LABEL_ACCIDENT      = "사고이력"
INSP_LABEL_FIRST_REG     = "최초등록일"
INSP_LABEL_FIRST_REG_NUM = "⑤"
INSP_LABEL_VALID_PERIOD  = "검사유효기간"
INSP_LABEL_VALID_PERIOD_NUM = "④"
INSP_LABEL_INSPECTOR     = "성능"          # part of "성능점검자" th
INSP_LABEL_INSPECTOR2    = "점검자"

# ── Cylinders hint pattern ────────────────────────────────────────────────────
CYLINDERS_PATTERN = r"(\d+)\s*기통"
