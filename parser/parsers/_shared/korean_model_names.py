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

    # ── BMW (check GT/Tourer variants before plain series) ───────────────────────
    "3시리즈 gt": "3 Series GT",
    "5시리즈 gt": "5 Series GT",
    "6시리즈 gt": "6 Series GT",
    "2시리즈 액티브 투어러": "2 Series Active Tourer",
    "1시리즈": "1 Series",
    "2시리즈": "2 Series",
    "3시리즈": "3 Series",
    "4시리즈": "4 Series",
    "5시리즈": "5 Series",
    "6시리즈": "6 Series",
    "7시리즈": "7 Series",
    "8시리즈": "8 Series",
    # BMW EVs (i3/i4/i7/iX) are usually ASCII on Korean sites → ASCII fallback handles them
    # ── Mercedes-Benz (most-specific class names first) ──────────────────────
    "cls-클래스": "CLS",
    "cla-클래스": "CLA",
    "gle-클래스": "GLE",
    "glc-클래스": "GLC",
    "gla-클래스": "GLA",
    "glb-클래스": "GLB",
    "gls-클래스": "GLS",
    "amg gt": "AMG GT",
    "slk-클래스": "SLK",
    "slc-클래스": "SLC",
    "sl-클래스": "SL",
    "g-클래스": "G-Class",
    "a-클래스": "A-Class",
    "b-클래스": "B-Class",
    "v-클래스": "V-Class",
    "e-클래스": "E-Class",
    "c-클래스": "C-Class",
    "s-클래스": "S-Class",
    "마이바흐": "Maybach",
    "eqe": "EQE",
    "eqs": "EQS",
    "eqb": "EQB",
    "eqc": "EQC",
    "eqa": "EQA",
    # ── Land Rover ────────────────────────────────────────────────────────
    "레인지로버 스포츠": "Range Rover Sport",
    "레인지로버 이보크": "Range Rover Evoque",
    "레인지로버 벨라": "Range Rover Velar",
    "레인지로버": "Range Rover",
    "디스커버리 스포츠": "Discovery Sport",
    "디스커버리 4": "Discovery 4",
    "디스커버리 5": "Discovery 5",
    "디스커버리": "Discovery",
    "디펜더": "Defender",
    "프리랜더": "Freelander",
    # ── MINI (check multi-word names before plain 쿠퍼) ──────────────────────────
    "쿠퍼 클럽맨": "Cooper Clubman",
    "쿠퍼 컨트리맨": "Cooper Countryman",
    "쿠퍼 컨버터블": "Cooper Convertible",
    "쿠퍼 로드스터": "Cooper Roadster",
    "쿠퍼 페이스맨": "Cooper Paceman",
    "쿠퍼": "Cooper",
    # ── Toyota ──────────────────────────────────────────────────────────
    "캠리": "Camry",
    "카롤라": "Corolla",
    "프리우스": "Prius",
    "아발론": "Avalon",
    "하이랜더": "Highlander",
    "라브4": "RAV4",
    "랜드크루저": "Land Cruiser",
    "알파드": "Alphard",
    "벨파이어": "Vellfire",
    "하리어": "Harrier",
    # ── Lexus (ASCII codes embedded in Korean trim strings) ─────────────────
    "ls460": "LS460",
    "ls500": "LS500",
    "ls600": "LS600",
    "nx300": "NX300",
    "nx200": "NX200",
    "nx350": "NX350",
    "es300": "ES300",
    "es350": "ES350",
    "es250": "ES250",
    "rx300": "RX300",
    "rx350": "RX350",
    "rx450": "RX450",
    "is250": "IS250",
    "is300": "IS300",
    "is350": "IS350",
    "gs300": "GS300",
    "gs350": "GS350",
    "gs450": "GS450",
    "lx570": "LX570",
    "lx600": "LX600",
    "ux250": "UX250",
    "lc500": "LC500",
    "ct200": "CT200h",
    # ── Honda ────────────────────────────────────────────────────────────
    "어코드": "Accord",
    "시빅": "Civic",
    "씨알브이": "CR-V",
    "파일럿": "Pilot",
    "오딧세이": "Odyssey",
    "재즈": "Jazz",
    # ── Nissan / Infiniti ───────────────────────────────────────────
    "알티마": "Altima",
    "맥시마": "Maxima",
    "무라노": "Murano",
    "패스파인더": "Pathfinder",
    "엑스트레일": "X-Trail",
    # ── Audi (Korean sites usually keep ASCII codes; add Korean phonetics only where certain) ──
    "에이포": "A4",
    "에이식스": "A6",
    "큐오": "Q5",
    "큐칠": "Q7",
    # ── Volkswagen ───────────────────────────────────────────────────────
    "골프": "Golf",
    "파사트": "Passat",
    "티구안": "Tiguan",
    "투아렉": "Touareg",
    "폴로": "Polo",
    "아르테온": "Arteon",
    # ── Lincoln ─────────────────────────────────────────────────────────
    "mkz": "MKZ",
    "mkx": "MKX",
    "mkc": "MKC",
    "mkt": "MKT",
    "내비게이터": "Navigator",
    "corsair": "Corsair",
    "nautilus": "Nautilus",
    # ── Dodge ────────────────────────────────────────────────────────────
    "챌린저": "Challenger",
    "차저": "Charger",
    "듀랑고": "Durango",
    "램 1500": "RAM 1500",
    # ── Porsche ──────────────────────────────────────────────────────────
    "카이엔": "Cayenne",
    "마칸": "Macan",
    "파나메라": "Panamera",
    "박스터": "Boxster",
    "카이만": "Cayman",
    "타이칸": "Taycan",
    # ── Cadillac ─────────────────────────────────────────────────────────
    "에스컬레이드": "Escalade",
    "xt5": "XT5",
    "xt6": "XT6",
    "ct6": "CT6",
    "ct5": "CT5",
    "ct4": "CT4",
    # ── Jeep ─────────────────────────────────────────────────────────────
    "랭글러": "Wrangler",
    "그랜드 체로키": "Grand Cherokee",
    "체로키": "Cherokee",
    "컴패스": "Compass",
    "레니게이드": "Renegade",
    "글래디에이터": "Gladiator",
    # ── Volvo ────────────────────────────────────────────────────────────
    "xc60": "XC60",
    "xc90": "XC90",
    "xc40": "XC40",
    "s90": "S90",
    "s60": "S60",
    "v90": "V90",
    "v60": "V60",
    "v40": "V40",
    "c30": "C30",
    # ── Renault Korea SM/QM/XM (ASCII codes in Korean trim strings) ──────────────
    "sm3": "SM3",
    "sm5": "SM5",
    "sm6": "SM6",
    "sm7": "SM7",
    "qm3": "QM3",
    "qm5": "QM5",
    "qm6": "QM6",
    "xm3": "XM3",
    # ── Genesis (additional) + Kia (additional) ──────────────────────────────
    "eq900": "G90",   # EQ900 = Genesis G90 older nameplate
    "k7": "K7",
    "프라이드": "Pride",
    "베스타": "Besta",
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
