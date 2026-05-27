-- ============================================================
-- Mine grade_detail_en from existing lots → populate translations (category='trim')
-- Run this AFTER: php artisan migrate && mysql < encar_translations_seed.sql
-- Extracts official Encar EN translations already stored in raw_data
-- ============================================================

INSERT INTO translations (category, kr, en, source, created_at, updated_at)
SELECT
    'trim',
    JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr')) AS kr,
    JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en')) AS en,
    'encar_official',
    NOW(),
    NOW()
FROM lots
WHERE
    source = 'encar'
    AND raw_data IS NOT NULL
    AND JSON_EXTRACT(raw_data, '$.badge_detail_kr') IS NOT NULL
    AND JSON_EXTRACT(raw_data, '$.badge_detail_kr') != 'null'
    AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr')) != ''
    AND JSON_EXTRACT(raw_data, '$.grade_detail_en') IS NOT NULL
    AND JSON_EXTRACT(raw_data, '$.grade_detail_en') != 'null'
    AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en')) != ''
    AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr'))
        != JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en'))  -- skip trivial same-value entries
GROUP BY
    JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr')),
    JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en'))
ON DUPLICATE KEY UPDATE
    en         = IF(source != 'encar_official', VALUES(en), en),
    source     = 'encar_official',
    updated_at = NOW();


-- ──────────────────────────────────────────────────────────────
-- Check what was inserted / preview before running
-- ──────────────────────────────────────────────────────────────

-- Preview unique (kr, en) pairs from lots:
-- SELECT
--     JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr')) AS kr,
--     JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en')) AS en,
--     COUNT(*) AS cnt
-- FROM lots
-- WHERE source = 'encar'
--   AND JSON_EXTRACT(raw_data, '$.badge_detail_kr') IS NOT NULL
--   AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr')) != ''
--   AND JSON_EXTRACT(raw_data, '$.grade_detail_en') IS NOT NULL
--   AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en')) != ''
--   AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.badge_detail_kr'))
--       != JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.grade_detail_en'))
-- GROUP BY 1, 2
-- ORDER BY cnt DESC;

-- ──────────────────────────────────────────────────────────────
-- After running: check coverage
-- ──────────────────────────────────────────────────────────────

-- SELECT
--     COUNT(DISTINCT l.trim) AS unique_trims_in_lots,
--     COUNT(DISTINCT t.kr)   AS covered_by_translations,
--     ROUND(COUNT(DISTINCT t.kr) / COUNT(DISTINCT l.trim) * 100, 1) AS coverage_pct
-- FROM lots l
-- LEFT JOIN trim_translations t ON t.kr = l.trim
-- WHERE l.source = 'encar' AND l.trim IS NOT NULL AND l.trim != '(세부등급 없음)';
