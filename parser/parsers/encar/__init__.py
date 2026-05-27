from __future__ import annotations

import logging
import re as _re
import time as _time
import json as _json
import os as _os
from datetime import datetime as _dt, timezone as _tz, timedelta as _td
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Callable

import httpx
try:
    import pymysql as _pymysql
    from pymysql.cursors import DictCursor as _DictCursor
except Exception:  # pragma: no cover - parser may run in reduced env for tests
    _pymysql = None
    _DictCursor = None

from config import Config
from models import CarLot, InspectionRecord
from repository import LotRepository
from ..base import AbstractParser, ProgressUpdate
from .._shared import sell_type as _sell
from .._shared.korean_model_names import resolve_model_en
from .client import EncarClient, ProxyBudgetExhausted, _generate_floppy_proxies, _reset_proxy_cache, check_floppy_balance
from .normalizer import EncarNormalizer

logger = logging.getLogger(__name__)

_SOURCE = "encar"
_PAGE_SIZE = 100
_BATCH_SIZE = 20   # batch_details API hard-caps at 20 items
_MAX_SAFE_OFFSET = 9900  # Encar search API (Elasticsearch) caps at ~10k results per query

_ENGINE_TOKEN_RE = _re.compile(r'^\d{1,2}(?:\.\d+)?(?:T|D|L)?$', _re.IGNORECASE)
_GEN_PAREN_RE = _re.compile(r'\(([A-Za-z0-9]{2,6})\)')
_GEN_TOKEN_RE = _re.compile(r'^[A-Z]{1,3}\d{1,3}$')
_SEAT_TOKEN_RE = _re.compile(r'^\d{1,2}인승$')

_DEFAULT_ENGINE_FAMILY_TOKENS: frozenset[str] = frozenset({
    'GDI', 'T-GDI', 'TGDI', 'GDE',
    'MPI', 'MPFI',
    'TFSI', 'TSI', 'FSI',
    'TDI', 'CRDI', 'VGT', 'TCI', 'E-VGT',
    'FHEV', 'HEV',
    'LPI', 'LPE',
    'EV', 'BEV',
    '4MATIC', '블루텍', 'GTE', 'LPLI', '4TRONIC',
})

_DEFAULT_TAIL_POWERTRAIN_TOKENS: frozenset[str] = frozenset({
    '가솔린', '디젤', '하이브리드', 'HEV', 'LPG', '전기', 'EV',
    '2WD', '4WD', 'AWD', 'FWD', 'RWD', 'xDrive', 'sDrive',
    '터보', 'TCe', 'TFSI', 'TDI', 'e-VGT', '(택시형)', '(렌터카)', '(영업용)',
    'GDI', 'T-GDI', 'GDe', 'GDi', 'MPI', 'TSI', 'FSI',
    'CRDi', 'VGT', 'TCI', 'FHEV', 'LPI', 'LPi', 'BEV',
    '4MATIC', '블루텍', 'GTe', 'LPLI', 'LPe', '4TRONIC', '콰트로',
})

_DEFAULT_GEN_NON_CHASSIS_TOKENS: frozenset[str] = frozenset({
    'EV', 'HEV', 'PHEV', 'GDI', 'TDI', 'TFSI', 'MPI',
    'AWD', 'FWD', 'RWD', '4WD', '2WD',
    # Genesis model codes (look like chassis codes but are model names)
    'G70', 'G80', 'G90', 'GV70', 'GV80', 'GV90', 'EQ900',
    # Volvo model codes
    'XC40', 'XC60', 'XC90', 'S60', 'S90', 'V60', 'V90', 'C40',
    # Volvo engine designations (D=diesel, T=petrol, B=mild-hybrid)
    'D3', 'D4', 'D5', 'T4', 'T5', 'T6', 'T8', 'B4', 'B5', 'B6',
})

_DEFAULT_GEN_EXCLUDE_TOKENS: frozenset[str] = frozenset({
    'V6', 'V8', 'V10', 'V12',
    'Q4',
    'VS380', 'CW700', 'EL300', 'G330',
    # Genesis engine grade codes (G + displacement): NOT generation codes
    'G300', 'G350', 'G380', 'G400', 'G450',
    # Hyundai Grandeur engine+gen combined tokens
    'HG300', 'HG330',
})

_VARIANT_RE = _re.compile(r'^(?:[A-Z]{1,4}\d{2,4}[a-zA-Z]{0,2}|\d{2,4}[A-Za-z]{1,3})$')

_DEFAULT_VARIANT_EXCLUDE: frozenset[str] = frozenset({
    'EV', 'BEV', 'HEV', 'FHEV',
    'GDI', 'TDI', 'MPI', 'CRDI', 'VGT', 'TCI', 'TFSI', 'TSI', 'FSI',
    'GDE', 'GTE', 'LPLI', 'LPE', 'LPI',
    '4WD', '2WD', 'AWD', 'FWD', 'RWD',
    'AWD4', 'V6', 'V8', 'V10', 'V12',
    'TCE', 'LPG',
    # Genesis model codes
    'G70', 'G80', 'G90', 'GV70', 'GV80', 'GV90', 'EQ900',
    # Volvo model codes
    'XC40', 'XC60', 'XC90', 'S60', 'S90', 'V60', 'V90', 'C40',
})

_DEFAULT_SPECIAL_TAGS: frozenset[str] = frozenset({'장애인용', '리무진', '캠핑카'})

_DEFAULT_PACKAGE_HINTS: tuple[str, ...] = (
    'M 스포츠 플러스', 'M 퍼포먼스', 'M 스포츠',
    'AMG Line', 'GT Line', 'N Line', 'S line', 'xLine',
)

_DEFAULT_TRIM_HINTS: tuple[str, ...] = (
    '캘리그래피 블랙에디션', '마스터즈 그래비티', '익스클루시브 스페셜',
    '프레스티지 스페셜', '노블레스 스페셜', '프리미엄 초이스',
    '디자인 퓨어 엑셀런스',
    '캘리그래피', '인스퍼레이션', '익스클루시브', '프레스티지', '시그니처',
    '노블레스', '프리미엄', '모던', '스마트', '럭셔리',
    '르블랑', '고급형', '기본형', '비즈니스 2', '비즈니스 1', '모빌리티',
    '리미티드', '아방가르드', '그란루쏘', '엘레강스',
    'RE Plus', 'LE Plus', 'SE Plus', 'PE Plus',
    'RE', 'LE', 'SE', 'PE',
    'SVR', 'SVX', 'VX',
)

_UNKNOWN_TAIL_HINT_RE = _re.compile(r'(에디션|라인|스페셜|패키지|플러스|스타일|셀렉션)$')
_MODEL_PREFIX_RE = _re.compile(r'^(?:더\s+뉴|더|올\s+뉴|올뉴|뉴|신형)\s+')
_ANOMALY_SEEN_MAX = 20_000
_ANOMALY_SEEN_TTL_SEC = 6 * 60 * 60
_anomaly_seen: dict[str, float] = {}
_TAX_RULES_RELOAD_SEC = 60.0
_tax_rules_cache: list[dict] = []
_tax_rules_loaded_at: float = 0.0

_TERMS_RELOAD_SEC = 60.0
_tax_terms_cache: dict[str, list[str]] = {}
_tax_terms_loaded_at: float = 0.0


def _anomaly_file_path() -> str:
    if Config.PARSER_ANOMALY_FILE:
        return Config.PARSER_ANOMALY_FILE
    base = Config.LOG_FILE or '/app/logs/parser.log'
    return _os.path.join(_os.path.dirname(base), 'taxonomy_anomalies.jsonl')


def _build_anomaly_key(payload: dict) -> str:
    return '|'.join([
        str(payload.get('source') or ''),
        str(payload.get('model_raw') or ''),
        str(payload.get('unknown_tail') or ''),
        str(payload.get('reason') or ''),
    ])


def _utc3_now_iso() -> str:
    tz = _tz(_td(hours=3))
    return _dt.now(tz=tz).isoformat(timespec='seconds')


def _estimate_lines(path: str) -> int:
    try:
        with open(path, 'rb') as fp:
            return sum(buf.count(b'\n') for buf in iter(lambda: fp.read(1024 * 1024), b''))
    except OSError:
        return 0


def _rotate_anomaly_file(path: str) -> None:
    try:
        if not _os.path.exists(path):
            return
        stamp = _dt.now().strftime('%Y%m%d-%H%M%S')
        rotated = path.replace('.jsonl', f'-{stamp}.jsonl')
        _os.replace(path, rotated)
    except OSError as e:
        logger.warning(f"[{_SOURCE}] cannot rotate taxonomy anomaly log: {e}")


def _is_duplicate_anomaly(payload: dict) -> bool:
    now = _time.time()
    key = _build_anomaly_key(payload)

    seen_at = _anomaly_seen.get(key)
    if seen_at and (now - seen_at) < _ANOMALY_SEEN_TTL_SEC:
        return True

    if len(_anomaly_seen) >= _ANOMALY_SEEN_MAX:
        stale_cutoff = now - _ANOMALY_SEEN_TTL_SEC
        stale_keys = [k for k, ts in _anomaly_seen.items() if ts < stale_cutoff]
        for k in stale_keys[: max(1, len(stale_keys))]:
            _anomaly_seen.pop(k, None)
        if len(_anomaly_seen) >= _ANOMALY_SEEN_MAX:
            # fallback bounded growth protection
            _anomaly_seen.clear()

    _anomaly_seen[key] = now
    return False


def ensure_anomaly_file_exists() -> None:
    path = _anomaly_file_path()
    try:
        _os.makedirs(_os.path.dirname(path), exist_ok=True)
        if not _os.path.exists(path):
            with open(path, 'a', encoding='utf-8'):
                pass
    except OSError as e:
        logger.warning(f"[{_SOURCE}] cannot initialize taxonomy anomaly log file: {e}")


def _append_taxonomy_anomaly(payload: dict) -> None:
    if _is_duplicate_anomaly(payload):
        return

    path = _anomaly_file_path()
    try:
        _os.makedirs(_os.path.dirname(path), exist_ok=True)
        if _os.path.exists(path):
            st = _os.stat(path)
            if st.st_size >= Config.PARSER_ANOMALY_MAX_BYTES:
                _rotate_anomaly_file(path)
            if _estimate_lines(path) >= Config.PARSER_ANOMALY_MAX_LINES:
                _rotate_anomaly_file(path)
        if not _os.path.exists(path):
            with open(path, 'a', encoding='utf-8'):
                pass
        with open(path, 'a', encoding='utf-8') as fp:
            fp.write(_json.dumps(payload, ensure_ascii=False, default=str))
            fp.write('\n')
    except OSError as e:
        logger.warning(f"[{_SOURCE}] cannot write taxonomy anomaly log: {e}")


def _load_taxonomy_terms() -> dict[str, list[str]]:
    global _tax_terms_cache, _tax_terms_loaded_at
    now = _time.monotonic()
    if _tax_terms_cache and now - _tax_terms_loaded_at < _TERMS_RELOAD_SEC:
        return _tax_terms_cache

    if _pymysql is None or _DictCursor is None:
        return _tax_terms_cache

    try:
        conn = _pymysql.connect(
            host=Config.DB_HOST,
            port=Config.DB_PORT,
            user=Config.DB_USERNAME,
            password=Config.DB_PASSWORD,
            database=Config.DB_DATABASE,
            charset='utf8mb4',
            cursorclass=_DictCursor,
            autocommit=True,
        )
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT term_type, term FROM taxonomy_terms
                WHERE is_active = 1 AND (source = 'encar' OR source = '*')
                ORDER BY priority ASC, id ASC
                """
            )
            result: dict[str, list[str]] = {}
            for row in cur.fetchall():
                t = (row['term'] or '').strip()
                if t:
                    result.setdefault(row['term_type'], []).append(t)
            _tax_terms_cache = result
            _tax_terms_loaded_at = now
        conn.close()
    except Exception as e:  # pragma: no cover
        logger.debug(f"[{_SOURCE}] taxonomy terms load skipped: {type(e).__name__}: {e}")

    return _tax_terms_cache


def _get_tail_powertrain_tokens() -> frozenset[str]:
    db = frozenset(_load_taxonomy_terms().get('tail_powertrain_token', []))
    return _DEFAULT_TAIL_POWERTRAIN_TOKENS | db


def _get_engine_family_tokens() -> frozenset[str]:
    db = frozenset(t.upper() for t in _load_taxonomy_terms().get('engine_family_tokens', []))
    return _DEFAULT_ENGINE_FAMILY_TOKENS | db


def _get_gen_non_chassis_tokens() -> frozenset[str]:
    db = frozenset(t.upper() for t in _load_taxonomy_terms().get('gen_non_chassis_token', []))
    return _DEFAULT_GEN_NON_CHASSIS_TOKENS | db


def _get_gen_exclude_tokens() -> frozenset[str]:
    db = frozenset(t.upper() for t in _load_taxonomy_terms().get('gen_exclude_token', []))
    return _DEFAULT_GEN_EXCLUDE_TOKENS | db


def _get_variant_exclude() -> frozenset[str]:
    db = frozenset(t.upper() for t in _load_taxonomy_terms().get('variant_exclude', []))
    return _DEFAULT_VARIANT_EXCLUDE | db


def _get_special_tags() -> frozenset[str]:
    db = frozenset(_load_taxonomy_terms().get('special_tag', []))
    return _DEFAULT_SPECIAL_TAGS | db


def _get_package_hints() -> tuple[str, ...]:
    db = sorted(
        _load_taxonomy_terms().get('package_hint', []),
        key=len, reverse=True,
    )
    seen = set(_DEFAULT_PACKAGE_HINTS)
    extra = tuple(t for t in db if t not in seen)
    return extra + _DEFAULT_PACKAGE_HINTS


def _get_trim_hints() -> tuple[str, ...]:
    db = sorted(
        _load_taxonomy_terms().get('trim_hint', []),
        key=len, reverse=True,
    )
    seen = set(_DEFAULT_TRIM_HINTS)
    extra = tuple(t for t in db if t not in seen)
    return extra + _DEFAULT_TRIM_HINTS


def _load_taxonomy_rules() -> list[dict]:
    global _tax_rules_cache, _tax_rules_loaded_at
    now = _time.monotonic()
    if _tax_rules_cache and now - _tax_rules_loaded_at < _TAX_RULES_RELOAD_SEC:
        return _tax_rules_cache

    if _pymysql is None or _DictCursor is None:
        return _tax_rules_cache

    try:
        conn = _pymysql.connect(
            host=Config.DB_HOST,
            port=Config.DB_PORT,
            user=Config.DB_USERNAME,
            password=Config.DB_PASSWORD,
            database=Config.DB_DATABASE,
            charset='utf8mb4',
            cursorclass=_DictCursor,
            autocommit=True,
        )
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT source, make, model_contains, unknown_tail, action, action_value
                FROM taxonomy_rules
                WHERE is_active = 1
                ORDER BY priority ASC, id ASC
                """
            )
            _tax_rules_cache = [dict(r) for r in cur.fetchall()]
            _tax_rules_loaded_at = now
        conn.close()
    except Exception as e:  # pragma: no cover - must never break parser run
        logger.debug(f"[{_SOURCE}] taxonomy rules load skipped: {type(e).__name__}: {e}")

    return _tax_rules_cache


def _rule_matches(rule: dict, ctx: dict) -> bool:
    source = (rule.get('source') or '').strip()
    if source and source != '*' and source != ctx.get('source'):
        return False

    make = (rule.get('make') or '').strip().lower()
    if make and make != (ctx.get('make') or '').strip().lower():
        return False

    model_contains = (rule.get('model_contains') or '').strip().lower()
    if model_contains and model_contains not in (ctx.get('model_raw') or '').strip().lower():
        return False

    tail = (rule.get('unknown_tail') or '').strip().lower()
    if tail and tail != (ctx.get('unknown_tail') or '').strip().lower():
        return False

    return True


def _apply_taxonomy_rules(
    source: str,
    make: str,
    model_raw: str,
    model: str,
    generation: str | None,
    trim: str | None,
    unknown_tail: str | None,
    badge_raw: str = '',
) -> tuple[str, str | None, str | None, str | None, str | None, str | None, str | None]:
    full_search = f"{model_raw} {badge_raw}".strip() if badge_raw else model_raw
    state = {
        'source': source,
        'make': make or '',
        'model_raw': full_search,
        'model': model or '',
        'generation': generation,
        'trim': trim,
        'unknown_tail': unknown_tail,
        'fuel': None,
        'drive_type': None,
        'variant': None,
    }

    for rule in _load_taxonomy_rules():
        if not _rule_matches(rule, state):
            continue

        action = (rule.get('action') or '').strip()
        value = (rule.get('action_value') or '').strip()

        if action == 'set_trim':
            if not state['trim']:
                trim_val = value or state['unknown_tail']
                state['trim'] = trim_val
                state['unknown_tail'] = None
                if trim_val:
                    suffix = f" {trim_val}"
                    if state['model'].endswith(suffix):
                        state['model'] = state['model'][:-len(suffix)].strip()
        elif action == 'set_generation':
            if not state['generation'] and value:
                state['generation'] = value
        elif action == 'set_fuel':
            if state['fuel'] is None and value:
                state['fuel'] = value
        elif action == 'set_drive_type':
            if state['drive_type'] is None and value:
                state['drive_type'] = value
        elif action == 'set_variant':
            if state['variant'] is None and value:
                state['variant'] = value
        elif action == 'strip_tail':
            target = value or (state['unknown_tail'] or '')
            if target:
                suffix = f" {target}"
                if state['model'].endswith(suffix):
                    state['model'] = state['model'][:-len(suffix)].strip()
                if state['unknown_tail'] and state['unknown_tail'].lower() == target.lower():
                    state['unknown_tail'] = None
        elif action == 'replace_model':
            if value:
                state['model'] = value

    return (
        state['model'], state['generation'], state['trim'], state['unknown_tail'],
        state['fuel'], state['drive_type'], state['variant'],
    )


def _clean_tech_spec_tokens_from_model(model: str) -> str:
    """Remove engine-family, engine-volume, and powertrain tokens from all positions in model string."""
    engine_family = _get_engine_family_tokens()
    tail_tokens = _get_tail_powertrain_tokens()
    tokens = model.split()
    clean = []
    for t in tokens:
        if t.upper() in engine_family:
            continue
        if t in tail_tokens:
            continue
        if _ENGINE_TOKEN_RE.match(t):
            continue
        clean.append(t)
    result = ' '.join(clean).strip()
    return result if result else model


def _extract_variant(model: str) -> tuple[str, str | None]:
    """Find and extract a variant code (e.g. E300, 730Ld) from model string."""
    variant_exclude = _get_variant_exclude()
    tokens = model.split()
    for i, tok in enumerate(tokens):
        if tok.upper() in variant_exclude:
            continue
        if _VARIANT_RE.match(tok):
            variant = tok
            remaining = tokens[:i] + tokens[i + 1:]
            cleaned = ' '.join(remaining).strip()
            return (cleaned if cleaned else model), variant
    return model, None


def _extract_engine_volume(text: str) -> float | None:
    """Scan full string for engine volume token (e.g. 2.0, 1.6T, 3.5)."""
    for tok in text.split():
        m = _re.match(r'^(\d+(?:\.\d+)?)(?:T|D|L)?$', tok, _re.IGNORECASE)
        if m:
            try:
                vol = float(m.group(1))
                if 0.5 <= vol <= 10.0:
                    return round(vol, 1)
            except ValueError:
                pass
    return None


def _strip_tail_noise(model: str) -> tuple[str, list[str]]:
    tail_tokens = _get_tail_powertrain_tokens()
    tokens = model.split()
    stripped: list[str] = []
    while tokens:
        tail = tokens[-1]
        if (
            tail in tail_tokens
            or _ENGINE_TOKEN_RE.match(tail)
            or _SEAT_TOKEN_RE.match(tail)
        ):
            stripped.insert(0, tokens.pop())
            continue
        break
    return ' '.join(tokens).strip(), stripped


def _extract_tech_specs_from_tokens(tokens: list[str], norm: EncarNormalizer) -> dict:
    specs: dict = {}
    for tok in tokens:
        if 'fuel' not in specs:
            f = norm.fuel(tok)
            if f:
                specs['fuel'] = f
        if 'drive_type' not in specs:
            d = norm.drive(tok)
            if d:
                specs['drive_type'] = d
        if 'engine_volume' not in specs and _ENGINE_TOKEN_RE.match(tok):
            try:
                vol = float(_re.sub(r'[TDLtdl]$', '', tok))
                if 0.5 <= vol <= 10.0:
                    specs['engine_volume'] = round(vol, 1)
            except ValueError:
                pass
    return specs


def _extract_generation(model: str, model_group: str | None) -> tuple[str, str | None]:
    cleaned = _re.sub(r'\s+', ' ', model or '').strip()
    generation: str | None = None

    m = _GEN_PAREN_RE.search(cleaned)
    if m:
        generation = m.group(1)
        cleaned = _GEN_PAREN_RE.sub('', cleaned)
        cleaned = _re.sub(r'\s+', ' ', cleaned).strip()

    if generation is None and model_group:
        for token in str(model_group).replace('/', ' ').split():
            if _is_generation_token(token) and not _looks_like_model_prefix(cleaned, token):
                generation = token
                break

    if generation is None:
        for token in cleaned.split():
            if _is_generation_token(token) and not _looks_like_model_prefix(cleaned, token):
                generation = token
                cleaned = ' '.join([t for t in cleaned.split() if t != token]).strip()
                break

    if generation is not None:
        cleaned = ' '.join([t for t in cleaned.split() if t != generation]).strip()

    return cleaned, generation


def _is_generation_token(token: str) -> bool:
    t = (token or '').strip()
    if not t:
        return False
    upper = t.upper()
    if upper in _get_gen_non_chassis_tokens():
        return False
    if upper in _get_gen_exclude_tokens():
        return False
    if _re.match(r'^V\d{1,2}$', upper):
        return False
    if _re.match(r'^Q\d$', upper):
        return False
    return bool(_GEN_TOKEN_RE.match(upper))


def _looks_like_model_prefix(model_text: str, candidate: str) -> bool:
    text = _re.sub(r'\s+', ' ', model_text or '').strip()
    cand = (candidate or '').strip()
    if not text or not cand:
        return False
    if text.startswith(cand + ' ') or text == cand:
        return True
    stripped = _MODEL_PREFIX_RE.sub('', text)
    if stripped.startswith(cand + ' ') or stripped == cand:
        return True
    return False


def _extract_suffix_hint(text: str, hints: tuple[str, ...]) -> tuple[str, str | None]:
    normalized = _re.sub(r'\s+', ' ', text or '').strip()
    if not normalized:
        return '', None
    for hint in sorted(hints, key=len, reverse=True):
        if normalized == hint:
            return normalized, None
        suffix = f' {hint}'
        if normalized.endswith(suffix):
            return normalized[:-len(suffix)].strip(), hint
    return normalized, None


def _split_model_trim_package(model: str) -> tuple[str, str | None, str | None]:
    normalized = _re.sub(r'\s+', ' ', model or '').strip()
    if not normalized:
        return '', None, None

    base_after_pkg, package = _extract_suffix_hint(normalized, _get_package_hints())
    base_after_trim, trim = _extract_suffix_hint(base_after_pkg, _get_trim_hints())
    return base_after_trim, trim, package


def _detect_unknown_tail(model_no_gen: str, model_clean: str, inferred_trim: str | None, inferred_package: str | None) -> str | None:
    if inferred_trim or inferred_package:
        return None
    tokens = (model_no_gen or '').split()
    if len(tokens) < 2:
        return None
    tail2 = ' '.join(tokens[-2:])
    tail1 = tokens[-1]
    if _UNKNOWN_TAIL_HINT_RE.search(tail2):
        return tail2
    if _UNKNOWN_TAIL_HINT_RE.search(tail1):
        return tail1
    return None


def _normalize_model_taxonomy(
    model_raw: str, model_group: str | None
) -> tuple[str, str | None, str | None, str | None, str | None, list[str], str | None]:
    model_no_noise, stripped_tokens = _strip_tail_noise(_re.sub(r'\s+', ' ', model_raw or '').strip())
    model_no_gen, generation = _extract_generation(model_no_noise, model_group)
    model_clean, inferred_trim, inferred_package = _split_model_trim_package(model_no_gen)
    model_clean, variant = _extract_variant(model_clean)
    model_clean = _clean_tech_spec_tokens_from_model(model_clean)
    unknown_tail = _detect_unknown_tail(model_no_gen, model_clean, inferred_trim, inferred_package)
    return (
        model_clean or model_no_noise or (model_raw or '').strip(),
        generation,
        inferred_trim,
        inferred_package,
        unknown_tail,
        stripped_tokens,
        variant,
    )


def _lot_from_search(item: dict, norm: EncarNormalizer) -> CarLot:
    vid = str(item["Id"])
    make_kr = item.get("Manufacturer", "")
    model    = item.get("Model", "")
    badge    = item.get("Badge", "")        # grade/fuel e.g. "디젤 2.2 4WD"
    badge_detail = item.get("BadgeDetail", "")  # trim e.g. "노블레스"

    year_raw = item.get("FormYear") or str(item.get("Year") or "")
    year = int(str(year_raw)[:4]) if year_raw and len(str(year_raw)) >= 4 else 0

    price_man = int(item.get("Price") or 0)
    if price_man > 1_000_000_000:  # > 10 trillion KRW — clearly garbage data
        logger.warning(f"[encar] lot {item.get('Id')}: absurd price {price_man}만원, zeroing")
        price_man = 0
    price_raw = norm.price_from_man(price_man)
    mileage   = int(item.get("Mileage") or 0)

    # Drive type will be resolved by taxonomy rules (set_drive_type) from full model+badge string.

    # Main photo: first Photos entry or Photo prefix
    photos = item.get("Photos") or []
    photo_path = photos[0]["location"] if photos else ""
    image_url = EncarClient.photo_url(photo_path) if photo_path else None

    location = item.get("OfficeCityState") or ""

    conditions = item.get("Condition") or []
    sell_type, sell_type_raw = _sell.normalize_encar(
        item.get("SellType"), item.get("AdType"), conditions,
    )

    # FormYear is already an INT like 202006 (YYYYMM) — store as first-class col.
    form_year = item.get("FormYear") or item.get("Year")
    reg_ym: int | None = None
    if form_year:
        try:
            s = str(int(form_year))  # drop possible ".0"
            if len(s) == 6 and s.isdigit():
                reg_ym = int(s)
        except (TypeError, ValueError):
            pass

    model_group = item.get("ModelGroup")
    model_clean, generation, inferred_trim, inferred_package, unknown_tail, stripped_tokens, heuristic_variant = _normalize_model_taxonomy(model, model_group)
    model_clean, generation, inferred_trim, unknown_tail, rule_fuel, rule_drive_type, rule_variant = _apply_taxonomy_rules(
        source=_SOURCE,
        make=make_kr,
        model_raw=model,
        model=model_clean,
        generation=generation,
        trim=inferred_trim,
        unknown_tail=unknown_tail,
        badge_raw=badge,
    )
    trim = (badge_detail or '').strip() or inferred_trim

    if unknown_tail:
        _append_taxonomy_anomaly({
            'ts': _utc3_now_iso(),
            'source': _SOURCE,
            'lot_id': vid,
            'make_kr': make_kr,
            'model_raw': model,
            'model_group_raw': model_group,
            'badge_raw': badge,
            'badge_detail_raw': badge_detail,
            'model_clean': model_clean,
            'generation_inferred': generation,
            'trim_inferred': inferred_trim,
            'package_inferred': inferred_package,
            'unknown_tail': unknown_tail,
            'reason': 'model_tail_not_matched_by_known_trim_package_patterns',
        })

    lot_fuel = norm.fuel(item.get("FuelType")) or rule_fuel
    _drive_tokens = f"{model} {badge}".split()
    lot_drive_type = rule_drive_type or next(
        (norm.drive(t) for t in _drive_tokens if norm.drive(t)),
        None,
    )
    lot_engine_volume = _extract_engine_volume(f"{model} {badge}")
    lot_variant = rule_variant or heuristic_variant
    # extract special tags from the raw model string (장애인용, 캠핑카 etc.)
    _special_found = [t for t in _get_special_tags() if t in model]
    _raw_data: dict = {
        "manufacturer_kr":      make_kr,
        "model_kr_raw":         model,
        "model_group_kr":       model_group,
        "badge_kr":             badge,
        "badge_detail_kr":      badge_detail,
        "model_taxonomy_clean": model_clean,
        "generation_inferred":  generation,
        "trim_inferred":        inferred_trim,
        "package_inferred":     inferred_package,
        "unknown_tail_candidate": unknown_tail,
        "ad_type":              item.get("AdType"),
        "condition":            conditions,
    }
    if stripped_tokens:
        _raw_data["stripped_model_tokens"] = stripped_tokens
    if _special_found:
        _raw_data["special_tags"] = _special_found

    return CarLot(
        id=vid,
        source=_SOURCE,
        make=norm.make(make_kr),
        model=model_clean,
        model_en=resolve_model_en(model_clean),
        generation=generation,
        variant=lot_variant,
        trim=trim or None,
        package=inferred_package or None,
        year=year,
        price=price_raw,
        mileage=mileage,
        registration_year_month=reg_ym,
        fuel=lot_fuel,
        transmission=norm.transmission(item.get("Transmission")),
        color=norm.color(item.get("Color")),
        seat_color=norm.color(item.get("SeatColor")),
        drive_type=lot_drive_type,
        engine_volume=lot_engine_volume,
        location=location or None,
        image_url=image_url,
        lot_url=f"https://fem.encar.com/cars/detail/{vid}",
        sell_type=sell_type,
        sell_type_raw=sell_type_raw or None,
        raw_data=_raw_data,
    )


def _enrich_from_detail(lot: CarLot, detail: dict, norm: EncarNormalizer) -> None:
    # Detail API returns flat structure (not nested under 'base')
    cat     = detail.get("category", {})
    spec    = detail.get("spec", {})
    adv     = detail.get("advertisement", {})
    contact = detail.get("contact", {})
    manage  = detail.get("manage", {})
    photos  = detail.get("photos", [])
    opts    = detail.get("options", {})
    cond    = detail.get("condition", {})
    partner = detail.get("partnership", {})

    if spec.get("transmissionName"):
        lot.transmission = norm.transmission(spec["transmissionName"])
    if spec.get("fuelName"):
        lot.fuel = norm.fuel(spec["fuelName"])
    if spec.get("colorName"):
        lot.color = norm.color(spec["colorName"])
    if spec.get("bodyName"):
        lot.body_type = norm.body(spec["bodyName"])
    if spec.get("displacement"):
        lot.engine_volume = round(spec["displacement"] / 1000, 1)
    if spec.get("drivingMethodName") and not lot.drive_type:
        lot.drive_type = norm.drive(spec["drivingMethodName"])
    if spec.get("seatCount"):
        lot.seat_count = int(spec["seatCount"])

    if detail.get("vin"):
        lot.vin = detail["vin"]
    if detail.get("vehicleNo"):
        lot.plate_number = detail["vehicleNo"]

    if contact.get("address"):
        lot.location = contact["address"]

    if manage.get("registDateTime"):
        lot.listed_at = manage["registDateTime"][:10]

    # NOTE: lien_status/seizure_status are set from the Record API in _enrich_from_record
    # (rec["loan"] / rec["robberCnt"]) which is the authoritative source.
    # The batch detail API's seizing.pledgeCount is unreliable and must not overwrite it.

    outer = [p["path"] for p in photos if p.get("type") == "OUTER"]
    if outer and not lot.image_url:
        lot.image_url = EncarClient.photo_url(outer[0])

    # Photos go to the transit field `lot.photos` — LotRepository.upsert_batch
    # will persist them into the `lot_photos` table. They are NOT serialized
    # into raw_data (see CarLot._RAW_DATA_BLOCKLIST).
    all_photo_urls = [EncarClient.photo_url(p["path"]) for p in photos if p.get("path")]
    if all_photo_urls:
        # Deduplicate while preserving order
        lot.photos = list(dict.fromkeys(all_photo_urls))

    # Inspection uses an inner vehicle ID embedded in photo paths (e.g. /pic4097/40977911_004.jpg)
    # which can differ from the listing ID (lot.id).
    if photos:
        _m = _re.search(r'/(\d+)_\d+\.', photos[0].get("path", ""))
        if _m and _m.group(1) != lot.id:
            lot.raw_data["inspect_vehicle_id"] = _m.group(1)

    std_opts = opts.get("standard", [])
    if std_opts:
        lot.options = std_opts

    # Paid/choice options — separate from standard options
    _paid = []
    for _key in ("choice", "paid", "color", "package"):
        _group = opts.get(_key, [])
        if _group:
            _paid.extend(_group)
    if _paid:
        lot.paid_options = _paid

    # originPrice is MSRP in 만원 units — promote it to the first-class
    # `retail_value` column (in KRW) so filter rules and UI can compare it.
    origin_price_man = cat.get("originPrice")
    if origin_price_man and not lot.retail_value:
        try:
            lot.retail_value = int(origin_price_man) * 10_000
        except (TypeError, ValueError):
            pass

    lot.raw_data.update({
        "grade_detail_kr": cat.get("gradeDetailName"),
        "grade_detail_en": cat.get("gradeDetailEnglishName"),
        "ad_status":       adv.get("status"),
    })


_ACCIDENT_TYPE = {"1": "my-fault", "2": "my-fault", "3": "other-fault"}
_OUTER_STATUS  = {"W": "panel", "X": "replaced", "A": "scratch", "U": "damaged", "C": "corrosion"}


def _parse_outer_damage(outers: list) -> tuple[bool, str]:
    if not outers:
        return False, ""
    parts = []
    for o in outers:
        title    = (o.get("type") or {}).get("title", "")
        statuses = [(s.get("title") or "") for s in o.get("statusTypes") or []]
        if title and statuses:
            parts.append(f"{title}: {', '.join(statuses)}")
    return len(parts) > 0, "\n".join(parts)


def _enrich_from_record(lot: CarLot, rec: dict) -> InspectionRecord:
    """Update CarLot from accident-history record API and return InspectionRecord."""
    my_cnt    = int(rec.get("myAccidentCnt") or 0)
    other_cnt = int(rec.get("otherAccidentCnt") or 0)
    lot.has_accident    = (my_cnt + other_cnt) > 0
    lot.insurance_count = int(rec.get("accidentCnt") or (my_cnt + other_cnt))
    lot.owners_count    = rec.get("ownerChangeCnt")

    flood = int(rec.get("floodTotalLossCnt") or 0) + int(rec.get("floodPartLossCnt") or 0)
    lot.flood_history      = flood > 0
    lot.total_loss_history = int(rec.get("totalLossCnt") or 0) > 0

    lot.lien_status    = "lien"    if int(rec.get("loan") or 0)     > 0 else "clean"
    lot.seizure_status = "seizure" if int(rec.get("robberCnt") or 0) > 0 else "clean"

    my_cost    = int(rec.get("myAccidentCost") or 0)
    other_cost = int(rec.get("otherAccidentCost") or 0)
    if my_cost + other_cost > 0:
        lot.repair_cost = my_cost + other_cost

    if rec.get("firstDate") and not lot.first_reg_date:
        lot.first_reg_date = rec["firstDate"]

    accidents = rec.get("accidents") or []
    acc_lines = [
        f"{a.get('date', '')} [{_ACCIDENT_TYPE.get(a.get('type',''),'?')}] ₩{int(a.get('insuranceBenefit',0)):,}"
        for a in accidents
    ]

    return InspectionRecord(
        lot_id=lot.id,
        source="encar",
        first_registration=rec.get("firstDate"),
        has_accident=lot.has_accident,
        has_flood=lot.flood_history,
        my_accident_cost=my_cost if my_cost else None,
        other_accident_cost=other_cost if other_cost else None,
        accident_detail="\n".join(acc_lines) if acc_lines else None,
        details={
            "accidents":           accidents,
            "owner_changes":       rec.get("ownerChanges"),
            "plate_changes":       rec.get("carInfoChanges"),
            "plate_change_cnt":    rec.get("carNoChangeCnt"),
            "robber_cnt":          rec.get("robberCnt"),
            "total_loss_cnt":      rec.get("totalLossCnt"),
            "loan":                rec.get("loan"),
            "my_accident_cost":    my_cost,
            "other_accident_cost": other_cost,
            "government":          rec.get("government"),
            "business":            rec.get("business"),
        },
    )


def _enrich_from_inspection(
    lot: CarLot, insp: dict, record: InspectionRecord
) -> None:
    """Merge inspection API data into CarLot and update InspectionRecord in place."""
    master = insp.get("master") or {}
    detail = master.get("detail") or {}

    if master.get("accdient") is not None:
        # master.accdient = structural accident (성능점검 판단), not insurance claims.
        # Only update lot.has_accident if not already set by the record API.
        if lot.has_accident is None:
            lot.has_accident = master["accdient"]
        record.has_accident = master["accdient"]

    if detail.get("waterlog") is not None:
        lot.flood_history = detail["waterlog"]
        record.has_flood  = detail["waterlog"]

    if detail.get("tuning") is not None:
        record.has_tuning = detail["tuning"]

    if detail.get("vin") and not lot.vin:
        lot.vin = detail["vin"]

    outers = insp.get("outers") or []
    has_outer, outer_text = _parse_outer_damage(outers)
    record.has_outer_damage = has_outer
    if outer_text:
        record.outer_detail = outer_text

    if master.get("supplyNum"):
        _cert = str(master["supplyNum"]).strip()
        # Valid cert numbers are typically 8-15 digits; skip short garbage
        if _cert.isdigit() and len(_cert) >= 8:
            record.cert_no = _cert
    if master.get("registrationDate"):
        record.inspection_date = master["registrationDate"][:10]
    record.report_url = (
        f"https://www.encar.com/md/sl/mdsl_regcar.do"
        f"?method=inspectionViewNew&carid={lot.id}"
    )

    def _parse_date8(s: str | None) -> str | None:
        if not s or len(s) != 8 or not s.isdigit():
            return None
        m, d = int(s[4:6]), int(s[6:8])
        if not (1 <= m <= 12 and 1 <= d <= 31):
            return None
        return f"{s[:4]}-{s[4:6]}-{s[6:8]}"

    if vs := _parse_date8(detail.get("validityStartDate")):
        record.valid_from = vs
    if ve := _parse_date8(detail.get("validityEndDate")):
        record.valid_until = ve
    if fr := _parse_date8(detail.get("firstRegistrationDate")):
        record.first_registration = fr
        if not lot.first_reg_date:
            lot.first_reg_date = fr

    if detail.get("mileage"):
        record.inspection_mileage = int(detail["mileage"])

    # Engine model code (e.g. "D4CB", "G4KE") and warranty type
    if detail.get("motorType"):
        lot.raw_data["engine_code"] = detail["motorType"]
    if detail.get("guarantyType"):
        lot.raw_data["warranty_type"] = (detail["guarantyType"] or {}).get("title")

    # Recall status
    recall_flag = detail.get("recall")
    recall_types = [(r.get("title") or "") for r in (detail.get("recallFullFillTypes") or [])]
    if recall_flag:
        record.has_recall = True
        lot.raw_data["recall"] = True
        lot.raw_data["recall_status"] = recall_types or ["미확인"]

    # Overall car state
    if detail.get("carStateType"):
        lot.raw_data["car_state"] = (detail["carStateType"] or {}).get("title")

    # Mechanical anomalies from inners (engine / transmission / etc.)
    _BAD_INNER = {"누유", "누수", "미세누수", "불량", "부족", "과다", "누유있음", "미세누유"}
    def _collect_inner_issues(node: dict, path: str = "") -> list[str]:
        title     = (node.get("type") or {}).get("title", "")
        full_path = f"{path}/{title}" if path else title
        st_title  = (node.get("statusType") or {}).get("title", "")
        issues: list[str] = []
        if st_title and st_title in _BAD_INNER:
            issues.append(f"{full_path} → {st_title}")
        for ch in node.get("children") or []:
            issues.extend(_collect_inner_issues(ch, full_path))
        return issues

    mech_issues: list[str] = []
    for inner in (insp.get("inners") or []):
        mech_issues.extend(_collect_inner_issues(inner))
    if mech_issues:
        lot.raw_data["mechanical_issues"] = mech_issues

    record.details = record.details or {}
    record.details.update({
        "simple_repair":       master.get("simpleRepair"),
        "engine_check":        detail.get("engineCheck"),
        "trns_check":          detail.get("trnsCheck"),
        "recall":              recall_flag,
        "recall_types":        recall_types,
        "mechanical_issues":   mech_issues or None,
        "serious_types":       [(s.get("title") or "") for s in (detail.get("seriousTypes") or [])],
        "car_state":           (detail.get("carStateType") or {}).get("title"),
        "outer_parts":         [{"part": (o.get("type") or {}).get("title"), "status": [(s.get("title")) for s in o.get("statusTypes") or []]} for o in outers],
    })


def _enrich_from_inspection_html(
    lot: CarLot, html: str, record: InspectionRecord
) -> None:
    """Parse the human-readable inspection report (www.encar.com/md/sl/mdsl_regcar.do).

    Used as a fallback when the JSON inspection API is unavailable.
    Extracts: VIN, plate, first-registration date, engine code, mileage,
    accident/simple-repair flags, recall status, tuning, and flood history.
    """
    from bs4 import BeautifulSoup
    soup = BeautifulSoup(html, "lxml")

    # ── Helper: find <td> immediately after a <th> whose text contains `label` ─
    def _td_after(label: str) -> str | None:
        for th in soup.find_all("th", scope="row"):
            if label in th.get_text():
                td = th.find_next_sibling("td")
                return td.get_text(strip=True) if td else None
        return None

    # ── Helper: for a status row, return the text of the selected span (on/active) ─
    def _selected_state(row_label: str) -> str | None:
        for th in soup.find_all("th", scope="row"):
            if th.get_text(strip=True).startswith(row_label):
                td = th.find_next_sibling("td")
                if td:
                    sel = td.find("span", class_=lambda c: c and ("active" in c or " on" in c or c.endswith("on")))
                    return sel.get_text(strip=True) if sel else None
        return None

    # ── Basic info table ──────────────────────────────────────────────────────
    vin = _td_after("차대번호")
    if vin and not lot.vin:
        lot.vin = vin

    plate = _td_after("차량번호")
    if plate and not lot.plate_number:
        lot.plate_number = plate

    reg_raw = _td_after("최초등록일")
    if reg_raw:
        m = _re.search(r"(\d{4})년\s*(\d{1,2})월\s*(\d{1,2})일", reg_raw)
        if m:
            reg_date = f"{m.group(1)}-{int(m.group(2)):02d}-{int(m.group(3)):02d}"
            if not lot.first_reg_date:
                lot.first_reg_date = reg_date
            if not record.first_registration:
                record.first_registration = reg_date

    engine_code = _td_after("원동기형식")
    if engine_code and not lot.raw_data.get("engine_code"):
        lot.raw_data["engine_code"] = engine_code

    warranty = _td_after("보증유형")
    if warranty and not lot.raw_data.get("warranty_type"):
        lot.raw_data["warranty_type"] = warranty

    # ── Cert / performance number from .ckdate span ───────────────────────────
    ckdate = soup.find("span", class_="ckdate")
    if ckdate and not record.cert_no:
        m2 = _re.search(r"성능번호\s*제\s*([\d]+)\s*호", ckdate.get_text())
        if m2:
            record.cert_no = m2.group(1)

    # ── Mileage at inspection ─────────────────────────────────────────────────
    for th in soup.find_all("th", scope="row"):
        if "주행거리" in th.get_text() and "계기" not in th.get_text():
            # mileage value is in 2nd <td> sibling (first has 많음/보통/적음 spans)
            for td in th.find_next_siblings("td"):
                detail = td.find("span", class_="txt_detail")
                if detail:
                    km_m = _re.search(r"([\d,]+)\s*km", detail.get_text())
                    if km_m and not record.inspection_mileage:
                        record.inspection_mileage = int(km_m.group(1).replace(",", ""))
                    break
            break

    # ── Status flags ──────────────────────────────────────────────────────────
    def _is_selected(row_label: str, value: str) -> bool:
        for th in soup.find_all("th", scope="row"):
            if th.get_text(strip=True).startswith(row_label):
                td = th.find_next_sibling("td")
                if not td:
                    continue
                for span in td.find_all("span", class_="txt_state"):
                    if value in span.get_text(strip=True):
                        classes = span.get("class", [])
                        return "on" in classes or "active" in classes
        return False

    # Accident history (사고이력): 있음 selected → has structural accident
    if lot.has_accident is None:
        if _is_selected("사고이력", "있음"):
            lot.has_accident = True
            record.has_accident = True
        elif _is_selected("사고이력", "없음"):
            lot.has_accident = False
            record.has_accident = False

    # Simple repair (단순수리): store in record.details
    simple_repair = _is_selected("단순수리", "있음")
    record.details = record.details or {}
    record.details["simple_repair"] = simple_repair

    # Flood (침수): 있음 = True
    if _is_selected("침수", "있음"):
        lot.flood_history = True
        record.has_flood = True
    elif _is_selected("침수", "없음"):
        lot.flood_history = False
        record.has_flood = False

    # Tuning (튜닝): 있음 = True
    if not record.details.get("tuning_set"):
        if _is_selected("튜닝", "있음"):
            record.has_tuning = True
        elif _is_selected("튜닝", "없음"):
            record.has_tuning = False
        record.details["tuning_set"] = True

    # Recall (리콜대상): 해당 = True (exact match — avoid matching inside 해당없음)
    def _is_selected_exact(row_label: str, value: str) -> bool:
        for th in soup.find_all("th", scope="row"):
            if th.get_text(strip=True).startswith(row_label):
                td = th.find_next_sibling("td")
                if not td:
                    continue
                for span in td.find_all("span", class_="txt_state"):
                    if span.get_text(strip=True) == value:
                        classes = span.get("class", [])
                        return "on" in classes or "active" in classes
        return False

    if _is_selected_exact("리콜대상", "해당"):
        lot.raw_data["recall"] = True

    # Report URL
    if not record.report_url:
        record.report_url = (
            f"https://www.encar.com/md/sl/mdsl_regcar.do"
            f"?method=inspectionViewNew&carid={lot.id}"
        )


_DIAG_RESULT_MAP = {
    "NORMAL":      "정상",
    "REPLACEMENT": "교환",
    "PANEL":       "판금",
    "SCRATCH":     "스크래치",
    "CORROSION":   "부식",
}

_DIAG_PART_MAP = {
    "HOOD":               "후드",
    "FRONT_FENDER_LEFT":  "프론트 휀더(좌)",
    "FRONT_FENDER_RIGHT": "프론트 휀더(우)",
    "FRONT_DOOR_LEFT":    "앞 도어(좌)",
    "FRONT_DOOR_RIGHT":   "앞 도어(우)",
    "BACK_DOOR_LEFT":     "뒤 도어(좌)",
    "BACK_DOOR_RIGHT":    "뒤 도어(우)",
    "TRUNK_LID":          "트렁크 리드",
    "QUARTER_PANEL_LEFT": "쿼터패널(좌)",
    "QUARTER_PANEL_RIGHT":"쿼터패널(우)",
    "ROOF_PANEL":         "루프 패널",
    "SIDE_SILL_LEFT":     "사이드실(좌)",
    "SIDE_SILL_RIGHT":    "사이드실(우)",
}


def _enrich_from_diagnosis(
    lot: CarLot, diag: dict, record: InspectionRecord
) -> None:
    """Parse Encar internal diagnosis (body panel inspection) into InspectionRecord."""
    items = diag.get("items") or []
    non_normal = []
    checker_comment = None
    outer_comment   = None

    for it in items:
        name   = it.get("name", "")
        result = it.get("result", "")
        code   = it.get("resultCode")
        if name == "CHECKER_COMMENT":
            checker_comment = result
        elif name == "OUTER_PANEL_COMMENT":
            outer_comment = result
        elif code and code != "NORMAL":
            part_kr = _DIAG_PART_MAP.get(name, name)
            non_normal.append(f"{part_kr}: {result}")

    has_damage = bool(non_normal)
    if has_damage:
        record.has_outer_damage = True
        damage_text = "\n".join(non_normal)
        if outer_comment:
            damage_text += f"\n\n[Encar 진단]\n{outer_comment}"
        record.outer_detail = damage_text

    if diag.get("diagnosisDate") and not record.inspection_date:
        record.inspection_date = diag["diagnosisDate"][:10]

    record.details = record.details or {}
    record.details["diagnosis"] = {
        "diagnosisNo":   diag.get("diagnosisNo"),
        "center":        diag.get("reservationCenterName"),
        "date":          diag.get("diagnosisDate", "")[:10],
        "checker_comment": checker_comment,
        "items":         [{"part": it.get("name"), "result": it.get("resultCode")} for it in items
                          if it.get("resultCode")],
    }
    lot.raw_data["diagnosis_center"] = diag.get("reservationCenterName")


_DRIVE_PART_CODE  = "SPEC_drivingMethodNm"
_OPT_KEYS         = 10    # 차량 키 수량
_OPT_TINT         = 16    # 틴팅 (정면 유리)
_OPT_TIRE_FL      = 330   # 동승석(앞) tread
_OPT_TIRE_FR      = 327   # 운전석(앞) tread
_OPT_TIRE_RL      = 329   # 동승석(뒤) tread
_OPT_TIRE_RR      = 328   # 운전석(뒤) tread


def _enrich_from_verification(lot: CarLot, vdata: dict) -> None:
    """Parse /verification/{id}/simple response into CarLot fields."""
    items = vdata.get("items") or []
    opt_map: dict[int, str] = {}
    for item in items:
        opt_id = (item.get("option") or {}).get("id")
        val    = item.get("value")
        if opt_id is not None and val is not None:
            opt_map[opt_id] = val

    # Keys count
    if _OPT_KEYS in opt_map:
        try:
            keys_count = int(opt_map[_OPT_KEYS])
            lot.has_keys = keys_count > 0
            lot.raw_data["keys_count"] = keys_count
        except ValueError:
            pass

    # Tire tread depth (mm) for all 4 positions
    tire_map = {
        "fl": _OPT_TIRE_FL, "fr": _OPT_TIRE_FR,
        "rl": _OPT_TIRE_RL, "rr": _OPT_TIRE_RR,
    }
    tire_depths: dict[str, int] = {}
    for pos, opt_id in tire_map.items():
        if opt_id in opt_map:
            try:
                tire_depths[pos] = int(opt_map[opt_id])
            except ValueError:
                pass
    if tire_depths:
        lot.raw_data["tire_depth_mm"] = tire_depths

    # Tinting
    if _OPT_TINT in opt_map:
        lot.raw_data["front_tinting"] = opt_map[_OPT_TINT] == "INCLUDE"

    # Extra photos from itemPictures (add to raw_data for display)
    pics = vdata.get("itemPictures") or []
    extra_photos: list[str] = []
    for pic in pics:
        for att in pic.get("attachments") or []:
            key = att.get("key")
            if key:
                extra_photos.append(EncarClient.verify_photo_url(key))
    if extra_photos:
        lot.raw_data["verify_photos"] = extra_photos
        if not lot.image_url:
            lot.image_url = extra_photos[0]


def _enrich_from_sellingpoint(lot: CarLot, sp: dict, norm: EncarNormalizer) -> None:
    """Extract drive_type from uniqueOptionPhotos; store sellingPoint sentence in raw_data."""
    for photo in sp.get("uniqueOptionPhotos") or []:
        if photo.get("partCode") == _DRIVE_PART_CODE:
            part_name = photo.get("partName", "")  # e.g. "구동방식(전륜)"
            # Extract value inside parentheses: "구동방식(전륜)" → "전륜"
            if "(" in part_name and ")" in part_name:
                raw = part_name[part_name.index("(") + 1: part_name.rindex(")")]
                lot.drive_type = norm.drive(raw)
            break

    selling = sp.get("sellingPoint") or {}
    sentence = selling.get("sentence")
    if sentence:
        lot.raw_data["selling_point"] = sentence


class EncarParser(AbstractParser):
    MIN_DELIST_COVERAGE = Config.ENCAR_DELIST_COVERAGE

    def __init__(self, repo: LotRepository):
        super().__init__(repo)
        self._client = EncarClient()
        self._norm = EncarNormalizer()

    def _regenerate_proxies(self):
        """Clear proxy cache, generate fresh sessions, and rebuild the HTTP client."""
        _reset_proxy_cache()
        self._client = EncarClient()
        logger.info(f"[{_SOURCE}] Client rebuilt with fresh proxy sessions")

    def get_source_key(self) -> str:
        return _SOURCE

    def get_source_name(self) -> str:
        return "Encar"

    def _paginate_query(
        self,
        query: str,
        max_pages: int,
        seen_ids: set[str],
        existing_ids: set[str],
        stats: dict,
        on_page_callback: Callable | None,
        label: str = "",
        collect_models: dict[str, set[str]] | None = None,
    ) -> int:
        """Paginate one Encar search query. Returns API total count.
        Stops early when API cycles (all results already in seen_ids)."""
        source = _SOURCE
        total_count: int | None = None
        call_seen: set[str] = set()  # IDs first encountered in THIS call — for cycling detection only
        stop_reason = "max_pages reached"
        consecutive_empty = 0  # pages where ALL items were already in seen_ids
        _MAX_CONSECUTIVE_EMPTY = 5
        pages_done = 0

        for page in range(max_pages):
            _t_page = _time.monotonic()
            offset = page * _PAGE_SIZE
            if offset > _MAX_SAFE_OFFSET:
                stop_reason = f"offset {offset} > API cap {_MAX_SAFE_OFFSET}"
                break
            _t_search = _time.monotonic()
            try:
                data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
            except httpx.HTTPStatusError as e:
                etype = str(e.response.status_code)
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                if e.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504):
                    logger.warning(f"[{source}]{label} p.{page+1}: {e.response.status_code}, rotating proxy and retrying")
                    self._client.rotate_proxy()
                    _p = _time.monotonic(); _time.sleep(2); stats["pause_time"] += _time.monotonic() - _p
                    try:
                        data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
                    except Exception as e2:
                        stop_reason = f"retry failed: {e2}"
                        stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                        logger.error(f"[{source}]{label} p.{page+1} {stop_reason}")
                        break
                else:
                    stop_reason = f"HTTP {e.response.status_code}"
                    stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                    logger.error(f"[{source}]{label} p.{page+1} error: {e}")
                    break
            except ProxyBudgetExhausted as e:
                logger.error(f"[{source}]{label} p.{page+1}: proxy budget exhausted — aborting. {e}")
                self.inc_error(stats, "ProxyBudgetExhausted", f"proxy budget exhausted at p.{page+1}{label}")
                return api_total
            except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout) as e:
                etype = type(e).__name__
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                logger.warning(f"[{source}]{label} p.{page+1}: {etype}: {e}, rotating proxy and retrying")
                self._client.rotate_proxy()
                _p = _time.monotonic(); _time.sleep(3); stats["pause_time"] += _time.monotonic() - _p
                try:
                    data = self._client.search(query=query, offset=offset, count=_PAGE_SIZE)
                except ProxyBudgetExhausted as e2:
                    logger.error(f"[{source}]{label} p.{page+1}: proxy budget exhausted on retry — aborting. {e2}")
                    self.inc_error(stats, "ProxyBudgetExhausted", f"proxy budget exhausted at p.{page+1}{label}")
                    return api_total
                except Exception as e2:
                    stop_reason = f"proxy retry failed: {e2}"
                    stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                    logger.error(f"[{source}]{label} p.{page+1} {stop_reason}")
                    break
            except Exception as e:
                etype = type(e).__name__
                stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                stop_reason = f"error: {etype}: {e}"
                stats["error_log"].append(f"p.{page+1}{label}: {stop_reason}")
                logger.error(f"[{source}]{label} p.{page+1} {stop_reason}")
                break
            finally:
                stats["search_time"] += _time.monotonic() - _t_search

            if total_count is None:
                total_count = data.get("Count", 0)
                pass  # total_count captured

            items = data.get("SearchResults", [])
            if not items:
                stop_reason = "empty page (no results)"
                break

            page_lots: list[CarLot] = []
            phase1_skip = 0
            for item in items:
                vid = str(item.get("Id", ""))
                if not vid:
                    continue
                if vid in seen_ids:
                    phase1_skip += 1
                    continue
                seen_ids.add(vid)
                call_seen.add(vid)
                lot = _lot_from_search(item, self._norm)
                page_lots.append(lot)
                if collect_models is not None:
                    mk = item.get("Manufacturer", "")
                    mo = item.get("Model", "")
                    if mk:
                        collect_models.setdefault(mk, set())
                        if mo:
                            collect_models[mk].add(mo)

            if not page_lots and items:
                # True cycling: API returned IDs we already saw in THIS call
                truly_cycling = all(str(i.get("Id", "")) in call_seen for i in items)
                if truly_cycling:
                    stop_reason = f"API cycling (all {len(items)} in call_seen)"
                    break
                # Phase overlap: IDs seen in a prior phase — advance to next page
                consecutive_empty += 1
                if consecutive_empty >= _MAX_CONSECUTIVE_EMPTY:
                    stop_reason = f"phase overlap stuck ({consecutive_empty} consecutive empty pages)"
                    logger.warning(f"[{source}]{label} {stop_reason} — breaking to avoid waste")
                    break
                logger.info(f"[STAT] [{source}]{label} p.{page+1}: {phase1_skip} seen in prior phase, skipping → next page")
                if offset + _PAGE_SIZE >= (total_count or 0):
                    stop_reason = "reached end (all overlap)"
                    break
                continue

            consecutive_empty = 0  # reset on any page with new data
            pass  # page lots counted

            _t_batch_start = _time.monotonic()
            self._enrich_batch(page_lots, stats)
            stats["search_time"] += _time.monotonic() - _t_batch_start

            self.repo.upsert_batch(page_lots, stats)
            # Photos are auto-upserted by LotRepository.upsert_batch from
            # lot.photos (see parser/models.py). No need to handle them here.
            for lot in page_lots:
                is_new = lot.id not in existing_ids
                if is_new:
                    stats["new"] += 1
                    pass  # new lot
                else:
                    stats["updated"] += 1
                    pass  # updated lot
                stats["total"] += 1

            if on_page_callback:
                stats["proxy_bytes"] = self._client.proxy_bytes
                _progress = stats["total"] / total_count if total_count else 0
                on_page_callback(ProgressUpdate(
                    phase="search",
                    phase_progress=min(_progress, 1.0),
                    total_progress=min(_progress, 1.0),
                    lots_found=total_count or 0,
                    lots_processed=len(page_lots),
                    message=f"p.{page+1}{label} {stats['total']:,}/{total_count or '?'}",
                    stats=stats,
                ))

            _t_after_upsert = _time.monotonic()
            new_lots = [l for l in page_lots if l.id not in existing_ids]
            # Enrich ALL lots (not just new) so has_accident/damage stays current
            if page_lots:
                _t_enr = _time.monotonic()
                self._enrich_accident_data(page_lots, stats)
                stats["enrich_time"] += _time.monotonic() - _t_enr

            _t_total = _time.monotonic() - _t_page
            _t_enrich = _time.monotonic() - _t_after_upsert
            _t_batch = _t_after_upsert - _t_page
            pages_done += 1
            logger.info(
                f"[STAT] [{source}]{label} p.{page+1} done in {_t_total:.1f}s "
                f"(batch+upsert={_t_batch:.1f}s, enrich={_t_enrich:.1f}s, "
                f"new={len(new_lots)}/{len(page_lots)})"
            )

            if offset + _PAGE_SIZE >= (total_count or 0):
                stop_reason = f"reached end ({total_count} total)"
                break

        logger.info(f"[STAT] [{source}]{label} SEGMENT DONE: {stop_reason} | pages={pages_done} seen={len(call_seen)}")
        return total_count or 0

    def run_reparse(self, lot_ids: list[str], on_progress=None) -> dict:
        """Re-enrich specific lots by ID (accident + inspection records)."""
        source = _SOURCE
        run_start = _time.monotonic()
        stats = self.init_stats()

        lots = self.repo.get_lots_by_source(source, ids=lot_ids)
        if not lots:
            msg = f"No lots found for ids={lot_ids}"
            logger.warning(f"[{source}] Reparse: {msg}")
            return {"total": 0, "errors": 1, "error_log": [msg], "error_types": {},
                    "elapsed_s": 0.0, "time": "0m", "reparse": True}

        total = len(lots)
        logger.info(f"[{source}] Reparse: enriching {total} lot(s): {lot_ids}")

        if on_progress:
            on_progress(ProgressUpdate(
                phase="enrich", phase_progress=0.0, total_progress=0.0,
                lots_found=total, lots_processed=0,
                message=f"Fetching accident records for {total} lot(s)...",
            ))

        self._enrich_accident_data(lots, stats)

        elapsed = _time.monotonic() - run_start
        logger.info(
            f"[{source}] Reparse done: {total} lot(s) in {elapsed:.1f}s "
            f"(errors={stats.get('errors', 0)})"
        )

        if on_progress:
            on_progress(ProgressUpdate(
                phase="done", phase_progress=1.0, total_progress=1.0,
                lots_found=total, lots_processed=total,
                message=f"Reparse complete: {total} lot(s)",
            ))

        return {
            "total": total,
            "errors": stats.get("errors", 0),
            "error_log": (stats.get("error_log") or [])[-20:],
            "error_types": stats.get("error_types", {}),
            "elapsed_s": round(elapsed, 1),
            "time": self.format_elapsed(elapsed),
            "reparse": True,
        }

    def run(
        self,
        max_pages: int | None = None,
        maker_filter: str | None = None,
        on_page_callback: Callable | None = None,
        checkpoint: dict | None = None,
    ) -> dict:
        source = _SOURCE
        run_start = _time.monotonic()
        stats = self.init_stats()

        pages = max_pages or 9999  # 0 / None = all pages

        logger.info(f"[STAT] [{source}] ========== IMPORT STARTED ==========")
        logger.info(f"[STAT] [{source}] Pages: {pages}, page_size: {_PAGE_SIZE}")

        check_floppy_balance()

        existing_ids = self.repo.get_existing_ids(source)
        logger.info(f"[{source}] Existing active lots in DB: {len(existing_ids)}")

        seen_ids: set[str] = set()

        api_total: int = 0  # total listings reported by Encar API

        _search_phase = self.start_phase("search")

        if maker_filter or max_pages:
            query = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker_filter}.)" if maker_filter else "(And.Hidden.N._.CarType.A.)"
            if maker_filter:
                logger.info(f"[{source}] Maker filter: {maker_filter}")
            api_total = self._paginate_query(query, pages, seen_ids, existing_ids, stats, on_page_callback)
        else:
            # Phase 1: global scan to discover all manufacturers (capped at 10k)
            base_query = "(And.Hidden.N._.CarType.A.)"
            discovered_models: dict[str, set[str]] = {}
            logger.info(f"[{source}] Phase 1: global scan to discover manufacturers and models")
            api_total = self._paginate_query(
                base_query, 100, seen_ids, existing_ids, stats,
                on_page_callback, label=" [global]", collect_models=discovered_models,
            )
            discovered_makers = sorted(discovered_models.keys())
            logger.info(f"[{source}] Phase 1 done. Manufacturers found: {discovered_makers}")
            logger.info(f"[{source}] Phase 1 done. Models per maker: { {k: len(v) for k, v in discovered_models.items()} }")

            # Phase 2: per-manufacturer queries to bypass 10k pagination cap
            logger.info(f"[{source}] Phase 2: per-manufacturer pagination ({len(discovered_makers)} makers)")
            consecutive_maker_errors = 0
            proxy_regens = 0
            _MAX_PROXY_REGENS = 5
            maker_idx = 0
            makers_api_sum = 0  # sum of all maker totals from API
            while maker_idx < len(discovered_makers):
                maker = discovered_makers[maker_idx]
                mq = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}.)"
                try:
                    count_data = self._client.search(query=mq, offset=0, count=1)
                    maker_total = count_data.get("Count", 0)
                    consecutive_maker_errors = 0  # reset on success
                except ProxyBudgetExhausted as e:
                    logger.error(f"[{source}] [{maker}] proxy budget exhausted — aborting Phase 2. {e}")
                    self.inc_error(stats, "ProxyBudgetExhausted", f"budget exhausted at maker {maker}")
                    break
                except Exception as e:
                    consecutive_maker_errors += 1
                    etype = str(e.response.status_code) if isinstance(e, httpx.HTTPStatusError) else type(e).__name__
                    stats["error_types"][etype] = stats["error_types"].get(etype, 0) + 1
                    stats["errors"] += 1
                    stats["error_log"].append(f"[{maker}] count: {etype}: {e}")
                    logger.warning(f"[{source}] [{maker}] count query failed ({consecutive_maker_errors} in a row): {e}")
                    if consecutive_maker_errors >= 5:
                        if proxy_regens < _MAX_PROXY_REGENS:
                            proxy_regens += 1
                            wait = 60 * proxy_regens  # 60s, 120s, 180s, 240s, 300s
                            logger.warning(f"[{source}] Regenerating proxy sessions (attempt {proxy_regens}/{_MAX_PROXY_REGENS}), waiting {wait}s...")
                            self._regenerate_proxies()
                            consecutive_maker_errors = 0
                            _p = _time.monotonic(); _time.sleep(wait); stats["pause_time"] += _time.monotonic() - _p
                            continue  # retry same maker (maker_idx not incremented)
                        logger.error(f"[{source}] API appears down after {proxy_regens} proxy regenerations — aborting Phase 2")
                        break
                    maker_idx += 1
                    continue

                makers_api_sum += maker_total
                logger.info(f"[{source}] [{maker}]: {maker_total} total (maker {maker_idx+1}/{len(discovered_makers)}, sum={makers_api_sum})")
                if maker_total == 0:
                    maker_idx += 1
                    continue

                if maker_total <= _MAX_SAFE_OFFSET:
                    self._paginate_query(
                        mq, 100, seen_ids, existing_ids, stats,
                        on_page_callback, label=f" [{maker}]",
                    )
                else:
                    current_year = _time.localtime().tm_year
                    logger.info(f"[{source}] [{maker}] {maker_total} > {_MAX_SAFE_OFFSET}, splitting by year")
                    for year in range(1990, current_year + 2):
                        yq = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99).)"
                        try:
                            ydata = self._client.search(query=yq, offset=0, count=1)
                            year_total = ydata.get("Count", 0)
                        except Exception as e:
                            logger.warning(f"[{source}] [{maker}/{year}] count query failed: {type(e).__name__}: {e}, retrying...")
                            self._client.rotate_proxy()
                            _p = _time.monotonic(); _time.sleep(2); stats["pause_time"] += _time.monotonic() - _p
                            try:
                                ydata = self._client.search(query=yq, offset=0, count=1)
                                year_total = ydata.get("Count", 0)
                            except Exception as e2:
                                logger.warning(f"[{source}] [{maker}/{year}] count query retry failed: {type(e2).__name__}: {e2}")
                                continue
                        if year_total == 0:
                            continue
                        if year_total <= _MAX_SAFE_OFFSET:
                            self._paginate_query(
                                yq, 100, seen_ids, existing_ids, stats,
                                on_page_callback, label=f" [{maker}/{year}]",
                            )
                        else:
                            # Year still too large — sub-split by model
                            maker_models = sorted(discovered_models.get(maker, []))
                            logger.info(f"[{source}] [{maker}/{year}] {year_total} > cap, sub-splitting by model ({len(maker_models)} models)")
                            for model in maker_models:
                                mq2 = f"(And.Hidden.N._.CarType.A._.Manufacturer.{maker}._.Year.range({year}00..{year}99)._.Model.{model}.)"
                                try:
                                    mdata = self._client.search(query=mq2, offset=0, count=1)
                                    model_total = mdata.get("Count", 0)
                                except Exception as e:
                                    logger.warning(f"[{source}] [{maker}/{year}/{model}] count query failed: {type(e).__name__}: {e}, retrying...")
                                    self._client.rotate_proxy()
                                    _p = _time.monotonic(); _time.sleep(2); stats["pause_time"] += _time.monotonic() - _p
                                    try:
                                        mdata = self._client.search(query=mq2, offset=0, count=1)
                                        model_total = mdata.get("Count", 0)
                                    except Exception as e2:
                                        logger.warning(f"[{source}] [{maker}/{year}/{model}] count query retry failed: {type(e2).__name__}: {e2}")
                                        continue
                                if model_total > 0:
                                    self._paginate_query(
                                        mq2, 100, seen_ids, existing_ids, stats,
                                        on_page_callback, label=f" [{maker}/{year}/{model}]",
                                    )
                            # Fallback: paginate the year query itself for models not discovered in Phase 1
                            logger.info(f"[{source}] [{maker}/{year}] fallback: paginating year query for undiscovered models")
                            self._paginate_query(
                                yq, 100, seen_ids, existing_ids, stats,
                                on_page_callback, label=f" [{maker}/{year}/fallback]",
                            )
                maker_idx += 1

            logger.info(
                f"[{source}] Phase 2 done. Makers API sum: {makers_api_sum:,} | "
                f"Phase 1 API total: {api_total:,} | Processed so far: {stats['total']:,}"
            )

        self.end_phase(_search_phase, lots_out=stats["total"], errors=stats.get("errors", 0))

        elapsed = _time.monotonic() - run_start

        db_count = self.repo.count_active(source)

        # ── Delist phase ─────────────────────────────────────────────────
        _delist_phase = self.start_phase("delist", lots_in=len(seen_ids))
        stale = self.delist_if_complete(seen_ids, reference_total=api_total, grace_hours=1)
        self.end_phase(_delist_phase, lots_out=stale)

        _proxy_bytes = self._client.proxy_bytes
        self._client.close()

        result = self.finalize_summary(
            elapsed, stats, seen_ids,
            api_total=api_total, stale=stale, db_count=db_count,
        )
        result.extra["proxy_bytes"] = _proxy_bytes
        return result.to_dict()

    def _enrich_batch(self, lots: list[CarLot], stats: dict) -> None:
        if not lots:
            return
        ids = [lot.id for lot in lots]
        id_map = {lot.id: lot for lot in lots}

        for i in range(0, len(ids), _BATCH_SIZE):
            chunk = ids[i: i + _BATCH_SIZE]
            try:
                details = self._client.batch_details(chunk)
                pass  # batch_details received
                enriched = 0
                for detail in details:
                    manage = detail.get("manage") or {}
                    # dummy=True: inner vehicleId differs from listing Id;
                    # dummyVehicleId holds the actual listing Id we requested.
                    if manage.get("dummy") and manage.get("dummyVehicleId"):
                        listing_id = str(manage["dummyVehicleId"])
                    else:
                        listing_id = str(detail.get("vehicleId", ""))
                    # Also store inner vehicleId for inspection API calls
                    inner_id = str(detail.get("vehicleId", ""))
                    lot = id_map.get(listing_id)
                    if lot:
                        if inner_id and inner_id != listing_id:
                            lot.raw_data["inspect_vehicle_id"] = inner_id
                        _enrich_from_detail(lot, detail, self._norm)
                        enriched += 1
                    else:
                        pass  # unmatched listing
                pass  # batch enrichment done
            except Exception as e:
                logger.warning(f"[encar] batch_details failed ({type(e).__name__}: {e}), falling back to single fetch")
                ok = 0
                for vid in chunk:
                    try:
                        detail = self._client.detail(vid)
                        if vid in id_map:
                            _enrich_from_detail(id_map[vid], detail, self._norm)
                            ok += 1
                    except Exception as e2:
                        logger.error(f"[encar] detail {vid} error: {type(e2).__name__}: {e2}")
                        stats["errors"] += 1
                        # rotate proxy on block/rate-limit/proxy error
                        if isinstance(e2, httpx.HTTPStatusError) and e2.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504):
                            self._client.rotate_proxy()
                            logger.info(f"[encar] rotated proxy after {e2.response.status_code}")
                        elif isinstance(e2, (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout)):
                            self._client.rotate_proxy()
                    _time.sleep(0.5)
                logger.info(f"[encar] single fallback: enriched {ok}/{len(chunk)} lots")

    @staticmethod
    def _fetch_lot_enrichment(
        lot: CarLot, client: EncarClient, norm: EncarNormalizer
    ) -> tuple[CarLot, InspectionRecord | None, int]:
        """HTTP-only enrichment for one lot. Safe to run in a thread — no DB access."""
        source = _SOURCE
        insp_record: InspectionRecord | None = None
        is_certified = False
        errors = 0

        _inner_id = lot.raw_data.get("inspect_vehicle_id") or lot.id
        condition = set(lot.raw_data.get("condition") or [])
        has_record     = "Record"     in condition
        has_inspection = "Inspection" in condition

        def _call(fn, *args, _max_retries=3):
            """Call fn(*args), retry up to _max_retries with backoff on rate-limit/block."""
            for attempt in range(_max_retries + 1):
                try:
                    return fn(*args)
                except httpx.HTTPStatusError as e:
                    if e.response.status_code in (401, 403, 407, 408, 410, 429, 502, 503, 504) and attempt < _max_retries:
                        wait = 1 * (2 ** attempt)  # 1s, 2s, 4s
                        logger.warning(f"[{source}] {e.response.status_code} on {lot.id} — retry {attempt+1}/{_max_retries} in {wait}s")
                        _time.sleep(wait)
                        client.rotate_proxy()
                        continue
                    raise
                except (httpx.ProxyError, httpx.ConnectError, httpx.ReadTimeout) as e:
                    if attempt < _max_retries:
                        wait = 1 * (2 ** attempt)
                        logger.warning(f"[{source}] {type(e).__name__} on {lot.id} — retry {attempt+1}/{_max_retries} in {wait}s")
                        _time.sleep(wait)
                        client.rotate_proxy()
                        continue
                    raise

        # Record API — only if car has record data
        if has_record:
            try:
                rec = _call(client.record, _inner_id, lot.plate_number or None)
                if rec and rec.get("openData"):
                    is_certified = True
                    insp_record = _enrich_from_record(lot, rec)
            except Exception as e:
                logger.warning(f"[{source}] record {lot.id}: {e}")
                errors += 1

        # Inspection JSON API — only if car has inspection data
        insp_api_ok = False
        if has_inspection:
            try:
                insp = _call(client.inspection, _inner_id)
                if insp:
                    if insp_record is None:
                        insp_record = InspectionRecord(lot_id=lot.id, source="encar")
                    _enrich_from_inspection(lot, insp, insp_record)
                    is_certified = True
                    insp_api_ok = True
            except Exception as e:
                logger.warning(f"[{source}] inspection {lot.id}: {e}")

        # NOTE: diagnosis endpoint disabled — returns 404 via proxies (requires
        # origin:fem.encar.com header which proxies strip). The /inspection
        # endpoint already provides identical data (outers, inners, master).
        # Removing saves ~10s per page of wasted 404 calls.

        # NOTE: inspection_html removed after field-source audit (called 0/40, 0 unique fields).
        # sellingpoint kept — provides drive_type for ~2.5% of lots not covered by search.

        if not lot.drive_type:
            try:
                sp = _call(client.sellingpoint, lot.id)
                if sp:
                    _enrich_from_sellingpoint(lot, sp, norm)
            except Exception as e:
                logger.warning(f"[{source}] sellingpoint {lot.id}: {e}")

        return lot, insp_record, errors

    def _enrich_accident_data(self, lots: list[CarLot], stats: dict) -> None:
        """Fetch record + inspection in parallel; DB writes on main thread."""
        source = _SOURCE
        workers = min(Config.ENCAR_WORKERS, len(lots))

        proxy_list = _generate_floppy_proxies(count=max(workers, 20)) if Config.FLOPPYDATA_API_KEY else []

        def _task(lot: CarLot, idx: int) -> tuple[CarLot, InspectionRecord | None, int]:
            if proxy_list:
                proxy = proxy_list[idx % len(proxy_list)]
            else:
                proxy = None
            client = EncarClient(proxy=proxy)
            try:
                return self._fetch_lot_enrichment(lot, client, self._norm)
            finally:
                client.close()

        results: list[tuple[CarLot, InspectionRecord | None, int]] = []
        with ThreadPoolExecutor(max_workers=workers) as pool:
            future_map = {pool.submit(_task, lot, idx): lot for idx, lot in enumerate(lots)}
            for i, future in enumerate(as_completed(future_map)):
                try:
                    lot, insp_record, errors = future.result()
                except Exception as e:
                    orig_lot = future_map[future]
                    logger.error(f"[{source}] worker failed for {orig_lot.id}: {e}")
                    errors = 1
                    lot, insp_record = orig_lot, None
                stats["errors"] += errors
                results.append((lot, insp_record))

        # DB writes — main thread only
        n_accident = n_flood = n_insp = 0
        for lot, insp_record in results:
            try:
                self.repo.upsert_batch([lot], stats)
                pass  # lot enriched
                if lot.has_accident:    n_accident += 1
                if lot.flood_history:   n_flood    += 1
            except Exception as e:
                logger.warning(f"[{source}] upsert lot {lot.id} after accident enrich: {e}")
            if insp_record is not None:
                n_insp += 1
                try:
                    self.repo.upsert_inspection(insp_record)
                except Exception as e:
                    logger.warning(f"[{source}] upsert_inspection {lot.id}: {e}")
        logger.info(
            f"[STAT] [{source}] enriched {len(results)} lots: "
            f"accident={n_accident}, flood={n_flood}, insp={n_insp}"
        )

        # Post-filters: evaluate rules that depend on inspection data
        enriched_ids = [lot.id for lot, _ in results]
        if enriched_ids:
            try:
                post_deactivated = self.repo.apply_post_filters(enriched_ids, stats)
                if post_deactivated:
                    logger.info(f"[{source}] post-filter deactivated {post_deactivated} lots")
            except Exception as e:
                logger.warning(f"[{source}] post-filter error: {e}")
