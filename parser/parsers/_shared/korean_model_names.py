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
# Order matters: more specific entries should come before shorter/ambiguous ones.
_KR_TO_EN: dict[str, str] = {

    # ── Hyundai ──────────────────────────────────────────────────────────────
    "투싼": "Tucson",           # 투싼(NX4), 더 뉴 투싼, 투싼ix
    "투산": "Tucson",
    "아이오닉 9": "Ioniq 9",    # check longer strings first
    "아이오닉 6": "Ioniq 6",
    "아이오닉 5": "Ioniq 5",
    "아이오닉9": "Ioniq 9",
    "아이오닉6": "Ioniq 6",
    "아이오닉5": "Ioniq 5",
    "아이오닉": "Ioniq",
    "팰리세이드": "Palisade",
    "싼타페": "Santa Fe",
    "산타페": "Santa Fe",
    "쏘나타": "Sonata",
    "소나타": "Sonata",
    "그랜저": "Grandeur",
    "아반떼": "Avante",
    "아반테": "Avante",
    "스타리아": "Staria",
    "캐스퍼": "Casper",
    "넥쏘": "Nexo",
    "코나": "Kona",
    "베뉴": "Venue",
    "포터": "Porter",           # Porter II — light truck
    "스타렉스": "Starex",
    "맥스크루즈": "MaxCruze",
    "엑센트": "Accent",
    "벨로스터": "Veloster",
    "에쿠스": "Equus",
    "다이너스티": "Dynasty",
    "테라칸": "Terracan",
    "갤로퍼": "Galloper",
    "라비타": "Lavita",
    "아토스": "Atos",
    "티뷰론": "Tiburon",
    "베르나": "Verna",
    "클릭": "Click",
    "마이티": "Mighty",         # medium truck
    "유니버스": "Universe",      # bus
    "카운티": "County",          # minibus
    "솔라티": "Solati",          # van

    # ── Kia ──────────────────────────────────────────────────────────────────
    "스포티지": "Sportage",
    "쏘렌토": "Sorento",
    "카니발": "Carnival",
    "셀토스": "Seltos",
    "텔루라이드": "Telluride",
    "모하비": "Mohave",
    "스팅어": "Stinger",
    "니로": "Niro",
    "쏘울": "Soul",
    "카렌스": "Carens",
    "봉고": "Bongo",            # light truck/van
    "레이": "Ray",
    "모닝": "Morning",
    "프레지오": "Pregio",
    "레토나": "Retona",
    "크레도스": "Credos",
    "엔터프라이즈": "Enterprise",
    "스펙트라": "Spectra",
    "리오": "Rio",
    "비스토": "Visto",

    # ── Genesis ───────────────────────────────────────────────────────────────
    # Usually stored in English on Korean sites; ASCII fallback in resolve_model_en handles them.
    # Korean-script variants (rare but possible):
    "지브이80": "GV80",
    "지브이70": "GV70",
    "지브이60": "GV60",
    "지브이50": "GV50",

    # ── Chevrolet / GM Korea ──────────────────────────────────────────────────
    "트레일블레이저": "Trailblazer",
    "이쿼녹스": "Equinox",
    "트래버스": "Traverse",
    "콜로라도": "Colorado",
    "실버라도": "Silverado",
    "서버번": "Suburban",
    "타호": "Tahoe",
    "말리부": "Malibu",
    "트렉스": "Trax",
    "스파크": "Spark",
    "볼트 euv": "Bolt EUV",    # check before "볼트 ev"
    "볼트 ev": "Bolt EV",
    "볼트euv": "Bolt EUV",
    "볼트ev": "Bolt EV",
    "올란도": "Orlando",
    "캡티바": "Captiva",
    "크루즈": "Cruze",
    "마티즈": "Matiz",
    "라세티": "Lacetti",
    "토스카": "Tosca",
    "레조": "Rezzo",
    "임팔라": "Impala",

    # ── Renault Korea (르노코리아 / 삼성) ──────────────────────────────────────
    "아르카나": "Arkana",
    "그랑 콜레오스": "Grand Koleos",
    "그랑콜레오스": "Grand Koleos",
    "콜레오스": "Koleos",
    "조에": "Zoe",
    "마스터": "Master",
    "트위지": "Twizy",

    # ── KG Mobility / SsangYong (쌍용/KG모빌리티) ────────────────────────────
    "토레스 evx": "Torres EVX",
    "토레스": "Torres",
    "티볼리 에어": "Tivoli Air",
    "티볼리": "Tivoli",
    "코란도": "Korando",
    "렉스턴 스포츠": "Rexton Sports",
    "렉스턴": "Rexton",
    "무쏘": "Musso",
    "액티언": "Actyon",
    "로디우스": "Rodius",
    "스타빅": "Stavic",
    "체어맨": "Chairman",
    "이스타나": "Istana",

    # ── Foreign brands (Korean-script model names on KBCha/Encar) ────────────
    # BMW
    "삼십사": "3 Series",        # 3시리즈 / 34
    "오시리즈": "5 Series",
    "칠시리즈": "7 Series",
    "엑스오": "X5",
    # Mercedes
    "이클래스": "E-Class",
    "씨클래스": "C-Class",
    "에스클래스": "S-Class",
    # Toyota / Lexus
    "캠리": "Camry",
    "카롤라": "Corolla",
    "프리우스": "Prius",
    "아발론": "Avalon",
    "하이랜더": "Highlander",
    "라브4": "RAV4",
    "랜드크루저": "Land Cruiser",
    "알파드": "Alphard",
    # Honda
    "어코드": "Accord",
    "시빅": "Civic",
    "씨알브이": "CR-V",
    "파일럿": "Pilot",
    "오딧세이": "Odyssey",
    # Nissan / Infiniti
    "알티마": "Altima",
    "맥시마": "Maxima",
    "무라노": "Murano",
    "패스파인더": "Pathfinder",
    # Audi
    "에이포": "A4",
    "에이식스": "A6",
    "큐오": "Q5",
    "큐칠": "Q7",
    # Volkswagen
    "골프": "Golf",
    "파사트": "Passat",
    "티구안": "Tiguan",
    "투아렉": "Touareg",
}

# English alias substrings that map back to canonical name (for cases where
# model string contains English but in non-canonical casing/form).
_EN_ALIASES: dict[str, str] = {
    "elantra":   "Avante",
    "azera":     "Grandeur",
    "cadenza":   "K8",
    "optima":    "K5",
    "cerato":    "K3",
    "forte":     "K3",
    "picanto":   "Morning",
    "quoris":    "K9",
    "borrego":   "Mohave",
    "ssangyong": None,          # brand, not model — skip
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
        if alias in raw_lower and canonical is not None:
            return canonical

    # Already plain ASCII — return stripped original (Encar sometimes stores
    # model names directly in English, e.g. "K5", "EV6").
    if all(ord(c) < 128 for c in model_raw.strip()):
        return model_raw.strip() or None

    return None
