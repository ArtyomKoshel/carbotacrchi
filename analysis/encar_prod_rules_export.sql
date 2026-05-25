-- ============================================================
-- STG → PROD: Encar taxonomy rules export
-- ============================================================
-- Purpose: generate INSERT statements from STG taxonomy_rules
--          and taxonomy_terms to apply on PROD.
--
-- How to use:
--   1) Run this on STG DB (after validation is complete).
--   2) Copy the `sql_line` output.
--   3) Execute the copied SQL on PROD DB.
--   4) Then run normalization on PROD:
--        php artisan lots:normalize-encar-taxonomy --chunk=1000
--      Verify dry-run first, then:
--        php artisan lots:normalize-encar-taxonomy --apply --chunk=1000
-- ============================================================

-- ── 1. taxonomy_terms (package_hints, trim_hints, tail_powertrain_tokens) ──

SELECT '-- taxonomy_terms' AS sql_line
UNION ALL
SELECT CONCAT(
    'INSERT IGNORE INTO `taxonomy_terms` ',
    '(`source`,`term_type`,`term`,`priority`,`is_active`,`created_at`,`updated_at`) VALUES (',
    QUOTE(source), ',',
    QUOTE(term_type), ',',
    QUOTE(term), ',',
    priority, ',',
    is_active, ',',
    'NOW(),NOW()',
    ');'
) AS sql_line
FROM taxonomy_terms
WHERE source IN ('encar', '*') AND is_active = 1
ORDER BY FIELD(sql_line, '-- taxonomy_terms') DESC, term_type, id ASC;

-- ── 2. taxonomy_rules ──────────────────────────────────────────────────────

SELECT '-- taxonomy_rules' AS sql_line
UNION ALL
SELECT CONCAT(
    'INSERT IGNORE INTO `taxonomy_rules` ',
    '(`source`,`make`,`model_contains`,`unknown_tail`,`action`,`action_value`,`priority`,`is_active`,`notes`,`created_at`,`updated_at`) VALUES (',
    QUOTE(source), ',',
    IF(make            IS NULL, 'NULL', QUOTE(make)),            ',',
    IF(model_contains  IS NULL, 'NULL', QUOTE(model_contains)),  ',',
    IF(unknown_tail    IS NULL, 'NULL', QUOTE(unknown_tail)),     ',',
    QUOTE(action), ',',
    IF(action_value    IS NULL, 'NULL', QUOTE(action_value)),     ',',
    priority, ',',
    is_active, ',',
    IF(notes           IS NULL, 'NULL', QUOTE(notes)),            ',',
    'NOW(),NOW()',
    ');'
) AS sql_line
FROM taxonomy_rules
WHERE source = 'encar' AND is_active = 1
ORDER BY priority ASC, id ASC;

-- ── 3. Quick sanity check (run on STG before exporting) ────────────────────

SELECT
    'rules_total'          AS metric, COUNT(*)                                    AS value FROM taxonomy_rules  WHERE source='encar' AND is_active=1
UNION ALL SELECT
    'rules_set_trim',                  COUNT(*)  FROM taxonomy_rules  WHERE source='encar' AND is_active=1 AND action='set_trim'
UNION ALL SELECT
    'rules_set_generation',            COUNT(*)  FROM taxonomy_rules  WHERE source='encar' AND is_active=1 AND action='set_generation'
UNION ALL SELECT
    'rules_strip_tail',                COUNT(*)  FROM taxonomy_rules  WHERE source='encar' AND is_active=1 AND action='strip_tail'
UNION ALL SELECT
    'terms_package_hints',             COUNT(*)  FROM taxonomy_terms  WHERE source IN ('encar','*') AND is_active=1 AND term_type='package_hint'
UNION ALL SELECT
    'terms_trim_hints',                COUNT(*)  FROM taxonomy_terms  WHERE source IN ('encar','*') AND is_active=1 AND term_type='trim_hint'
UNION ALL SELECT
    'terms_tail_powertrain',           COUNT(*)  FROM taxonomy_terms  WHERE source IN ('encar','*') AND is_active=1 AND term_type='tail_powertrain_token';
