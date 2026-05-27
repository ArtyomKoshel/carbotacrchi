-- ============================================================
-- translations table seed: Korean → English across all categories
-- Sources:
--   'encar_official' = directly from Encar's grade_detail_en / official names
--   'manual'         = un-romanization of Korean loan words
-- ============================================================
-- Run AFTER: php artisan migrate (creates translations table)
-- ============================================================

INSERT INTO translations (category, kr, en, ru, source, created_at, updated_at) VALUES

-- ══════════════════════════════════════════════════════════════
-- CATEGORY: trim  (badge_detail_kr values)
-- ══════════════════════════════════════════════════════════════

-- Core tier names (loan words: Korean romanization → English original)
('trim', '프레스티지',               'Prestige',               NULL, 'manual', NOW(), NOW()),
('trim', '프리미엄',                 'Premium',                NULL, 'manual', NOW(), NOW()),
('trim', '노블레스',                 'Noblesse',               NULL, 'manual', NOW(), NOW()),
('trim', '익스클루시브',             'Exclusive',              NULL, 'manual', NOW(), NOW()),
('trim', '캘리그래피',               'Calligraphy',            NULL, 'manual', NOW(), NOW()),
('trim', '인스퍼레이션',             'Inspiration',            NULL, 'manual', NOW(), NOW()),
('trim', '시그니처',                 'Signature',              NULL, 'manual', NOW(), NOW()),
('trim', '트렌디',                   'Trendy',                 NULL, 'encar_official', NOW(), NOW()),
('trim', '스마트',                   'Smart',                  NULL, 'encar_official', NOW(), NOW()),
('trim', '모던',                     'Modern',                 NULL, 'manual', NOW(), NOW()),
('trim', '럭셔리',                   'Luxury',                 NULL, 'manual', NOW(), NOW()),
('trim', '디럭스',                   'Deluxe',                 NULL, 'manual', NOW(), NOW()),
('trim', '스페셜',                   'Special',                NULL, 'manual', NOW(), NOW()),
('trim', '스탠다드',                 'Standard',               NULL, 'manual', NOW(), NOW()),
('trim', '스타일',                   'Style',                  NULL, 'manual', NOW(), NOW()),
('trim', '플래티넘',                 'Platinum',               NULL, 'manual', NOW(), NOW()),
('trim', '플러스',                   'Plus',                   NULL, 'manual', NOW(), NOW()),
('trim', '프리미어',                 'Premier',                NULL, 'manual', NOW(), NOW()),
('trim', '프레지던트',               'President',              NULL, 'manual', NOW(), NOW()),
('trim', '마스터',                   'Master',                 NULL, 'manual', NOW(), NOW()),
('trim', '마스터즈',                 'Masters',                NULL, 'manual', NOW(), NOW()),
('trim', '엘리트',                   'Elite',                  NULL, 'manual', NOW(), NOW()),
('trim', '어드벤처',                 'Adventure',              NULL, 'manual', NOW(), NOW()),
('trim', '그래비티',                 'Gravity',                NULL, 'manual', NOW(), NOW()),
('trim', '클럽',                     'Club',                   NULL, 'manual', NOW(), NOW()),
('trim', '어스',                     'Earth',                  NULL, 'manual', NOW(), NOW()),
('trim', '에어',                     'Air',                    NULL, 'manual', NOW(), NOW()),
('trim', '르블랑',                   'Le Blanc',               NULL, 'manual', NOW(), NOW()),
('trim', '라이트',                   'Lite',                   NULL, 'manual', NOW(), NOW()),
('trim', '퍼포먼스',                 'Performance',            NULL, 'manual', NOW(), NOW()),
('trim', '아방가르드',               'Avantgarde',             NULL, 'manual', NOW(), NOW()),
('trim', '엘레강스',                 'Elegance',               NULL, 'manual', NOW(), NOW()),
('trim', '그란루쏘',                 'GranLusso',              NULL, 'manual', NOW(), NOW()),
('trim', '익스큐티브',               'Executive',              NULL, 'encar_official', NOW(), NOW()),

-- Korean descriptive grade names
('trim', '기본형',                   'Base',                   NULL, 'manual', NOW(), NOW()),
('trim', '고급형',                   'Premium',                NULL, 'manual', NOW(), NOW()),
('trim', '최고급형',                 'Top',                    NULL, 'manual', NOW(), NOW()),
('trim', '스페셜 에디션',            'Special Edition',        NULL, 'manual', NOW(), NOW()),

-- Multi-word compound trims
('trim', '노블레스 스페셜',          'Noblesse Special',       NULL, 'encar_official', NOW(), NOW()),
('trim', '노블레스 그래비티',        'Noblesse Gravity',       NULL, 'encar_official', NOW(), NOW()),
('trim', '익스클루시브 스페셜',      'Exclusive Special',      NULL, 'encar_official', NOW(), NOW()),
('trim', '캘리그래피 블랙에디션',    'Calligraphy Black Ed.',  NULL, 'encar_official', NOW(), NOW()),
('trim', '프레스티지 초이스',        'Prestige Choice',        NULL, 'manual', NOW(), NOW()),
('trim', '프레스티지 플러스',        'Prestige Plus',          NULL, 'manual', NOW(), NOW()),
('trim', '프리미엄 초이스',          'Premium Choice',         NULL, 'manual', NOW(), NOW()),
('trim', '프리미엄 플러스',          'Premium Plus',           NULL, 'manual', NOW(), NOW()),
('trim', '프리미엄 럭셔리',          'Premium Luxury',         NULL, 'manual', NOW(), NOW()),
('trim', '프리미엄 패밀리',          'Premium Family',         NULL, 'manual', NOW(), NOW()),
('trim', '시그니처 그래비티',        'Signature Gravity',      NULL, 'encar_official', NOW(), NOW()),
('trim', '마스터즈 그래비티',        'Masters Gravity',        NULL, 'encar_official', NOW(), NOW()),
('trim', 'CVX 럭셔리',               'CVX Luxury',             NULL, 'encar_official', NOW(), NOW()),
('trim', 'CVX 프리미엄',             'CVX Premium',            NULL, 'encar_official', NOW(), NOW()),
('trim', 'CVX 디럭스',               'CVX Deluxe',             NULL, 'encar_official', NOW(), NOW()),
('trim', '하이 루프',                'High-roof',              NULL, 'encar_official', NOW(), NOW()),
('trim', 'A 타입',                   'A Type',                 NULL, 'encar_official', NOW(), NOW()),
('trim', 'B 타입',                   'B Type',                 NULL, 'encar_official', NOW(), NOW()),
('trim', 'E 타입',                   'E Type',                 NULL, 'encar_official', NOW(), NOW()),
('trim', '플래티넘Ⅰ',               'PlatinumⅠ',              NULL, 'encar_official', NOW(), NOW()),
('trim', '플래티넘Ⅱ',               'PlatinumⅡ',              NULL, 'encar_official', NOW(), NOW()),
('trim', '플래티넘Ⅲ',               'PlatinumⅢ',              NULL, 'encar_official', NOW(), NOW()),
('trim', '그랜드 마스터즈',          'Grand Masters',          NULL, 'encar_official', NOW(), NOW()),
('trim', '그랜드 플래티넘',          'Grand Platinum',         NULL, 'encar_official', NOW(), NOW()),
('trim', '베스트 셀렉션 Ⅰ',         'Best Selection Ⅰ',       NULL, 'encar_official', NOW(), NOW()),
('trim', '베스트 셀렉션 Ⅱ',         'Best Selection Ⅱ',       NULL, 'encar_official', NOW(), NOW()),
('trim', 'VIP',                      'VIP',                    NULL, 'encar_official', NOW(), NOW()),
('trim', '프레스티지 캠퍼 4인승',    'Prestige Camper 4-seat', NULL, 'encar_official', NOW(), NOW()),

-- Generation codes used as trim by Encar (specific brands)
('trim', '1세대',                    '1st Gen',                NULL, 'encar_official', NOW(), NOW()),
('trim', '2세대',                    '2nd Gen',                NULL, 'encar_official', NOW(), NOW()),
('trim', '3세대',                    '3rd Gen',                NULL, 'encar_official', NOW(), NOW()),
('trim', '4세대',                    '4th Gen',                NULL, 'encar_official', NOW(), NOW()),
('trim', '5세대',                    '5th Gen',                NULL, 'encar_official', NOW(), NOW()),
('trim', '6세대',                    '6th Gen',                NULL, 'encar_official', NOW(), NOW()),

-- Porsche / Lexus / BMW / Audi / Nissan / Jeep chassis codes (already EN, kept for completeness)
('trim', '991',   '991',   NULL, 'encar_official', NOW(), NOW()),
('trim', '981',   '981',   NULL, 'encar_official', NOW(), NOW()),
('trim', '958',   '958',   NULL, 'encar_official', NOW(), NOW()),
('trim', '970',   '970',   NULL, 'encar_official', NOW(), NOW()),
('trim', '971',   '971',   NULL, 'encar_official', NOW(), NOW()),
('trim', '992',   '992',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'XF40',  'XF40',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'XV60',  'XV60',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'XV40',  'XV40',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'XU30',  'XU30',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'XE30',  'XE30',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'XE20',  'XE20',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'ZWA10', 'ZWA10', NULL, 'encar_official', NOW(), NOW()),
('trim', 'V1',    'V1',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'V3',    'V3',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'V5',    'V5',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'V7',    'V7',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'T5',    'T5',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'T7',    'T7',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'TL7',   'TL7',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'S7',    'S7',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'S8',    'S8',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'S9',    'S9',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'B8',    'B8',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'B9',    'B9',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'C7',    'C7',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'D4',    'D4',    NULL, 'encar_official', NOW(), NOW()),
('trim', 'WK2',   'WK2',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'X350',  'X350',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'X351',  'X351',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'X152',  'X152',  NULL, 'encar_official', NOW(), NOW()),
('trim', 'L33',   'L33',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'L32',   'L32',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'K12',   'K12',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'F15',   'F15',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'Z12',   'Z12',   NULL, 'encar_official', NOW(), NOW()),
('trim', 'J32',   'J32',   NULL, 'encar_official', NOW(), NOW()),
('trim', '(세부등급 없음)',           NULL,                     NULL, 'manual', NOW(), NOW()),

-- ══════════════════════════════════════════════════════════════
-- CATEGORY: color  (exterior color field)
-- ══════════════════════════════════════════════════════════════
('color', '흰색',         'White',          NULL, 'manual', NOW(), NOW()),
('color', '백색',         'White',          NULL, 'manual', NOW(), NOW()),
('color', '검정',         'Black',          NULL, 'manual', NOW(), NOW()),
('color', '검정색',       'Black',          NULL, 'manual', NOW(), NOW()),
('color', '은색',         'Silver',         NULL, 'manual', NOW(), NOW()),
('color', '은회색',       'Silver Gray',    NULL, 'manual', NOW(), NOW()),
('color', '회색',         'Gray',           NULL, 'manual', NOW(), NOW()),
('color', '진회색',       'Dark Gray',      NULL, 'manual', NOW(), NOW()),
('color', '파란색',       'Blue',           NULL, 'manual', NOW(), NOW()),
('color', '남색',         'Navy Blue',      NULL, 'manual', NOW(), NOW()),
('color', '빨간색',       'Red',            NULL, 'manual', NOW(), NOW()),
('color', '빨강',         'Red',            NULL, 'manual', NOW(), NOW()),
('color', '주황색',       'Orange',         NULL, 'manual', NOW(), NOW()),
('color', '노란색',       'Yellow',         NULL, 'manual', NOW(), NOW()),
('color', '노랑',         'Yellow',         NULL, 'manual', NOW(), NOW()),
('color', '초록색',       'Green',          NULL, 'manual', NOW(), NOW()),
('color', '녹색',         'Green',          NULL, 'manual', NOW(), NOW()),
('color', '담녹색',       'Light Green',    NULL, 'manual', NOW(), NOW()),
('color', '갈색',         'Brown',          NULL, 'manual', NOW(), NOW()),
('color', '베이지',       'Beige',          NULL, 'manual', NOW(), NOW()),
('color', '크림',         'Cream',          NULL, 'manual', NOW(), NOW()),
('color', '진주',         'Pearl White',    NULL, 'manual', NOW(), NOW()),
('color', '진주색',       'Pearl',          NULL, 'manual', NOW(), NOW()),
('color', '금색',         'Gold',           NULL, 'manual', NOW(), NOW()),
('color', '자주색',       'Maroon',         NULL, 'manual', NOW(), NOW()),
('color', '보라색',       'Purple',         NULL, 'manual', NOW(), NOW()),
('color', '와인색',       'Wine Red',       NULL, 'manual', NOW(), NOW()),
('color', '흰색투톤',     'White Two-tone', NULL, 'manual', NOW(), NOW()),

-- ══════════════════════════════════════════════════════════════
-- CATEGORY: seat_color  (interior/seat color field)
-- ══════════════════════════════════════════════════════════════
('seat_color', '검정색 계열',   'Black',          NULL, 'manual', NOW(), NOW()),
('seat_color', '갈색 계열',     'Brown / Beige',  NULL, 'manual', NOW(), NOW()),
('seat_color', '회색 계열',     'Gray',           NULL, 'manual', NOW(), NOW()),
('seat_color', '베이지 계열',   'Beige',          NULL, 'manual', NOW(), NOW()),
('seat_color', '빨간색 계열',   'Red',            NULL, 'manual', NOW(), NOW()),
('seat_color', '흰색 계열',     'White',          NULL, 'manual', NOW(), NOW()),
('seat_color', '아이보리 계열', 'Ivory',          NULL, 'manual', NOW(), NOW()),
('seat_color', '크림 계열',     'Cream',          NULL, 'manual', NOW(), NOW()),

-- ══════════════════════════════════════════════════════════════
-- CATEGORY: model_prefix  (Korean model name prefixes to strip/translate)
-- ══════════════════════════════════════════════════════════════
('model_prefix', '더 뉴',      'The New',   NULL, 'manual', NOW(), NOW()),
('model_prefix', '올 뉴',      'All New',   NULL, 'manual', NOW(), NOW()),
('model_prefix', '더 올 뉴',   'The All New', NULL, 'manual', NOW(), NOW()),
('model_prefix', '뉴',         'New',       NULL, 'manual', NOW(), NOW()),
('model_prefix', '더',         'The',       NULL, 'manual', NOW(), NOW()),
('model_prefix', '베리 뉴',    'Very New',  NULL, 'manual', NOW(), NOW()),
('model_prefix', '뉴 더',      'New The',   NULL, 'manual', NOW(), NOW())

ON DUPLICATE KEY UPDATE
  en         = IF(VALUES(source) = 'encar_official' AND source != 'encar_official', VALUES(en), en),
  source     = IF(VALUES(source) = 'encar_official', 'encar_official', source),
  updated_at = NOW();
