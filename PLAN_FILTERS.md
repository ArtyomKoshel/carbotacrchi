# План: Расширение системы фильтров

## Контекст проекта

Telegram Mini App для поиска машин по аукционам. Laravel 11 backend + vanilla JS frontend.
Сейчас фильтры минимальные (5 штук) и статичные — марки/модели захардкожены (10 марок, 5 моделей на марку).

**Основной поставщик данных (будущий)**: auction-api.app — платный REST API покрывающий Copart и IAAI. Документация: https://documenter.getpostman.com/view/27447348/2s9YsNfWNa

---

## Справка: API auction-api.app (реальные данные)

### Базовый URL: `https://auction-api.app/api/v1`

### Аутентификация
`POST /login` → возвращает JWT токен. Все остальные запросы: `Authorization: Bearer <token>`

### Эндпоинты справочников

| Метод | Путь | Параметры | Возвращает |
|-------|------|-----------|------------|
| POST | `/get-transport-type` | — | Типы транспорта: v=авто, c=мото, u=грузовики и т.д. |
| POST | `/get-brand-transport-type` | type | Массив марок: {slug, name, name_ru, count} |
| POST | `/get-model-bybrand-transport-type` | type, brand | Массив моделей: {slug, name, name_ru} |
| POST | `/get-years-bymodel` | type, brand, model | Массив: {year, price, count} |
| POST | `/get-year-list` | type, brand, model | Массив строк годов: ["2015","2016",...] |

### Поиск лотов

`POST /get-list-lots`

Параметры:
- `type` — тип транспорта (string: "v")
- `brand` — slug марки (string: "honda")
- `model` — slug модели (string: "civic")
- `year` — год (string: "2020")
- `page` — страница пагинации
- `paginate` — кол-во на страницу
- `order` — поле сортировки (created_at, price, mileage и т.д.)
- `direction` — направление (asc, desc)
- `body_type` — JSON массив типов кузова: ["sedan", "suv"]
- `drive` — JSON массив привода: ["front", "rear", "all"]
- `source` — JSON массив источников: ["copart", "iaai"]
- `history` — история аукционов (true/false)

### Поиск по VIN

`POST /get-car-vin` — параметры: vin, number (опц), source (опц), history (опц)

### Структура объекта лота из API (полная)

```json
{
  "id": 5749517919,
  "slug": "77625603-acura-ilx-base-w-2017-vin-19ude2f34ha007623",
  "number": "77625603",
  "source_lot_id": "19273214",
  "model_id": "14000",
  "vehicle_type": "v",
  "vehicle_type_key": "1",
  "sale_title_state": "CA",
  "sale_title_state_id": "37",
  "sale_title_type": "",
  "sale_title_type_id": "1",
  "salvage_id": null,
  "has_keys": "0",
  "lot_cond_code": "",
  "lot_cond_code_key": "2",
  "retail_value": "18500.00",
  "repair_cost": "24945.00",
  "cylinders": "4",
  "seller": null,
  "document": "CA - SALVAGE CERTIFICATE",
  "buy_it_now_price": "0.00",
  "price_future": "7000.00",
  "trim": "BASE W",
  "vin": "19UDE2F34HA007623",
  "engine_volume": "2.4",
  "engine_fuel": "petrol",
  "drive": "front",
  "transmission": "auto",
  "mileage": "49115",
  "year": "2017",
  "color": "GRAY",
  "color_id": "10",
  "description": null,
  "status": "used",
  "damage_status": "DEFAULT",
  "damage_status_key": "1",
  "damage_main_damages": "FRONT END",
  "damage_main_damages_key": "2",
  "damage_secondary_damages": "SIDE",
  "damage_secondary_damages_key": "4",
  "damage_notes": null,
  "yard_number": "1",
  "yard_name": "CA - RANCHO CUCAMONGA",
  "phone": null,
  "equipment": null,
  "emails": null,
  "photo": [
    {
      "url": "https://cs.copart.com/.../image_hrs.jpg",
      "thumb_url": "https://cs.copart.com/.../image_ful.jpg",
      "thumb_url_min": "https://cs.copart.com/.../image_thb.jpg"
    }
  ],
  "state": "CA",
  "city": "RANCHO CUCAMONGA",
  "city_slug": "rancho_cucamonga",
  "source": "copart",
  "buy_it_now": "0",
  "final_bid": "0.00",
  "views": "0",
  "created_at": "2023-11-29T22:28:18.000000Z",
  "updated_at": "2023-12-13T22:05:46.000000Z",
  "started_at": null,
  "ended_at": "2023-12-07T18:00:00.000000Z",
  "sold_at": "2023-12-08T06:00:00.000000Z",
  "brand_name": "Acura",
  "model_name": "ILX",
  "has_keys_readable": "нет",
  "run_and_drive": "нет",
  "calc_price": 0,
  "model": {
    "id": 1247,
    "brand_id": "30",
    "slug": "elantra",
    "name": "Elantra",
    "brand": {
      "id": 30,
      "slug": "hyundai",
      "name": "Hyundai"
    }
  }
}
```

### Ключевые значения полей из реального API

**`engine_fuel`**: `"petrol"`, `"diesel"`, `"hybrid"`, `"electric"`
**`drive`**: `"front"`, `"rear"`, `"all"` (FWD, RWD, AWD)
**`transmission`**: `"auto"`, `"manual"`
**`source`**: `"copart"`, `"iaai"`
**`color`**: `"White"`, `"Black"`, `"Silver"`, `"GRAY"`, `"Blue"`, `"Red"`, `"Green"`, `"Brown"`, `"Gold"`, `"Orange"`, `"Yellow"`, `"Burgundy"`, `"Beige"`
**`damage_main_damages`**: `"FRONT END"`, `"REAR END"`, `"SIDE"`, `"ALL OVER"`, `"ROLLOVER"`, `"WATER/FLOOD"`, `"HAIL"`, `"ELECTRICAL"`, `"MECHANICAL"`, `"MINOR DENTS"`, `"VANDALISM"`, `"BURN"`, `"UNDERCARRIAGE"`
**`damage_status`**: `"DEFAULT"` (не заводится), другие значения
**`document`**: `"CA - SALVAGE CERTIFICATE"`, `"TX - CERTIFICATE OF TITLE"`, `"CLEAN TITLE"` и т.д.
**`has_keys`**: `"0"` (нет), `"1"` (да)
**`body_type` (параметр поиска)**: `"sedan"`, `"suv"`, `"truck"`, `"coupe"`, `"hatchback"`, `"wagon"`, `"van"`, `"convertible"`

### Пагинация (Laravel-стиль)
```json
{
  "current_page": 3,
  "data": [...],
  "first_page_url": "...?page=1",
  "last_page": 12,
  "per_page": 8,
  "total": 96
}
```

### Rate Limiting
Заголовки: `X-RateLimit-Limit`, `X-RateLimit-Remaining`. Поле `limit` в ответе показывает остаток запросов.

---

## Что есть сейчас (текущее состояние)

### Backend

**`laravel/app/Http/Controllers/Api/FiltersController.php`** — возвращает статичный JSON:
- `makes`: ассоциативный массив {марка → [модели]}, 10 марок по 5 моделей
- `sources`: массив площадок [{key, name}]
- `years`: range от текущего года до 2000

**`laravel/app/Services/SearchQuery.php`** — DTO поискового запроса, поля:
- `make` (string), `model` (string), `yearFrom` (int), `yearTo` (int)
- `priceMax` (int), `sources` (string[]), `sort` (string), `limit` (int), `offset` (int)

**`laravel/app/AuctionProviders/AbstractProvider.php`** → метод `applyFilters()`:
- Фильтрация по: make (str_contains), model (str_contains), yearFrom, yearTo, priceMax
- Нет фильтрации по пробегу, повреждениям, типу title и другим полям

**`laravel/app/Dto/LotDTO.php`** — readonly class, поля:
- id, source, sourceName, make, model, year, price, mileage, damage, title, location
- lotUrl, imageUrl, vin, auctionDate, createdAt
- **Нет полей**: transmission, fuel, bodyType, driveType, color, engineVolume

### Frontend

**`miniapp/index.html`** — экран поиска содержит:
- Чипсы источников (source-chips)
- Два select: марка и модель
- Два input number: год от/до
- Один input number: макс. цена

**`miniapp/js/filters.js`** — IIFE модуль:
- `state` объект: sources[], make, model, yearFrom, yearTo, priceMax, sort
- `renderSourceChips()` — кнопки-чипсы с toggle
- `renderMakeSelect()` — populate <select> из filtersData.makes (Object.keys)
- `renderModelSelect()` — populate <select> зависимый от выбранной марки
- `readFormState()` — читает DOM inputs
- `getQuery()` → возвращает объект для API

### Mock-данные (5 файлов в `laravel/storage/app/data/`):

**mock_copart.json** (14 машин) — поля: lot_number, make, model, year, buy_now_price, odometer, damage_description, title_type, location, image_url, vin, auction_date

**mock_iai.json** (14 машин) — поля: lotNumber, make, model, year, salePrice, miles, primaryDamage, titleType, city, state, imgUrl, vin, saleDate

**mock_manheim.json** (14 машин) — поля: id, make, model, year, price, mileage, conditionGrade, location, imageUrl, vin, saleDate (нет damage)

**mock_encar.json** (14 машин) — поля: carId, make, model, year, price, mileage_km, location, imageUrl, vin, registrationDate (нет damage, нет title)

**mock_kbcha.json** (14 машин) — поля: carNm, make, model, year, price, distance, location, imgUrl, regDate (нет vin, нет damage)

**Важно**: в mock-данных нет полей transmission, fuel, bodyType, driveType, color, engineVolume — их нужно добавить.

---

## Что нужно сделать

### Задача 1: Расширить LotDTO новыми полями

**Файл**: `laravel/app/Dto/LotDTO.php`

Добавить nullable поля в конструктор (после существующих). Имена полей нормализованы под наш формат, но значения соответствуют реальному API auction-api.app:

```php
public ?string $transmission = null,  // Из API: 'auto' → 'Automatic', 'manual' → 'Manual'
public ?string $fuel         = null,  // Из API: 'petrol' → 'Gasoline', 'diesel' → 'Diesel', 'hybrid' → 'Hybrid', 'electric' → 'Electric'
public ?string $bodyType     = null,  // Из API параметр body_type: 'sedan' → 'Sedan', 'suv' → 'SUV' и т.д.
public ?string $driveType    = null,  // Из API: 'front' → 'FWD', 'rear' → 'RWD', 'all' → 'AWD'
public ?string $color        = null,  // Из API: 'White', 'Black', 'Silver', 'GRAY' и т.д. (нормализовать регистр → 'Gray')
public ?float  $engineVolume = null,  // Из API: engine_volume "2.4" → 2.4 (float, литры)
public ?int    $cylinders    = null,  // Из API: cylinders "4" → 4
public ?bool   $hasKeys      = null,  // Из API: has_keys "0"/"1" → false/true
public ?string $secondaryDamage = null, // Из API: damage_secondary_damages "SIDE"
public ?string $document     = null,  // Из API: document "CA - SALVAGE CERTIFICATE"
public ?int    $retailValue  = null,  // Из API: retail_value — рыночная стоимость
public ?int    $repairCost   = null,  // Из API: repair_cost — стоимость ремонта
public ?string $trim         = null,  // Из API: trim "SEL", "BASE W"
```

Обновить метод `toArray()` — добавить все новые поля.

**Таблица маппинга значений (API → наш формат для отображения в UI):**

| Поле | Значение API | Наше значение |
|------|-------------|---------------|
| transmission | `auto` | `Automatic` |
| transmission | `manual` | `Manual` |
| engine_fuel | `petrol` | `Gasoline` |
| engine_fuel | `diesel` | `Diesel` |
| engine_fuel | `hybrid` | `Hybrid` |
| engine_fuel | `electric` | `Electric` |
| drive | `front` | `FWD` |
| drive | `rear` | `RWD` |
| drive | `all` | `AWD` |
| body_type | `sedan` | `Sedan` |
| body_type | `suv` | `SUV` |
| body_type | `truck` | `Truck` |
| body_type | `coupe` | `Coupe` |
| body_type | `hatchback` | `Hatchback` |
| body_type | `wagon` | `Wagon` |
| body_type | `van` | `Van` |
| body_type | `convertible` | `Convertible` |
| body_type | `crossover` | `Crossover` |

---

### Задача 2: Обновить mock-данные (все 5 файлов)

Добавить новые поля. Значения должны быть реалистичными и соответствовать реальному API.

**mock_copart.json** — добавить к каждому объекту поля как в реальном API:
```json
{
  "lot_number": "45892831",
  "make": "Honda",
  "model": "Accord",
  "year": 2020,
  "buy_now_price": 11400,
  "odometer": 85000,
  "damage_description": "FRONT END",
  "secondary_damage": "MINOR DENTS",
  "title_type": "Salvage",
  "document": "CA - SALVAGE CERTIFICATE",
  "location": "Los Angeles, CA",
  "image_url": null,
  "vin": "1HGCV1F34LA123456",
  "auction_date": "2024-03-24",
  "transmission": "auto",
  "fuel": "petrol",
  "body_type": "sedan",
  "drive_type": "front",
  "color": "White",
  "engine_volume": "1.5",
  "cylinders": "4",
  "has_keys": "1",
  "retail_value": "22000.00",
  "repair_cost": "8500.00",
  "trim": "Sport"
}
```

**Правила распределения значений по машинам:**

Соответствие модель → тип кузова:
- F-150, Silverado, Tacoma, Tundra, Frontier, Colorado → `truck`
- RAV4, Tucson, Sportage, CR-V, Explorer, Santa Fe, Highlander, Palisade, Grand Cherokee → `suv`
- Camry, Accord, Civic, Corolla, Altima, Elantra, Sonata, K5 → `sedan`
- Mustang, 3 Series (Coupe) → `coupe`
- Model 3, Model Y → `sedan` / `suv` (Model Y = suv)
- Carnival, Odyssey, Staria → `van`

Соответствие модель → привод:
- F-150, Silverado → `rear` или `all`
- RAV4, Tucson, CR-V → `front` или `all`
- BMW 3 Series → `rear`
- Tesla Model 3 → `rear`, Tesla Model Y → `all`
- Большинство корейских → `front`

Соответствие модель → двигатель:
- Tesla, IONIQ 5, EV6 → fuel: `electric`, engine_volume: null, cylinders: null
- Camry Hybrid → fuel: `hybrid`, engine_volume: "2.5"
- F-150 → engine_volume: "3.5" или "5.0"
- Civic → engine_volume: "1.5" или "2.0"
- BMW 3 Series → engine_volume: "2.0"

Соответствие модель → трансмиссия:
- ~90% → `auto`
- Некоторые спорткары (Mustang, BRZ) → `manual`
- Некоторые японские → `auto` (CVT в реальности, но API возвращает `auto`)

**Повторить аналогичный подход для всех 5 файлов:**

| Файл | Ключи для новых полей |
|------|----------------------|
| mock_copart.json | transmission, fuel, body_type, drive_type, color, engine_volume, cylinders, has_keys, secondary_damage, document, retail_value, repair_cost, trim |
| mock_iai.json | transmission, fuelType, bodyType, drivelineType, color, engine, cylinders, hasKeys, secondaryDamage, document, retailValue, repairCost, trim |
| mock_manheim.json | transmission, fuelType, bodyStyle, drivetrain, exteriorColor, displacement, cylinders, trim |
| mock_encar.json | transmission, fuel, bodyType, driveType, color, engineCC |
| mock_kbcha.json | transmission, fuel, bodyType, driveType, color, engineCC |

**Для корейских площадок (encar, kbcha)**: engineCC в кубических сантиметрах (1999, 2497 и т.д.), поля document/has_keys/repair_cost/retail_value НЕ нужны.

---

### Задача 3: Обновить normalize() в каждом провайдере

Маппинг сырых значений API → нормализованные значения LotDTO.

**Вспомогательные маппинг-массивы (можно поместить в AbstractProvider как protected static):**

```php
protected static array $transmissionMap = [
    'auto' => 'Automatic', 'automatic' => 'Automatic',
    'manual' => 'Manual',
    'cvt' => 'CVT',
];

protected static array $fuelMap = [
    'petrol' => 'Gasoline', 'gasoline' => 'Gasoline', 'gas' => 'Gasoline',
    'diesel' => 'Diesel',
    'hybrid' => 'Hybrid',
    'electric' => 'Electric',
];

protected static array $driveMap = [
    'front' => 'FWD', 'fwd' => 'FWD',
    'rear' => 'RWD', 'rwd' => 'RWD',
    'all' => 'AWD', 'awd' => 'AWD', '4wd' => '4WD', '4x4' => '4WD',
];

protected static array $bodyTypeMap = [
    'sedan' => 'Sedan', 'suv' => 'SUV', 'truck' => 'Truck',
    'coupe' => 'Coupe', 'hatchback' => 'Hatchback', 'wagon' => 'Wagon',
    'van' => 'Van', 'convertible' => 'Convertible', 'crossover' => 'Crossover',
];

protected static function mapValue(?string $value, array $map): ?string
{
    if ($value === null || $value === '') return null;
    return $map[strtolower(trim($value))] ?? ucfirst(strtolower(trim($value)));
}
```

**`laravel/app/AuctionProviders/CopartProvider.php`** — в normalize() добавить:
```php
transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
fuel:            self::mapValue($raw['fuel'] ?? null, self::$fuelMap),
bodyType:        self::mapValue($raw['body_type'] ?? null, self::$bodyTypeMap),
driveType:       self::mapValue($raw['drive_type'] ?? null, self::$driveMap),
color:           isset($raw['color']) ? ucfirst(strtolower(trim($raw['color']))) : null,
engineVolume:    isset($raw['engine_volume']) ? (float) $raw['engine_volume'] : null,
cylinders:       isset($raw['cylinders']) ? (int) $raw['cylinders'] : null,
hasKeys:         isset($raw['has_keys']) ? $raw['has_keys'] === '1' : null,
secondaryDamage: $raw['secondary_damage'] ?? null,
document:        $raw['document'] ?? null,
retailValue:     isset($raw['retail_value']) ? (int) (float) $raw['retail_value'] : null,
repairCost:      isset($raw['repair_cost']) ? (int) (float) $raw['repair_cost'] : null,
trim:            $raw['trim'] ?? null,
```

**`laravel/app/AuctionProviders/IAIProvider.php`** — в normalize():
```php
transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
fuel:            self::mapValue($raw['fuelType'] ?? null, self::$fuelMap),
bodyType:        self::mapValue($raw['bodyType'] ?? null, self::$bodyTypeMap),
driveType:       self::mapValue($raw['drivelineType'] ?? null, self::$driveMap),
color:           isset($raw['color']) ? ucfirst(strtolower(trim($raw['color']))) : null,
engineVolume:    isset($raw['engine']) ? (float) $raw['engine'] : null,
cylinders:       isset($raw['cylinders']) ? (int) $raw['cylinders'] : null,
hasKeys:         isset($raw['hasKeys']) ? $raw['hasKeys'] === '1' : null,
secondaryDamage: $raw['secondaryDamage'] ?? null,
document:        $raw['document'] ?? null,
retailValue:     isset($raw['retailValue']) ? (int) (float) $raw['retailValue'] : null,
repairCost:      isset($raw['repairCost']) ? (int) (float) $raw['repairCost'] : null,
trim:            $raw['trim'] ?? null,
```

**`laravel/app/AuctionProviders/ManheimProvider.php`** — в normalize():
```php
transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
fuel:            self::mapValue($raw['fuelType'] ?? null, self::$fuelMap),
bodyType:        self::mapValue($raw['bodyStyle'] ?? null, self::$bodyTypeMap),
driveType:       self::mapValue($raw['drivetrain'] ?? null, self::$driveMap),
color:           isset($raw['exteriorColor']) ? ucfirst(strtolower(trim($raw['exteriorColor']))) : null,
engineVolume:    isset($raw['displacement']) ? (float) $raw['displacement'] : null,
cylinders:       isset($raw['cylinders']) ? (int) $raw['cylinders'] : null,
hasKeys:         null,
secondaryDamage: null,
document:        null,
retailValue:     null,
repairCost:      null,
trim:            $raw['trim'] ?? null,
```

**`laravel/app/AuctionProviders/EncarProvider.php`** — в normalize():
```php
transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
fuel:            self::mapValue($raw['fuel'] ?? null, self::$fuelMap),
bodyType:        self::mapValue($raw['bodyType'] ?? null, self::$bodyTypeMap),
driveType:       self::mapValue($raw['driveType'] ?? null, self::$driveMap),
color:           isset($raw['color']) ? ucfirst(strtolower(trim($raw['color']))) : null,
engineVolume:    isset($raw['engineCC']) ? round((float) $raw['engineCC'] / 1000, 1) : null,
cylinders:       isset($raw['cylinders']) ? (int) $raw['cylinders'] : null,
hasKeys:         null,
secondaryDamage: null,
document:        null,
retailValue:     null,
repairCost:      null,
trim:            $raw['trim'] ?? null,
```

**`laravel/app/AuctionProviders/KBChachaProvider.php`** — в normalize():
```php
transmission:    self::mapValue($raw['transmission'] ?? null, self::$transmissionMap),
fuel:            self::mapValue($raw['fuel'] ?? null, self::$fuelMap),
bodyType:        self::mapValue($raw['bodyType'] ?? null, self::$bodyTypeMap),
driveType:       self::mapValue($raw['driveType'] ?? null, self::$driveMap),
color:           isset($raw['color']) ? ucfirst(strtolower(trim($raw['color']))) : null,
engineVolume:    isset($raw['engineCC']) ? round((float) $raw['engineCC'] / 1000, 1) : null,
cylinders:       null,
hasKeys:         null,
secondaryDamage: null,
document:        null,
retailValue:     null,
repairCost:      null,
trim:            null,
```

---

### Задача 4: Расширить SearchQuery

**Файл**: `laravel/app/Services/SearchQuery.php`

Добавить поля:
```php
public int    $priceMin       = 0;
public int    $mileageMin     = 0;
public int    $mileageMax     = 0;
public float  $engineMin      = 0;
public float  $engineMax      = 0;
/** @var string[] */
public array  $damageTypes    = [];    // ['Front End', 'Rear End', ...] — пустой = любые
/** @var string[] */
public array  $titleTypes     = [];    // ['Salvage', 'Clean', ...] — пустой = любые
/** @var string[] */
public array  $bodyTypes      = [];    // ['Sedan', 'SUV', ...] — пустой = любые
/** @var string[] */
public array  $transmissions  = [];    // ['Automatic', 'Manual'] — пустой = любые
/** @var string[] */
public array  $fuelTypes      = [];    // ['Gasoline', 'Diesel', ...] — пустой = любые
/** @var string[] */
public array  $driveTypes     = [];    // ['FWD', 'RWD', 'AWD'] — пустой = любые
public string $vin            = '';    // поиск по VIN (начало или полный)
```

Обновить `fromArray()`:
```php
$q->priceMin      = (int) ($data['priceMin']    ?? 0);
$q->mileageMin    = (int) ($data['mileageMin']  ?? 0);
$q->mileageMax    = (int) ($data['mileageMax']  ?? 0);
$q->engineMin     = (float) ($data['engineMin'] ?? 0);
$q->engineMax     = (float) ($data['engineMax'] ?? 0);
$q->vin           = trim((string) ($data['vin'] ?? ''));

if (!empty($data['damageTypes']) && is_array($data['damageTypes'])) {
    $q->damageTypes = array_map('strval', $data['damageTypes']);
}
if (!empty($data['titleTypes']) && is_array($data['titleTypes'])) {
    $q->titleTypes = array_map('strval', $data['titleTypes']);
}
if (!empty($data['bodyTypes']) && is_array($data['bodyTypes'])) {
    $q->bodyTypes = array_map('strval', $data['bodyTypes']);
}
if (!empty($data['transmissions']) && is_array($data['transmissions'])) {
    $q->transmissions = array_map('strval', $data['transmissions']);
}
if (!empty($data['fuelTypes']) && is_array($data['fuelTypes'])) {
    $q->fuelTypes = array_map('strval', $data['fuelTypes']);
}
if (!empty($data['driveTypes']) && is_array($data['driveTypes'])) {
    $q->driveTypes = array_map('strval', $data['driveTypes']);
}
```

---

### Задача 5: Расширить applyFilters() в AbstractProvider

**Файл**: `laravel/app/AuctionProviders/AbstractProvider.php`

Добавить в замыкание `applyFilters()` после существующих проверок:

```php
// Цена мин
if ($query->priceMin > 0 && $lot->price < $query->priceMin) {
    return false;
}
// Пробег
if ($query->mileageMin > 0 && $lot->mileage < $query->mileageMin) {
    return false;
}
if ($query->mileageMax > 0 && $lot->mileage > $query->mileageMax) {
    return false;
}
// Объём двигателя
if ($query->engineMin > 0 && ($lot->engineVolume === null || $lot->engineVolume < $query->engineMin)) {
    return false;
}
if ($query->engineMax > 0 && ($lot->engineVolume === null || $lot->engineVolume > $query->engineMax)) {
    return false;
}
// Тип повреждения (multi-select, пустой массив = любые)
if (!empty($query->damageTypes)) {
    $lotDamage = $lot->damage ? strtoupper(trim($lot->damage)) : '';
    $match = false;
    foreach ($query->damageTypes as $dt) {
        if (strtoupper(trim($dt)) === $lotDamage) { $match = true; break; }
    }
    if (!$match) return false;
}
// Тип title
if (!empty($query->titleTypes) && !in_array($lot->title, $query->titleTypes, true)) {
    return false;
}
// Тип кузова
if (!empty($query->bodyTypes) && ($lot->bodyType === null || !in_array($lot->bodyType, $query->bodyTypes, true))) {
    return false;
}
// Трансмиссия
if (!empty($query->transmissions) && ($lot->transmission === null || !in_array($lot->transmission, $query->transmissions, true))) {
    return false;
}
// Топливо
if (!empty($query->fuelTypes) && ($lot->fuel === null || !in_array($lot->fuel, $query->fuelTypes, true))) {
    return false;
}
// Привод
if (!empty($query->driveTypes) && ($lot->driveType === null || !in_array($lot->driveType, $query->driveTypes, true))) {
    return false;
}
// VIN
if ($query->vin !== '' && ($lot->vin === null || !str_starts_with(strtoupper($lot->vin), strtoupper($query->vin)))) {
    return false;
}
```

---

### Задача 6: Создать полный справочник марок/моделей

**Новый файл**: `laravel/storage/app/data/makes_models.json`

JSON объект: ключ = марка, значение = массив моделей. Данные основаны на реальном API auction-api.app (эндпоинт get-brand-transport-type / get-model-bybrand-transport-type).

Включить минимум 30 марок. Вот список (на основе популярных марок из реального API):

```json
{
  "Acura": ["ILX", "Integra", "MDX", "NSX", "RDX", "TLX"],
  "Audi": ["A3", "A4", "A5", "A6", "A7", "A8", "e-tron", "Q3", "Q5", "Q7", "Q8", "RS3", "RS5", "RS6", "RS7", "S3", "S4", "S5", "TT"],
  "BMW": ["2 Series", "3 Series", "4 Series", "5 Series", "7 Series", "8 Series", "M3", "M4", "M5", "X1", "X2", "X3", "X4", "X5", "X6", "X7", "Z4", "iX", "i4", "i7"],
  "Buick": ["Enclave", "Encore", "Encore GX", "Envision"],
  "Cadillac": ["CT4", "CT5", "Escalade", "Lyriq", "XT4", "XT5", "XT6"],
  "Chevrolet": ["Blazer", "Bolt EV", "Camaro", "Colorado", "Corvette", "Equinox", "Malibu", "Silverado", "Suburban", "Tahoe", "Trailblazer", "Traverse", "Trax"],
  "Chrysler": ["300", "Pacifica", "Voyager"],
  "Dodge": ["Challenger", "Charger", "Durango", "Hornet"],
  "Ford": ["Bronco", "Edge", "Escape", "Expedition", "Explorer", "F-150", "F-250", "F-350", "Maverick", "Mustang", "Ranger"],
  "Genesis": ["G70", "G80", "G90", "GV60", "GV70", "GV80"],
  "GMC": ["Acadia", "Canyon", "Sierra", "Terrain", "Yukon"],
  "Honda": ["Accord", "Civic", "CR-V", "HR-V", "Odyssey", "Passport", "Pilot", "Ridgeline"],
  "Hyundai": ["Avante", "Elantra", "Grandeur", "IONIQ 5", "IONIQ 6", "Kona", "Palisade", "Santa Cruz", "Santa Fe", "Sonata", "Staria", "Tucson", "Venue"],
  "Infiniti": ["Q50", "Q60", "QX50", "QX55", "QX60", "QX80"],
  "Jaguar": ["E-PACE", "F-PACE", "F-TYPE", "I-PACE", "XE", "XF"],
  "Jeep": ["Cherokee", "Compass", "Gladiator", "Grand Cherokee", "Renegade", "Wagoneer", "Wrangler"],
  "Kia": ["Carnival", "EV6", "EV9", "Forte", "K5", "K8", "Niro", "Seltos", "Sorento", "Soul", "Sportage", "Stinger", "Telluride"],
  "Land Rover": ["Defender", "Discovery", "Range Rover", "Range Rover Evoque", "Range Rover Sport", "Range Rover Velar"],
  "Lexus": ["ES", "GX", "IS", "LC", "LS", "LX", "NX", "RC", "RX", "RZ", "TX", "UX"],
  "Lincoln": ["Aviator", "Corsair", "Nautilus", "Navigator"],
  "Mazda": ["CX-30", "CX-5", "CX-50", "CX-70", "CX-90", "Mazda3", "Mazda6", "MX-5"],
  "Mercedes-Benz": ["A-Class", "C-Class", "CLA", "CLE", "E-Class", "EQB", "EQE", "EQS", "G-Class", "GLA", "GLB", "GLC", "GLE", "GLS", "S-Class"],
  "Mitsubishi": ["Eclipse Cross", "Mirage", "Outlander", "Outlander Sport"],
  "Nissan": ["Altima", "Ariya", "Frontier", "Kicks", "Leaf", "Maxima", "Murano", "Pathfinder", "Rogue", "Sentra", "Titan", "Versa", "Z"],
  "Porsche": ["718", "911", "Cayenne", "Macan", "Panamera", "Taycan"],
  "Ram": ["1500", "2500", "3500", "ProMaster"],
  "Subaru": ["Ascent", "BRZ", "Crosstrek", "Forester", "Impreza", "Legacy", "Outback", "Solterra", "WRX"],
  "Tesla": ["Cybertruck", "Model 3", "Model S", "Model X", "Model Y"],
  "Toyota": ["4Runner", "bZ4X", "Camry", "Corolla", "Crown", "GR86", "Grand Highlander", "Highlander", "Prius", "RAV4", "Sequoia", "Sienna", "Supra", "Tacoma", "Tundra", "Venza"],
  "Volkswagen": ["Atlas", "Golf", "GTI", "ID.4", "Jetta", "Taos", "Tiguan"],
  "Volvo": ["C40", "S60", "S90", "V60", "V90", "XC40", "XC60", "XC90"]
}
```

---

### Задача 7: Обновить FiltersController

**Файл**: `laravel/app/Http/Controllers/Api/FiltersController.php`

Заменить статичные данные на динамические из файла + добавить списки значений для новых фильтров. Значения фильтров соответствуют нормализованным значениям из нашего LotDTO (после маппинга из API):

```php
public function index(): JsonResponse
{
    // Марки/модели из JSON-справочника
    $makesFile = storage_path('app/data/makes_models.json');
    $makes = file_exists($makesFile)
        ? json_decode(file_get_contents($makesFile), true) ?? []
        : [];

    $currentYear = (int) date('Y');

    return response()->json([
        'ok'   => true,
        'data' => [
            'makes'   => $makes,
            'sources' => [
                ['key' => 'copart',  'name' => 'Copart'],
                ['key' => 'iai',     'name' => 'IAAI'],
                ['key' => 'manheim', 'name' => 'Manheim'],
                ['key' => 'encar',   'name' => 'Encar'],
                ['key' => 'kbcha',   'name' => 'KBChacha'],
            ],
            'years' => range($currentYear, 2000),
            'damageTypes' => [
                'FRONT END', 'REAR END', 'SIDE', 'ALL OVER',
                'ROLLOVER', 'WATER/FLOOD', 'HAIL', 'ELECTRICAL',
                'MECHANICAL', 'MINOR DENTS', 'VANDALISM', 'BURN',
                'UNDERCARRIAGE',
            ],
            'titleTypes' => [
                'Clean', 'Salvage', 'Rebuilt', 'Flood',
                'Lemon', 'Junk', 'Non-repairable',
            ],
            'bodyTypes'      => ['Sedan', 'SUV', 'Truck', 'Coupe', 'Hatchback', 'Wagon', 'Van', 'Convertible', 'Crossover'],
            'transmissions'  => ['Automatic', 'Manual', 'CVT'],
            'fuelTypes'      => ['Gasoline', 'Diesel', 'Hybrid', 'Electric'],
            'driveTypes'     => ['FWD', 'RWD', 'AWD', '4WD'],
        ],
    ]);
}
```

---

### Задача 8: Обновить frontend — HTML

**Файл**: `miniapp/index.html`

Внутри `<div id="screen-search">`, в блоке `<div class="filters-screen" id="filters-scroll">`.

Порядок секций на экране фильтров (отмечены существующие и новые):

1. **Источники** (ЕСТЬ — source-chips, без изменений)
2. **Марка и модель** (ЕСТЬ — два select, без изменений)
3. **Тип кузова** (НОВЫЙ — chip-group, id="bodytype-chips")
4. **Год выпуска** (ЕСТЬ — два input, без изменений)
5. **Цена** (ИЗМЕНИТЬ — заменить один input "макс" на два: "от" и "до")
6. **Пробег** (НОВЫЙ — два input, id="filter-mileage-min" / "filter-mileage-max")
7. **Объём двигателя** (НОВЫЙ — два input, id="filter-engine-min" / "filter-engine-max")
8. **Трансмиссия** (НОВЫЙ — chip-group, id="transmission-chips")
9. **Топливо** (НОВЫЙ — chip-group, id="fuel-chips")
10. **Привод** (НОВЫЙ — chip-group, id="drive-chips")
11. **Тип повреждений** (НОВЫЙ — chip-group, id="damage-chips")
12. **Статус title** (НОВЫЙ — chip-group, id="title-chips")
13. **VIN** (НОВЫЙ — text input, id="filter-vin")
14. **Кнопка "Найти"** (ЕСТЬ — без изменений)

**Секция "Цена" — ЗАМЕНИТЬ** существующую секцию (которая содержит один input "Макс. цена"):

Было:
```html
<div class="filter-section">
  <div class="filter-label">Макс. цена</div>
  <div class="filter-input-wrap">
    <span class="filter-input-prefix">$</span>
    <input class="filter-input has-prefix" type="number" id="filter-price-max" placeholder="напр. 15000" min="0">
  </div>
</div>
```

Стало:
```html
<div class="filter-section">
  <div class="filter-label">Цена ($)</div>
  <div class="filter-range-row">
    <div class="filter-input-wrap">
      <span class="filter-input-prefix">$</span>
      <input class="filter-input has-prefix" type="number" id="filter-price-min" placeholder="От" min="0">
    </div>
    <div class="filter-input-wrap">
      <span class="filter-input-prefix">$</span>
      <input class="filter-input has-prefix" type="number" id="filter-price-max" placeholder="До" min="0">
    </div>
  </div>
</div>
```

**Новые секции** — добавить между существующими. Каждая новая секция обёрнута `<div class="filter-divider"></div>` сверху:

```html
<!-- Тип кузова — ПОСЛЕ секции "Марка и модель" -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Тип кузова</div>
  <div class="chip-group" id="bodytype-chips"></div>
</div>

<!-- Пробег — ПОСЛЕ секции "Цена" -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Пробег (km)</div>
  <div class="filter-range-row">
    <input class="filter-input" type="number" id="filter-mileage-min" placeholder="От" min="0">
    <input class="filter-input" type="number" id="filter-mileage-max" placeholder="До" min="0">
  </div>
</div>

<!-- Объём двигателя — ПОСЛЕ "Пробег" -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Объём двигателя (л)</div>
  <div class="filter-range-row">
    <input class="filter-input" type="number" id="filter-engine-min" placeholder="От" min="0" step="0.1">
    <input class="filter-input" type="number" id="filter-engine-max" placeholder="До" min="0" step="0.1">
  </div>
</div>

<!-- Трансмиссия -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Трансмиссия</div>
  <div class="chip-group" id="transmission-chips"></div>
</div>

<!-- Топливо -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Топливо</div>
  <div class="chip-group" id="fuel-chips"></div>
</div>

<!-- Привод -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Привод</div>
  <div class="chip-group" id="drive-chips"></div>
</div>

<!-- Тип повреждений -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Тип повреждений</div>
  <div class="chip-group" id="damage-chips"></div>
</div>

<!-- Статус title -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">Статус title</div>
  <div class="chip-group" id="title-chips"></div>
</div>

<!-- VIN -->
<div class="filter-divider"></div>
<div class="filter-section">
  <div class="filter-label">VIN</div>
  <input class="filter-input" type="text" id="filter-vin" placeholder="Поиск по VIN" maxlength="17" style="text-transform:uppercase">
</div>
```

---

### Задача 9: Обновить frontend — CSS

**Файл**: `miniapp/css/filters.css`

Добавить стиль для chip-group (используется для multi-select фильтров типа кузова, трансмиссии и т.д.):

```css
/* ── Multi-select chip groups ── */
.chip-group {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.chip-group .filter-chip {
  padding: 5px 12px;
  border-radius: 16px;
  border: 1.5px solid rgba(255,255,255,.12);
  background: transparent;
  color: var(--hint);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all .15s;
  -webkit-tap-highlight-color: transparent;
}
.chip-group .filter-chip.selected {
  border-color: var(--accent);
  background: rgba(51,144,236,.15);
  color: var(--accent);
}
```

---

### Задача 10: Обновить frontend — JavaScript (filters.js)

**Файл**: `miniapp/js/filters.js`

Полная переработка модуля.

**1. Расширить объект state:**
```javascript
const state = {
  sources:        ['copart', 'iai', 'manheim', 'encar', 'kbcha'],
  make:           '',
  model:          '',
  yearFrom:       '',
  yearTo:         '',
  priceMin:       '',
  priceMax:       '',
  mileageMin:     '',
  mileageMax:     '',
  engineMin:      '',
  engineMax:      '',
  bodyTypes:      [],   // multi-select, пустой = все
  transmissions:  [],   // multi-select
  fuelTypes:      [],   // multi-select
  driveTypes:     [],   // multi-select
  damageTypes:    [],   // multi-select
  titleTypes:     [],   // multi-select
  vin:            '',
  sort:           'date',
};
```

**2. Добавить универсальную функцию renderChipGroup():**
```javascript
function renderChipGroup(containerId, items, stateKey) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = items.map(item => `
    <button class="filter-chip${state[stateKey].includes(item) ? ' selected' : ''}"
            data-value="${item}">${item}</button>
  `).join('');
  container.querySelectorAll('.filter-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      const val = btn.dataset.value;
      if (state[stateKey].includes(val)) {
        state[stateKey] = state[stateKey].filter(x => x !== val);
      } else {
        state[stateKey].push(val);
      }
      btn.classList.toggle('selected', state[stateKey].includes(val));
      TG.haptic('selection');
    });
  });
}
```

**3. Обновить render():**
```javascript
function render() {
  renderSourceChips();
  renderMakeSelect();
  renderModelSelect();
  renderChipGroup('bodytype-chips',     filtersData?.bodyTypes     ?? [], 'bodyTypes');
  renderChipGroup('transmission-chips', filtersData?.transmissions ?? [], 'transmissions');
  renderChipGroup('fuel-chips',         filtersData?.fuelTypes     ?? [], 'fuelTypes');
  renderChipGroup('drive-chips',        filtersData?.driveTypes    ?? [], 'driveTypes');
  renderChipGroup('damage-chips',       filtersData?.damageTypes   ?? [], 'damageTypes');
  renderChipGroup('title-chips',        filtersData?.titleTypes    ?? [], 'titleTypes');
}
```

**4. Обновить readFormState():**
```javascript
function readFormState() {
  state.yearFrom   = document.getElementById('filter-year-from')?.value   ?? '';
  state.yearTo     = document.getElementById('filter-year-to')?.value     ?? '';
  state.priceMin   = document.getElementById('filter-price-min')?.value   ?? '';
  state.priceMax   = document.getElementById('filter-price-max')?.value   ?? '';
  state.mileageMin = document.getElementById('filter-mileage-min')?.value ?? '';
  state.mileageMax = document.getElementById('filter-mileage-max')?.value ?? '';
  state.engineMin  = document.getElementById('filter-engine-min')?.value  ?? '';
  state.engineMax  = document.getElementById('filter-engine-max')?.value  ?? '';
  state.vin        = document.getElementById('filter-vin')?.value?.trim() ?? '';
}
```

**5. Обновить getQuery():**
```javascript
function getQuery() {
  readFormState();
  return {
    make:           state.make          || undefined,
    model:          state.model         || undefined,
    yearFrom:       state.yearFrom      ? parseInt(state.yearFrom)      : undefined,
    yearTo:         state.yearTo        ? parseInt(state.yearTo)        : undefined,
    priceMin:       state.priceMin      ? parseInt(state.priceMin)      : undefined,
    priceMax:       state.priceMax      ? parseInt(state.priceMax)      : undefined,
    mileageMin:     state.mileageMin    ? parseInt(state.mileageMin)    : undefined,
    mileageMax:     state.mileageMax    ? parseInt(state.mileageMax)    : undefined,
    engineMin:      state.engineMin     ? parseFloat(state.engineMin)   : undefined,
    engineMax:      state.engineMax     ? parseFloat(state.engineMax)   : undefined,
    bodyTypes:      state.bodyTypes.length      ? state.bodyTypes      : undefined,
    transmissions:  state.transmissions.length  ? state.transmissions  : undefined,
    fuelTypes:      state.fuelTypes.length      ? state.fuelTypes      : undefined,
    driveTypes:     state.driveTypes.length     ? state.driveTypes     : undefined,
    damageTypes:    state.damageTypes.length    ? state.damageTypes    : undefined,
    titleTypes:     state.titleTypes.length     ? state.titleTypes     : undefined,
    vin:            state.vin           || undefined,
    sources:        state.sources,
    sort:           state.sort,
    limit:          40,
  };
}
```

---

### Задача 11: Обновить отображение новых полей в карточке и detail sheet

**Файл**: `miniapp/js/results.js`

**В функции `renderCard()`** — добавить теги с ключевыми характеристиками под блоком damage:

```javascript
<div class="lot-card__tags">
  ${lot.bodyType ? `<span class="lot-card__tag">${escHtml(lot.bodyType)}</span>` : ''}
  ${lot.transmission ? `<span class="lot-card__tag">${escHtml(lot.transmission)}</span>` : ''}
  ${lot.fuel ? `<span class="lot-card__tag">${escHtml(lot.fuel)}</span>` : ''}
  ${lot.driveType ? `<span class="lot-card__tag">${escHtml(lot.driveType)}</span>` : ''}
</div>
```

**В функции `openSheet()`** — добавить новые поля в detail sheet. В блок `.sheet-details`, после существующих полей (Пробег, Дата аукциона, Местоположение, Статус) и ПЕРЕД VIN:

```javascript
${lot.bodyType ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Кузов</span>
  <span class="sheet-detail-value">${escHtml(lot.bodyType)}</span>
</div>` : ''}
${lot.transmission ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">КПП</span>
  <span class="sheet-detail-value">${escHtml(lot.transmission)}</span>
</div>` : ''}
${lot.fuel ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Топливо</span>
  <span class="sheet-detail-value">${escHtml(lot.fuel)}</span>
</div>` : ''}
${lot.driveType ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Привод</span>
  <span class="sheet-detail-value">${escHtml(lot.driveType)}</span>
</div>` : ''}
${lot.engineVolume ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Двигатель</span>
  <span class="sheet-detail-value">${lot.engineVolume} л${lot.cylinders ? ' / ' + lot.cylinders + ' цил.' : ''}</span>
</div>` : ''}
${lot.color ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Цвет</span>
  <span class="sheet-detail-value">${escHtml(lot.color)}</span>
</div>` : ''}
${lot.trim ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Комплектация</span>
  <span class="sheet-detail-value">${escHtml(lot.trim)}</span>
</div>` : ''}
${lot.hasKeys !== null && lot.hasKeys !== undefined ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Ключи</span>
  <span class="sheet-detail-value">${lot.hasKeys ? 'Есть' : 'Нет'}</span>
</div>` : ''}
${lot.document ? `<div class="sheet-detail-item" style="grid-column:span 2">
  <span class="sheet-detail-label">Документ</span>
  <span class="sheet-detail-value" style="font-size:12px">${escHtml(lot.document)}</span>
</div>` : ''}
${lot.retailValue ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Рыночная цена</span>
  <span class="sheet-detail-value">$${Number(lot.retailValue).toLocaleString()}</span>
</div>` : ''}
${lot.repairCost ? `<div class="sheet-detail-item">
  <span class="sheet-detail-label">Стоимость ремонта</span>
  <span class="sheet-detail-value" style="color:var(--danger)">$${Number(lot.repairCost).toLocaleString()}</span>
</div>` : ''}
${lot.secondaryDamage ? `<div class="sheet-detail-item" style="grid-column:span 2">
  <span class="sheet-detail-label">Доп. повреждения</span>
  <span class="sheet-detail-value" style="color:var(--danger)">${escHtml(lot.secondaryDamage)}</span>
</div>` : ''}
```

**Файл**: `miniapp/css/cards.css` — добавить стили:

```css
.lot-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  margin-top: 4px;
}
.lot-card__tag {
  display: inline-block;
  font-size: 10px;
  padding: 2px 6px;
  border-radius: 4px;
  background: rgba(255,255,255,.08);
  color: var(--hint);
}
```

---

## Порядок выполнения (пошагово)

1. `laravel/app/Dto/LotDTO.php` — добавить 13 новых полей + обновить toArray()
2. `laravel/app/AuctionProviders/AbstractProvider.php` — добавить static маппинг-массивы и метод mapValue()
3. `laravel/storage/app/data/makes_models.json` — создать полный справочник (30+ марок)
4. `laravel/storage/app/data/mock_copart.json` — добавить новые поля ко всем 14 объектам
5. `laravel/storage/app/data/mock_iai.json` — добавить новые поля ко всем 14 объектам
6. `laravel/storage/app/data/mock_manheim.json` — добавить новые поля ко всем 14 объектам
7. `laravel/storage/app/data/mock_encar.json` — добавить новые поля ко всем 14 объектам
8. `laravel/storage/app/data/mock_kbcha.json` — добавить новые поля ко всем 14 объектам
9. `laravel/app/AuctionProviders/CopartProvider.php` — обновить normalize()
10. `laravel/app/AuctionProviders/IAIProvider.php` — обновить normalize()
11. `laravel/app/AuctionProviders/ManheimProvider.php` — обновить normalize()
12. `laravel/app/AuctionProviders/EncarProvider.php` — обновить normalize()
13. `laravel/app/AuctionProviders/KBChachaProvider.php` — обновить normalize()
14. `laravel/app/Services/SearchQuery.php` — добавить новые поля + fromArray()
15. `laravel/app/AuctionProviders/AbstractProvider.php` — расширить applyFilters()
16. `laravel/app/Http/Controllers/Api/FiltersController.php` — динамические данные
17. `miniapp/css/filters.css` — добавить стили chip-group
18. `miniapp/css/cards.css` — добавить стиль lot-card__tags + lot-card__tag
19. `miniapp/index.html` — добавить HTML новых фильтров, заменить секцию цены
20. `miniapp/js/filters.js` — полная переработка
21. `miniapp/js/results.js` — отображение новых полей в карточках и sheet

---

## Критерии проверки (Definition of Done)

- [ ] GET /api/filters возвращает 30+ марок из makes_models.json
- [ ] GET /api/filters возвращает списки: damageTypes, titleTypes, bodyTypes, transmissions, fuelTypes, driveTypes
- [ ] POST /api/search принимает все новые параметры (priceMin, mileageMin/Max, engineMin/Max, bodyTypes[], transmissions[], fuelTypes[], driveTypes[], damageTypes[], titleTypes[], vin)
- [ ] Фильтрация по каждому новому параметру работает корректно в applyFilters()
- [ ] Все 5 mock-файлов содержат новые поля (transmission, fuel, body_type, drive_type, color, engine_volume, cylinders, has_keys, secondary_damage, document, retail_value, repair_cost, trim — где применимо)
- [ ] Все 5 провайдеров корректно маппят новые поля через mapValue() в LotDTO
- [ ] Маппинг значений корректен: auto→Automatic, petrol→Gasoline, front→FWD и т.д.
- [ ] На экране фильтров Mini App видны все 13 фильтров (+ кнопка Найти)
- [ ] Чипсы multi-select фильтров кликабельны, toggle работает, haptic feedback присутствует
- [ ] Секция "Цена" имеет два поля: "От" и "До"
- [ ] Выбранные фильтры передаются в API при поиске
- [ ] В карточке лота (renderCard) отображаются теги: кузов, КПП, топливо, привод
- [ ] В detail sheet видны все новые поля: кузов, КПП, топливо, привод, двигатель, цвет, комплектация, ключи, документ, рыночная цена, стоимость ремонта, доп. повреждения
- [ ] Обратная совместимость: поиск без новых фильтров работает как раньше (пустые массивы/нули = "любые")
