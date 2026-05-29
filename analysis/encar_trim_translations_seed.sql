-- ============================================================
-- translations seed: Korean → English across multiple categories
-- Sources:
--   'encar_official' = taken directly from Encar's grade_detail_en field
--   'manual'         = un-romanization / known translations
-- ============================================================
-- Run AFTER migration 2026_05_27_000003_create_trim_translations_table
-- ============================================================

INSERT INTO translations (category, kr, en, ru, source, created_at, updated_at) VALUES

-- ──────────────────────────────────────────────────────────────
-- SECTION 1: Core tier/grade names (loan words from EN/FR)
-- ──────────────────────────────────────────────────────────────
('프레스티지',               'Prestige',              NULL, 'manual', NOW(), NOW()),
('프리미엄',                 'Premium',               NULL, 'manual', NOW(), NOW()),
('노블레스',                 'Noblesse',              NULL, 'manual', NOW(), NOW()),
('익스클루시브',             'Exclusive',             NULL, 'manual', NOW(), NOW()),
('캘리그래피',               'Calligraphy',           NULL, 'manual', NOW(), NOW()),
('인스퍼레이션',             'Inspiration',           NULL, 'manual', NOW(), NOW()),
('시그니처',                 'Signature',             NULL, 'manual', NOW(), NOW()),
('트렌디',                   'Trendy',                NULL, 'encar_official', NOW(), NOW()),
('스마트',                   'Smart',                 NULL, 'encar_official', NOW(), NOW()),
('모던',                     'Modern',                NULL, 'manual', NOW(), NOW()),
('럭셔리',                   'Luxury',                NULL, 'manual', NOW(), NOW()),
('디럭스',                   'Deluxe',                NULL, 'manual', NOW(), NOW()),
('스페셜',                   'Special',               NULL, 'manual', NOW(), NOW()),
('스탠다드',                 'Standard',              NULL, 'manual', NOW(), NOW()),
('스타일',                   'Style',                 NULL, 'manual', NOW(), NOW()),
('플래티넘',                 'Platinum',              NULL, 'manual', NOW(), NOW()),
('플러스',                   'Plus',                  NULL, 'manual', NOW(), NOW()),
('프리미어',                 'Premier',               NULL, 'manual', NOW(), NOW()),
('프레지던트',               'President',             NULL, 'manual', NOW(), NOW()),
('마스터',                   'Master',                NULL, 'manual', NOW(), NOW()),
('마스터즈',                 'Masters',               NULL, 'manual', NOW(), NOW()),
('엘리트',                   'Elite',                 NULL, 'manual', NOW(), NOW()),
('어드벤처',                 'Adventure',             NULL, 'manual', NOW(), NOW()),
('그래비티',                 'Gravity',               NULL, 'manual', NOW(), NOW()),
('클럽',                     'Club',                  NULL, 'manual', NOW(), NOW()),
('어스',                     'Earth',                 NULL, 'manual', NOW(), NOW()),
('에어',                     'Air',                   NULL, 'manual', NOW(), NOW()),
('르블랑',                   'Le Blanc',              NULL, 'manual', NOW(), NOW()),
('라이트',                   'Lite',                  NULL, 'manual', NOW(), NOW()),
('퍼포먼스',                 'Performance',           NULL, 'manual', NOW(), NOW()),
('아방가르드',               'Avantgarde',            NULL, 'manual', NOW(), NOW()),
('엘레강스',                 'Elegance',              NULL, 'manual', NOW(), NOW()),
('그란루쏘',                 'GranLusso',             NULL, 'manual', NOW(), NOW()),

-- ──────────────────────────────────────────────────────────────
-- SECTION 2: Korean descriptive grade names
-- ──────────────────────────────────────────────────────────────
('기본형',                   'Base',                  NULL, 'manual', NOW(), NOW()),
('고급형',                   'Premium',               NULL, 'manual', NOW(), NOW()),
('최고급형',                 'Top',                   NULL, 'manual', NOW(), NOW()),
('스페셜 에디션',            'Special Edition',       NULL, 'manual', NOW(), NOW()),

-- ──────────────────────────────────────────────────────────────
-- SECTION 3: Multi-word compound trims (official Encar EN)
-- ──────────────────────────────────────────────────────────────
('CVX 럭셔리',               'CVX Luxury',            NULL, 'encar_official', NOW(), NOW()),
('CVX 프리미엄',             'CVX Premium',           NULL, 'encar_official', NOW(), NOW()),
('CVX 디럭스',               'CVX Deluxe',            NULL, 'encar_official', NOW(), NOW()),
('노블레스 스페셜',          'Noblesse Special',      NULL, 'encar_official', NOW(), NOW()),
('노블레스 그래비티',        'Noblesse Gravity',      NULL, 'encar_official', NOW(), NOW()),
('익스클루시브 스페셜',      'Exclusive Special',     NULL, 'encar_official', NOW(), NOW()),
('캘리그래피 블랙에디션',    'Calligraphy Black Edition', NULL, 'encar_official', NOW(), NOW()),
('프레스티지 초이스',        'Prestige Choice',       NULL, 'manual', NOW(), NOW()),
('프레스티지 플러스',        'Prestige Plus',         NULL, 'manual', NOW(), NOW()),
('프리미엄 초이스',          'Premium Choice',        NULL, 'manual', NOW(), NOW()),
('프리미엄 플러스',          'Premium Plus',          NULL, 'manual', NOW(), NOW()),
('프리미엄 패밀리',          'Premium Family',        NULL, 'manual', NOW(), NOW()),
('시그니처 그래비티',        'Signature Gravity',     NULL, 'encar_official', NOW(), NOW()),
('시그니처 X Line',          'Signature X Line',      NULL, 'encar_official', NOW(), NOW()),
('마스터즈 그래비티',        'Masters Gravity',       NULL, 'encar_official', NOW(), NOW()),
('인스퍼레이션 N Line',      'Inspiration N Line',    NULL, 'manual', NOW(), NOW()),
('N Line 인스퍼레이션',      'N Line Inspiration',    NULL, 'manual', NOW(), NOW()),
('N Line 프리미엄',          'N Line Premium',        NULL, 'manual', NOW(), NOW()),
('모던 아트',                'Modern Art',            NULL, 'manual', NOW(), NOW()),
('GT Line',                  'GT Line',               NULL, 'encar_official', NOW(), NOW()),
('하이 루프',                'High-roof',             NULL, 'encar_official', NOW(), NOW()),
('A 타입',                   'A Type',                NULL, 'encar_official', NOW(), NOW()),
('B 타입',                   'B Type',                NULL, 'encar_official', NOW(), NOW()),
('E 타입',                   'E Type',                NULL, 'encar_official', NOW(), NOW()),

-- ──────────────────────────────────────────────────────────────
-- SECTION 4: Kia/Hyundai grade-level trims
-- ──────────────────────────────────────────────────────────────
('플래티넘Ⅰ',               'PlatinumⅠ',             NULL, 'encar_official', NOW(), NOW()),
('플래티넘Ⅱ',               'PlatinumⅡ',             NULL, 'encar_official', NOW(), NOW()),
('플래티넘Ⅲ',               'PlatinumⅢ',             NULL, 'encar_official', NOW(), NOW()),
('그랜드 마스터즈',          'Grand Masters',         NULL, 'encar_official', NOW(), NOW()),
('그랜드 플래티넘',          'Grand Platinum',        NULL, 'encar_official', NOW(), NOW()),
('베스트 셀렉션 Ⅰ',         'Best Selection Ⅰ',      NULL, 'encar_official', NOW(), NOW()),
('베스트 셀렉션 Ⅱ',         'Best Selection Ⅱ',      NULL, 'encar_official', NOW(), NOW()),
('VIP',                      'VIP',                   NULL, 'encar_official', NOW(), NOW()),

-- ──────────────────────────────────────────────────────────────
-- SECTION 5: Hyundai-specific trims
-- ──────────────────────────────────────────────────────────────
('프레스티지 캠퍼 4인승',    'Prestige Camper 4-seat', NULL, 'encar_official', NOW(), NOW()),

-- ──────────────────────────────────────────────────────────────
-- SECTION 6: Generation codes used as trim by Encar
-- (These ARE valid trim identifiers for specific brands)
-- ──────────────────────────────────────────────────────────────
('1세대',                    '1st Gen',               NULL, 'encar_official', NOW(), NOW()),
('2세대',                    '2nd Gen',               NULL, 'encar_official', NOW(), NOW()),
('3세대',                    '3rd Gen',               NULL, 'encar_official', NOW(), NOW()),
('4세대',                    '4th Gen',               NULL, 'encar_official', NOW(), NOW()),
('5세대',                    '5th Gen',               NULL, 'encar_official', NOW(), NOW()),
('6세대',                    '6th Gen',               NULL, 'encar_official', NOW(), NOW()),

-- Porsche internal codes
('991',                      '991',                   NULL, 'encar_official', NOW(), NOW()),
('981',                      '981',                   NULL, 'encar_official', NOW(), NOW()),
('958',                      '958',                   NULL, 'encar_official', NOW(), NOW()),
('970',                      '970',                   NULL, 'encar_official', NOW(), NOW()),
('971',                      '971',                   NULL, 'encar_official', NOW(), NOW()),
('992',                      '992',                   NULL, 'encar_official', NOW(), NOW()),

-- Lexus chassis codes
('XF40',                     'XF40',                  NULL, 'encar_official', NOW(), NOW()),
('XV60',                     'XV60',                  NULL, 'encar_official', NOW(), NOW()),
('XV40',                     'XV40',                  NULL, 'encar_official', NOW(), NOW()),
('XU30',                     'XU30',                  NULL, 'encar_official', NOW(), NOW()),
('XE30',                     'XE30',                  NULL, 'encar_official', NOW(), NOW()),
('XE20',                     'XE20',                  NULL, 'encar_official', NOW(), NOW()),
('ZWA10',                    'ZWA10',                 NULL, 'encar_official', NOW(), NOW()),

-- BMW classic chassis codes
('E36',                      'E36',                   NULL, 'encar_official', NOW(), NOW()),
('E46',                      'E46',                   NULL, 'encar_official', NOW(), NOW()),
('E34',                      'E34',                   NULL, 'encar_official', NOW(), NOW()),
('E38',                      'E38',                   NULL, 'encar_official', NOW(), NOW()),
('E39',                      'E39',                   NULL, 'encar_official', NOW(), NOW()),
('E60',                      'E60',                   NULL, 'encar_official', NOW(), NOW()),
('F10',                      'F10',                   NULL, 'encar_official', NOW(), NOW()),
('F30',                      'F30',                   NULL, 'encar_official', NOW(), NOW()),

-- KG Mobility (SsangYong) grade codes
('V1',                       'V1',                    NULL, 'encar_official', NOW(), NOW()),
('V3',                       'V3',                    NULL, 'encar_official', NOW(), NOW()),
('V5',                       'V5',                    NULL, 'encar_official', NOW(), NOW()),
('V7',                       'V7',                    NULL, 'encar_official', NOW(), NOW()),
('T5',                       'T5',                    NULL, 'encar_official', NOW(), NOW()),
('T7',                       'T7',                    NULL, 'encar_official', NOW(), NOW()),
('TL7',                      'TL7',                   NULL, 'encar_official', NOW(), NOW()),
('S7',                       'S7',                    NULL, 'encar_official', NOW(), NOW()),
('S8',                       'S8',                    NULL, 'encar_official', NOW(), NOW()),
('S9',                       'S9',                    NULL, 'encar_official', NOW(), NOW()),

-- Nissan chassis codes
('K12',                      'K12',                   NULL, 'encar_official', NOW(), NOW()),
('L32',                      'L32',                   NULL, 'encar_official', NOW(), NOW()),
('L33',                      'L33',                   NULL, 'encar_official', NOW(), NOW()),
('F15',                      'F15',                   NULL, 'encar_official', NOW(), NOW()),
('Z12',                      'Z12',                   NULL, 'encar_official', NOW(), NOW()),
('J32',                      'J32',                   NULL, 'encar_official', NOW(), NOW()),

-- Audi generation codes
('B8',                       'B8',                    NULL, 'encar_official', NOW(), NOW()),
('B9',                       'B9',                    NULL, 'encar_official', NOW(), NOW()),
('C7',                       'C7',                    NULL, 'encar_official', NOW(), NOW()),
('D4',                       'D4',                    NULL, 'encar_official', NOW(), NOW()),
('8R',                       '8R',                    NULL, 'encar_official', NOW(), NOW()),
('8V',                       '8V',                    NULL, 'encar_official', NOW(), NOW()),
('8U',                       '8U',                    NULL, 'encar_official', NOW(), NOW()),
('4G',                       '4G',                    NULL, 'encar_official', NOW(), NOW()),
('5N',                       '5N',                    NULL, 'encar_official', NOW(), NOW()),

-- Jeep codes
('WK2',                      'WK2',                   NULL, 'encar_official', NOW(), NOW()),
('KL',                       'KL',                    NULL, 'encar_official', NOW(), NOW()),

-- Maserati
('M157',                     'M157',                  NULL, 'encar_official', NOW(), NOW()),

-- Jaguar
('X350',                     'X350',                  NULL, 'encar_official', NOW(), NOW()),
('X351',                     'X351',                  NULL, 'encar_official', NOW(), NOW()),
('X152',                     'X152',                  NULL, 'encar_official', NOW(), NOW()),

-- Mini
('R56',                      'R56',                   NULL, 'encar_official', NOW(), NOW()),
('R60',                      'R60',                   NULL, 'encar_official', NOW(), NOW()),
('F55',                      'F55',                   NULL, 'encar_official', NOW(), NOW()),
('F56',                      'F56',                   NULL, 'encar_official', NOW(), NOW()),
('F60',                      'F60',                   NULL, 'encar_official', NOW(), NOW()),

-- ──────────────────────────────────────────────────────────────
-- SECTION 7: Special body / use variants (not really trims)
-- ──────────────────────────────────────────────────────────────
('익스큐티브',               'Executive',             NULL, 'encar_official', NOW(), NOW()),
('PYL',                      'PYL',                   NULL, 'encar_official', NOW(), NOW()),
('(세부등급 없음)',           NULL,                    NULL, 'manual', NOW(), NOW())

ON DUPLICATE KEY UPDATE
  en      = IF(VALUES(source) = 'encar_official', VALUES(en), en),
  source  = IF(VALUES(source) = 'encar_official', 'encar_official', source),
  updated_at = NOW();
