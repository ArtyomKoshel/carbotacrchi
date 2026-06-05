# 06 · Нормализация

> [← Catalog](./05-catalog.md) · [Index](./index.md) · [Taxonomy →](./07-taxonomy.md)

---

## Команда `lots:normalize-encar-taxonomy`

```bash
php artisan lots:normalize-encar-taxonomy [OPTIONS]

  --apply             Сохранить изменения (дефолт: dry-run)
  --limit=N           Максимум лотов (0 = все)
  --chunk=2000        Размер батча для запросов к БД
  --source=encar      Фильтр по источнику
  --random            Случайный порядок обработки
  --only-empty        Только лоты с NULL в поле trim
  --dump-trims=PATH   Записать частоту предложенных тримов в файл
```

### Типичный workflow

```bash
# 1. Посмотреть что изменится (dry-run)
php artisan lots:normalize-encar-taxonomy --source=encar --limit=5000

# 2. Применить
php artisan lots:normalize-encar-taxonomy --source=encar --limit=5000 --apply

# 3. Только пустые тримы
php artisan lots:normalize-encar-taxonomy --only-empty --apply

# 4. Проанализировать нераспознанные тримы
php artisan lots:normalize-encar-taxonomy --dump-trims=/tmp/trims.txt
```

---

## Стадии нормализации

```mermaid
flowchart TD
    START(["Чтение лотов из БД\n(chunks по --chunk)"])

    subgraph S1["Стадия 1 · Снимок состояния"]
        SN1["Первый запуск?\nСохранить raw_data['pre_norm']\n(никогда не перезаписывается)"]
    end

    subgraph S2["Стадия 2 · GradeExtractorService"]
        GE1["Input: model_raw + badge_kr"]
        GE2["6 стадий позитивного матчинга"]
        GE3["Заполнить только NULL-поля:\nmodel, generation, trim,\nfuel, drive_type, body_type,\nengine_volume, seat_count,\ncylinders"]
        GE4["Остаток → taxonomy_anomaly_queue"]
    end

    subgraph S3["Стадия 3 · TaxonomyRuleEngine"]
        TR1["Загрузить активные правила\n(ORDER BY priority ASC)"]
        TR2["Сопоставить с контекстом лота\n(source, make, model, tail, badge)"]
        TR3["Применить действие\n(может ПЕРЕЗАПИСАТЬ непустые поля)"]
        TR4["Залогировать в taxonomy_rule_hits"]
    end

    subgraph S4["Стадия 4 · Персистенция"]
        P1["Сравнить патч с текущими значениями"]
        P2{"Есть\nизменения?"}
        P3["UPDATE lots SET ...\nОбновить raw_data JSON"]
        P4["Пропустить"]
    end

    STATS(["Вывод счётчиков"])

    START --> S1 --> S2 --> S3 --> S4
    GE2 --> GE3
    GE2 --> GE4
    TR2 --> TR3 --> TR4
    P1 --> P2
    P2 -->|"да"| P3 --> STATS
    P2 -->|"нет"| P4 --> STATS
```

---

## Счётчики вывода

```
Processed:      всего лотов обработано
Would update:   лотов с изменениями (dry-run режим)
Updated:        лотов фактически обновлено (--apply)
Anomalies:      новых строк в taxonomy_anomaly_queue

Per-field updates:
  model, generation, trim, fuel, drive_type, body_type,
  engine_volume, seat_count, cylinders, variant, package
```

---

## Пример изменений (до / после)

**До нормализации** (raw_data из парсера):
```
model_raw = "5시리즈 (G30) 520d xDrive M 스포츠"
badge_kr  = "스포츠라인"
trim      = NULL
generation = NULL
fuel      = NULL
drive_type = NULL
```

**После нормализации**:
```
model      = "5시리즈"
generation = "G30"
trim       = "M 스포츠"
fuel       = "diesel"          ← из "520d"
drive_type = "awd"             ← из "xDrive"
```

**Нераспознанный остаток** → `taxonomy_anomaly_queue`:
```
unknown_tail   = "스포츠라인"
make           = "BMW"
seen_count     = 147
status         = "new"
```

---

## Защита данных

| Механизм | Описание |
|----------|----------|
| **pre_norm snapshot** | До первого изменения сохраняет оригинальные значения в `raw_data['pre_norm']`. Никогда не перезаписывается. |
| **Non-destructive** | Стадия 2 (GradeExtractor) заполняет только NULL-поля |
| **Rule override** | Стадия 3 (RuleEngine) может перезаписать — это сделано намеренно для корректировок |
| **Dry-run дефолт** | Без `--apply` команда только показывает что изменится |

---

[← Catalog](./05-catalog.md) · [Index](./index.md) · [Taxonomy →](./07-taxonomy.md)
