# 12 · Field Map & Data Dictionary

> [← Commands](./11-commands.md) · [Index](./index.md)
>
> Актуально на **2026-05-30** · Источники: **Encar** (API) + **KBCha** (scraper)
>
> Авторитетный источник маппингов — [`parser/parsers/_shared/field_mappings.py`](../parser/parsers/_shared/field_mappings.py)

---

## API-эндпоинты Encar

| Шаг | URL | Назначение |
|-----|-----|-----------|
| **search** | `api.encar.com/search/car/list/mobile` | Листинг объявлений |
| **detail** | `api.encar.com/v1/readside/vehicle/{id}` | Полный лот: VIN, фото, комплектация |
| **record** | `api.encar.com/v1/readside/record/vehicle/{inner_id}/open` | Страховая история (КIDI) |
| **inspection** | `api.encar.com/v1/readside/inspection/vehicle/{inner_id}` | 성능점검 (акт техосмотра) |
| **diagnosis** | `api.encar.com/v1/readside/diagnosis/vehicle/{id}` | Диагностика Encar (только сертиф.) |
| **sellingpoint** | `api.encar.com/v1/readside/diagnosis/vehicle/{id}/sellingpoint` | Привод, спецопции |
| **verification** | `api.encar.com/verification/{id}/simple` | Ключи, тонировка, протектор |

> `inner_id` ≠ `listing ID`. Извлекается из пути к фото:  
> `/carpicture07/pic4097/**40977911**_004.jpg` → `40977911`  
> Хранится в `raw_data["inspect_vehicle_id"]`. Нужен для record + inspection.

---

## API-эндпоинты KBCha

| Шаг | URL | Назначение |
|-----|-----|-----------|
| **list** | `www.kbchachacha.com/public/search/...` | Список по марке |
| **detail** | `www.kbchachacha.com/public/car/detail.kbc?carSeq={id}` | Полная карточка |
| **km_popup** | `www.kbchachacha.com/public/layer/car/km/analysis/info.kbc?carSeq={id}` | Пробег/история |

---

## Колонки таблицы `lots`

### Идентификация

| Колонка | Encar | KBCha | Покр. Encar | Покр. KBCha | Примечание |
|---------|-------|-------|-------------|-------------|-----------|
| `id` | `Id` (search) | `kbcha_{carSeq}` | 100% | 100% | Неймспейс предотвращает коллизии |
| `source` | const `"encar"` | const `"kbcha"` | 100% | 100% | |
| `make` | `Manufacturer` → `ENCAR_MAKE` | `maker_code` → `KBCHA_MAKE` | 100% | 100% | Канонич. EN название |
| `model` | `Model` | `title` (HTML) | 100% | 100% | Корейское название |
| `model_en` | Resolved via `korean_model_names` | Resolved via `korean_model_names` | ~85% | ~80% | Английское; из словаря |
| `generation` | Из `Model`/`ModelGroup` или бейджа | Из `title` | ~60% | ~50% | Код шасси (TL, NX4, G30…) |
| `year` | `FormYear` / `Year` | `연식` cell | 100% | 100% | |
| `vin` | `detail.vin` / `inspection.vin` | `차대번호` (inspection) | 100% | ~80% | |
| `plate_number` | `manage.vehicleNo` | `차량번호` | 100% | ~90% | |

### Даты

| Колонка | Encar | KBCha | Примечание |
|---------|-------|-------|-----------|
| `first_reg_date` | `record.firstDate` / `inspection.firstRegistrationDate` | Из `연식` → YYYY-MM-01 | Дата **первой** регистрации ТС |
| `listed_at` | `manage.registDateTime[:10]` | — | Дата публикации объявления |
| `registration_year_month` | `FormYear` (YYYYMM) | `parse_year_month(연식)` | INT 202006; индексированный |

### Цена и пробег

| Колонка | Encar | KBCha | Покр. | Примечание |
|---------|-------|-------|-------|-----------|
| `price` | `Price * 10000` | `price * 10000` | 100% | **KRW**. Единственная колонка цены. |
| `mileage` | `Mileage` | `주행거리` | 100% | км |
| `retail_value` | `category.originPrice * 10000` | detail retail cell | ~60% / ~50% | Цена нового ТС, KRW |
| `repair_cost` | `myAccidentCost + otherAccidentCost` | — | ~30% | Стоимость ремонта по страховым, KRW |

### Технические характеристики

| Колонка | Encar | KBCha | Покр. Encar | Покр. KBCha |
|---------|-------|-------|-------------|-------------|
| `fuel` | `FuelType` → `ENCAR_FUEL` | `연료` → `KBCHA_FUEL` | 100% | 100% |
| `transmission` | `Transmission` → `ENCAR_TRANS` | `변속기` | 100% | 100% |
| `body_type` | `spec.bodyName` → `ENCAR_BODY` | `차종` | 100% | ~80% |
| `drive_type` | `spec.drivingMethodName` / badge-токены | `구동` / title-токены | ~40% | ~70% |
| `engine_volume` | `spec.displacement / 1000` | `배기량` (cc) | 93% | ~85% |
| `cylinders` | — (нет в API) | — | ~50%* | ~40%* |
| `seat_count` | — (нет в API) | — | ~50%* | ~40%* |
| `color` | `Color` / `spec.colorName` | `차량색상` | 100% | ~90% |
| `seat_color` | `SeatColor` | `시트색상` | 100% | ~70% |

> \* `cylinders` и `seat_count` заполняются **нормализацией через каталог** ([`05-catalog.md`](./05-catalog.md)), не напрямую из API.

### Таксономия (заполняется нормализацией, не парсером)

| Колонка | Источник | Покрытие | Примечание |
|---------|----------|---------|-----------|
| `trim` | API: `BadgeDetail` / KBCha `title` | ~60%+ | Пополняется через `GradeExtractorService` |
| `generation` | API / каталог | ~65%+ | Код поколения/шасси |
| `variant` | Taxonomy rules | ~20% | Подвариант грейда |
| `package` | Taxonomy rules | ~15% | Пакет опций |

### История и состояние

| Колонка | Encar | KBCha | Покр. Encar | Покр. KBCha |
|---------|-------|-------|-------------|-------------|
| `has_accident` | `myAccidentCnt + otherAccidentCnt > 0` | `사고이력` inspection | ~50% | ~80% |
| `flood_history` | `floodTotalLossCnt + floodPartLossCnt > 0` | `침수` inspection | ~50% | ~60% |
| `total_loss_history` | `record.totalLossCnt > 0` | — | ~50% | — |
| `owners_count` | `record.ownerChangeCnt` | `소유자변경` cell | ~50% | ~80% |
| `insurance_count` | `record.accidentCnt` | — | ~50% | — |

> Encar: история доступна только при `openData=true`. Машины без страховых случаев и частники могут его не раскрывать.

### Юридический статус

| Колонка | Encar | KBCha | Примечание |
|---------|-------|-------|-----------|
| `lien_status` | `record.loan → "clean"/"lien"` | `압류` cell | Encar: default "clean" |
| `seizure_status` | `record.robberCnt → "clean"/"seizure"` | `저당` cell | |

### Продажа и ссылки

| Колонка | Encar | KBCha | Примечание |
|---------|-------|-------|-----------|
| `sell_type` | `SellType + AdType + Condition[]` → `normalize_encar()` | `inspection.usage_change` | `dealer/private/auction/rental/lease/…` |
| `sell_type_raw` | pipe-joined raw values | оригинальный usage | Для отладки |
| `lot_url` | `fem.encar.com/cars/detail/{id}` | `kbchachacha.com/public/car/detail.kbc?carSeq={id}` | |
| `image_url` | `Photos[0]` CDN URL | первое фото | |
| `location` | `OfficeCityState` / `contact.address` | dealer location | |

### Опции

| Колонка | Encar | KBCha |
|---------|-------|-------|
| `options` | `options.standard[]` (коды) | detail option list |
| `paid_options` | — | paid options section |

### Служебные

| Колонка | Описание |
|---------|----------|
| `raw_data` | JSON-blob: всё остальное из API + `pre_norm` snapshot |
| `is_active` | `0` = отфильтрован `parse_filters`; не показывается в поиске |
| `parsed_at` | Время последнего парсинга |
| `fetched_at` | Время получения detail |
| `expires_at` | Время истечения актуальности лота |

---

## `raw_data` — ключи (Encar)

| Ключ | API step | Описание |
|------|---------|---------|
| `inspect_vehicle_id` | detail | inner ID для record/inspection API (из пути к фото) |
| `badge_kr` | detail | Бейдж комплектации KR (`BadgeDetail`) |
| `model_raw` | search | Сырое название модели до нормализации |
| `engine_code` | inspection | Код двигателя (G6DN, D4CB…) |
| `warranty_type` | inspection | Тип гарантии (`보험사보증`…) |
| `recall` | inspection | Отзыв (bool) |
| `recall_status` | inspection | Статус выполнения отзыва |
| `car_state` | inspection | Общее состояние (`양호`/`불량`) |
| `mechanical_issues` | inspection | Найденные неисправности |
| `diagnosis_center` | diagnosis | Центр Encar-диагностики |
| `domestic` | detail | Отечественная/иностранная (bool, не хранится как колонка) |
| `origin_price` | detail | Цена нового авто в 만원 (до пересчёта) |
| `pre_norm` | — | Снимок значений **до** первой нормализации (необратимо) |

---

## `raw_data` — ключи (KBCha)

| Ключ | Описание |
|------|---------|
| `inspection_type` | Тип инспекции (`kb_popup`/`url`/`none`) |
| `car_seq` | Числовой ID KBCha |
| `maker_code` | Код марки |
| `pre_norm` | Снимок до нормализации |

---

## Таблица `lot_inspections`

| Колонка | Encar | KBCha | Покр. |
|---------|-------|-------|-------|
| `cert_no` | `master.supplyNum` | номер акта | ~60% / ~40% |
| `inspection_date` | `master.registrationDate` | | ~60% / ~40% |
| `valid_from` / `valid_until` | `detail.validity*Date` | | ~60% |
| `report_url` | шаблон по lot.id | | ~60% |
| `inspection_mileage` | `detail.mileage` | | ~60% |
| `has_accident` | `master.accdient` | `사고이력` | ~60% / ~70% |
| `has_outer_damage` | `outers` list → bool | | ~60% |
| `outer_detail` | `outers` → текст по панелям | | ~30% |
| `has_flood` | `detail.waterlog` | `침수` flag | ~60% |
| `has_tuning` | `detail.tuning` | | ~60% / ~50% |
| `accident_detail` | `accidents[]` → текст | | ~30% |
| `details` | JSON-blob (см. ниже) | | ~60% |

**`details` JSON** содержит:
```json
{
  "simple_repair":       true,
  "engine_check":        "Y",
  "trns_check":          "Y",
  "recall":              true,
  "recall_types":        ["미이행"],
  "mechanical_issues":   ["원동기/오일누유/..."],
  "car_state":           "양호",
  "outer_parts":         [{"part": "후드", "status": ["교환(교체)"]}],
  "accidents":           [...],
  "owner_changes":       ["2024-07-10", "..."],
  "my_accident_cost":    2515897,
  "other_accident_cost": 0
}
```

---

## Сводная матрица покрытия

| Поле | Encar | KBCha |
|------|-------|-------|
| make / model / year | ✅ 100% | ✅ 100% |
| model_en | ✅ ~85% | ✅ ~80% |
| generation | ✅ ~60% | ✅ ~50% |
| trim | ✅ ~65%¹ | ✅ ~70% |
| variant / package | ⚠️ ~20%¹ | ⚠️ ~15%¹ |
| vin | ✅ 100% | ✅ ~80% |
| plate_number | ✅ 100% | ✅ ~90% |
| mileage / price | ✅ 100% | ✅ 100% |
| fuel / transmission | ✅ 100% | ✅ 100% |
| drive_type | ⚠️ ~40%² | ✅ ~70% |
| engine_volume | ✅ 93% | ✅ ~85% |
| cylinders | ⚠️ ~50%¹ | ⚠️ ~40%¹ |
| seat_count | ⚠️ ~50%¹ | ⚠️ ~40%¹ |
| body_type | ✅ 100% | ✅ ~80% |
| color | ✅ 100% | ✅ ~90% |
| seat_color | ✅ 100% | ✅ ~70% |
| has_accident | ✅ ~50%³ | ✅ ~80% |
| insurance_count | ✅ ~50%³ | ❌ нет |
| owners_count | ✅ ~50%³ | ✅ ~80% |
| flood_history | ✅ ~50%³ | ✅ ~60% |
| total_loss_history | ✅ ~50%³ | ❌ нет |
| repair_cost | ✅ ~30% | ❌ нет |
| lien / seizure | ✅ 100%⁴ | ✅ ~60% |
| sell_type | ✅ ~80% | ✅ ~50% |
| retail_value | ✅ ~60% | ✅ ~50% |
| location | ✅ 100% | ✅ ~80% |
| options | ✅ 100% | ✅ ~60% |
| lot_photos | ✅ 100% | ✅ 100% |
| inspection cert | ✅ ~60% | ✅ ~40% |
| outer_damage | ✅ ~60% | ✅ ~60% |
| engine_code (raw_data) | ✅ ~60% | ❌ нет |
| recall (raw_data) | ✅ ~60% | ❌ нет |

> ¹ Заполняется через нормализацию каталога, не напрямую из API  
> ² Encar: `spec.drivingMethodName` (только сертификация) + badge-токены  
> ³ Только при `openData=true` у дилера  
> ⁴ Encar: дефолт `"clean"` когда данных нет  

---

## Нормализация значений

### make (Encar → EN canonical)
```
현대 → Hyundai          기아 → Kia
제네시스 → Genesis       쉐보레 → Chevrolet
르노코리아 → Renault Korea  KG모빌리티(쌍용) → KGM
벤츠 → Mercedes-Benz    비엠더블유 → BMW
아우디 → Audi            폭스바겐 → Volkswagen
볼보 → Volvo             렉서스 → Lexus
```

### fuel
```
가솔린 → gasoline     디젤 → diesel
LPG → lpg            전기 → electric
가솔린+전기 → hybrid  디젤+전기 → hybrid
수소 → hydrogen
```

### transmission
```
자동 / 오토 → automatic   수동 → manual
CVT → cvt               DCT / 듀얼클러치 → dct
```

### drive_type
```
전륜 / 2WD → fwd    후륜 / RWD → rwd
사륜 / AWD / 4WD → awd
```

### body_type
```
세단 → sedan       SUV → suv
해치백 → hatchback  쿠페 → coupe
밴 → van           트럭 → truck
경차 → hatchback   대형차 → sedan
```

---

## Важные ограничения

| # | Ограничение |
|---|------------|
| 1 | **Encar inner_id** — listing ID ≠ vehicle ID. Без `inspect_vehicle_id` из пути фото record и inspection API недоступны для ~30% машин |
| 2 | **openData** — страховая история Encar раскрывается только по желанию дилера. Частные продавцы обычно не раскрывают |
| 3 | **성능점검** — акт техосмотра есть у ~50–60% лотов Encar. Бюджетные дилеры не загружают |
| 4 | **Encar diagnosis** — только у сертифицированных машин Encar (~10–15%) |
| 5 | **KBCha anti-bot** — нужен User-Agent rotation + задержки. Proxy обязателен в production |
| 6 | **cylinders / seat_count** — нет в обоих API; заполняются через `catalog_grades` при нормализации |
| 7 | **Encar drive_type** — `spec.drivingMethodName` только для сертифицированных; badge-токены дают ещё ~30% |

---

[← Commands](./11-commands.md) · [Index](./index.md)
