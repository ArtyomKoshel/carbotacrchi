# План V2 — AI-поиск, погрешности фильтров, парсеры

> Предыдущие планы (PLAN.md, PLAN_FILTERS.md) — выполнены полностью.
> Этот план — следующий этап развития продукта.

---

## Обзор задач

| # | Задача | Приоритет | Зависимости |
|---|--------|-----------|-------------|
| 1 | AI-поиск через чат (Natural Language Search) | Высокий | Claude Haiku API |
| 2 | Погрешности фильтров (Fuzzy Tolerance) | Высокий | — |
| 3 | Парсер Encar (Python) | Высокий | Корейский прокси |
| 4 | Парсер KBChacha (Python + Playwright) | Средний | Корейский прокси |

---

## Задача 1: AI-поиск через чат

### Что делаем

Менеджер пишет в чат боту свободный текст:
```
BMW X3 пробег от 10000 бензин 2.8
```

Бот через Claude Haiku API парсит текст → преобразует в `SearchQuery` → выполняет поиск → отправляет карточки лотов в чат.

### Поток данных

```
Менеджер пишет текст в Telegram чат
    │
    ▼
WebhookController → текст не /команда → ChatSearchService
    │
    ▼
ChatSearchService::parseQuery(text)
    │
    ├── Формирует system prompt с описанием всех фильтров
    │   и их допустимых значений (из FiltersController)
    │
    ├── Отправляет в Claude Haiku API
    │   (model: claude-haiku-4-5-20251001)
    │
    └── Получает JSON:
        {
          "make": "BMW",
          "model": "X3",
          "mileageMin": 10000,
          "fuelTypes": ["Gasoline"],
          "engineMin": 2.8
        }
    │
    ▼
SearchQuery::fromArray(json) + withTolerance() (→ задача 2)
    │
    ▼
ProviderAggregator::search(query) → результаты
    │
    ▼
Бот отправляет в чат:
  - "🔍 Ищу: BMW X3, пробег от 10000, бензин, 2.8л..."
  - Топ-5 карточек лотов
  - Кнопка «📱 Все результаты» (deep link в Mini App с фильтрами)
  - Кнопка «🔔 Подписаться» (создать подписку на эти фильтры)
```

### Новые файлы

| Файл | Назначение |
|------|-----------|
| `app/Services/ChatSearchService.php` | Парсинг текста через Claude API → SearchQuery |
| `config/ai.php` | Конфиг: API key, модель, system prompt |

### Изменения в существующих файлах

| Файл | Что меняется |
|------|-------------|
| `WebhookController.php` | Добавить ветку для обычного текста → ChatSearchService |
| `TelegramBot.php` | Метод `sendSearchResults()` — отправка результатов AI-поиска |
| `.env` | Добавить `ANTHROPIC_API_KEY` |

### System prompt для Claude (черновик)

```
Ты — парсер поисковых запросов для автомобилей. Пользователь пишет свободный текст,
ты извлекаешь параметры поиска и возвращаешь JSON.

Доступные фильтры:
- make (string) — марка: BMW, Toyota, Honda, Hyundai, Kia, Mercedes-Benz...
- model (string) — модель: X3, Camry, Accord, Tucson...
- yearFrom, yearTo (int) — диапазон годов
- priceMin, priceMax (int) — цена в USD
- mileageMin, mileageMax (int) — пробег в км
- engineMin, engineMax (float) — объём двигателя в литрах
- fuelTypes (string[]) — допустимые: "Gasoline", "Diesel", "Hybrid", "Electric"
- transmissions (string[]) — допустимые: "Automatic", "Manual", "CVT"
- bodyTypes (string[]) — допустимые: "Sedan", "SUV", "Truck", "Coupe", "Hatchback", "Wagon", "Van", "Convertible", "Crossover"
- driveTypes (string[]) — допустимые: "FWD", "RWD", "AWD", "4WD"
- sources (string[]) — допустимые: "copart", "iai", "manheim", "encar", "kbcha"

Правила:
1. Возвращай ТОЛЬКО JSON, без пояснений
2. Включай только те поля, которые явно упомянуты в тексте
3. "бензин"/"бенз" → fuelTypes: ["Gasoline"]
4. "дизель" → fuelTypes: ["Diesel"]
5. "электро"/"электрический" → fuelTypes: ["Electric"]
6. "гибрид" → fuelTypes: ["Hybrid"]
7. "автомат"/"АКПП" → transmissions: ["Automatic"]
8. "механика"/"МКПП" → transmissions: ["Manual"]
9. "полный привод"/"4WD" → driveTypes: ["AWD"]
10. "передний привод" → driveTypes: ["FWD"]
11. "задний привод" → driveTypes: ["RWD"]
12. Числа после марки/модели без контекста — скорее всего объём двигателя (2.0, 2.5, 3.0)
13. "от X" → Min поле, "до X" → Max поле
14. Пробег определяй по контексту: "пробег от 10000" → mileageMin: 10000
15. Цену определяй по контексту: "до 15000$" → priceMax: 15000
16. Если текст не содержит параметров поиска авто — верни {"error": "not_a_search"}
```

### Пример

Вход: `"BMW X3 2020-2023 пробег до 80000 бензин 2.0 до 25000$"`

Выход:
```json
{
  "make": "BMW",
  "model": "X3",
  "yearFrom": 2020,
  "yearTo": 2023,
  "mileageMax": 80000,
  "fuelTypes": ["Gasoline"],
  "engineMin": 2.0,
  "engineMax": 2.0,
  "priceMax": 25000
}
```

### Стоимость

Claude Haiku: ~$0.001 за запрос (input ~500 tokens + output ~100 tokens).
При 100 запросах/день = ~$3/месяц.

---

## Задача 2: Погрешности фильтров (Fuzzy Tolerance)

### Что делаем

Числовые фильтры работают с погрешностью — расширяют диапазон поиска.
Менеджер пишет "пробег от 10000" → ищем от 7000 до 13000 (±30%).

### Новый файл конфига

**`config/search_tolerance.php`**:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Включить/выключить систему погрешностей
    |--------------------------------------------------------------------------
    */
    'enabled' => env('SEARCH_TOLERANCE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Где применять погрешности
    |--------------------------------------------------------------------------
    | 'chat_only' — только для AI-поиска из чата
    | 'all'       — для всех поисков (включая Mini App)
    */
    'apply_to' => env('SEARCH_TOLERANCE_APPLY_TO', 'chat_only'),

    /*
    |--------------------------------------------------------------------------
    | Коэффициенты погрешности
    |--------------------------------------------------------------------------
    | Процент от значения. 0.30 = ±30%
    | Для year — абсолютное значение (1 = ±1 год)
    */
    'tolerances' => [
        'mileage' => (float) env('TOLERANCE_MILEAGE', 0.30),   // ±30%
        'price'   => (float) env('TOLERANCE_PRICE',   0.20),    // ±20%
        'engine'  => (float) env('TOLERANCE_ENGINE',  0.15),    // ±15%
        'year'    => (int)   env('TOLERANCE_YEAR',    1),        // ±1 год
    ],
];
```

### Логика применения

**Новый метод в `SearchQuery`**:

```php
public function withTolerance(): self
{
    $config = config('search_tolerance');
    if (!$config['enabled']) return $this;

    $clone = clone $this;
    $t = $config['tolerances'];

    // Пробег: ±30%
    if ($clone->mileageMin > 0) {
        $clone->mileageMin = (int) round($clone->mileageMin * (1 - $t['mileage']));
    }
    if ($clone->mileageMax > 0) {
        $clone->mileageMax = (int) round($clone->mileageMax * (1 + $t['mileage']));
    }

    // Цена: ±20%
    if ($clone->priceMin > 0) {
        $clone->priceMin = (int) round($clone->priceMin * (1 - $t['price']));
    }
    if ($clone->priceMax > 0) {
        $clone->priceMax = (int) round($clone->priceMax * (1 + $t['price']));
    }

    // Двигатель: ±15%
    if ($clone->engineMin > 0) {
        $clone->engineMin = round($clone->engineMin * (1 - $t['engine']), 1);
    }
    if ($clone->engineMax > 0) {
        $clone->engineMax = round($clone->engineMax * (1 + $t['engine']), 1);
    }

    // Год: ±1
    if ($clone->yearFrom > 0) {
        $clone->yearFrom -= $t['year'];
    }
    if ($clone->yearTo > 0) {
        $clone->yearTo += $t['year'];
    }

    return $clone;
}
```

### Где вызывать

- **ChatSearchService** (AI-поиск) → всегда применяет `withTolerance()`
- **SearchController** (Mini App) → применяет только если `apply_to === 'all'`

### Отображение пользователю

Когда погрешность применена, бот сообщает:
```
🔍 Ищу: BMW X3, пробег от 10000 км
📊 С учётом погрешности: пробег 7,000–13,000 км
```

---

## Задача 3: Парсер Encar.com (Python)

### Исследование

**API**: `https://api.encar.com/search/car/list/premium`

| Аспект | Детали |
|--------|--------|
| Формат | JSON REST API (без аутентификации) |
| Фильтры | RYVUSS query expression: марка, модель, год, пробег, цена, топливо, КПП |
| Пагинация | Offset-based: `sr=\|ModifiedDate\|0\|20` |
| Гео-ограничение | **Нужен корейский IP** для фильтров по марке, году, пробегу, цене |
| Rate limit | Не обнаружен, но агрессивный скрапинг может привести к блокировке |
| Данные | Марка, модель, год, цена (만원), пробег, топливо, КПП, фото, дилер |

### Архитектура парсера

```
/parser/                          # Отдельный Python-сервис
├── encar/
│   ├── __init__.py
│   ├── client.py                 # HTTP клиент к api.encar.com
│   ├── query_builder.py          # Построение RYVUSS query expressions
│   ├── normalizer.py             # Encar JSON → наш формат (LotDTO-совместимый)
│   └── config.py                 # Настройки: прокси, интервалы, лимиты
├── storage/
│   └── db.py                     # Запись в MySQL таблицу lots
├── scheduler.py                  # APScheduler: запуск по расписанию
├── requirements.txt
├── Dockerfile
└── .env
```

### Ключевые параметры Encar API

**Query expression (параметр `q`):**
```
(And.Hidden.N._.(C.CarType.N._.(C.Manufacturer.BMW._.ModelGroup.X3.))_.Year.range(202000..202300)_.Mileage.range(..80000)_.FuelType.가솔린.)
```

**Маппинг наших фильтров → Encar:**

| Наш фильтр | Encar параметр | Примечание |
|-------------|---------------|------------|
| make | `Manufacturer.{name}` | Корейские названия для корейских марок |
| model | `ModelGroup.{name}` | Корейские названия |
| yearFrom/To | `Year.range(YYYYMM..YYYYMM)` | Формат: 202000 = январь 2020 |
| priceMin/Max | `Price.range(min..max)` | В единицах 만원 (÷10000 KRW) |
| mileageMin/Max | `Mileage.range(min..max)` | В км |
| fuelTypes | `FuelType.{value}` | 가솔린/디젤/전기/가솔린+전기 |
| transmissions | `Transmission.{value}` | 오토/수동/CVT |

**Пагинация (параметр `sr`):**
```
|{SortField}|{Offset}|{Count}
```
Sort: `ModifiedDate`, `PriceAsc`, `PriceDesc`, `MileageAsc`, `Year`

**Формат ответа:**
```json
{
  "Count": 217832,
  "SearchResults": [
    {
      "Id": "41289395",
      "Manufacturer": "BMW",
      "Model": "X3",
      "Badge": "xDrive 20d",
      "Year": 202102.0,
      "Mileage": 45000.0,
      "Price": 3500.0,
      "FuelType": "디젤",
      "Transmission": "오토",
      "Photo": "/carpicture07/pic4127/41275652_",
      "Photos": [...],
      "ModifiedDate": "2026-03-28 17:13:19.000 +09",
      "OfficeCityState": "서울",
      "OfficeName": "...",
      "DealerName": "..."
    }
  ]
}
```

**Важно:**
- `Price` в 만원 (10,000 KRW). 3500.0 = 35,000,000 KRW ≈ $26,000
- `Year` формат YYYYMM.0: 202102.0 = февраль 2021
- Фото: `https://ci.encar.com/carpicture` + `Photos[].location`

### Маппинг значений Encar → наш формат

| Encar | Наш формат |
|-------|-----------|
| `가솔린` | Gasoline |
| `디젤` | Diesel |
| `전기` | Electric |
| `가솔린+전기` / `디젤+전기` | Hybrid |
| `LPG` | LPG |
| `오토` | Automatic |
| `수동` | Manual |
| `CVT` | CVT |

### Конвертация цен

```python
# Encar → USD (примерный курс, обновлять периодически)
KRW_PER_MAN_WON = 10000
USD_KRW_RATE = 1350  # ≈ текущий курс
price_usd = int(encar_price * KRW_PER_MAN_WON / USD_KRW_RATE)
```

### Требования

- **Корейский прокси/VPN** — обязателен для полноценных фильтров
- **Python 3.11+**
- **httpx** — HTTP клиент (async)
- **APScheduler** — запуск парсинга по расписанию
- **mysql-connector-python** — запись в БД

---

## Задача 4: Парсер KBChacha (Python + Playwright)

### Исследование

**Сайт**: `https://www.kbchachacha.com`

| Аспект | Детали |
|--------|--------|
| Формат | SPA, данные загружаются через AJAX (JSON endpoints: `.json` suffix) |
| Anti-bot | Лёгкий: нет Cloudflare, нет CAPTCHA |
| JS rendering | **Нужен** для страниц поиска (SPA). Detail pages — SSR с JSON-LD |
| Гео-ограничение | **Нет** — доступен из любой страны |
| Rate limit | Не обнаружен, но рекомендуется 2-5 сек между запросами |

### Обнаруженные JSON endpoints

| Endpoint | Назначение |
|----------|-----------|
| `GET /public/search/carMaker.json` | Все производители + кол-во объявлений |
| `POST /public/main/car/recommend/car/model/search/list/v3.json` | Поиск автомобилей |
| `GET /public/car/detail.kbc?carSeq={id}` | Детальная страница (HTML + JSON-LD) |

### Параметры поиска

| Параметр | Назначение |
|----------|-----------|
| `makerCode` | Код производителя (из carMaker.json) |
| `modelCode` | Код модели |
| `minYear`, `maxYear` | Диапазон годов |
| `minMile`, `maxMile` | Пробег (км) |
| `minPrice`, `maxPrice` | Цена (만원 = 10,000 KRW) |
| `fuelCode` | Тип топлива |
| `missionCode` | Тип КПП |
| `countryOrder` | 0=все, фильтр по стране |

### Данные с detail page (JSON-LD)

```json
{
  "@type": "Product",
  "name": "2023 Hyundai Tucson NX4 1.6T",
  "image": ["https://..."],
  "offers": {
    "price": "2490",
    "priceCurrency": "KRW"
  }
}
```

Дополнительно из HTML: пробег, топливо, КПП, цвет, история аварий, кол-во владельцев, диагностика KB.

### Стратегия парсинга

**Двухэтапный подход:**

1. **Playwright** — открываем страницу поиска, перехватываем XHR-ответы с JSON данными
   - `page.on("response")` — ловим ответы от `.json` endpoints
   - Это даёт нам поисковую выдачу в чистом JSON

2. **httpx** — для detail pages парсим JSON-LD (не нужен JS rendering)
   - Или берём достаточно данных из поисковой выдачи

### Архитектура

```
/parser/
├── kbcha/
│   ├── __init__.py
│   ├── scraper.py                # Playwright: поиск + перехват JSON
│   ├── detail_parser.py          # httpx + BeautifulSoup: detail pages
│   ├── normalizer.py             # KBCha → наш формат
│   └── config.py                 # Настройки
├── ...
```

---

## Общая инфраструктура парсеров

### Таблица lots в MySQL

```sql
CREATE TABLE lots (
    id          VARCHAR(100) PRIMARY KEY,  -- '{source}_{source_lot_id}'
    source      VARCHAR(20) NOT NULL,       -- 'encar', 'kbcha'
    raw_data    JSON NOT NULL,              -- Сырые данные от источника
    make        VARCHAR(100),
    model       VARCHAR(100),
    year        SMALLINT,
    price       INT,                        -- В USD
    price_krw   BIGINT,                     -- Оригинальная цена в KRW
    mileage     INT,                        -- В км
    fuel        VARCHAR(30),
    transmission VARCHAR(30),
    body_type   VARCHAR(30),
    drive_type  VARCHAR(30),
    engine_volume DECIMAL(3,1),
    color       VARCHAR(30),
    location    VARCHAR(200),
    lot_url     VARCHAR(500),
    image_url   VARCHAR(500),
    vin         VARCHAR(20),
    damage      VARCHAR(100),
    title       VARCHAR(50),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    parsed_at   TIMESTAMP,                  -- Когда последний раз обновлены данные
    is_active   BOOLEAN DEFAULT TRUE,       -- Ещё в продаже?
    INDEX idx_source (source),
    INDEX idx_make_model (make, model),
    INDEX idx_price (price),
    INDEX idx_year (year),
    INDEX idx_updated (updated_at)
);
```

### Миграция Laravel

Создать `create_lots_table` migration в Laravel для этой таблицы.

### Изменения в провайдерах

**EncarProvider** и **KBChachaProvider** → меняем `fetchRaw()`:

```php
// Было:
protected function fetchRaw(SearchQuery $query): array
{
    $path = config('auction.data_dir') . '/mock_encar.json';
    return json_decode(file_get_contents($path), true) ?: [];
}

// Стало:
protected function fetchRaw(SearchQuery $query): array
{
    return \DB::table('lots')
        ->where('source', 'encar')
        ->where('is_active', true)
        ->get()
        ->map(fn ($row) => json_decode($row->raw_data, true))
        ->toArray();
}
```

Т.е. провайдеры читают из таблицы `lots`, а парсер пишет в неё.

### Docker Compose

Добавить сервис `parser` в `docker-compose.yml`:

```yaml
parser:
  build: ./parser
  depends_on:
    - mysql
  environment:
    - DB_HOST=mysql
    - DB_NAME=auction_bot
    - ENCAR_PROXY=socks5://...
    - PARSE_INTERVAL_MINUTES=30
  restart: unless-stopped
```

### Расписание парсинга

| Источник | Интервал | Причина |
|----------|----------|---------|
| Encar | Каждые 30 мин | JSON API, быстро, много данных |
| KBChacha | Каждые 60 мин | Playwright медленнее, щадим ресурсы |

---

## Прокси для Кореи

### Нужен для

- **Encar** — обязательно (фильтры по марке/году/пробегу блокируются без KR IP)
- **KBChacha** — не нужен (доступен глобально), но желателен для стабильности

### Варианты

| Провайдер | Тип | Цена | Примечание |
|-----------|-----|------|------------|
| BrightData | Residential KR | ~$10-15/GB | Надёжный, дорогой |
| Smartproxy | Residential KR | ~$8-12/GB | Хороший баланс |
| ProxySale | Datacenter KR | ~$2-5/мес за IP | Дёшево, может быть заблокирован |
| Korean VPS | Datacenter | ~$5-10/мес | Свой IP, полный контроль |

**Рекомендация**: Начать с **Korean VPS** (напр. Vultr Seoul, ~$6/мес). Деплоить парсер прямо на VPS. Если заблокируют — перейти на residential proxy.

---

## Порядок реализации

```
Этап 1 (1-2 дня)    Задача 2: Погрешности фильтров
                     — config/search_tolerance.php
                     — SearchQuery::withTolerance()
                     — Интеграция в SearchController

Этап 2 (2-3 дня)    Задача 1: AI-поиск через чат
                     — config/ai.php + .env
                     — ChatSearchService.php (Claude Haiku)
                     — WebhookController: обработка текста
                     — TelegramBot: отправка результатов + кнопки

Этап 3 (3-4 дня)    Задача 3: Парсер Encar
                     — Python проект: /parser/
                     — encar/client.py + query_builder.py
                     — encar/normalizer.py
                     — Миграция lots table
                     — EncarProvider::fetchRaw() → БД
                     — Docker + Korean VPS

Этап 4 (3-4 дня)    Задача 4: Парсер KBChacha
                     — kbcha/scraper.py (Playwright)
                     — kbcha/normalizer.py
                     — KBChachaProvider::fetchRaw() → БД
                     — Тестирование + стабилизация

─────────────────────────────────────────
Итого: ~10-13 дней
```

---

## Вопросы для решения перед стартом

1. ~~Парсеры: Python или Node.js?~~ → **Python** (решено)
2. ~~Carapis или свои парсеры?~~ → **Свои** (решено)
3. Прокси: Korean VPS или residential proxy? → Рекомендую **VPS для старта**
4. AI-поиск: Claude Haiku API key — нужно завести аккаунт Anthropic
5. Погрешности: применять к Mini App тоже или только к чату? → Рекомендую **chat_only** для старта
