# 02 · Структура проекта

> [← Overview](./01-overview.md) · [Index](./index.md) · [Database →](./03-database.md)

---

## Дерево директорий

```
carbot/
│
├── 📁 laravel/                    Laravel 11 — API, Admin, Bot webhook
│   ├── app/
│   │   ├── AuctionProviders/      Адаптеры источников (Encar, KBChacha)
│   │   ├── Console/Commands/      Artisan-команды (нормализация, каталог, AI)
│   │   ├── Dto/                   Data Transfer Objects
│   │   ├── Http/
│   │   │   ├── Controllers/Api/   REST-контроллеры Mini App
│   │   │   ├── Controllers/Admin/ Контроллеры админ-панели
│   │   │   ├── Controllers/Bot/   Webhook-контроллер
│   │   │   └── Middleware/        TelegramAuth, AdminAuth
│   │   ├── Models/                Eloquent-модели
│   │   ├── Providers/             AppServiceProvider, AuctionServiceProvider
│   │   └── Services/
│   │       ├── Catalog/           GradeExtractorService, CatalogLookupService
│   │       └── Taxonomy/          TaxonomyRuleEngine, TaxonomySuggestionService
│   ├── config/
│   │   ├── auction.php            Bot token, источники
│   │   ├── ai.php                 Groq / Claude API keys
│   │   └── search_tolerance.php   Дефолтные погрешности (fallback)
│   ├── database/
│   │   ├── migrations/            ~60 миграций
│   │   └── seeders/               BotFilterSettingsSeeder, CatalogTokenMapSeeder
│   ├── resources/views/
│   │   └── admin/                 Blade-шаблоны (Tailwind, тёмная тема)
│   ├── routes/
│   │   ├── api.php                /api/* endpoints
│   │   └── web.php                /admin/* + /bot/webhook
│   └── storage/app/
│       ├── field_mappings.json    Экспорт из parser/_shared/field_mappings.py
│       └── fields.json            Экспорт из parser/fields/registry.py
│
├── 📁 parser/                     Python 3.11 — парсер аукционов
│   ├── main.py                    Точка входа + APScheduler
│   ├── config.py                  Конфиг из env-переменных
│   ├── models.py                  CarLot, InspectionRecord (dataclasses)
│   ├── repository.py              LotRepository (интерфейс к MySQL)
│   ├── scheduler.py               Планировщик запусков
│   ├── parsers/
│   │   ├── base.py                BaseParser (абстрактный)
│   │   ├── registry.py            get_enabled() — список активных парсеров
│   │   ├── encar/
│   │   │   ├── client.py          EncarClient — запросы к API
│   │   │   └── normalizer.py      Маппинг полей Encar → CarLot
│   │   ├── kbcha/
│   │   │   ├── client.py          KBChachaClient — скрейпер
│   │   │   └── normalizer.py      Маппинг полей KBChacha → CarLot
│   │   └── _shared/
│   │       ├── field_mappings.py  Метаданные полей (экспортируется в JSON)
│   │       └── korean_model_names.py  Словарь KR→EN названий моделей
│   ├── filters/                   FilterEngine (PRE/POST фазы)
│   └── fields/
│       └── registry.py            Реестр полей для UI фильтров
│
├── 📁 miniapp/                    Telegram Mini App (статика)
│   ├── index.html
│   ├── js/
│   │   ├── telegram.js            Инициализация Telegram WebApp SDK
│   │   ├── api.js                 Запросы к /api/*
│   │   ├── filters.js             Логика фильтров
│   │   ├── results.js             Рендер карточек лотов
│   │   └── app.js                 Точка входа
│   └── css/
│       ├── app.css
│       ├── cards.css
│       └── filters.css
│
├── 📁 docker/                     Docker-конфиги
│   ├── nginx/default.conf         Nginx: Mini App + PHP-FPM proxy
│   └── php/
│       ├── Dockerfile             PHP 8.2-FPM + Composer
│       ├── entrypoint.sh          Bootstrap: composer install, migrate, key:generate
│       └── php.ini
│
├── 📁 docs/                       Техническая документация (этот раздел)
│
├── docker-compose.yml
├── Makefile
├── README.md
└── FIELD_MAP.md                   Словарь полей: API-пути, покрытие, нормализация
```

---

## Модули и их ответственность

| Модуль | Расположение | Что делает |
|--------|-------------|------------|
| **GradeExtractorService** | `laravel/app/Services/Catalog/` | 6-стадийное позитивное извлечение из текста грейда |
| **TaxonomyRuleEngine** | `laravel/app/Services/Taxonomy/` | Применяет правила из `taxonomy_rules` к лотам |
| **TaxonomySuggestionService** | `laravel/app/Services/Taxonomy/` | Эвристические предложения для аномалий |
| **ProviderAggregator** | `laravel/app/Services/` | Объединяет результаты от всех провайдеров |
| **ChatSearchService** | `laravel/app/Services/` | AI-парсинг текста → `SearchQuery` |
| **FieldMappingsService** | `laravel/app/Services/` | Читает метаданные полей из JSON или Python |
| **EncarClient** | `parser/parsers/encar/` | HTTP-запросы к `api.encar.com` |
| **KBChachaClient** | `parser/parsers/kbcha/` | Скрейпинг KBChacha (httpx + перехват XHR) |
| **LotRepository** | `parser/repository.py` | upsert батчей, фильтрация, синхронизация фото |
| **FilterEngine** | `parser/filters/` | PRE/POST фазы фильтрации лотов |

---

[← Overview](./01-overview.md) · [Index](./index.md) · [Database →](./03-database.md)
