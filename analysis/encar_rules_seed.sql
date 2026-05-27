-- ============================================================
-- Encar taxonomy seed: consolidated (replaces batch1 + batch2)
-- Source of truth for STG and PROD.
-- All INSERTs use INSERT IGNORE for idempotency (safe to re-run).
-- ============================================================

-- ── 0. Fix rows inserted before ENUM was extended (term_type = '') ─────────
--      Run AFTER migration 2026_05_26_000002_extend_taxonomy_enums

UPDATE taxonomy_terms SET term_type = 'gen_non_chassis_token'
WHERE source = 'encar' AND term_type = ''
  AND term IN ('EV','HEV','PHEV','GDI','TDI','TFSI','MPI','AWD','FWD','RWD','4WD','2WD');

UPDATE taxonomy_terms SET term_type = 'gen_exclude_token'
WHERE source = 'encar' AND term_type = ''
  AND term IN ('V6','V8','V10','V12','Q4','VS380','CW700','EL300','G330');

UPDATE taxonomy_terms SET term_type = 'variant_exclude'
WHERE source = 'encar' AND term_type = ''
  AND term IN ('BEV','FHEV','CRDI','VGT','TCI','TSI','FSI','GDE','GTE','LPLI','LPE','LPI','AWD4','TCE','LPG');

UPDATE taxonomy_terms SET term_type = 'special_tag'
WHERE source = 'encar' AND term_type = ''
  AND term IN ('장애인용','리무진','캠핑카');

UPDATE taxonomy_terms SET term_type = 'engine_family_tokens'
WHERE source = 'encar' AND term_type = ''
  AND term IN ('T-GDI','TGDI','MPFI','e-VGT','4MATIC','블루텍','4TRONIC');

-- Delete any remaining orphan rows with term_type = '' after above updates
DELETE FROM taxonomy_terms WHERE source = 'encar' AND term_type = '';


-- ── 1. taxonomy_terms (package_hints) ─────────────────────────────────────

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'package_hint', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT '터보 패키지'  AS term
    UNION ALL SELECT '플래티넘 패키지'
    UNION ALL SELECT 'LT 래더패키지'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'package_hint' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 2. unknown_tail → set_trim ────────────────────────────────────────────
--      Source: auto-bootstrap IDs 1-25 + batch2 extras

INSERT INTO taxonomy_rules
    (source, make, model_contains, unknown_tail, action, action_value, priority, is_active, notes, created_at, updated_at)
SELECT
    'encar', NULL, NULL,
    m.unknown_tail, 'set_trim', m.action_value,
    90, 1,
    'unknown_tail seed', NOW(), NOW()
FROM (
    -- auto-bootstrapped (IDs 1–25, sorted by hit_count desc)
    SELECT '밸류 플러스'      AS unknown_tail, '밸류 플러스'      AS action_value
    UNION ALL SELECT '럭셔리 플러스',      '럭셔리 플러스'
    UNION ALL SELECT 'X 에디션',           'X 에디션'
    UNION ALL SELECT '스페셜 에디션',      '스페셜 에디션'
    UNION ALL SELECT 'AWD 레드라인',       '레드라인'
    UNION ALL SELECT '플래티넘 에디션',    '플래티넘 에디션'
    UNION ALL SELECT '베스트 셀렉션',      '베스트 셀렉션'
    UNION ALL SELECT '익스클루시브 에디션','익스클루시브 에디션'
    UNION ALL SELECT '2.0 스타일',         '스타일'
    UNION ALL SELECT '프리미엄 플러스',    '프리미엄 플러스'
    UNION ALL SELECT '기어 에디션',        '기어 에디션'
    UNION ALL SELECT '60th 에디션',        '60th 에디션'
    UNION ALL SELECT '퍼스트 에디션',      '퍼스트 에디션'
    UNION ALL SELECT '모던 플러스',        '모던 플러스'
    UNION ALL SELECT '1.4 레드라인',       '레드라인'
    UNION ALL SELECT '시그니처 스페셜',    '시그니처 스페셜'
    UNION ALL SELECT 'LT 래더패키지',      'LT 래더패키지'
    UNION ALL SELECT '기어 플러스',        '기어 플러스'
    UNION ALL SELECT 'GDI 스타일',         '스타일'
    UNION ALL SELECT '프리미엄 스페셜',    '프리미엄 스페셜'
    UNION ALL SELECT 'AMG 라인',           'AMG 라인'
    UNION ALL SELECT '클래식 플러스',      '클래식 플러스'
    UNION ALL SELECT 'SE 플러스',          'SE 플러스'
    UNION ALL SELECT '마이핏 에디션',      '마이핏 에디션'
    UNION ALL SELECT '2.0 케어플러스',     '케어플러스'
    -- batch2 extras (IDs 26–31 range + remaining)
    UNION ALL SELECT 'LPI 스타일',         '스타일'
    UNION ALL SELECT '레인지 플러스',      '레인지 플러스'
    UNION ALL SELECT '아크로 에디션',      '아크로 에디션'
    UNION ALL SELECT '리미티드 에디션',    '리미티드 에디션'
    UNION ALL SELECT '스마트 스페셜',      '스마트 스페셜'
    UNION ALL SELECT 'Line 에디션',        'Line 에디션'
    UNION ALL SELECT '2WD R-플러스',       'R-플러스'
    UNION ALL SELECT '리미티드 플러스',    '리미티드 플러스'
    UNION ALL SELECT '퍼포먼스 에디션',    '퍼포먼스 에디션'
    UNION ALL SELECT '파이니스트 에디션',  '파이니스트 에디션'
    UNION ALL SELECT '아웃도어 에디션',    '아웃도어 에디션'
    UNION ALL SELECT '블랙 에디션',        '블랙 에디션'
    UNION ALL SELECT 'GT 플러스',          'GT 플러스'
    UNION ALL SELECT '레솔루트 에디션',    '레솔루트 에디션'
    UNION ALL SELECT '스포츠 플러스',      '스포츠 플러스'
    UNION ALL SELECT '레드핏 에디션',      '레드핏 에디션'
    UNION ALL SELECT '테크노 플러스',      '테크노 플러스'
    UNION ALL SELECT '모던 스페셜',        '모던 스페셜'
) m
LEFT JOIN taxonomy_rules r
  ON  r.source = 'encar'
  AND COALESCE(r.make, '')          = ''
  AND COALESCE(r.model_contains, '') = ''
  AND COALESCE(r.unknown_tail, '')  = COALESCE(m.unknown_tail, '')
  AND r.action                      = 'set_trim'
  AND COALESCE(r.action_value, '')  = COALESCE(m.action_value, '')
  AND r.is_active = 1
WHERE r.id IS NULL;


-- ── 3. model_contains → set_trim (web-verified) ───────────────────────────
--      Source: batch1, IDs 32–66

INSERT INTO taxonomy_rules
    (source, make, model_contains, unknown_tail, action, action_value, priority, is_active, notes, created_at, updated_at)
SELECT
    'encar', m.make, m.model_contains, NULL,
    'set_trim', m.action_value,
    85, 1,
    'model_contains seed (web-verified)', NOW(), NOW()
FROM (
    -- Kia
    SELECT 'Kia' AS make, ' 시그니처'     AS model_contains, '시그니처'     AS action_value
    UNION ALL SELECT 'Kia', ' 노블레스',     '노블레스'
    UNION ALL SELECT 'Kia', ' 프레스티지',   '프레스티지'
    UNION ALL SELECT 'Kia', ' 럭셔리',       '럭셔리'
    UNION ALL SELECT 'Kia', ' 디럭스',       '디럭스'
    UNION ALL SELECT 'Kia', ' 트렌디',       '트렌디'
    UNION ALL SELECT 'Kia', ' 베스트 셀렉션','베스트 셀렉션'
    -- Hyundai
    UNION ALL SELECT 'Hyundai', ' 인스퍼레이션', '인스퍼레이션'
    UNION ALL SELECT 'Hyundai', ' 캘리그래피',   '캘리그래피'
    UNION ALL SELECT 'Hyundai', ' 익스클루시브', '익스클루시브'
    UNION ALL SELECT 'Hyundai', ' 르블랑',       '르블랑'
    UNION ALL SELECT 'Hyundai', ' 모던',          '모던'
    UNION ALL SELECT 'Hyundai', ' 프리미엄',      '프리미엄'
    UNION ALL SELECT 'Hyundai', ' 디 에센셜',     '디 에센셜'
    UNION ALL SELECT 'Hyundai', ' 플럭스',        '플럭스'
    -- BMW
    UNION ALL SELECT 'BMW', ' M 스포츠 플러스', 'M 스포츠 플러스'
    UNION ALL SELECT 'BMW', ' M 스포츠',        'M 스포츠'
    UNION ALL SELECT 'BMW', ' M 퍼포먼스',      'M 퍼포먼스'
    UNION ALL SELECT 'BMW', ' xLine',           'xLine'
    -- Mercedes-Benz
    UNION ALL SELECT 'Mercedes-Benz', ' 아방가르드',  '아방가르드'
    UNION ALL SELECT 'Mercedes-Benz', ' 익스클루시브','익스클루시브'
    UNION ALL SELECT 'Mercedes-Benz', ' AMG Line',    'AMG Line'
    -- Audi
    UNION ALL SELECT 'Audi', ' 프리미엄', '프리미엄'
    -- Renault Korea
    UNION ALL SELECT 'Renault Korea', ' RE 시그니처', 'RE 시그니처'
    UNION ALL SELECT 'Renault Korea', ' RE',          'RE'
    UNION ALL SELECT 'Renault Korea', ' LE',          'LE'
    UNION ALL SELECT 'Renault Korea', ' SE',          'SE'
    UNION ALL SELECT 'Renault Korea', ' 프리미에르',  '프리미에르'
    -- Chevrolet
    UNION ALL SELECT 'Chevrolet', ' 프리미어', '프리미어'
    UNION ALL SELECT 'Chevrolet', ' LTZ',      'LTZ'
    UNION ALL SELECT 'Chevrolet', ' 레드라인', '레드라인'
    -- Ford
    UNION ALL SELECT 'Ford', ' 리미티드', '리미티드'
    -- Volkswagen
    UNION ALL SELECT 'Volkswagen', ' 프레스티지', '프레스티지'
    UNION ALL SELECT 'Volkswagen', ' 럭셔리',     '럭셔리'
    -- Lexus
    UNION ALL SELECT 'Lexus', ' 럭셔리 플러스', '럭셔리 플러스'
) m
LEFT JOIN taxonomy_rules r
  ON  r.source = 'encar'
  AND COALESCE(r.make, '')           = COALESCE(m.make, '')
  AND COALESCE(r.model_contains, '') = COALESCE(m.model_contains, '')
  AND COALESCE(r.unknown_tail, '')   = ''
  AND r.action                       = 'set_trim'
  AND COALESCE(r.action_value, '')   = COALESCE(m.action_value, '')
  AND r.is_active = 1
WHERE r.id IS NULL;


-- ── 4a. taxonomy_terms: tail_powertrain_token ─────────────────────────────
--       Stripped from model TAIL only (used by _strip_tail_noise).

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'tail_powertrain_token', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT '가솔린' AS term  UNION ALL SELECT '디젤'
    UNION ALL SELECT '하이브리드'  UNION ALL SELECT 'HEV'
    UNION ALL SELECT 'LPG'         UNION ALL SELECT '전기'      UNION ALL SELECT 'EV'
    UNION ALL SELECT '2WD'         UNION ALL SELECT '4WD'       UNION ALL SELECT 'AWD'
    UNION ALL SELECT 'FWD'         UNION ALL SELECT 'RWD'       UNION ALL SELECT 'xDrive'
    UNION ALL SELECT 'sDrive'      UNION ALL SELECT '터보'      UNION ALL SELECT 'TCe'
    UNION ALL SELECT 'TFSI'        UNION ALL SELECT 'TDI'       UNION ALL SELECT 'e-VGT'
    UNION ALL SELECT '(택시형)'    UNION ALL SELECT '(렌터카)'  UNION ALL SELECT '(영업용)'
    UNION ALL SELECT 'GDI'         UNION ALL SELECT 'T-GDI'     UNION ALL SELECT 'GDe'
    UNION ALL SELECT 'MPI'         UNION ALL SELECT 'TSI'       UNION ALL SELECT 'FSI'
    UNION ALL SELECT 'CRDi'        UNION ALL SELECT 'VGT'       UNION ALL SELECT 'TCI'
    UNION ALL SELECT 'FHEV'        UNION ALL SELECT 'LPI'       UNION ALL SELECT 'BEV'
    UNION ALL SELECT '4MATIC'      UNION ALL SELECT '4MATIC+'    UNION ALL SELECT '블루텍'
    UNION ALL SELECT 'GTe'         UNION ALL SELECT 'LPLI'       UNION ALL SELECT 'LPe'
    UNION ALL SELECT '4TRONIC'     UNION ALL SELECT '콰트로'
    -- Mercedes diesel suffixes
    UNION ALL SELECT 'CDI'         UNION ALL SELECT 'd'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'tail_powertrain_token' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 4b. taxonomy_terms: gen_non_chassis_token ─────────────────────────────
--       Tokens that look like gen codes but are NOT chassis (blocked from gen).

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'gen_non_chassis_token', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT 'EV'   AS term  UNION ALL SELECT 'HEV'   UNION ALL SELECT 'PHEV'
    UNION ALL SELECT 'GDI'  UNION ALL SELECT 'TDI'  UNION ALL SELECT 'TFSI'
    UNION ALL SELECT 'MPI'  UNION ALL SELECT 'AWD'  UNION ALL SELECT 'FWD'
    UNION ALL SELECT 'RWD'  UNION ALL SELECT '4WD'  UNION ALL SELECT '2WD'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'gen_non_chassis_token' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 4c. taxonomy_terms: gen_exclude_token ─────────────────────────────────
--       Specific non-gen tokens known to look like chassis codes.

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'gen_exclude_token', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT 'V6'    AS term  UNION ALL SELECT 'V8'    UNION ALL SELECT 'V10'
    UNION ALL SELECT 'V12'  UNION ALL SELECT 'Q4'
    UNION ALL SELECT 'VS380' UNION ALL SELECT 'CW700' UNION ALL SELECT 'EL300'
    UNION ALL SELECT 'G330'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'gen_exclude_token' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 4d. taxonomy_terms: variant_exclude ───────────────────────────────────
--       Tokens that match _VARIANT_RE shape but are NOT model variants.

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'variant_exclude', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT 'EV'   AS term  UNION ALL SELECT 'BEV'   UNION ALL SELECT 'HEV'
    UNION ALL SELECT 'FHEV' UNION ALL SELECT 'GDI'  UNION ALL SELECT 'TDI'
    UNION ALL SELECT 'MPI'  UNION ALL SELECT 'CRDI' UNION ALL SELECT 'VGT'
    UNION ALL SELECT 'TCI'  UNION ALL SELECT 'TFSI' UNION ALL SELECT 'TSI'
    UNION ALL SELECT 'FSI'  UNION ALL SELECT 'GDE'  UNION ALL SELECT 'GTE'
    UNION ALL SELECT 'LPLI' UNION ALL SELECT 'LPE'  UNION ALL SELECT 'LPI'
    UNION ALL SELECT '4WD'  UNION ALL SELECT '2WD'  UNION ALL SELECT 'AWD'
    UNION ALL SELECT 'FWD'  UNION ALL SELECT 'RWD'  UNION ALL SELECT 'AWD4'
    UNION ALL SELECT 'V6'   UNION ALL SELECT 'V8'   UNION ALL SELECT 'V10'
    UNION ALL SELECT 'V12'  UNION ALL SELECT 'TCE'  UNION ALL SELECT 'LPG'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'variant_exclude' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 4e. taxonomy_terms: special_tag ───────────────────────────────────────

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'special_tag', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT '장애인용' AS term  UNION ALL SELECT '리무진'  UNION ALL SELECT '캠핑카'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'special_tag' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 4. taxonomy_terms: engine_family_tokens ───────────────────────────────
--      Used by cleanTechSpecTokensFromModel (PHP) to strip engine descriptors
--      from model field after trim/generation extraction.

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'engine_family_tokens', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT 'GDI'     AS term  UNION ALL SELECT 'T-GDI'   UNION ALL SELECT 'TGDI'
    UNION ALL SELECT 'GDe'    UNION ALL SELECT 'MPI'     UNION ALL SELECT 'MPFI'
    UNION ALL SELECT 'TFSI'   UNION ALL SELECT 'TSI'     UNION ALL SELECT 'FSI'
    UNION ALL SELECT 'TDI'    UNION ALL SELECT 'CRDi'    UNION ALL SELECT 'VGT'
    UNION ALL SELECT 'TCI'    UNION ALL SELECT 'e-VGT'
    UNION ALL SELECT 'FHEV'   UNION ALL SELECT 'HEV'
    UNION ALL SELECT 'LPI'    UNION ALL SELECT 'LPG'     UNION ALL SELECT 'LPLI'
    UNION ALL SELECT 'EV'     UNION ALL SELECT 'BEV'
    UNION ALL SELECT '4MATIC' UNION ALL SELECT '블루텍'
    UNION ALL SELECT 'GTe'    UNION ALL SELECT '4TRONIC' UNION ALL SELECT 'TCe'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'engine_family_tokens' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 5. taxonomy_terms: trim_hints (additional) ────────────────────────────

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'trim_hint', t.term, 90, 1, NOW(), NOW()
FROM (
    -- Renault Korea grade tiers (single-token)
    SELECT 'RE'       AS term  UNION ALL SELECT 'LE'
    UNION ALL SELECT 'SE'      UNION ALL SELECT 'PE'
    -- Multi-token Renault grades
    UNION ALL SELECT 'RE Plus' UNION ALL SELECT 'LE Plus'
    UNION ALL SELECT 'SE Plus' UNION ALL SELECT 'PE Plus'
    -- Korean trim names
    UNION ALL SELECT '리미티드'    UNION ALL SELECT '아방가르드'
    UNION ALL SELECT '그란루쏘'   UNION ALL SELECT '엘레강스'
    -- BMW luxury packages
    UNION ALL SELECT '디자인 퓨어 엑셀런스'
    -- Land Rover
    UNION ALL SELECT 'SVR'         UNION ALL SELECT 'SVX'
    UNION ALL SELECT 'HSE'         UNION ALL SELECT 'SE'
    -- Special edition suffix (언차티드 에디션, 삼바 에디션, 하이랜드 에디션, ...)
    UNION ALL SELECT '에디션'
    -- VW/Audi trim line
    UNION ALL SELECT 'R-라인'
    -- BMW trim levels (multi-token → 2-token tail extraction)
    UNION ALL SELECT 'M 스포츠'        UNION ALL SELECT 'M 스포츠 프로'
    UNION ALL SELECT 'xLine'           UNION ALL SELECT '퍼포먼스'
    UNION ALL SELECT '럭셔리'          UNION ALL SELECT '익스클루시브'
    UNION ALL SELECT '온라인 익스클루시브'
    -- Mercedes trim/line
    UNION ALL SELECT 'AMG'             UNION ALL SELECT 'AMG Line'
    UNION ALL SELECT '아방가르드'      UNION ALL SELECT '엘레강스'
    -- Porsche performance trims
    UNION ALL SELECT 'GTS'             UNION ALL SELECT 'Turbo S'
    UNION ALL SELECT 'Turbo'           UNION ALL SELECT 'GT3'
    UNION ALL SELECT 'GT3 RS'          UNION ALL SELECT 'Carrera'
    UNION ALL SELECT '컴페티션'
    -- Common Korean trim used by almost all brands
    UNION ALL SELECT '프리미엄'
    -- Compound edition forms (no space, caught as single token)
    UNION ALL SELECT '온라인에디션'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'trim_hint' AND x.term = t.term
WHERE x.id IS NULL;


-- ── 6. taxonomy_rules: set_fuel (DB-driven, replaces FUEL_TOKEN_MAP) ──────
--      Priority 80 — scans full model+badge string (model_contains).

INSERT INTO taxonomy_rules
    (source, make, model_contains, unknown_tail, action, action_value, priority, is_active, notes, created_at, updated_at)
SELECT
    'encar', NULL, m.model_contains, NULL,
    'set_fuel', m.fuel_value,
    80, 1,
    'fuel token seed', NOW(), NOW()
FROM (
    SELECT '가솔린'       AS model_contains, 'gasoline'      AS fuel_value
    UNION ALL SELECT '디젤',                 'diesel'
    UNION ALL SELECT '하이브리드',           'hybrid'
    UNION ALL SELECT ' HEV',                 'hybrid'
    UNION ALL SELECT ' PHEV',                'plugin_hybrid'
    UNION ALL SELECT 'E-하이브리드',         'plugin_hybrid'
    UNION ALL SELECT ' LPG',                 'lpg'
    UNION ALL SELECT 'LPi',                  'lpg'
    UNION ALL SELECT 'LPe',                  'lpg'
    UNION ALL SELECT 'LPLI',                 'lpg'
    UNION ALL SELECT '전기',                 'electric'
    UNION ALL SELECT ' EV',                  'electric'
    UNION ALL SELECT ' BEV',                 'electric'
    UNION ALL SELECT '블루텍',               'diesel'
    UNION ALL SELECT 'GTe',                  'gasoline'
    UNION ALL SELECT 'GDe',                  'gasoline'
    UNION ALL SELECT 'GDi',                  'gasoline'
    UNION ALL SELECT 'GDI',                  'gasoline'
    UNION ALL SELECT 'T-GDI',               'gasoline'
    UNION ALL SELECT 'MPI',                  'gasoline'
) m
LEFT JOIN taxonomy_rules r
  ON  r.source           = 'encar'
  AND COALESCE(r.make,'')             = ''
  AND COALESCE(r.model_contains, '')  = COALESCE(m.model_contains, '')
  AND COALESCE(r.unknown_tail, '')    = ''
  AND r.action                        = 'set_fuel'
  AND COALESCE(r.action_value, '')    = COALESCE(m.fuel_value, '')
  AND r.is_active = 1
WHERE r.id IS NULL;


-- ── 7. taxonomy_rules: set_drive_type ─────────────────────────────────────
--      Priority 75.

INSERT INTO taxonomy_rules
    (source, make, model_contains, unknown_tail, action, action_value, priority, is_active, notes, created_at, updated_at)
SELECT
    'encar', NULL, m.model_contains, NULL,
    'set_drive_type', m.drive_value,
    75, 1,
    'drive token seed', NOW(), NOW()
FROM (
    SELECT '2WD'    AS model_contains, '2wd'  AS drive_value
    UNION ALL SELECT '4WD',             '4wd'
    UNION ALL SELECT ' AWD',            'awd'
    UNION ALL SELECT ' FWD',            'fwd'
    UNION ALL SELECT ' RWD',            'rwd'
    UNION ALL SELECT 'xDrive',          'awd'
    UNION ALL SELECT '4MATIC',          'awd'
    UNION ALL SELECT '4MATIC+',         'awd'
    UNION ALL SELECT 'sDrive',          'rwd'
    UNION ALL SELECT '4TRONIC',         '4wd'
    UNION ALL SELECT 'QUATTRO',         'awd'
    UNION ALL SELECT 'quattro',         'awd'
    UNION ALL SELECT '콰트로',           'awd'
) m
LEFT JOIN taxonomy_rules r
  ON  r.source           = 'encar'
  AND COALESCE(r.make,'')             = ''
  AND COALESCE(r.model_contains, '')  = COALESCE(m.model_contains, '')
  AND COALESCE(r.unknown_tail, '')    = ''
  AND r.action                        = 'set_drive_type'
  AND COALESCE(r.action_value, '')    = COALESCE(m.drive_value, '')
  AND r.is_active = 1
WHERE r.id IS NULL;


-- ── 8. taxonomy_rules: set_trim for known unknown_tail patterns ───────────

INSERT IGNORE INTO taxonomy_rules
    (source, make, model_contains, unknown_tail, action, action_value, priority, is_active, notes, created_at, updated_at)
SELECT 'encar', NULL, NULL, r.unknown_tail, 'set_trim', r.trim_value, 90, 1, 'unknown_tail trim seed', NOW(), NOW()
FROM (
    SELECT 'AMG 에디션'       AS unknown_tail, 'AMG 에디션'       AS trim_value
    UNION ALL SELECT '제트 패키지',              '제트 패키지'
    UNION ALL SELECT '딜라이트 플러스',          '딜라이트 플러스'
    UNION ALL SELECT 'W 스페셜',                 'W 스페셜'
    UNION ALL SELECT '2.3 ST-라인',              'ST-라인'
    UNION ALL SELECT 'e-VGT 스타일',             '스타일'
    UNION ALL SELECT '메이필드 에디션',          '메이필드 에디션'
    UNION ALL SELECT '시티팝 에디션',            '시티팝 에디션'
    UNION ALL SELECT '80주년 에디션',            '80주년 에디션'
    UNION ALL SELECT '파이널 에디션',            '파이널 에디션'
    UNION ALL SELECT '언테임드 에디션',          '언테임드 에디션'
    UNION ALL SELECT 'AWD 에디션',               '에디션'
) r
LEFT JOIN taxonomy_rules x
  ON x.source = 'encar' AND COALESCE(x.unknown_tail,'') = r.unknown_tail AND x.action = 'set_trim'
WHERE x.id IS NULL;

-- Ford ST-라인: model_contains rule (covers all engine variants: 1.0, 1.5, 2.3 ST-라인)
INSERT IGNORE INTO taxonomy_rules
    (source, make, model_contains, unknown_tail, action, action_value, priority, is_active, notes, created_at, updated_at)
SELECT 'encar', 'Ford', ' ST-라인', NULL, 'set_trim', 'ST-라인', 85, 1, 'Ford ST-Line trim seed', NOW(), NOW()
FROM dual
WHERE NOT EXISTS (
    SELECT 1 FROM taxonomy_rules
    WHERE source = 'encar' AND make = 'Ford'
      AND model_contains = ' ST-라인' AND action = 'set_trim'
);


-- ── 9. taxonomy_terms: Genesis + Volvo model/engine codes ──────────────────
--      Prevents model names & engine designations from being misidentified.

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'gen_non_chassis_token', t.term, 100, 1, NOW(), NOW()
FROM (
    -- Genesis model codes
    SELECT 'G70'  AS term  UNION ALL SELECT 'G80'   UNION ALL SELECT 'G90'
    UNION ALL SELECT 'GV70'              UNION ALL SELECT 'GV80'  UNION ALL SELECT 'GV90'
    UNION ALL SELECT 'EQ900'
    -- Volvo model codes
    UNION ALL SELECT 'XC40'             UNION ALL SELECT 'XC60'  UNION ALL SELECT 'XC90'
    UNION ALL SELECT 'S60'              UNION ALL SELECT 'S90'
    UNION ALL SELECT 'V60'              UNION ALL SELECT 'V90'   UNION ALL SELECT 'C40'
    -- Volvo engine designations (D=diesel, T=petrol, B=mild-hybrid)
    UNION ALL SELECT 'D3'   UNION ALL SELECT 'D4'   UNION ALL SELECT 'D5'
    UNION ALL SELECT 'T4'   UNION ALL SELECT 'T5'   UNION ALL SELECT 'T6'   UNION ALL SELECT 'T8'
    UNION ALL SELECT 'B4'   UNION ALL SELECT 'B5'   UNION ALL SELECT 'B6'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'gen_non_chassis_token' AND x.term = t.term
WHERE x.id IS NULL;

-- Genesis engine grade codes (G + displacement) → gen_exclude_token
INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'gen_exclude_token', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT 'G300' AS term  UNION ALL SELECT 'G350'  UNION ALL SELECT 'G380'
    UNION ALL SELECT 'G400'             UNION ALL SELECT 'G450'
    UNION ALL SELECT 'HG300'            UNION ALL SELECT 'HG330'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'gen_exclude_token' AND x.term = t.term
WHERE x.id IS NULL;

INSERT IGNORE INTO taxonomy_terms
    (source, term_type, term, priority, is_active, created_at, updated_at)
SELECT 'encar', 'variant_exclude', t.term, 100, 1, NOW(), NOW()
FROM (
    SELECT 'G70'  AS term  UNION ALL SELECT 'G80'   UNION ALL SELECT 'G90'
    UNION ALL SELECT 'GV70'              UNION ALL SELECT 'GV80'  UNION ALL SELECT 'GV90'
    UNION ALL SELECT 'EQ900'
    UNION ALL SELECT 'XC40'             UNION ALL SELECT 'XC60'  UNION ALL SELECT 'XC90'
    UNION ALL SELECT 'S60'              UNION ALL SELECT 'S90'
    UNION ALL SELECT 'V60'              UNION ALL SELECT 'V90'   UNION ALL SELECT 'C40'
) t
LEFT JOIN taxonomy_terms x
  ON x.source = 'encar' AND x.term_type = 'variant_exclude' AND x.term = t.term
WHERE x.id IS NULL;


-- ── Quick sanity check ─────────────────────────────────────────────────────

SELECT
    CONCAT(COALESCE(make,'*'), ' | ', COALESCE(model_contains, CONCAT('tail:', unknown_tail))) AS rule,
    action_value,
    priority,
    hit_count
FROM taxonomy_rules
WHERE source = 'encar' AND is_active = 1
ORDER BY priority DESC, hit_count DESC;
