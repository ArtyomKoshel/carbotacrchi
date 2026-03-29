"""
Unit tests for KBChaNormalizer.parse_title() and related helpers.

Run with:
    pytest parser/tests/test_kbcha_normalizer.py -v
"""
from __future__ import annotations

import pytest
from parsers.kbcha.normalizer import KBChaNormalizer

norm = KBChaNormalizer()


# ─── Helpers ──────────────────────────────────────────────────────────────────

def pt(title: str) -> dict:
    return norm.parse_title(title)


# ─── Prefix stripping ─────────────────────────────────────────────────────────

class TestPrefixStripping:
    def test_더_뉴(self):
        r = pt("현대 더 뉴 투싼 1.6T 2WD 인스퍼레이션")
        assert r["model"] == "투싼"

    def test_올_뉴(self):
        r = pt("기아 올 뉴 K5 2.0 가솔린 2WD 프레스티지")
        assert r["model"] == "K5"

    def test_디_올_뉴(self):
        r = pt("기아 디 올 뉴 스포티지 1.6T 4WD 프레스티지")
        assert r["model"] == "스포티지"

    def test_standalone_뉴(self):
        """KG모빌리티 uses bare '뉴' prefix — must not become part of model."""
        r = pt("KG모빌리티 뉴 코란도 2.2 4WD 스탠다드")
        assert r["model"] == "코란도"
        assert r["model"] != "뉴 코란도"

    def test_no_prefix(self):
        r = pt("제네시스 G80 3.5 T-GDi AWD")
        assert r["model"] == "G80"


# ─── Generation extraction ────────────────────────────────────────────────────

class TestGenerationExtraction:
    def test_parenthetical_gen(self):
        r = pt("제네시스 G80(RG3) 2.5 T-GDi AWD")
        assert r["generation"] == "RG3"
        assert r["model"] == "G80(RG3)"  # parenthetical stays in model string

    def test_parenthetical_numeric(self):
        r = pt("KG모빌리티 액티언(J100) 1.5 GDI 가솔린 T7")
        assert r["generation"] == "J100"
        assert r["model"] == "액티언(J100)"  # parenthetical stays in model string

    def test_numgen_세대(self):
        """3세대 / 4세대 should be extracted as generation, NOT left in model."""
        r = pt("기아 K5 3세대 1.6 가솔린 터보 시그니처")
        assert r["generation"] == "3세대"
        assert "3세대" not in r["model"]
        assert r["model"] == "K5"

    def test_numgen_with_trailing_model_token(self):
        """카니발 4세대 하이리무진 — 하이리무진 is model, 4세대 is generation."""
        r = pt("기아 카니발 4세대 하이리무진 G3.5 가솔린 9인승 시그니처")
        assert r["generation"] == "4세대"
        assert "4세대" not in r["model"]
        assert "카니발" in r["model"]
        assert "하이리무진" in r["model"]

    def test_no_single_letter_as_gen(self):
        """Single uppercase letter like 'C' in '코란도 스포츠 C' must NOT be generation."""
        r = pt("KG모빌리티 뉴 코란도 스포츠 C 2.2 2WD 프레스티지")
        assert r["generation"] != "C"

    def test_two_letter_code_not_gen_if_first(self):
        """Generation plain code needs i > 0; first token is never generation."""
        r = pt("기아 K5 2.0 가솔린 프레스티지")
        assert r["generation"] is None


# ─── Engine string extraction ─────────────────────────────────────────────────

class TestEngineStr:
    def test_basic(self):
        assert pt("현대 투싼 1.6T 2WD 프리미엄")["engine_str"] == "1.6T"

    def test_decimal_only(self):
        assert pt("제네시스 GV80 3.5 AWD")["engine_str"] == "3.5"

    def test_no_engine(self):
        assert pt("기아 EV6 2WD 스탠다드")["engine_str"] is None


# ─── Trim extraction ──────────────────────────────────────────────────────────

class TestTrimExtraction:
    def test_single_token_trim(self):
        assert pt("현대 투싼 1.6T 2WD 인스퍼레이션")["trim"] == "인스퍼레이션"

    def test_시그니처(self):
        assert pt("기아 스포티지 1.6T 2WD 시그니처")["trim"] == "시그니처"

    def test_시그니쳐_alias(self):
        """Alternative spelling must also be recognised."""
        assert pt("기아 스포티지 1.6T 2WD 시그니쳐")["trim"] == "시그니쳐"

    def test_그래비티(self):
        assert pt("기아 쏘렌토 1.6 HEV 4WD 그래비티")["trim"] == "그래비티"

    def test_multi_word_trim(self):
        r = pt("기아 K9 3.8 AWD 노블레스 스페셜")
        assert r["trim"] is not None
        assert r["trim"].startswith("노블레스")

    def test_trim_none_when_absent(self):
        assert pt("제네시스 G80(RG3) 2.5 T-GDi AWD")["trim"] is None

    def test_trim_blocklist_전기차(self):
        """'전기차' is fuel type, not a trim."""
        assert pt("제네시스 더 뉴 G70 2.0T 전기차")["trim"] is None

    def test_trim_blocklist_하이브리드(self):
        assert pt("현대 아이오닉 하이브리드 1.6 2WD 프리미엄")["trim"] == "프리미엄"

    def test_drive_token_stops_trim(self):
        """Drive token after trim start should not be included in trim."""
        r = pt("현대 팰리세이드 3.8 가솔린 4WD 캘리그래피")
        assert r["trim"] == "캘리그래피"
        assert "4WD" not in (r["trim"] or "")

    def test_gl_trim(self):
        r = pt("기아 쏘렌토 2.2 디젤 4WD GL")
        assert r["trim"] == "GL"


# ─── Drive type extraction ────────────────────────────────────────────────────

class TestDriveExtraction:
    def test_2wd_to_fwd(self):
        assert pt("기아 스포티지 1.6T 2WD 프레스티지")["drive"] == "FWD"

    def test_4wd_to_awd(self):
        assert pt("현대 투싼 1.6T 4WD 인스퍼레이션")["drive"] == "AWD"

    def test_awd_passthrough(self):
        assert pt("제네시스 GV80 3.5 AWD")["drive"] == "AWD"

    def test_no_drive(self):
        assert pt("제네시스 더 뉴 G70 2.0T 시그니처")["drive"] is None


# ─── Model integrity ──────────────────────────────────────────────────────────

class TestModelIntegrity:
    def test_model_keeps_parenthetical_gen(self):
        """Parenthetical gen codes stay in model string; only 세대 tokens are stripped."""
        r = pt("제네시스 G90(RS4) 3.5T AWD 프레스티지")
        assert r["generation"] == "RS4"
        assert r["model"] == "G90(RS4)"

    def test_model_no_drive_token(self):
        r = pt("현대 팰리세이드 3.8 AWD 캘리그래피")
        assert "AWD" not in r["model"]

    def test_model_no_trim_token(self):
        r = pt("현대 아반떼 1.6 가솔린 2WD 프리미엄")
        assert "프리미엄" not in r["model"]

    def test_model_no_engine_token(self):
        r = pt("현대 소나타 2.0 가솔린 2WD 모던")
        assert "2.0" not in r["model"]


# ─── Dirty / malformed input ─────────────────────────────────────────────────

class TestDirtyInputs:
    def test_comma_separated(self):
        r = pt("투싼,,,,,,1.6T,,,,하이브리드")
        assert r["model"] == "투싼"
        assert r["engine_str"] == "1.6T"

    def test_tab_and_newline(self):
        r = pt("쏘렌토\n디젤\t2.2")
        assert r["model"] == "쏘렌토"
        assert r["engine_str"] == "2.2"

    def test_square_bracket_noise_stripped(self):
        r = pt("[무사고] 현대 투싼 1.6T 2WD 프레스티지")
        assert r["model"] == "투싼"
        assert r["trim"] == "프레스티지"
        assert r["drive"] == "FWD"

    def test_garbage_symbols_between_tokens(self):
        """@ and # between tokens must become spaces, not merge tokens.
        하이브리드 IS part of Korean model names (그랜저 하이브리드 etc.) so stays in model."""
        r = pt("그랜저@@@하이브리드###1.6")
        assert r["model"] == "그랜저 하이브리드"  # tokens correctly separated
        assert r["engine_str"] == "1.6"           # engine extracted from the mess

    def test_extra_spaces(self):
        r = pt("  코나   전기   모던   ")
        assert r["model"] == "코나"
        assert r["trim"] == "모던"

    def test_empty_string(self):
        r = pt("")
        assert r["model"] == ""

    def test_all_garbage(self):
        r = pt("@@@###$$$★★★")
        assert r["model"] == ""

    def test_make_after_noise(self):
        """Make name within first 15 chars after noise should still be stripped."""
        r = pt("특A급 현대 아반떼 1.6 2WD 모던")
        assert "현대" not in r["model"]
        assert "아반떼" in r["model"]


# ─── Real-world Korean title patterns ────────────────────────────────────────

class TestRealWorldCases:
    def test_hybrid_with_paren_gen(self):
        """투싼 하이브리드(NX4) — generation extracted from parenthetical in fuel token."""
        r = pt("현대 투싼 하이브리드(NX4) 1.6T")
        assert r["generation"] == "NX4"
        assert r["engine_str"] == "1.6T"
        assert "투싼" in r["model"]

    def test_gen_plain_two_letter(self):
        """아반떼 CN7 — two-letter+digit code at i>0 detected as generation."""
        r = pt("현대 아반떼 CN7 가솔린 1.6")
        assert r["model"] == "아반떼 CN7"
        assert r["generation"] == "CN7"
        assert r["engine_str"] == "1.6"

    def test_gen_plain_two_letter_no_digit(self):
        """싼타페 TM — two-letter code at i>0 detected as generation."""
        r = pt("현대 싼타페 TM 디젤 2.2")
        assert r["model"] == "싼타페 TM"
        assert r["generation"] == "TM"
        assert r["engine_str"] == "2.2"

    def test_gen_mq4_with_trim_and_drive(self):
        r = pt("기아 스포티지 MQ4 2.0T 4WD 시그니처")
        assert r["model"] == "스포티지 MQ4"
        assert r["generation"] == "MQ4"
        assert r["trim"] == "시그니처"
        assert r["drive"] == "AWD"

    def test_ev_model_fuel_stop(self):
        """일렉트릭 must stop model extraction — model is just 코나."""
        r = pt("코나 일렉트릭 모던")
        assert r["model"] == "코나"
        assert r["trim"] == "모던"

    def test_전기_fuel_stop(self):
        """전기 must stop model extraction."""
        r = pt("  코나   전기   모던   ")
        assert r["model"] == "코나"
        assert r["trim"] == "모던"

    def test_완전무사고_as_unknown(self):
        """Dealer noise '완전무사고' should surface in unknown_tokens, not pollute model."""
        r = pt("현대 투싼 NX4 하이브리드 1.6T 완전무사고")
        assert "완전무사고" not in r["model"]
        unknowns = r["unknown_tokens"] or []
        assert "완전무사고" in unknowns


# ─── Fuzz — no crash, invariants hold ────────────────────────────────────────

import random

_FUZZ_MAKES  = ["현대", "기아", "제네시스", "KG모빌리티", ""]
_FUZZ_MODELS = ["투싼", "그랜저", "쏘렌토", "아반떼", "코나", "팰리세이드", "카니발", "스포티지"]
_FUZZ_GENS   = ["NX4", "CN7", "TM", "MQ4", "IG", "SP2", "3세대", "4세대", None]
_FUZZ_FUELS  = ["디젤", "가솔린", "하이브리드", "전기", None]
_FUZZ_ENG    = ["1.6", "2.0", "2.2", "1.6T", "2.0T", None]
_FUZZ_DRIVES = ["2WD", "4WD", "AWD", None]
_FUZZ_TRIMS  = ["프레스티지", "모던", "인스퍼레이션", "시그니처", None]
_FUZZ_NOISE  = ["[무사고]", "[특A급]", "완전무사고", ""]


def _make_fuzz_title() -> str:
    parts: list[str] = []
    noise = random.choice(_FUZZ_NOISE)
    if noise:
        parts.append(noise)
    make = random.choice(_FUZZ_MAKES)
    if make:
        parts.append(make)
    parts.append(random.choice(_FUZZ_MODELS))
    gen = random.choice(_FUZZ_GENS)
    if gen:
        parts.append(f"({gen})" if random.random() > 0.5 else gen)
    fuel = random.choice(_FUZZ_FUELS)
    if fuel:
        parts.append(fuel)
    eng = random.choice(_FUZZ_ENG)
    if eng:
        parts.append(eng)
    drive = random.choice(_FUZZ_DRIVES)
    if drive:
        parts.append(drive)
    trim = random.choice(_FUZZ_TRIMS)
    if trim:
        parts.append(trim)
    noise2 = random.choice(_FUZZ_NOISE)
    if noise2:
        parts.append(noise2)
    return " ".join(parts)


def test_fuzz_no_crash():
    random.seed(42)
    for i in range(500):
        title = _make_fuzz_title()
        try:
            r = norm.parse_title(title)
        except Exception as exc:
            raise AssertionError(f"parse_title crashed on iteration {i}: {title!r}") from exc

        assert isinstance(r["model"], str), f"model not str: {title!r}"
        if r["engine_str"] is not None:
            assert any(c.isdigit() for c in r["engine_str"]), \
                f"engine_str has no digit: {r['engine_str']!r} in {title!r}"
        if r["drive"] is not None:
            assert r["drive"] in ("AWD", "FWD", "RWD"), \
                f"unexpected drive value: {r['drive']!r} in {title!r}"
        assert r["unknown_tokens"] is None or isinstance(r["unknown_tokens"], list), \
            f"unknown_tokens wrong type in {title!r}"


# ─── Unknown token detection ─────────────────────────────────────────────────

class TestUnknownTokens:
    def test_clean_title_no_unknowns(self):
        """Well-formed title should produce no unknown tokens."""
        r = pt("현대 투싼 1.6T 4WD 인스퍼레이션")
        assert r["unknown_tokens"] is None

    def test_known_engine_desc_not_unknown(self):
        """터보, HEV etc. must NOT appear in unknown_tokens."""
        r = pt("기아 K5 1.6 터보 HEV 2WD 프레스티지")
        unknowns = r["unknown_tokens"] or []
        assert "터보" not in unknowns
        assert "HEV" not in unknowns

    def test_genuinely_unknown_token_flagged(self):
        """A new trim name not in any glossary must surface in unknown_tokens."""
        r = pt("현대 아반떼 1.6 가솔린 2WD 어드밴스드")
        assert r["unknown_tokens"] is not None
        assert "어드밴스드" in r["unknown_tokens"]

    def test_unknown_tokens_none_when_trim_captured(self):
        """If the unknown token IS already captured as trim, it must not also
        appear in unknown_tokens."""
        r = pt("현대 투싼 1.6T 2WD 프레스티지")
        assert r["trim"] == "프레스티지"
        unknowns = r["unknown_tokens"] or []
        assert "프레스티지" not in unknowns

    def test_generation_not_unknown(self):
        """N세대 generation tokens must not appear in unknown_tokens."""
        r = pt("기아 K5 3세대 1.6 가솔린 시그니처")
        unknowns = r["unknown_tokens"] or []
        assert "3세대" not in unknowns


# ─── normalize_color ──────────────────────────────────────────────────────────

class TestNormalizeColor:
    def test_simple(self):
        assert norm.normalize_color("검정색") == "Black"
        assert norm.normalize_color("흰색") == "White"
        assert norm.normalize_color("실버") == "Silver"

    def test_compound_with_parens(self):
        """투톤(흰색,검정색) → base '투톤' → 'Two-tone'."""
        assert norm.normalize_color("투톤(흰색,검정색)") == "Two-tone"

    def test_fancy_color_with_parens(self):
        """스파클링 실버(블랙루프) → base '스파클링 실버' → Silver."""
        assert norm.normalize_color("스파클링 실버(블랙루프)") == "Silver"

    def test_unknown_returns_original(self):
        val = norm.normalize_color("퀀텀 블루")
        assert val == "퀀텀 블루"

    def test_none(self):
        assert norm.normalize_color(None) is None


# ─── parse_engine_cc ──────────────────────────────────────────────────────────

class TestParseEngineCc:
    def test_normal_cc(self):
        assert norm.parse_engine_cc("1598cc") == 1.6
        assert norm.parse_engine_cc("2497cc") == 2.5
        assert norm.parse_engine_cc("3778cc") == 3.8

    def test_zero_cc_returns_none(self):
        """EV/bad data returning 0cc must not produce engine_vol=0.0."""
        assert norm.parse_engine_cc("0") is None
        assert norm.parse_engine_cc("0cc") is None

    def test_100cc_returns_none(self):
        """100cc is an EV placeholder on KBCha, must be None."""
        assert norm.parse_engine_cc("100") is None
        assert norm.parse_engine_cc("100cc") is None

    def test_none_input(self):
        assert norm.parse_engine_cc(None) is None

    def test_empty_string(self):
        assert norm.parse_engine_cc("") is None
