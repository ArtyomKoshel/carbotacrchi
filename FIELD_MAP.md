# Carbot — Field Map & Data Dictionary

> Последнее обновление: 2026-04-07  
> Покрывает: **Encar** + **KBCha** (KB차차차)

---

## Таблица хранения

| Хранилище | Описание |
|---|---|
| `lots` | Основная таблица объявлений. Одна строка = один лот. |
| `lots.raw_data` | JSON-поле внутри `lots`. Данные, не влезающие в схему. |
| `lot_inspections` | Один inspection record на лот. |
| `lot_photos` | N фото на лот (lot_id, url, position). |
| `lot_changes` | История изменений цены/статуса (event, changes JSON). |

---

## ENCAR

### Источники данных (API endpoints)

| Шаг | URL | Назначение |
|---|---|---|
| **search** | `api.encar.com/search/car/list/mobile` | Список объявлений |
| **detail** | `api.encar.com/v1/readside/vehicle/{id}` | Полный лот: VIN, фото, комплектация |
| **record** | `api.encar.com/v1/readside/record/vehicle/{inner_id}/open` | Страховая история (КIDI) |
| **inspection** | `api.encar.com/v1/readside/inspection/vehicle/{inner_id}` | 성능점검 (акт технического осмотра) |
| **diagnosis** | `api.encar.com/v1/readside/diagnosis/vehicle/{id}` | Энкар-диагностика (только сертиф.) |
| **sellingpoint** | `api.encar.com/v1/readside/diagnosis/vehicle/{id}/sellingpoint` | Привод, особые опции |

> ⚠️ **inner_id** — внутренний vehicle ID Encar, отличается от listing ID.  
> Извлекается из пути фото: `/carpicture07/pic4097/**40977911**_004.jpg`  
> Хранится в `raw_data["inspect_vehicle_id"]`.  
> Используется для **record** и **inspection** API.

---

### Поля `lots` — Encar

| Колонка | Источник | API step | Coverage | Примечание |
|---|---|---|---|---|
| `id` | `item.Id` | search | 100% | Listing ID (не inner ID) |
| `source` | константа `"encar"` | — | 100% | |
| `lot_url` | шаблон по id | — | 100% | `fem.encar.com/cars/detail/{id}` |
| `image_url` | первое OUTER фото | detail | 100% | CDN URL |
| `make` | `Manufacturer` → normalizer | search | 100% | EN название |
| `model` | `Model` | search | 100% | |
| `year` | `Year` | search | 100% | |
| `trim` | `badge_detail_kr` | detail | ~40% | Не у всех лотов |
| `vin` | `spec.vin` | detail | 100% | Иногда дополняется из inspection |
| `plate_number` | `manage.vehicleNo` | detail | 100% | |
| `body_type` | normalizer по категории | search | 100% | sedan/suv/hatchback/etc |
| `fuel` | `spec.fuelType` → normalizer | detail | 100% | gasoline/diesel/hybrid/electric/lpg |
| `transmission` | `spec.transmissionType` → normalizer | detail | 100% | automatic/manual/cvt |
| `drive_type` | `uniqueOptionPhotos[partCode=SPEC_drivingMethodNm]` | sellingpoint | ~10% | Только у сертифицированных машин |
| `engine_volume` | `spec.displacement` / 1000 | detail | 93% | В литрах |
| `mileage` | `Mileage` | search | 100% | В км |
| `color` | `spec.colorType` → normalizer | detail | 100% | |
| `seat_color` | `spec.seatColorType` | detail | 100% | |
| `has_accident` | `myAccidentCnt + otherAccidentCnt > 0` | **record** | ~50% | Только если openData=true |
| `insurance_count` | `accidentCnt` | record | ~50% | Суммарное кол-во страховых случаев |
| `owners_count` | `ownerChangeCnt` | record | ~50% | Кол-во смен владельцев |
| `flood_history` | `floodTotalLossCnt + floodPartLossCnt > 0` | record | ~50% | |
| `total_loss_history` | `totalLossCnt > 0` | record | ~50% | |
| `registration_date` | `manage.registDateTime` / `firstDate` (record) | detail/record | 100% | |
| `price` | `Price` / 10 | search | 100% | В тысячах USD |
| `price_krw` | `Price` * 10000 | search | 100% | В вонах |
| `lien_status` | `loan > 0` → "lien"/"clean" | record | 100% | default "clean" |
| `seizure_status` | `robberCnt > 0` → "seizure"/"clean" | record | 100% | default "clean" |
| `repair_cost` | `myAccidentCost + otherAccidentCost` | record | ~30% | 0 если нет аварий |
| `dealer_name` | `contact.userId` | detail | 100% | ID продавца на Encar |
| `dealer_company` | `partner.dealer.firm.name` | detail | ~5% | Только крупные дилеры |
| `dealer_phone` | `contact.no` | detail | 100% | |
| `location` | `contact.address` | detail | 100% | |
| `options` | `opts.standard` | detail | 100% | Список кодов опций |
| `title` | всегда `"Clean"` | — | 100% | В Корее всегда clean title |

**Не доступны в Encar API:**
- `cylinders`, `fuel_economy`, `mileage_grade` — нет в API
- `has_keys`, `damage`, `secondary_damage` — нет прямого поля
- `tax_paid`, `document` — не применимо для Кореи
- `retail_value`, `new_car_price_ratio`, `ai_price_min/max` — внешние расчёты

---

### `raw_data` JSON — Encar

| Ключ | Источник | API step | Описание |
|---|---|---|---|
| `photos` | все фото с CDN URL | detail | Полный список URL фото |
| `photo_count` | `manage.photoCount` | detail | Кол-во фото |
| `inspect_vehicle_id` | regex из пути фото | detail | Inner ID для record/inspection API |
| `engine_code` | `detail.motorType` | inspection | Код двигателя (G6DN, D4CB, ...) |
| `warranty_type` | `detail.guarantyType.title` | inspection | Тип гарантии (보험사보증, ...) |
| `recall` | `detail.recall` | inspection | Есть ли отзыв |
| `recall_status` | `detail.recallFullFillTypes[].title` | inspection | Статус выполнения отзыва |
| `car_state` | `detail.carStateType.title` | inspection | Общее состояние (양호/불량) |
| `mechanical_issues` | `inners` tree → аномалии | inspection | Список найденных неисправностей |
| `diagnosis_center` | `reservationCenterName` | diagnosis | Центр Encar-диагностики |
| `drive_type` | `sellingpoint uniqueOptionPhotos` | sellingpoint | Привод (전륜/후륜/사륜) |
| `front_tinting` | verification opt 16 | verification | Тонировка лобового |
| `keys_count` | verification opt 10 | verification | Кол-во ключей |
| `tire_depth_mm` | verification opt 330-333 | verification | Глубина протектора |
| `domestic` | `category.domestic` | detail | Отечественная/иностранная |
| `import_type` | `category.importType` | detail | Тип импорта |
| `origin_price` | `category.originPrice` | detail | Цена нового авто |
| `ad_status` | `adv.status` | detail | Статус объявления |
| `sell_type` | тип продажи | search | auction/dealer/private |
| `manufacturer_kr` | корейское название | search | Hyundai → 현대 |
| `model_kr`, `model_group_kr` | корейские названия | search | |
| `year_month` | год+месяц | search | e.g. "202103" |
| `seat_count` | кол-во мест | detail | |
| `badge_kr`, `badge_detail_kr` | комплектация KR | detail | |
| `grade_detail_en` | комплектация EN | detail | |

---

### `lot_inspections` — Encar

| Колонка | Источник | API step | Coverage |
|---|---|---|---|
| `lot_id` | listing ID | — | 100% |
| `source` | `"encar"` | — | 100% |
| `cert_no` | `master.supplyNum` | inspection | ~60% |
| `inspection_date` | `master.registrationDate` | inspection | ~60% |
| `valid_from` | `detail.validityStartDate` | inspection | ~60% |
| `valid_until` | `detail.validityEndDate` | inspection | ~60% |
| `report_url` | шаблон по lot.id | inspection | ~60% |
| `first_registration` | `detail.firstRegistrationDate` | inspection | ~60% |
| `inspection_mileage` | `detail.mileage` | inspection | ~60% |
| `has_accident` | `master.accdient` (структурная авария) | inspection | ~60% |
| `has_outer_damage` | `outers` список → bool | inspection | ~60% |
| `outer_detail` | `outers` → текст по панелям | inspection | ~30% (только если есть повреждения) |
| `has_flood` | `detail.waterlog` | inspection | ~60% |
| `has_tuning` | `detail.tuning` | inspection | ~60% |
| `accident_detail` | `accidents[]` → текст | record | ~30% |
| `details` JSON | все детали (см. ниже) | inspection+record | ~60% |

**`details` JSON содержит:**
```json
{
  "simple_repair": true,
  "engine_check": "Y",
  "trns_check": "Y",
  "recall": true,
  "recall_types": ["미이행"],
  "mechanical_issues": ["원동기/오일누유/..."],
  "serious_types": [],
  "car_state": "양호",
  "outer_parts": [{"part": "후드", "status": ["교환(교체)"]}],
  "accidents": [...],
  "owner_changes": ["2024-07-10", ...],
  "plate_changes": [...],
  "my_accident_cost": 2515897,
  "other_accident_cost": 0
}
```

**Из скриншотов — поля что видим, но НЕ извлекаем:**
- `주행거리 계기상태` (odometer integrity) → не сохраняем отдельно
- `차대번호표기` (VIN plate status) → не сохраняем
- `특별이력` (special history: 없음) → не сохраняем
- `용도변경` (usage change) → `usageChangeTypes` есть в API, не сохраняем в колонку
- `배출가스 CO/HC` (emissions) → нет в inspection API

---

### `lot_photos` — Encar

| Колонка | Источник |
|---|---|
| `lot_id` | listing ID |
| `url` | CDN URL из `detail.photos[].path` |
| `position` | порядок в массиве фото |

Coverage: **100%** (15–30 фото на машину)

---

## KBCHA (KB차차차)

### Источники данных

| Шаг | URL | Назначение |
|---|---|---|
| **list** | `www.kbchachacha.com/public/search/...` | Список по марке |
| **detail** | `www.kbchachacha.com/public/car/detail.kbc?carSeq={id}` | Полная карточка |
| **km_popup** | `www.kbchachacha.com/public/layer/car/km/analysis/info.kbc?carSeq={id}` | Пробег/история |
| **inspection** | `www.kbchachacha.com` (несколько типов) | Акт осмотра (если есть) |

---

### Поля `lots` — KBCha

| Колонка | Источник | Coverage | Примечание |
|---|---|---|---|
| `id` | `kbcha_{carSeq}` | 100% | |
| `source` | `"kbcha"` | 100% | |
| `lot_url` | шаблон по carSeq | 100% | |
| `image_url` | первое фото | 100% | |
| `make` | maker name | 100% | |
| `model` | model name | 100% | |
| `year` | год | 100% | |
| `trim` | trim | ~70% | |
| `vin` | VIN | ~80% | |
| `plate_number` | номер авто | ~90% | |
| `body_type` | тип кузова | ~80% | |
| `fuel` | тип топлива | 100% | |
| `transmission` | коробка | 100% | |
| `drive_type` | привод | ~70% | Из detail страницы |
| `engine_volume` | объём двигателя | ~85% | |
| `mileage` | пробег | 100% | |
| `color` | цвет | ~90% | |
| `has_accident` | наличие аварий | ~80% | Из detail |
| `owners_count` | кол-во владельцев | ~80% | |
| `registration_date` | дата регистрации | ~90% | |
| `price` | цена в тыс. USD | 100% | |
| `price_krw` | цена в вонах | 100% | |
| `lien_status` | залог | ~60% | |
| `seizure_status` | арест | ~60% | |
| `dealer_name` | дилер | ~90% | |
| `dealer_phone` | телефон | ~70% | |
| `location` | регион | ~80% | |
| `options` | список опций | ~60% | |

---

### `raw_data` JSON — KBCha

| Ключ | Описание |
|---|---|
| `photos` | Список URL фото (→ `lot_photos`) |
| `inspection_type` | Тип инспекции (kb_popup/url/none) |
| `car_seq` | carSeq (числовой ID KBCha) |
| `maker_code` | Код марки |

---

### `lot_inspections` — KBCha

| Колонка | Coverage | Примечание |
|---|---|---|
| `source` | 100% | `"kb_chacha"` |
| `cert_no` | ~40% | Номер акта осмотра |
| `has_accident` | ~70% | |
| `has_outer_damage` | ~60% | |
| `outer_detail` | ~40% | Список повреждённых панелей |
| `has_flood` | ~60% | |
| `has_tuning` | ~50% | |
| `details` | ~50% | structural/outer damage списки |

---

## Сводная матрица покрытия

| Поле | Encar | KBCha | Примечание |
|---|---|---|---|
| **make/model/year** | ✅ 100% | ✅ 100% | |
| **vin** | ✅ 100% | ✅ ~80% | |
| **plate_number** | ✅ 100% | ✅ ~90% | |
| **mileage** | ✅ 100% | ✅ 100% | |
| **price** | ✅ 100% | ✅ 100% | |
| **fuel/transmission** | ✅ 100% | ✅ 100% | |
| **drive_type** | ⚠️ ~10% | ✅ ~70% | Encar: только certif. |
| **engine_volume** | ✅ 93% | ✅ ~85% | |
| **engine_code** | ✅ ~60% | ❌ нет | Encar: из inspection |
| **color** | ✅ 100% | ✅ ~90% | |
| **has_accident** | ✅ ~50% | ✅ ~80% | Encar: нужен openData |
| **insurance_count** | ✅ ~50% | ❌ нет | |
| **owners_count** | ✅ ~50% | ✅ ~80% | |
| **flood_history** | ✅ ~50% | ✅ ~60% | |
| **repair_cost** | ✅ ~30% | ❌ нет | |
| **lien/seizure** | ✅ 100% | ✅ ~60% | Encar: default "clean" |
| **dealer_name/phone** | ✅ 100% | ✅ ~90% | |
| **location** | ✅ 100% | ✅ ~80% | |
| **options** | ✅ 100% | ✅ ~60% | |
| **photos** | ✅ 100% | ✅ 100% | В `lot_photos` |
| **inspection cert** | ✅ ~60% | ✅ ~40% | |
| **outer_damage** | ✅ ~60% | ✅ ~60% | |
| **engine_check** | ✅ ~60% | ✅ ~50% | В `lot_inspections.details` |
| **recall** | ✅ ~60% | ❌ нет | Только Encar |
| **mechanical_issues** | ✅ ~20% | ✅ ~30% | При наличии проблем |
| **warranty_type** | ✅ ~60% | ❌ нет | Encar: из inspection |
| **cylinders** | ❌ нет | ❌ нет | Нет в API |
| **fuel_economy** | ❌ нет | ❌ нет | Нет в API |

---

## Ключевые маппинги

### Нормализация make (Encar → EN)
```
현대 → Hyundai, 기아 → Kia, 제네시스 → Genesis,
쉐보레 → Chevrolet, 르노코리아 → Renault Korea,
벤츠 → Mercedes-Benz, KG모빌리티(쌍용) → KG모빌리티(쌍용)
```

### Нормализация fuel
```
가솔린 → gasoline, 디젤 → diesel,
LPG → lpg, 전기 → electric,
가솔린+전기 → hybrid, 디젤+전기 → hybrid
```

### Нормализация transmission
```
자동 / 오토 → automatic,
수동 → manual,
CVT → cvt,
DCT / 듀얼클러치 → dct
```

### Нормализация body_type
```
세단 → sedan, SUV → suv,
해치백 → hatchback, 쿠페 → coupe,
밴 → van, 트럭 → truck,
경차 → hatchback (mapped), 대형차 → sedan
```

---

## Важные ограничения

1. **Encar inner_id** — listing ID ≠ vehicle ID. Без извлечения `inspect_vehicle_id` из фото — record и inspection API вернут null для ~30% машин.

2. **openData** — страховая история в record API доступна только если дилер раскрыл её. Машины без страховых случаев или частные продавцы могут не иметь `openData`.

3. **성능점검** — акт технического осмотра есть примерно у 50-60% машин на Encar. Бюджетные дилеры не загружают.

4. **Encar diagnosis** — только у машин с Encar сертификацией (~10-15%).

5. **drive_type у Encar** — только у сертифицированных машин через sellingpoint API.

6. **KBCha anti-bot** — нужен реальный User-Agent + задержки между запросами. Proxy обязателен в production.
