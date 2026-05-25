-- ============================================================
-- Encar taxonomy seed: consolidated (replaces batch1 + batch2)
-- Source of truth for STG and PROD.
-- All INSERTs are idempotent (LEFT JOIN WHERE id IS NULL).
-- ============================================================


-- ── 1. taxonomy_terms (package_hints) ─────────────────────────────────────

INSERT INTO taxonomy_terms
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


-- ── Quick sanity check ─────────────────────────────────────────────────────

SELECT
    CONCAT(COALESCE(make,'*'), ' | ', COALESCE(model_contains, CONCAT('tail:', unknown_tail))) AS rule,
    action_value,
    priority,
    hit_count
FROM taxonomy_rules
WHERE source = 'encar' AND is_active = 1
ORDER BY priority DESC, hit_count DESC;
