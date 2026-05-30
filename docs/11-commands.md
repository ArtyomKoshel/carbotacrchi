# 11 · Artisan Commands

> [← Filters](./10-filters.md) · [Index](./index.md)

---

## Каталог

### `catalog:import`

Импортирует сырой JSON-дамп Encar в таблицы `catalog_*`.

```bash
php artisan catalog:import --file=PATH [--fresh] [--apply]

  --file=PATH    Путь к JSON-файлу с данными каталога
  --fresh        Очистить catalog_models, catalog_grades перед импортом
  --apply        Записать в БД (дефолт: dry-run)
```

**Пример:**
```bash
php artisan catalog:import \
  --file=../analysis/encar_taxonomy_raw.json \
  --fresh --apply
```

---

## Нормализация

### `lots:normalize-encar-taxonomy`

Главная команда нормализации. Запускает [GradeExtractorService](./05-catalog.md) + [TaxonomyRuleEngine](./07-taxonomy.md).

```bash
php artisan lots:normalize-encar-taxonomy [OPTIONS]

  --apply           Сохранить изменения (дефолт: dry-run)
  --source=encar    Фильтр по источнику
  --limit=N         Максимум лотов (0 = все)
  --chunk=2000      Размер батча
  --random          Случайный порядок
  --only-empty      Только лоты с NULL в trim
  --dump-trims=PATH Записать предложенные тримы в файл
```

**Примеры:**
```bash
# Dry-run: посмотреть что изменится
php artisan lots:normalize-encar-taxonomy --source=encar --limit=5000

# Применить
php artisan lots:normalize-encar-taxonomy --source=encar --limit=5000 --apply

# Только лоты без трима
php artisan lots:normalize-encar-taxonomy --only-empty --apply

# Выгрузить аномальные тримы для анализа
php artisan lots:normalize-encar-taxonomy --dump-trims=/tmp/trims.txt
```

---

## Taxonomy

### `taxonomy:classify-anomalies`

Классифицирует строки из `taxonomy_anomaly_queue` через Claude API.

```bash
php artisan taxonomy:classify-anomalies [OPTIONS]

  --source=encar    Источник
  --batch=20        Записей на один API-вызов
  --limit=200       Максимум записей
  --dry-run         Показать ответ AI без сохранения
```

**Пример:**
```bash
php artisan taxonomy:classify-anomalies --source=encar --batch=20 --limit=200
```

---

### `taxonomy:ai-classify-patterns`

Классифицирует уникальные паттерны `(make, model_kr_raw, badge_kr)` и создаёт правила.

```bash
php artisan taxonomy:ai-classify-patterns [OPTIONS]

  --source=encar    Источник
  --make=BMW        Фильтр по марке (опционально)
  --limit=50        Уникальных паттернов
  --confidence=70   Порог для авто-создания правила (0–100)
  --dry-run         Не сохранять
  --sleep=4         Секунды между API-вызовами (лимит TPM)
```

**Пример:**
```bash
# Посмотреть предложения без записи
php artisan taxonomy:ai-classify-patterns --source=encar --limit=50 --dry-run

# Создать правила с confidence >= 70%
php artisan taxonomy:ai-classify-patterns --source=encar --limit=50 --confidence=70
```

---

### `taxonomy:bootstrap-rules`

Создаёт `taxonomy_rules` из аномалий с высоким confidence.

```bash
php artisan taxonomy:bootstrap-rules [OPTIONS]

  --source=encar          Источник
  --min-seen=5            Минимум seen_count
  --min-confidence=0.80   Минимальный confidence (0.0–1.0)
  --actions=set_trim      CSV допустимых actions
  --apply                 Создать правила в БД
```

**Пример:**
```bash
# Dry-run: посмотреть какие правила будут созданы
php artisan taxonomy:bootstrap-rules \
  --source=encar --min-seen=5 --min-confidence=0.80

# Применить
php artisan taxonomy:bootstrap-rules \
  --source=encar --min-seen=5 --min-confidence=0.80 --apply
```

---

### `taxonomy:ingest-anomalies`

Просмотр аномалий по статусу.

```bash
php artisan taxonomy:ingest-anomalies --source=encar --status=new
```

---

## Каталог опций

### `options:import-encar`

Загружает коды опций и их корейские названия из фильтр-API Encar и делает upsert в `encar_option_catalog`.

```bash
php artisan options:import-encar [OPTIONS]

  --dry-run    Показать что будет импортировано без записи в БД
  --no-flush   Не сбрасывать Redis-кэш после импорта
```

**Пример:**
```bash
# Посмотреть что загрузится
php artisan options:import-encar --dry-run

# Импортировать
php artisan options:import-encar
```

---

### `options:name-by-ai`

AI-именование и перевод опций в `encar_option_catalog`. Работает в двух режимах:
- Если `name_kr` пустой — генерирует корейское имя из знания об Encar + RU/EN переводы
- Если `name_kr` заполнен — переводит в `name_ru` и `name_en`

```bash
php artisan options:name-by-ai [OPTIONS]

  --batch=20   Записей на один AI-вызов (дефолт: 20)
  --all        Перезаписать все записи (включая уже переведённые)
  --dry-run    Показать ответ AI без сохранения
```

**Пример:**
```bash
# Обработать только незаполненные
php artisan options:name-by-ai

# Проверить что получится (без записи)
php artisan options:name-by-ai --dry-run

# Перегенерировать все при обновлении prompt
php artisan options:name-by-ai --all --dry-run
php artisan options:name-by-ai --all
```

**Полный цикл первоначального заполнения:**
```bash
php artisan options:import-encar           # 1. Собрать коды из lots.options в БД
php artisan options:name-by-ai             # 2. AI генерирует name_kr + name_ru + name_en
# Вручную — проверить и поправить иконки/категории через Admin или SQL
```

---

## Утилиты

### `lots:ai-model-en-backfill`

Заполняет `model_en` через AI для лотов с NULL.

```bash
php artisan lots:ai-model-en-backfill \
  --source=encar \
  --limit=200 \
  --apply
```

### `lots:compute-field-coverage`

Вычисляет процент заполненности каждого поля. Пишет в `field_coverage_stats`.

```bash
php artisan lots:compute-field-coverage --source=encar
```

### `export:fields-schema`

Экспортирует метаданные полей из Python в `storage/app/fields.json` и `storage/app/field_mappings.json`.  
Запускать после изменений в `parser/fields/registry.py` или `parser/parsers/_shared/field_mappings.py`.

```bash
php artisan export:fields-schema
```

### `taxonomy:classify-anomalies` (AI classify)

```bash
php artisan taxonomy:classify-anomalies --source=encar --batch=20
```

---

## Типичный полный цикл после парсинга

```bash
# 1. Запустить парсер (Python)
docker compose exec parser python main.py --once

# 2. Нормализовать (dry-run сначала)
php artisan lots:normalize-encar-taxonomy --source=encar --limit=10000

# 3. Применить нормализацию
php artisan lots:normalize-encar-taxonomy --source=encar --limit=10000 --apply

# 4. Классифицировать аномалии через AI
php artisan taxonomy:classify-anomalies --source=encar --limit=200

# 5. Создать правила из уверенных предложений
php artisan taxonomy:bootstrap-rules --source=encar --min-seen=3 --min-confidence=0.75 --apply

# 6. Повторно нормализовать с новыми правилами
php artisan lots:normalize-encar-taxonomy --source=encar --limit=10000 --apply

# 7. Статистика покрытия полей
php artisan lots:compute-field-coverage --source=encar
```

---

[← Filters](./10-filters.md) · [Index](./index.md)
