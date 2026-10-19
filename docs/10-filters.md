# 10 · Filter Engine

> [← Bot](./09-bot.md) · [Index](./index.md) · [Commands →](./11-commands.md)

---

## Назначение

FilterEngine (Python) определяет, **какие лоты вообще попадают в БД** и остаются активными.  
Это отдельно от поиска пользователей — это политики исключения источника данных.

---

## Две фазы

```mermaid
flowchart TD
    subgraph PRE["PRE-фаза · До записи в БД"]
        direction LR
        P1["CarLot в памяти"]
        P2{{"FilterEngine\n.apply(lot)"}}
        P3["Лот отброшен\n(не пишется в lots)"]
        P4["Продолжить → upsert"]
        P1 --> P2
        P2 -->|"matched"| P3
        P2 -->|"no match"| P4
    end

    subgraph POST["POST-фаза · После инспекции"]
        direction LR
        Q1["lot_id в БД\n+ inspection данные"]
        Q2{{"FilterEngine\n.apply(lot_id)"}}
        Q3["UPDATE lots\nSET is_active=0"]
        Q4["UPSERT filter_skip_log"]
        Q5["Лот остаётся активным"]
        Q1 --> Q2
        Q2 -->|"matched"| Q3 --> Q4
        Q2 -->|"no match"| Q5
    end

    PARSE["Парсер — фаза ENRICH"] --> PRE
    PRE --> POST
```

### Зачем два этапа?

| Фаза | Когда | Что доступно |
|------|-------|-------------|
| **PRE** | До DB-вставки | Базовые поля из листинга: make, model, year, price, mileage, fuel, body_type |
| **POST** | После инспекции | drive_type (из инспекции), детали ДТП, страховые случаи, специфические флаги |

---

## Структура правила (`parse_filters`)

| Колонка | Описание |
|---------|----------|
| `phase` | `pre` \| `post` |
| `source` | Источник (`encar`, `kbcha`) или `*` = все |
| `rule_group` | Логическая группа (правила внутри группы объединяются OR; группы — AND) |
| `field` | Поле лота для сравнения |
| `operator` | Оператор сравнения (см. ниже) |
| `value` | Значение для сравнения |
| `is_active` | Включить/отключить без удаления |
| `notes` | Комментарий |

### Операторы

| Оператор | Пример |
|----------|--------|
| `eq` | `sell_type = auction` |
| `ne` | `fuel != electric` |
| `gt` / `gte` | `year >= 2018` |
| `lt` / `lte` | `price <= 50000000` |
| `in` | `make IN [현대, 기아, 제네시스]` |
| `not_in` | `body_type NOT IN [truck, van]` |
| `contains` | `model CONTAINS "SUV"` |
| `regex` | `plate_number MATCHES "^[가-힣]{2}\d{4}"` |

---

## Логика групп

```
rule_group = "A": sell_type = "auction"
rule_group = "A": source = "kbcha"
rule_group = "B": year < 2015

Результат: (sell_type="auction" OR source="kbcha") AND (year < 2015)
```

Это позволяет строить сложные условия без кода.

---

## `filter_skip_log`

Одна строка на пару «лот + правило» (`UNIQUE (source, source_id, rule_name)`).
Парсер делает upsert: повторный пропуск тем же правилом не добавляет строку,
а обновляет `last_skipped_at`, `skip_count` и `field_value`.

```sql
source, source_id, lot_url
rule_name, rule_id, action   -- какое правило сработало (skip | mark_inactive)
field_name, field_value      -- поле и его значение при последнем пропуске
skipped_at                   -- первый пропуск этим правилом
last_skipped_at              -- последний пропуск
skip_count                   -- сколько раз пропущен
```

Ретенция: `model:prune` (ежедневно 04:20) удаляет строки, у которых `last_skipped_at`
старше 30 дней (`FilterSkipLog::RETENTION_DAYS`) — лот снят с продажи или больше не фильтруется.

Просмотр и ручная очистка через `/admin/filter-skip-log`.

---

## Управление

Правила управляются через `/admin/filters`.  
Filter Engine в парсере перезагружает правила из БД **каждые 60 секунд** — изменения применяются без перезапуска парсера.

---

[← Bot](./09-bot.md) · [Index](./index.md) · [Commands →](./11-commands.md)
