-- Reset taxonomy tables before re-seeding from scratch.
-- Run BEFORE encar_rules_seed.sql + encar_taxonomy_generated.sql.
-- WARNING: deletes ALL encar rules and terms. Manual rules will be lost.

START TRANSACTION;

DELETE FROM taxonomy_rule_hits;
DELETE FROM taxonomy_anomaly_queue WHERE source = 'encar';
DELETE FROM taxonomy_rules WHERE source = 'encar';
DELETE FROM taxonomy_terms WHERE source = 'encar';

COMMIT;
