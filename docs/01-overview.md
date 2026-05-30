# 01 · Обзор системы

> [Index](./index.md) · **Overview** · [Structure →](./02-structure.md)

---

## Архитектура

```mermaid
flowchart TB
    subgraph PY["🐍 Python Parser"]
        direction LR
        ENC["Encar\n(JSON API)"]
        KBC["KBChacha\n(Scraper)"]
    end

    subgraph DB["🗄️ MySQL"]
        LOTS[("lots")]
        CAT[("catalog_*")]
        TAX[("taxonomy_*")]
    end

    subgraph NORM["⚙️ Нормализация (Artisan)"]
        direction LR
        GES["GradeExtractor\nService"]
        TRE["TaxonomyRule\nEngine"]
    end

    subgraph LAR["🐘 Laravel"]
        direction LR
        API["REST API\n/api/*"]
        ADM["Admin Panel\n/admin/*"]
        WH["Bot Webhook\n/bot/webhook"]
    end

    subgraph CLI["📱 Клиенты"]
        direction LR
        MINI["Telegram\nMini App"]
        TG["Telegram Bot\n(Chat)"]
    end

    ENC -->|upsert| LOTS
    KBC -->|upsert| LOTS
    CAT -->|seed data| GES
    TAX -->|rules| TRE
    LOTS -->|raw data| GES
    GES -->|remainder| TRE
    TRE -->|patch| LOTS

    LOTS --> API
    LOTS --> ADM
    LOTS --> WH

    API --> MINI
    WH --> TG
```

---

## Компоненты

| Компонент | Технология | Роль |
|-----------|-----------|------|
| **Python Parser** | Python 3.11, httpx, APScheduler | Получает лоты с аукционов, пишет в `lots` |
| **MySQL** | MySQL 8 | Хранилище всех данных |
| **GradeExtractorService** | Laravel/PHP | Извлекает структурированные поля из сырого текста грейда |
| **TaxonomyRuleEngine** | Laravel/PHP | Применяет правила корректировки к лотам |
| **Laravel API** | Laravel 11, PHP 8.2 | REST API для Mini App и Bot |
| **Admin Panel** | Laravel Blade + Tailwind | Управление правилами, фильтрами, лотами |
| **Telegram Mini App** | Vanilla JS | Поиск по каталогу с фильтрами |
| **Telegram Bot** | Laravel + Groq AI | Поиск по свободному тексту |

---

## Поток данных

```mermaid
sequenceDiagram
    participant P as Python Parser
    participant DB as MySQL (lots)
    participant N as Normalization<br/>(Artisan)
    participant A as Laravel API
    participant C as Client

    P->>DB: INSERT / UPDATE lots (raw data)
    Note over N: runs manually or on schedule
    N->>DB: READ lots in chunks
    N->>DB: UPDATE lots (trim, generation, fuel…)
    N->>DB: INSERT taxonomy_anomaly_queue (unmatched)
    C->>A: GET /api/search
    A->>DB: SELECT * FROM lots WHERE is_active=1
    A-->>C: JSON lots array
```

---

## Ключевые принципы

| Принцип | Реализация |
|---------|-----------|
| **Non-destructive** | Нормализация заполняет только NULL-поля; никогда не перезаписывает |
| **Аудит** | `raw_data['pre_norm']` хранит исходное состояние до первой нормализации |
| **Фазовые фильтры** | PRE (до записи в БД) и POST (после инспекции) — разные уровни фильтрации |
| **Rule engine** | Taxonomy-правила могут переопределять каталог (более высокий приоритет) |
| **Кэш метаданных** | Python-схема полей экспортируется в JSON-файл, не вызывается subprocess в web-запросах |

---

[Index](./index.md) · [Structure →](./02-structure.md)
