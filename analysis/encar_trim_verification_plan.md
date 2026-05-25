# Encar Taxonomy Normalization: STG → PROD Playbook

Цель: проверить на STG полноту и корректность нормализации полей `model`, `generation`, `trim`, `fuel`, `drive_type`, `engine_volume` для `source='encar'`, а затем перенести проверенные правила на PROD.

## Scope / Guardrails

- Только `source='encar'` — фильтровать везде.
- KBCha в этот цикл не включать.

## 1) STG: Подготовка

```bash
php artisan migrate --force
php artisan parser:export-fields
php artisan db:seed --class=Database\\Seeders\\BotFilterSettingsSeeder --force
```

Проверить что таблицы `taxonomy_rules`, `taxonomy_terms`, `taxonomy_anomaly_queue` существуют.

---

## 2) STG: Seed правил

```sql
-- Запустить на STG:
analysis/encar_rules_seed.sql
```

---

## 3) STG: Snapshot «до»

```sql
SET @run_id = 'stg_YYYYMMDD';
SET @phase  = 'before';
-- запустить блок CAPTURE PHASE SNAPSHOT из encar_trim_verification_before_after.sql
```

Метрики для KPI:
- `empty_trim_pct`
- `empty_fuel_pct`, `empty_drive_type_pct`, `empty_engine_volume_pct`
- `generation_empty`

---

## 4) STG: Dry-run нормализации

```bash
php artisan lots:normalize-encar-taxonomy --source=encar --chunk=1000
```

Проверить в выводе:
- `Would update`, `Trim updates`, `Tech spec updates`
- `Top unknown tail candidates` — ручной sanity-check
- Sample changes — убедиться нет ложных trim

---

## 5) STG: Apply

```bash
php artisan lots:normalize-encar-taxonomy --source=encar --apply --chunk=1000
```

---

## 6) STG: Snapshot «после» + DIFF

```sql
SET @phase = 'after';
-- запустить блок CAPTURE PHASE SNAPSHOT, затем DIFF из encar_trim_verification_before_after.sql
```

Критерии готовности:
- `empty_trim_pct` снизился на ≥10 pp.
- `trim_became_empty` = 0 (нет ложных затираний).
- `empty_fuel_pct` / `empty_drive_type_pct` снизились (tech spec работают).
- `badge_detail_but_no_trim` близко к 0.

---

## 7) STG: Итерация правил (если не готово)

Если нужны дополнительные правила:

```bash
php artisan taxonomy:bootstrap-rules --source=encar --min-seen=5 --min-confidence=0.80 --actions=set_trim
# если ок:
php artisan taxonomy:bootstrap-rules --source=encar --min-seen=5 --min-confidence=0.80 --actions=set_trim --apply
php artisan lots:normalize-encar-taxonomy --source=encar --apply --chunk=1000
# снова snapshot с новым run_id или новым @phase='after_rules2'
```

---

## 8) STG → PROD: Экспорт правил

```sql
-- Запустить на STG:
analysis/encar_prod_rules_export.sql
-- Блок #3 (sanity check): проверить счётчики rules/terms
-- Блоки #1 и #2: скопировать сгенерированные INSERT-строки
```

---

## 9) PROD: Применение

```sql
-- Запустить на PROD сначала taxonomy_terms, затем taxonomy_rules (из вывода шага 8)
```

```bash
# Dry-run на PROD (обязательно!):
php artisan lots:normalize-encar-taxonomy --source=encar --chunk=1000

# Apply:
php artisan lots:normalize-encar-taxonomy --source=encar --apply --chunk=1000
```

---

## 10) PROD: Верификация

```sql
SET @run_id = 'prod_YYYYMMDD';
SET @phase  = 'after';
-- CAPTURE PHASE SNAPSHOT из encar_trim_verification_before_after.sql
```

---

## Артефакты

| Файл | Назначение |
|------|-----------|
| `encar_rules_seed.sql` | Единый seed — все правила (unknown_tail + model_contains) |
| `encar_trim_verification_before_after.sql` | Before/after snapshot + diff |
| `encar_prod_rules_export.sql` | Экспорт правил STG → PROD |
