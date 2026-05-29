-- Fix typos and semantic errors in translations table
-- Run: mysql -u root -p carbot < analysis/fix_translations_typos.sql

UPDATE translations SET en = 'Standard'          WHERE id = 14;
UPDATE translations SET en = 'N Line'             WHERE id = 379;
UPDATE translations SET en = 'GT-Line'            WHERE id = 495;
UPDATE translations SET en = 'Smart Standard'     WHERE id = 406;
UPDATE translations SET en = 'Prime Safety'       WHERE id = 452;
UPDATE translations SET en = 'Limited Edition'    WHERE id = 415;
UPDATE translations SET en = '1 Million Ultimate' WHERE id = 483;
UPDATE translations SET en = '1 Million Style'    WHERE id = 477;
UPDATE translations SET en = 'Noblesse(Taxi)'     WHERE id = 448;

-- Semantic fixes
UPDATE translations SET en = 'Model Taxi'         WHERE id = 332;  -- 모범형 = 모범택시 variant
UPDATE translations SET en = 'Limited'            WHERE id = 502;  -- LTD = Limited
UPDATE translations SET en = 'Dynamic'            WHERE id = 456;  -- 다이나믹 ≠ 다이내믹 에디션 (id 352)
UPDATE translations SET en = NULL                 WHERE id = 110;  -- (세부등급 없음) → filter out, not string "null"
