# Encar Trim Verification Plan (Staging)

Цель: тщательно проверить, что после загрузки свежего прод-дампа в stg цепочка `parser -> taxonomy -> lots.trim` корректно заполняет `trim` для `source='encar'`.

## 0) Scope / Guardrails

- Проверка строго `encar-only`.
- Во всех SQL и artisan-командах указывать/фильтровать `source='encar'`.
- KBCha в этот цикл не включать.

## 1) Подготовка + точка отката

1. Сделать backup текущего stg.
2. Импортировать свежий прод-дамп (или дамп только таблицы `lots`).
3. Прогнать миграции:
   - `php artisan migrate --force`
4. Убедиться, что нужные таблицы есть:
   - `taxonomy_rules`
   - `taxonomy_terms`
   - `taxonomy_anomaly_queue`

---

## 2) Seed/Schema readiness для новых фильтров (важное дополнение)

### Почему это важно

После импорта только `lots` в stg часто «плывут» настройки UI/бот-фильтров (`bot_filter_settings`), особенно для новых полей:

- `trim`
- `flood_history`
- `listed_at`
- `first_reg_date`
- `lot_url`
- `location`

### Что запускать

1. Экспортировать актуальную схему полей parser в `storage/app/fields.json`:
   - `php artisan parser:export-fields`
2. Пересидировать **только** настройки фильтров:
   - `php artisan db:seed --class=Database\\Seeders\\BotFilterSettingsSeeder --force`

> Важно: не запускать общий `php artisan db:seed` на stg после дампа, потому что `DatabaseSeeder` добавляет демо-данные подписок/избранного.

### SQL-check после seed

```sql
SELECT field_name, field_label, enabled, display_in_card, tolerance_type, tolerance_value
FROM bot_filter_settings
WHERE field_name IN ('trim','flood_history','listed_at','first_reg_date','lot_url','location')
ORDER BY FIELD(field_name,'trim','flood_history','listed_at','first_reg_date','lot_url','location');
```

---

## 3) Baseline «до»

Использовать `analysis/encar_trim_verification_before_after.sql`:

1. выставить:
   - `@run_id='stg_YYYYMMDD_HHMM'`
   - `@phase='before'`
2. выполнить блок `CAPTURE PHASE SNAPSHOT`.

Он зафиксирует:
- core-метрики (`empty_trim`, `empty_trim_pct`, `badge_detail_but_no_trim`, т.д.)
- taxonomy readiness (`taxonomy_terms`, `taxonomy_rules`)
- top-50 моделей с пустым `trim`
- row-level snapshot (`id/model/generation/trim`) для дальнейшего diff.

---

## 4) Dry-run backfill (без записи)

1. Сэмпл:
   - `php artisan lots:normalize-encar-taxonomy --source=encar --limit=20000 --chunk=1000`
2. Полный dry-run:
   - `php artisan lots:normalize-encar-taxonomy --source=encar --chunk=1000`

Фиксировать:
- `Would update`
- `Trim updates`
- `Top unknown tail candidates`
- sample changes (ручной sanity-check).

---

## 5) Apply + capture «после»

1. Применить backfill:
   - `php artisan lots:normalize-encar-taxonomy --source=encar --apply --chunk=1000`
2. В SQL-файле поставить:
   - `@phase='after'`
3. Выполнить тот же блок `CAPTURE PHASE SNAPSHOT`.

---

## 6) Проверка parser write-path (fresh data)

1. Запустить parser only Encar (ограниченный прогон 1–2 марок).
2. Выполнить секцию `RECENT PARSER WRITE-PATH CHECKS` в SQL-файле.

Критичный KPI:
- `badge_detail_but_no_trim_recent` должен быть близок к 0.

---

## 7) Контур «аномалии -> правила -> повторный проход»

1. `php artisan taxonomy:ingest-anomalies --source=encar --max=50000`
2. Dry-run bootstrap:
   - `php artisan taxonomy:bootstrap-rules --source=encar --min-seen=5 --min-confidence=0.80 --actions=set_trim`
3. Если адекватно — apply:
   - `php artisan taxonomy:bootstrap-rules --source=encar --min-seen=5 --min-confidence=0.80 --actions=set_trim --apply`
4. Повторить:
   - `php artisan lots:normalize-encar-taxonomy --source=encar --apply --chunk=1000`
5. Снова снять `@phase='after'` snapshot (можно с новым `run_id`, например `..._rules2`).

---

## 8) Критерии «готово / не готово»

Считаем успешным, если:

- `empty_trim_pct` снизился заметно и не только в 1–2 моделях;
- `badge_detail_but_no_trim_recent` ~ 0;
- row-level diff не показывает системно ложные `trim`;
- очередь `taxonomy_anomaly_queue` становится управляемее (новые хвосты конвертируются в rules).

---

## 9) Артефакты

- План: `analysis/encar_trim_verification_plan.md`
- SQL (единый before/after): `analysis/encar_trim_verification_before_after.sql`
