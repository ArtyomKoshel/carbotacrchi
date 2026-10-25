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

## Жизненный цикл лотов

### `lots:lifecycle`

Скрывает лоты, которых не было в последнем успешном полном прогоне, а лоты, которых не было
в последних N успешных прогонах, архивирует в `lots_archive` и удаляет вместе с детьми.
Считает прогоны, а не дни. Правила и определение «успешного прогона» описаны в
[04-parser → Жизненный цикл лота](./04-parser.md#жизненный-цикл-лота).

Планировщик запускает команду каждый час в :45 (`routes/console.php`). Пока идёт парсинг
(`parse_jobs.status = running`), запуск пропускается.

```bash
php artisan lots:lifecycle [OPTIONS]

  --dry-run           Ничего не писать: показать прогоны и счётчики по правилам и таблицам
  --source=encar      Источник
  --runs=N            Удалять после N успешных прогонов (дефолт LOTS_PURGE_AFTER_RUNS=5)
  --min-coverage=P    Порог покрытия прогона, % (дефолт LOTS_LIFECYCLE_MIN_COVERAGE=85)
  --batch=2000        Лотов на транзакцию (LOTS_LIFECYCLE_BATCH)
  --pause-ms=200      Пауза между транзакциями (LOTS_LIFECYCLE_PAUSE_MS)
  --orphan-options    Также удалить строки lot_options, чьих лотов уже нет (разовая чистка)
  -v                  Также показать done-прогоны, которые не засчитаны, и причину
```

`--dry-run` печатает две таблицы:
- последние N успешных прогонов и отметки, какой из них даёт границу для delist, а какой для purge;
- счётчики `Rule | Table | Rows`:

```
+---------+---------------------------------+--------+
| Rule    | Table                           | Rows   |
+---------+---------------------------------+--------+
| delist  | lots (is_active 1→0)            | 3      |
| delist  | lot_changes (insert 'delisted') | 3      |
| purge   | lots_archive (insert)           | 5,002  |
| purge   | lots (delete)                   | 5,002  |
| purge   | lot_photos (FK cascade)         | 10,003 |
| purge   | lot_inspections (FK cascade)    | 1      |
| purge   | lot_changes (FK cascade)        | 2      |
| purge   | lot_options (delete, no FK)     | 15,004 |
| purge   | lots kept: in favorites         | 1      |
| orphans | lot_options (delete, lot gone)  | 5      |
| orphans | distinct missing lot_ids        | 2      |
+---------+---------------------------------+--------+
```

В dry-run purge учитывает лоты, которые delist скроет в этом же запуске.
`lot_changes` в строке purge не включает строки `delisted`, которые delist добавит только в реальном запуске.

**Примеры:**
```bash
# Что изменится сейчас (и почему не засчитаны отдельные прогоны)
php artisan lots:lifecycle --dry-run -v

# Разовая чистка осиротевших lot_options: сначала посмотреть, потом выполнить
php artisan lots:lifecycle --dry-run --orphan-options
php artisan lots:lifecycle --orphan-options
```

---

## Уведомления

### `notifications:watch`

Шлёт Telegram-уведомления админам: события задач парсинга, недоступность БД, низкие балансы FloppyData и DeepSeek, долгое отсутствие успешного прогона. Получателей, типы и пороги настраивают в [`/admin/notifications`](./08-api.md#уведомления-adminnotifications). В расписании стоит каждые 5 минут (`routes/console.php`, выполняет `schedule:work` в контейнере Railway).

```bash
php artisan notifications:watch [OPTIONS]

  --dry-run         Печатать сообщения вместо отправки; ничего не пишет (ни в БД, ни в redis)
  --only=LIST       Только эти проверки: jobs,proxy,ai,runs (БД проверяется всегда)
```

**Как работает:**
- Каждое событие отправляется один раз: ключи хранятся в `notification_events`, например `job:42:started`, `job:42:finished`, `balance:proxy:low`. Инциденты (зависшая задача, низкий баланс, …) открываются, повторяются раз в `repeat_hours` и закрываются сообщением о восстановлении. Если доставка не прошла ни одному получателю, событие не помечается и уходит при следующем запуске.
- Первый запуск на новой БД не пересылает уже завершённые задачи, только помечает их.
- БД проверяется с таймаутом 5 с. Если MySQL недоступен, алерт уходит по снимку получателей из redis (`notifications:recipients`; без redis используется файловый кэш). Строки лога копятся в redis и пишутся в БД после восстановления.
- Балансы опрашиваются не чаще раза в 15 минут (`NOTIFICATIONS_BALANCE_CHECK_MINUTES`). FloppyData: `GET /v2/proxy/rotating/balance`, при ошибке `/v1/rotating/balance`, заголовок `X-Api-Key`. DeepSeek: `GET /user/balance` с `AI_API_KEY`, только если `AI_API_URL` указывает на `deepseek.com`.
- Секреты не логируются: токен бота вырезается из текстов ошибок, ошибки API пишутся как путь и HTTP-код.

**Примеры:**
```bash
# Посмотреть, что ушло бы сейчас
php artisan notifications:watch --dry-run

# Только задачи парсинга
php artisan notifications:watch --only=jobs --dry-run

# Только балансы (отправить по-настоящему)
php artisan notifications:watch --only=proxy,ai
```

**Env (необязательные):** `TELEGRAM_API_BASE`, `DEEPSEEK_BASE_URL`, `NOTIFICATIONS_CACHE_STORE` (`redis`, если задан `REDIS_HOST`, иначе `file`), `NOTIFICATIONS_BALANCE_CHECK_MINUTES`.

---

## Сборщик (collector) и приём данных

Сборщик — отдельный сервис (`collector/`, Python). Режим задаётся в ядре: `COLLECTOR_MODE=off|shadow|primary`
(см. [refactor/cutover.md](./refactor/cutover.md)).

### Команды ядра (Laravel)

```bash
php artisan ingest:consume [--batch=500] [--max-batches=0]   # применить записи буфера к lots (по расписанию в primary)
php artisan collector:enqueue [--dry-run]                    # поставить сборщику прогоны по parser_schedules (shadow/primary)
php artisan collector:compare [--job=ID] [--json]            # теневой режим: прогон сборщика против lots старого парсера
php artisan ingest:cutover                                   # один раз при переходе в primary: пропустить теневой буфер
php artisan collector:seed-history [--dry-run]               # один раз: история из lots → collector.lot_history, первый прогон без перекачки всей истории
```

### Команды сборщика

```bash
python -m collector serve     # брать задачи из parse_jobs до SIGTERM (прод); по SIGTERM — interrupted с чекпоинтом
python -m collector migrate   # применить collector/migrations к COLLECTOR_DATABASE_URL
python -m collector replay    # пересобрать все лоты из сырого слоя без запросов к бирже
python -m collector.adapters.encar.golden parser/tests/golden   # сверка с эталоном старого парсера
```

---

## Справочник

Подробно — [13-catalog](./13-catalog.md).

### `catalog:remap`

Пишет эталон (`resources/catalog/reference`) в `ref_*`, перечитывает все варианты Encar правилами
(кроме размеченных вручную) и переставляет лоты (`lots.ref_*`) порциями по PK. Запускать после
изменения эталона или правил; новые лоты связываются сами при приёме.

```bash
php artisan catalog:remap                 # всё заново
php artisan catalog:remap --new-only      # только варианты, которых ещё нет в ref_variants
php artisan catalog:remap --chunk=2000 --pause-ms=50
```

### `catalog:validate`

Только чтение: проверка файлов справочника и годов поколений против лотов. Код выхода 1 при ошибке в файлах.

```bash
php artisan catalog:validate
php artisan catalog:validate --no-data
```

### `catalog:encar-names`

Корейские названия моделей Encar → английские для `resources/catalog/encar/{make}.json` (`names`),
по лотам, где у Encar есть оба и поколение совпадает. Без `--write` только показывает новые.

```bash
php artisan catalog:encar-names
php artisan catalog:encar-names --make=BMW --write
```

### `catalog:coverage`

Только чтение: сколько вариантов и лотов марки правила понимают, что осталось непонятным.

```bash
php artisan catalog:coverage --make=BMW
php artisan catalog:coverage --make=Mercedes-Benz --tree --limit=80
```

---

## Утилиты

### `subscriptions:to-catalog`

Разово записывает id справочника (`refMakeId`, `refModelId`, …) в сохранённые подписки, где марка, модель, поколение
или комплектация названы текстом. Текст читается так же, как при поиске (`App\Catalog\CatalogNames`): старое значение
`trim` вида «530e M 스포츠» становится модификацией 530e + комплектацией M Sport. Подписка с названием, которого нет
в справочнике, после перехода не находит ничего — команда показывает такие строки отдельно.

```bash
php artisan subscriptions:to-catalog          # показать, что изменится
php artisan subscriptions:to-catalog --apply  # записать
```


### `lots:ai-model-en-backfill`

Заполняет `model_en` через AI для лотов с NULL.

```bash
php artisan lots:ai-model-en-backfill \
  --source=encar \
  --limit=200 \
  --apply
```

### `lots:compact-backfill`

Разовое заполнение компактных колонок из старых pivot-таблиц: `lots.options_mask` из `lots.options`,
`lots.photo_*` из `lot_photos`. Идёт чанками по PK, трогает только NULL — можно прерывать и перезапускать,
безопасно при работающем парсере.

```bash
php artisan lots:compact-backfill                 # обе части
php artisan lots:compact-backfill --only=photos   # только фото
php artisan lots:compact-backfill --verify        # сверить с lot_options / lot_photos без записи
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
