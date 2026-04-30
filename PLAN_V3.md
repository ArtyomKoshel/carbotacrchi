# Carbot V3: Оставшиеся задачи

> Все выполненные задачи перенесены в [PLAN_V3_DONE.md](PLAN_V3_DONE.md).
>
> Этот документ содержит **только pending** работы, отсортированные по приоритету.

---

## Сводка по приоритетам

| Приоритет | Задача | Категория | Статус |
|---|---|---|---|
| ~~MEDIUM~~ | ~~M.1 (9.7) Reparse через job pipeline~~ | jobs | ✅ done |
| ~~MEDIUM~~ | ~~M.2 (9.10) Admin UI: phase timeline~~ | ui | ✅ done |
| ~~MEDIUM~~ | ~~M.3 (9.3) Unified progress display в UI~~ | ui | ✅ done |
| ~~LOW~~ | ~~L.1 (10D) Job log picker dropdown~~ | ui | ✅ done |
| ~~LOW~~ | ~~L.2 (10E) Source filter UI в логах~~ | ui | ✅ done |
| ~~LOW~~ | ~~L.3 (10J) XSS escape в search highlight~~ | security | ✅ done |
| ~~LOW~~ | ~~L.4 (10C) JSON-format логов~~ | ops | ✅ done |
| ~~LOW~~ | ~~L.5 (10B) Единый logging module + scheduler entry-point safety~~ | refactor | ✅ done |
| DEFERRED | D.1 (3.4) ParallelFetcher / NormalizationPipeline shared | refactor | ⏸ deferred |
| DEFERRED | D.2 (4.5) Единый model normalizer | normalization | ⏸ deferred |
| SKIPPED | S.1 (8.2) Virtual inspection fields + pre/post filtering | filters | 🚫 skipped |
| SKIPPED | S.2 (6.2) kb_paper парсер (low ROI) | parser | 🚫 skipped |
| SKIPPED | S.3 (7.5) KBCha производительность (80+ часов на полный прогон) | performance | 🚫 skipped |

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
