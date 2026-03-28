# Telegram Mini App — Auction Bot
## Детальный план реализации v3

---

## СТРУКТУРА ПРОЕКТА

```
/project-root
│
├── /backend
│   │
│   ├── /api
│   │   ├── search.php              # POST /api/search — основной поиск
│   │   ├── favorites.php           # GET/POST /api/favorites
│   │   └── filters.php             # GET /api/filters — списки марок/моделей
│   │
│   ├── /bot
│   │   ├── webhook.php             # Telegram webhook endpoint
│   │   └── TelegramBot.php         # Класс отправки сообщений в чат
│   │
│   ├── /core
│   │   ├── Database.php            # PDO singleton
│   │   ├── Auth.php                # Валидация Telegram init_data (hash_hmac)
│   │   └── Response.php            # JSON response helper
│   │
│   ├── /dto
│   │   └── LotDTO.php              # Единый формат лота (readonly class)
│   │
│   ├── /providers
│   │   ├── ProviderInterface.php   # Интерфейс: getKey, getName, isAvailable,
│   │   │                           #            search, fetchRaw, normalize
│   │   ├── AbstractProvider.php    # Абстрактный класс: applyFilters, logError,
│   │   │                           #            обработка ошибок, search() pipeline
│   │   ├── CopartProvider.php      # fetchRaw → mock_copart.json (→ API позже)
│   │   ├── IAIProvider.php         # fetchRaw → mock_iai.json    (→ API позже)
│   │   ├── ManheimProvider.php     # fetchRaw → mock_manheim.json(→ API позже)
│   │   ├── EncarProvider.php       # fetchRaw → mock_encar.json  (→ парсер позже)
│   │   └── KBChachaProvider.php    # fetchRaw → mock_kbcha.json  (→ парсер позже)
│   │
│   ├── /services
│   │   ├── ProviderAggregator.php  # Супер-класс: register(), search(),
│   │   │                           #   запускает все провайдеры, мержит LotDTO[]
│   │   ├── SearchQuery.php         # DTO запроса: make, model, year, price, sources[]
│   │   ├── SearchResult.php        # DTO результата: lots[], total, errors[]
│   │   └── CacheService.php        # Файловый кэш на старте (→ Redis позже)
│   │
│   └── config.php                  # BOT_TOKEN, DB_*, APP_ENV
│
├── /miniapp
│   ├── index.html
│   ├── /css
│   │   ├── app.css
│   │   ├── cards.css
│   │   └── filters.css
│   ├── /js
│   │   ├── app.js                  # Роутинг экранов (filters ↔ results)
│   │   ├── api.js                  # fetch() обёртка → /api/search.php
│   │   ├── filters.js              # Состояние фильтров, валидация
│   │   ├── results.js              # Рендер карточек LotDTO
│   │   └── telegram.js             # WebApp SDK: ready, expand, MainButton, haptic
│   └── /img
│       └── placeholder.svg
│
├── /data                           # Mock JSON файлы (по одному на провайдер)
│   ├── mock_copart.json
│   ├── mock_iai.json
│   ├── mock_manheim.json
│   ├── mock_encar.json
│   └── mock_kbcha.json
│
└── /database
    └── schema.sql
```

---

## АРХИТЕКТУРА ПРОВАЙДЕРОВ

### Иерархия классов

```
ProviderInterface          (contract: getKey, getName, isAvailable,
                                      search, fetchRaw, normalize)
        │
        └── AbstractProvider       (реализует: search pipeline, applyFilters,
                │                              logError, isAvailable)
                │
                ├── CopartProvider     (реализует: getKey, getName, fetchRaw, normalize)
                ├── IAIProvider        (реализует: getKey, getName, fetchRaw, normalize)
                ├── ManheimProvider    (реализует: getKey, getName, fetchRaw, normalize)
                ├── EncarProvider      (реализует: getKey, getName, fetchRaw, normalize)
                └── KBChachaProvider   (реализует: getKey, getName, fetchRaw, normalize)

ProviderAggregator         (принимает ProviderInterface[], запускает search(),
                            мержит LotDTO[], сортирует, возвращает SearchResult)
```

### Поток данных

```
search.php
    │
    ▼
ProviderAggregator::search(SearchQuery)
    │
    ├── CopartProvider::search(SearchQuery)
    │       ├── fetchRaw()     ← сейчас: читает mock_copart.json
    │       │                    потом:  вызов auction-api.app / парсер
    │       ├── normalize()    ← маппинг полей Copart → LotDTO
    │       └── applyFilters() ← в AbstractProvider (общий для всех)
    │
    ├── IAIProvider::search(SearchQuery)
    ├── ManheimProvider::search(SearchQuery)
    ├── EncarProvider::search(SearchQuery)
    └── KBChachaProvider::search(SearchQuery)
            │
            ▼
        merge LotDTO[]  →  sort  →  slice(offset, limit)
            │
            ▼
        SearchResult { lots: LotDTO[], total: int, errors: string[] }
            │
            ▼
        JSON response → Mini App
```

### Правило замены провайдера (главный принцип)

```
Сейчас (фаза 4):              Потом (фаза 5+):

CopartProvider                CopartProvider
  fetchRaw() {                  fetchRaw() {
    return json_decode(           return (new AuctionApiClient())
      file_get_contents(              ->search($query);
        'mock_copart.json'      }
      )
    );
  }                           // normalize(), getKey(), getName()
                              // — не меняются вообще
```

Aggregator, AbstractProvider, Mini App — не знают о замене.
Меняется только тело одного метода fetchRaw() в нужном провайдере.

---

## ФАЗА 1 — Локальное окружение (1 день)

### 1.1 Что нужно установить
```
PHP 8.2+        php.net/downloads
MySQL 8.0+      dev.mysql.com  (или MariaDB)
ngrok           ngrok.com/download  ← туннель для Telegram webhook
```

### 1.2 Локальный сервер
```bash
# Вариант A — встроенный PHP сервер (проще всего)
cd /project-root
php -S localhost:8080

# Вариант B — если уже есть XAMPP/WAMP/Laragon
# просто положить проект в htdocs/www
```

### 1.3 ngrok — туннель для webhook
```
Telegram требует HTTPS и публичный URL для webhook.
ngrok пробрасывает локальный порт наружу.

ngrok http 8080
# → выдаёт https://abc123.ngrok-free.app

# Зарегистрировать webhook:
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
     -d "url=https://abc123.ngrok-free.app/backend/bot/webhook.php"
```

> ⚠️ Бесплатный ngrok меняет URL при каждом перезапуске.
> После каждого `ngrok http 8080` нужно перерегистрировать webhook.
> Чтобы не делать это вручную — написать скрипт register_webhook.php (см. фазу 1.4)

### 1.4 register_webhook.php (вспомогательный скрипт)
```
Запускать руками когда меняется ngrok URL:
php register_webhook.php https://abc123.ngrok-free.app

Скрипт делает setWebhook запрос и выводит результат.
```

### 1.5 config.php для локальной разработки
```
APP_ENV   = 'development'   ← отключает проверку Auth::validate()
BOT_TOKEN = 'ваш токен'
DB_HOST   = 'localhost'
DB_NAME   = 'auction_bot'
DB_USER   = 'root'
DB_PASS   = ''
```

### 1.6 BotFather
```
/newbot            → получить BOT_TOKEN
/setmenubutton     → URL = https://abc123.ngrok-free.app/miniapp/
/setcommands       → start: Открыть поиск | help: Помощь
```

> ⚠️ /setmenubutton тоже нужно обновлять при смене ngrok URL.
> Или использовать ngrok с фиксированным доменом (бесплатный аккаунт даёт 1 статичный домен).

---

## ФАЗА 2 — Frontend Mini App (4–5 дней)

### 2.1 Инициализация (telegram.js)
- Подключить `telegram-web-app.js`
- `tg.ready()` + `tg.expand()` — раскрыть на весь экран
- CSS переменные темы: `var(--tg-theme-bg-color)`, `var(--tg-theme-text-color)`

### 2.2 Экран фильтров (filters.js)
- Список марок/моделей: `GET /api/filters.php` → populate `<select>`
- Поля: марка, модель, год от/до, цена макс, чекбоксы площадок
- Состояние фильтров — plain object в filters.js

### 2.3 MainButton (telegram.js)
- `tg.MainButton.setText('🔍 Найти')` + `.show()` при загрузке
- По клику → валидация → `api.js::search(filters)` → переход на результаты

### 2.4 Экран результатов (results.js)
- Сетка карточек 2 колонки
- Карточка: фото, цена, марка/модель/год, площадка (badge), повреждение, кнопка ⭐
- Сортировка: дата / цена ↑ / цена ↓
- Пагинация: кнопка "Ещё 10" (offset += 10)
- Loading skeleton пока идёт запрос

### 2.5 Отправка в чат (app.js)
- После поиска: `tg.sendData(JSON.stringify({ top_lots: lots.slice(0,3), total }))`
- webhook получает → бот отправляет топ-3 карточки в чат

---

## ФАЗА 3 — PHP Backend (3–4 дня)

### 3.1 SearchQuery DTO
```
make       string    'Honda'
model      string    'Accord'
yearFrom   int       2019
yearTo     int       2021
priceMax   int       15000
sources    string[]  ['copart','iaai','encar','kbcha']
sort       string    'date' | 'price_asc' | 'price_desc'
limit      int       20
offset     int       0
```

### 3.2 LotDTO (readonly class)
```
id           string    'copart_45892831'
source       string    'copart'
sourceName   string    'Copart'
make         string    'Honda'
model        string    'Accord'
year         int       2020
price        int       11400
mileage      int       85000
damage       ?string   'Front End'
title        string    'Salvage'
location     string    'Los Angeles, CA'
lotUrl       string    'https://copart.com/lot/45892831'
imageUrl     ?string
vin          ?string
auctionDate  ?string   '2024-03-24'
createdAt    string    '2024-03-20T10:00:00Z'
```

### 3.3 ProviderInterface — полный контракт
```
getKey()                → string        'copart'
getName()               → string        'Copart'
isAvailable()           → bool          провайдер не упал?
search(SearchQuery)     → LotDTO[]      pipeline (в AbstractProvider)
fetchRaw(SearchQuery)   → array         сырые данные (в провайдерах)
normalize(array)        → LotDTO        маппинг полей (в провайдерах)
```

### 3.4 AbstractProvider — что реализует сам
```
search()       — fetchRaw() → array_map(normalize) → applyFilters()
applyFilters() — фильтрует LotDTO[] по make/model/year/price
logError()     — error_log с именем провайдера и сообщением
isAvailable()  — $this->available (false если fetchRaw бросил исключение)
```

### 3.5 AbstractProvider — что оставляет провайдерам (abstract)
```
getKey()     abstract
getName()    abstract
fetchRaw()   abstract   ← сюда подключается реальный источник
normalize()  abstract   ← маппинг полей источника в LotDTO
```

### 3.6 ProviderAggregator — методы
```
register(...$providers)    → static         fluent, регистрирует по getKey()
search(SearchQuery)        → SearchResult   главный метод
getActiveProviders($keys)  → array          фильтр по sources[] + isAvailable()
sort($lots, $by)           → LotDTO[]       сортировка результата
```

### 3.7 search.php — точка входа
```
1. Прочитать JSON body
2. Auth::validate(init_data)  ← пропускать в APP_ENV=development
3. SearchQuery::fromArray($body['query'])
4. (new ProviderAggregator)->register(
       new CopartProvider,
       new IAIProvider,
       new ManheimProvider,
       new EncarProvider,
       new KBChachaProvider,
   )->search($query)
5. Response::success($result->toArray())
```

### 3.8 TelegramBot::sendLotCard() — формат сообщения
```
🚗 Honda Accord 2020 · Copart
💰 $11,400 · Lot #45892831
📍 Los Angeles, CA · 🗓 24 Mar
💥 Front End · 🛣 85,000 km
🔗 Открыть лот
```

---

## ФАЗА 4 — Mock данные (2 дня)

### 4.1 Структура /data/mock_*.json
Каждый файл — сырые данные как у реального источника.
Поля специально разные — чтобы проверить что normalize() правильно маппит.

```
mock_copart.json    поля: lot_number, odometer, damage_description, title_type
mock_iai.json       поля: lotNumber, miles, primaryDamage, titleType
mock_manheim.json   поля: id, mileage, conditionGrade (нет damage поля)
mock_encar.json     поля: carId, mileage_km (нет damage — чистые машины)
mock_kbcha.json     поля: carNm (корейский), distance
```

### 4.2 Каждый провайдер
```
fetchRaw()   → читает /data/mock_{key}.json → return array
normalize()  → маппит свои raw поля → new LotDTO(...)
getKey()     → 'copart' | 'iai' | 'manheim' | 'encar' | 'kbcha'
getName()    → 'Copart' | 'IAAI' | 'Manheim' | 'Encar' | 'KBChacha'
```

### 4.3 Сценарий проверки
```
Aggregator::search({ make:'Honda', sources:['copart','iaai','encar','kbcha'] })
  → CopartProvider вернул 3 лота Honda
  → IAIProvider вернул 2 лота Honda
  → EncarProvider вернул 2 лота Honda
  → KBChachaProvider вернул 1 Hyundai → applyFilters отрезал
  → Итого: 7 лотов, отсортированных по дате

Imitate падение IAI:
  → IAIProvider::fetchRaw() бросает Exception
  → AbstractProvider ловит, logError(), available=false, return []
  → SearchResult.errors = ['iai'] ← фронт может показать предупреждение
```

---

## ФАЗА 5 — Webhook + чат + БД (2 дня)

### 5.1 webhook.php обрабатывает
```
/start         → приветствие + инструкция открыть Mini App
web_app_data   → получает top_lots[] от Mini App → sendLotCard() × 3
```

### 5.2 Сохранение в БД
```
При каждом поиске  → INSERT searches (user_id, параметры, results_cnt)
При нажатии ⭐     → INSERT/DELETE favorites (user_id, lot_id, lot_data JSON)
```

---

## БАЗА ДАННЫХ

### users
```
id          BIGINT PK        Telegram user_id
username    VARCHAR(100)
first_name  VARCHAR(100)
created_at  DATETIME
last_seen   DATETIME
```

### searches
```
id          INT AI PK
user_id     BIGINT FK → users.id
make        VARCHAR(50)
model       VARCHAR(50)
year_from   SMALLINT
year_to     SMALLINT
price_max   INT
sources     JSON             ["copart","iaai","encar"]
results_cnt INT
created_at  DATETIME
```

### favorites
```
id          INT AI PK
user_id     BIGINT FK → users.id
lot_id      VARCHAR(100)
source      VARCHAR(20)
lot_data    JSON             snapshot LotDTO на момент сохранения
created_at  DATETIME
UNIQUE (user_id, lot_id)
```

---

## ПОРЯДОК НАПИСАНИЯ ФАЙЛОВ (все выполнены)

```
[x] 1.  config/auction.php (Laravel config)
[x] 2.  app/Dto/LotDTO.php (расширен: +13 полей)
[x] 3.  app/Services/SearchQuery.php (расширен: +14 фильтров)
[x] 4.  app/Services/SearchResult.php
[x] 5.  app/AuctionProviders/ProviderInterface.php
[x] 6.  app/AuctionProviders/AbstractProvider.php (14 фильтров + маппинг)
[x] 7.  app/AuctionProviders/CopartProvider.php
[x] 8.  app/AuctionProviders/IAIProvider.php
[x] 9.  app/AuctionProviders/ManheimProvider.php
[x] 10. app/AuctionProviders/EncarProvider.php
[x] 11. app/AuctionProviders/KBChachaProvider.php
[x] 12-16. storage/app/data/mock_*.json (все 5 файлов с расширенными полями)
[x] 17. app/Services/ProviderAggregator.php
[x] 18. Laravel migrations (7 миграций)
[x] 19. app/Http/Middleware/ValidateTelegramAuth.php
[x] 20. app/Http/Controllers/Api/SearchController.php
[x] 21. app/Http/Controllers/Api/FiltersController.php
[x] 22. app/Http/Controllers/Api/FavoritesController.php
[x] 23. app/Http/Controllers/Api/SubscriptionsController.php
[x] 24. app/Services/TelegramBot.php
[x] 25. app/Http/Controllers/Bot/WebhookController.php
[x] 26. app/Console/Commands/CheckSubscriptions.php
[x] 27. app/Console/Commands/DemoNotify.php + SeedDemo.php
[x] 28. miniapp/js/telegram.js
[x] 29. miniapp/js/api.js
[x] 30. miniapp/js/filters.js (14 фильтров + чипсы + селекты)
[x] 31. miniapp/js/results.js (карточки + bottom sheet)
[x] 32. miniapp/js/subscriptions.js
[x] 33. miniapp/js/app.js (роутинг 4 экранов)
[x] 34. miniapp/index.html
[x] 35. miniapp/css/app.css + cards.css + filters.css
[x] 36. Docker + docker-compose.yml + railway.toml
```

---

## ТАЙМЛАЙН

```
Фаза 1   1 день    Локальное окружение + ngrok + BotFather          ✅ DONE
Фаза 2   4-5 дней  Frontend Mini App                                ✅ DONE
Фаза 3   3-4 дня   Backend (Interface → Abstract → Providers → Agg) ✅ DONE
Фаза 4   2 дня     Mock данные + тест всей цепочки                  ✅ DONE
Фаза 5   2 дня     Webhook + чат + БД + подписки + уведомления      ✅ DONE
─────────────────────────────────────────────────────────────
Итого    ~2 недели  Рабочий MVP с mock данными — ЗАВЕРШЁН

Фаза 6+            → см. PLAN_V2.md (AI-поиск, погрешности, парсеры)
```