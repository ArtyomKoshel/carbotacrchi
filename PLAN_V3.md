# Carbot V3: Оставшиеся задачи

> Все выполненные задачи перенесены в [PLAN_V3_DONE.md](PLAN_V3_DONE.md).
>
> Этот документ содержит **только pending** работы, отсортированные по приоритету.

---

## Сводка по приоритетам

| Приоритет | Задача | Категория |
|---|---|---|
| **MEDIUM** | M.1 (9.7) Reparse через job pipeline | jobs |
| **MEDIUM** | M.2 (9.10) Admin UI: phase timeline | ui |
| **MEDIUM** | M.3 (9.3) Unified progress display в UI | ui |
| LOW | L.1 (10D) Job log picker dropdown | ui |
| LOW | L.2 (10E) Source filter UI в логах | ui |
| LOW | L.3 (10J) XSS escape в search highlight | security |
| LOW | L.4 (10C) JSON-format логов | ops |
| LOW | L.5 (10B) Единый logging module + scheduler entry-point safety | refactor |
| DEFERRED | D.1 (3.4) ParallelFetcher / NormalizationPipeline shared | refactor |
| DEFERRED | D.2 (4.5) Единый model normalizer | normalization |
| SKIPPED | S.1 (8.2) Virtual inspection fields + pre/post filtering (редко используются) | filters |
| SKIPPED | S.2 (6.2) kb_paper парсер (low ROI) | parser |
| SKIPPED | S.3 (7.5) KBCha производительность (80+ часов на полный прогон) | performance |

---

## MEDIUM priority

### M.1 (9.7) Reparse унификация через job pipeline

**Сейчас**: `reparse_worker.py` отдельный pipeline через `reparse_requests` таблицу — без SSE, без per-job логов, в admin UI только статус запроса без прогресса.

**Цель**: Reparse создаёт `parse_job` с `type='reparse'` и проходит через тот же pipeline что и обычный парсинг.

**Шаги:**
- Добавить enum `type` в `parse_jobs` (full / reparse / refresh)
- `reparse_worker.py` мержится в `job_worker.py` (один dispatcher)
- Reparse получает SSE, per-job logs, checkpoint/resume — бесплатно
- Удалить таблицу `reparse_requests` (миграция)

### M.2 (9.10) Admin UI: phase timeline

Timeline визуализация для job detail:

```
[ Search ✓ ]──[ Enrich ▶ 67% ]──[ Inspect ⋯ ]──[ Delist ⋯ ]
```

С прогрессом каждой фазы (используем уже существующий `PhaseResult`). UI компонент:
- Зелёный = done
- Синий + spinner = running
- Серый = pending

### M.3 (9.3) Unified progress display

Заменить во всех местах admin UI "стр. N" на `phase + phase_progress + total_progress`:

| Сейчас | Цель |
|---|---|
| "стр. 415" | "Enrich: 67% (14,200 / 21,200)" |
| "page 1 of 78" | "Search: 1.3% (page 1/78)" |
| Bar показывает page/total | Bar показывает total_progress |

`ProgressUpdate` уже передаёт это всё — нужно только обновить рендеринг в `job-detail.blade.php` и list view.

---

## LOW priority

### L.1 (10D) Job log picker dropdown

В `/admin/logs` сейчас можно открыть `job-N.log` только дописав `?job=job-N.log` в URL вручную. Добавить:

- Dropdown / sidebar со списком файлов из `logs/jobs/`
- Дата + размер каждого
- Click → загружает лог конкретного job

### L.2 (10E) Source filter UI

`AdminController::logs()` поддерживает `?source=encar`, но в форме нет элемента. Добавить `<select>` (encar / kbcha / all) в toolbar.

### L.3 (10J) XSS escape в search highlight

`job-detail.blade.php` использует `innerHTML` для search highlighting. Если лог содержит `<script>` — потенциальная injection. Использовать `textContent` + DOM-based highlight.

### L.4 (10C) JSON-format логов

ENV `LOG_FORMAT=json` → переключает formatter на:
```json
{"ts":"...","level":"INFO","logger":"job.42","msg":"...","job_id":42}
```

Для Railway/Docker — stdout JSON, для local dev — plain text. Полезно для интеграции с ELK / Loki / Datadog.

### L.5 (10B) Единый logging module

Перенести `_UTC3Formatter`, `_ImportantOnly`, setup в `parser/logging_config.py`:
- Решает дублирование (formatter сейчас в двух местах)
- `scheduler.py` импортирует `ensure_logging_configured()` — entry-point safety
- `_ImportantOnly` заменить на filter по logger name (не string matching `[STAT]`)

---

## Deferred (low priority, дольше окупается)

### D.1 (3.4) ParallelFetcher / NormalizationPipeline shared

Общие модули в `_shared/` для:
- ThreadPool + retry + backoff (сейчас дублируется в encar/kbcha)
- Normalization pipeline: fuel → transmission → body → drive → color → lien/seizure

Польза умеренная — дублирование терпимое. Браться когда будет третий парсер.

### D.2 (4.5) Единый model normalizer

Encar: raw Korean concat (`"BMW 5 시리즈 (G30) 530i M Sport"`)
KBCha: уже parsed (`"5 Series"`)

Long-term задача — нужна модель-маппинг таблица или fuzzy-match. Не критично для текущих фильтров.

---

## Skipped

### S.1 (8.2) Virtual inspection fields + pre/post filtering

Фильтры из inspection-данных нужны редко. Основные фильтры работают по базовым полям CarLot и этого достаточно. Большой рефакторинг (виртуальные агрегаты + split filter engine) не оправдан.

### S.2 (6.2) kb_paper парсер

`checkpaper.iwsp.co.kr` отдаёт inspection HTML с собственной структурой, парсится плохо (`parsed_count: 1`, `parsed_fields: ["cert_no"]` для большинства).

Пропущено: основные данные приходят через carmon / autocafe / carmodoo. ROI низкий.

### S.3 (7.5) KBCha производительность

21,883 лота за 9ч 56мин (1.64 сек/лот). При полном покрытии 176K лотов = 80+ часов.

Требует архитектурного решения (больше параллельности, кэш HTML, инкрементальный режим). Не критично пока работает в фоне через scheduler.

---

## Архитектурные диаграммы

> Полная архитектура, field matrix и mermaid-диаграммы оставлены в [PLAN_V3_DONE.md](PLAN_V3_DONE.md) как историческая справка.
