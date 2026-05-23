# Encar Dump Taxonomy Analysis

Generated at: 2026-05-23T14:20:19.219700Z

## Core Metrics
- total: 231614
- active: 165120
- empty_trim: 121169
- empty_trim_pct: 52.32
- model_has_engine_token: 209991
- model_has_engine_token_pct: 90.66
- model_has_drive_token: 103139
- model_has_drive_token_pct: 44.53
- model_has_fuel_token: 95489
- model_has_fuel_token_pct: 41.23
- model_has_seat_token: 21259
- model_has_seat_token_pct: 9.18
- empty_trim_but_trim_hint_in_model: 66760
- empty_trim_but_trim_hint_in_model_pct_of_empty_trim: 55.1

## Top Empty Trim Models (Top 40)
- Kia | 카니발 4세대 9인승 시그니처 -> 1453
- Kia | 카니발 4세대 9인승 노블레스 -> 1338
- Kia | 카니발 4세대 9인승 프레스티지 -> 1276
- Genesis | GV80 3.0 디젤 AWD -> 781
- Kia | 더 뉴 기아 레이 시그니처 -> 741
- Hyundai | 그랜저 HG HG240 모던 -> 696
- BMW | 5시리즈 (G30) 520i M 스포츠 -> 625
- Hyundai | 아반떼 AD 1.6 GDI 밸류 플러스 -> 613
- Hyundai | 캐스퍼 터보 인스퍼레이션 -> 611
- Kia | 올 뉴 카니발 9인승 프레스티지 -> 585
- Ford | 익스플로러 6세대 2.3 리미티드 4WD -> 566
- Kia | 카니발 4세대 가솔린 9인승 시그니처 -> 528
- Kia | 올 뉴 모닝 (JA) 럭셔리 -> 513
- Kia | 더 뉴 레이 프레스티지 -> 492
- Chevrolet | 더 뉴 스파크 프리미어 -> 488
- Chevrolet | 더 넥스트 스파크 LTZ -> 483
- Mercedes-Benz | E-클래스 W213 E250 아방가르드 -> 477
- BMW | 5시리즈 (G30) 520i 럭셔리 -> 470
- Kia | 카니발 4세대 7인승 시그니처 -> 467
- Genesis | GV80 2.5T 가솔린 2WD -> 466
- Kia | 올 뉴 카니발 9인승 노블레스 -> 453
- BMW | 3시리즈 (G20) 320i M 스포츠 -> 452
- Audi | A6 (C8) 45 TFSI 콰트로 프리미엄 -> 446
- BMW | X6 (G06) xDrive40i M 스포츠 -> 444
- Kia | K8 하이브리드 노블레스 -> 443
- Mercedes-Benz | E-클래스 W213 E250 익스클루시브 -> 436
- Kia | 더 뉴 모닝 럭셔리 -> 433
- Renault Korea | XM3 1.3 TCe RE 시그니처 -> 423
- Mercedes-Benz | E-클래스 W213 E350 4MATIC AMG Line -> 398
- Kia | 더 뉴 카니발 9인승 노블레스 스페셜 -> 390
- Renault Korea | SM6 2.0 GDe LE -> 377
- Renault Korea | SM6 2.0 GDe RE -> 375
- Renault Korea | 더 뉴 QM6 2.0 LPe RE 시그니처 2WD -> 375
- Kia | 카니발 4세대 가솔린 7인승 시그니처 -> 373
- Kia | K8 하이브리드 시그니처 -> 364
- Mercedes-Benz | GLE-클래스 W167 GLE450 4MATIC -> 358
- BMW | 5시리즈 (G60) 520i M 스포츠 -> 354
- Kia | 더 뉴 카니발 9인승 프레스티지 -> 352
- Audi | A6 (C8) 45 TFSI 프리미엄 -> 351
- Hyundai | 제네시스 DH G330 프리미엄 AWD -> 349

## Top Trim Values (Top 40)
- (세부등급 없음) -> 15616
- 프레스티지 -> 13151
- 시그니처 -> 6409
- 노블레스 -> 6352
- 모던 -> 5182
- 캘리그래피 -> 4268
- 프리미엄 -> 4079
- 익스클루시브 -> 3630
- 인스퍼레이션 -> 3448
- 스마트 -> 2152
- 스페셜 -> 1917
- 트렌디 -> 1902
- 럭셔리 -> 1897
- 3세대 -> 1861
- 2세대 -> 1592
- 그래비티 -> 1414
- 마스터즈 -> 1378
- 프리미엄 럭셔리 -> 1287
- 기본형 -> 1245
- 플러스 -> 1166
- T7 -> 1153
- 노블레스 스페셜 -> 1006
- 프레지던트 -> 934
- 프리미엄 초이스 -> 838
- 고급형 -> 773
- 르블랑 -> 752
- 스타일 -> 713
- RS -> 636
- V3 -> 627
- VIP -> 595
- 5세대 -> 565
- 어스 -> 531
- 플래티넘 -> 522
- 디럭스 -> 519
- C7 -> 470
- 익스클루시브 스페셜 -> 435
- 스탠다드 -> 410
- 마스터 -> 362
- 프레스티지 스페셜 -> 340
- 마스터즈 그래비티 -> 334

## Recommendations
- Stop writing badge/fuel/drive tokens into `model` for new Encar ingestion.
- Add first-class `generation` column and parse from model patterns like `(G30)`, `W213`, `NX4`.
- Build one-time backfill command to split mixed `model` into clean `model` + `generation` + inferred trim.
- Use confidence-scored rewrite: strict rules auto-apply, uncertain rows flagged for review.
