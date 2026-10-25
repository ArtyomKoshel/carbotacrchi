# 01 · Обзор системы

> [Index](./index.md) · **Overview** · [Structure →](./02-structure.md)

---

## Архитектура

```mermaid
flowchart TB
    subgraph COL["🐍 Сборщик (collector/)"]
        ENC["Encar
(JSON API)"]
    end

    subgraph LAR["🐘 Laravel"]
        direction LR
        ING["Приём
ingest:consume"]
        LNK["Справочник
EncarMatcher → CatalogLinker"]
        API["REST API
/api/*"]
        ADM["Админка
/admin/*"]
        WH["Бот
/bot/webhook"]
    end

    subgraph DB["🗄️ MySQL"]
        LOTS[("lots")]
        REF[("ref_*")]
    end

    subgraph CLI["📱 Клиенты"]
        direction LR
        MINI["Telegram
Mini App"]
        TG["Telegram Bot"]
    end

    ENC -->|буфер приёма| ING
    ING -->|upsert| LOTS
    ING --> LNK
    LNK -->|ref_*_id| LOTS
    REF --- LNK

    LOTS --> API
    REF --> API
    LOTS --> ADM
    LOTS --> WH

    API --> MINI
    WH --> TG
```

---

## Компоненты

| Компонент | Технология | Роль |
|-----------|-----------|------|
| **Сборщик** | Python 3.11 (`collector/`) | Получает лоты с Encar, кладёт записи в буфер приёма |
| **Приём** | Laravel, `ingest:consume` | Пишет записи буфера в `lots`, отбирает по `parse_filters`, сразу ставит новые лоты в справочник |
| **Справочник** | Laravel (`app/Catalog`), JSON `resources/catalog` → `ref_*` | Марка → модель → поколение → модификация → комплектация; см. [13-catalog](./13-catalog.md) |
| **MySQL** | MySQL 8–9 | Хранилище всех данных |
| **Laravel API** | Laravel 13, PHP 8.4 | REST API для Mini App и бота; поиск только по `ref_*` |
| **Admin Panel** | Filament 5 (Livewire 4) | Парсинг по площадкам, лоты, очередь вариантов справочника, правила отбора, доступ |
| **Telegram Mini App** | Vanilla JS | Поиск с каскадом справочника |
| **Telegram Bot** | Laravel + ИИ | Поиск по свободному тексту; названия переводятся в id справочника (`CatalogNames`) |

---

## Поток данных

```mermaid
sequenceDiagram
    participant S as Сборщик
    participant I as Приём (ingest:consume)
    participant DB as MySQL
    participant A as Laravel API
    participant C as Клиент

    S->>DB: записи в буфер приёма
    I->>DB: UPSERT lots (поля Encar)
    I->>DB: lots.ref_*_id по справочнику (CatalogLinker)
    Note over DB: catalog:remap — пересобрать всё после правки справочника
    C->>A: POST /api/search (refMakeId, refModelId, …)
    A->>DB: SELECT … FROM lots WHERE is_active=1 AND ref_* …
    A-->>C: лоты с местом в справочнике (CatalogPlaces)
```

---

## Ключевые принципы

| Принцип | Реализация |
|---------|-----------|
| **Только то, что сказал Encar** | Двигатель, привод, топливо, поколение — только из полей Encar; неизвестное остаётся пустым, в фильтре видно как «не указано» |
| **Названия — из справочника** | Марка, модель, поколение, модификация, комплектация — `ref_*`; ИИ-переводов в данных нет |
| **Поля Encar не переписываются** | Корейские `make`, `model`, `badge`, `trim` хранятся как прислал Encar; непустое новое значение заменяет старое |
| **Фазовые фильтры** | PRE (до записи в БД) и POST (после инспекции) — разные уровни фильтрации |
| **Кэш метаданных** | Python-схема полей экспортируется в JSON-файл, не вызывается subprocess в web-запросах |

---

[Index](./index.md) · [Structure →](./02-structure.md)
