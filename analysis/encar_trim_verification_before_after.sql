-- Encar trim verification: unified SQL for BEFORE/AFTER snapshots
-- Usage:
--   1) Set run id once:   SET @run_id = 'stg_20260524_2230';
--   2) Before apply:      SET @phase = 'before';
--   3) Run CAPTURE PHASE SNAPSHOT block.
--   4) After apply:       SET @phase = 'after';
--   5) Run CAPTURE PHASE SNAPSHOT block again.
--   6) Run DIFF block.

SET @run_id = COALESCE(@run_id, DATE_FORMAT(NOW(), 'stg_%Y%m%d_%H%i%s'));
SET @phase  = COALESCE(@phase, 'before');

-- Safety
SELECT @run_id AS run_id, @phase AS phase;

CREATE TABLE IF NOT EXISTS verification_trim_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id VARCHAR(64) NOT NULL,
  phase VARCHAR(16) NOT NULL,
  captured_at DATETIME NOT NULL,
  source VARCHAR(32) NOT NULL,
  metric_name VARCHAR(128) NOT NULL,
  metric_value DECIMAL(20,4) NOT NULL,
  UNIQUE KEY uniq_metric (run_id, phase, source, metric_name)
);

CREATE TABLE IF NOT EXISTS verification_trim_top_empty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id VARCHAR(64) NOT NULL,
  phase VARCHAR(16) NOT NULL,
  captured_at DATETIME NOT NULL,
  source VARCHAR(32) NOT NULL,
  make VARCHAR(80) NULL,
  model VARCHAR(191) NULL,
  cnt INT NOT NULL,
  KEY idx_run_phase_source_cnt (run_id, phase, source, cnt)
);

CREATE TABLE IF NOT EXISTS verification_trim_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id VARCHAR(64) NOT NULL,
  phase VARCHAR(16) NOT NULL,
  captured_at DATETIME NOT NULL,
  lot_id VARCHAR(100) NOT NULL,
  source VARCHAR(32) NOT NULL,
  make VARCHAR(80) NULL,
  model VARCHAR(191) NULL,
  generation VARCHAR(64) NULL,
  trim_value VARCHAR(191) NULL,
  KEY idx_run_phase_source_lot (run_id, phase, source, lot_id)
);

-- =========================================================
-- CAPTURE PHASE SNAPSHOT (run for before and after)
-- =========================================================

-- Cleanup current run+phase (idempotent re-run)
DELETE FROM verification_trim_metrics   WHERE run_id=@run_id AND phase=@phase AND source='encar';
DELETE FROM verification_trim_top_empty WHERE run_id=@run_id AND phase=@phase AND source='encar';
DELETE FROM verification_trim_rows      WHERE run_id=@run_id AND phase=@phase AND source='encar';

-- Core metrics
INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', 'total', COUNT(*)
FROM lots
WHERE source='encar';

INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', 'empty_trim',
       SUM(CASE WHEN TRIM(COALESCE(`trim`, '')) = '' THEN 1 ELSE 0 END)
FROM lots
WHERE source='encar';

INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', 'empty_trim_pct',
       ROUND(
         100 * SUM(CASE WHEN TRIM(COALESCE(`trim`, '')) = '' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0),
         2
       )
FROM lots
WHERE source='encar';

INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', 'badge_detail_but_no_trim', COUNT(*)
FROM lots
WHERE source='encar'
  AND TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.badge_detail_kr')), '')) <> ''
  AND TRIM(COALESCE(`trim`, '')) = '';

INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', 'generation_empty',
       SUM(CASE WHEN TRIM(COALESCE(generation, '')) = '' THEN 1 ELSE 0 END)
FROM lots
WHERE source='encar';

-- Taxonomy readiness metrics
INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', CONCAT('taxonomy_terms_', term_type), COUNT(*)
FROM taxonomy_terms
WHERE source IN ('encar','*') AND is_active=1
GROUP BY term_type;

INSERT INTO verification_trim_metrics (run_id, phase, captured_at, source, metric_name, metric_value)
SELECT @run_id, @phase, NOW(), 'encar', CONCAT('taxonomy_rules_', action), COUNT(*)
FROM taxonomy_rules
WHERE source IN ('encar','*') AND is_active=1
GROUP BY action;

-- Top-50 empty trim models
INSERT INTO verification_trim_top_empty (run_id, phase, captured_at, source, make, model, cnt)
SELECT @run_id, @phase, NOW(), 'encar', make, model, COUNT(*) cnt
FROM lots
WHERE source='encar' AND TRIM(COALESCE(`trim`, ''))=''
GROUP BY make, model
ORDER BY cnt DESC
LIMIT 50;

-- Row-level snapshot for diff
INSERT INTO verification_trim_rows (run_id, phase, captured_at, lot_id, source, make, model, generation, trim_value)
SELECT @run_id, @phase, NOW(), id, source, make, model, generation, `trim`
FROM lots
WHERE source='encar';

-- Show captured metrics for current phase
SELECT metric_name, metric_value
FROM verification_trim_metrics
WHERE run_id=@run_id AND phase=@phase AND source='encar'
ORDER BY metric_name;

-- Show top empty trim models for current phase
SELECT make, model, cnt
FROM verification_trim_top_empty
WHERE run_id=@run_id AND phase=@phase AND source='encar'
ORDER BY cnt DESC, make, model
LIMIT 50;

-- =========================================================
-- DIFF (run after both before/after snapshots exist)
-- =========================================================

SELECT
  b.metric_name,
  b.metric_value AS before_value,
  a.metric_value AS after_value,
  (a.metric_value - b.metric_value) AS delta
FROM verification_trim_metrics b
JOIN verification_trim_metrics a
  ON a.run_id=b.run_id
 AND a.source=b.source
 AND a.metric_name=b.metric_name
WHERE b.run_id=@run_id
  AND b.source='encar'
  AND b.phase='before'
  AND a.phase='after'
ORDER BY b.metric_name;

-- Changed rows overview (model/generation/trim)
SELECT
  SUM(CASE WHEN COALESCE(b.model,'') <> COALESCE(a.model,'') THEN 1 ELSE 0 END) AS model_changed,
  SUM(CASE WHEN COALESCE(b.generation,'') <> COALESCE(a.generation,'') THEN 1 ELSE 0 END) AS generation_changed,
  SUM(CASE WHEN COALESCE(TRIM(b.trim_value),'')='' AND COALESCE(TRIM(a.trim_value),'')<>'' THEN 1 ELSE 0 END) AS trim_filled_from_empty,
  SUM(CASE WHEN COALESCE(TRIM(b.trim_value),'')<>'' AND COALESCE(TRIM(a.trim_value),'')='' THEN 1 ELSE 0 END) AS trim_became_empty
FROM verification_trim_rows b
JOIN verification_trim_rows a
  ON a.run_id=b.run_id
 AND a.source=b.source
 AND a.lot_id=b.lot_id
WHERE b.run_id=@run_id
  AND b.source='encar'
  AND b.phase='before'
  AND a.phase='after';

-- Sample of changed rows (manual QA)
SELECT
  b.lot_id,
  b.make,
  b.model AS model_before,
  a.model AS model_after,
  b.generation AS generation_before,
  a.generation AS generation_after,
  b.trim_value AS trim_before,
  a.trim_value AS trim_after
FROM verification_trim_rows b
JOIN verification_trim_rows a
  ON a.run_id=b.run_id
 AND a.source=b.source
 AND a.lot_id=b.lot_id
WHERE b.run_id=@run_id
  AND b.source='encar'
  AND b.phase='before'
  AND a.phase='after'
  AND (
    COALESCE(b.model,'') <> COALESCE(a.model,'')
    OR COALESCE(b.generation,'') <> COALESCE(a.generation,'')
    OR COALESCE(b.trim_value,'') <> COALESCE(a.trim_value,'')
  )
ORDER BY b.lot_id
LIMIT 100;

-- Empty trim top-models delta (before vs after)
SELECT
  COALESCE(b.make, a.make) AS make,
  COALESCE(b.model, a.model) AS model,
  COALESCE(b.cnt, 0) AS before_cnt,
  COALESCE(a.cnt, 0) AS after_cnt,
  COALESCE(a.cnt, 0) - COALESCE(b.cnt, 0) AS delta
FROM (
  SELECT make, model, cnt
  FROM verification_trim_top_empty
  WHERE run_id=@run_id AND phase='before' AND source='encar'
) b
LEFT JOIN (
  SELECT make, model, cnt
  FROM verification_trim_top_empty
  WHERE run_id=@run_id AND phase='after' AND source='encar'
) a
  ON a.make <=> b.make AND a.model <=> b.model
UNION ALL
SELECT
  a.make,
  a.model,
  0,
  a.cnt,
  a.cnt
FROM (
  SELECT make, model, cnt
  FROM verification_trim_top_empty
  WHERE run_id=@run_id AND phase='after' AND source='encar'
) a
LEFT JOIN (
  SELECT make, model, cnt
  FROM verification_trim_top_empty
  WHERE run_id=@run_id AND phase='before' AND source='encar'
) b
  ON a.make <=> b.make AND a.model <=> b.model
WHERE b.make IS NULL AND b.model IS NULL
ORDER BY delta DESC, after_cnt DESC
LIMIT 100;

-- =========================================================
-- RECENT PARSER WRITE-PATH CHECKS (after parser run)
-- =========================================================

SELECT COUNT(*) total_recent,
       SUM(CASE WHEN TRIM(COALESCE(`trim`,''))='' THEN 1 ELSE 0 END) empty_trim_recent
FROM lots
WHERE source='encar' AND parsed_at >= NOW() - INTERVAL 1 HOUR;

SELECT COUNT(*) badge_detail_but_no_trim_recent
FROM lots
WHERE source='encar'
  AND parsed_at >= NOW() - INTERVAL 1 HOUR
  AND TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_data,'$.badge_detail_kr')), '')) <> ''
  AND TRIM(COALESCE(`trim`, '')) = '';
