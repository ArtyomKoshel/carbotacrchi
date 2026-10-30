# CarBot collector

Сервис-сборщик: забирает лоты с площадок (сейчас Encar) и передаёт ядру записи CarLot v1 через буфер
`collector.ingest_lots`. Архитектура и решения — [docs/adr](../docs/adr/), план — [docs/refactor](../docs/refactor/).

Для che168 реализован отдельный API-сборщик с файловым результатом, CNY, ротацией прокси
и возобновлением. Он пока не подключён к производственному ingest: [запуск и ограничения](CHE168.md).

```
src/collector/
  domain/carlot.py      контракт CarLot v1 (pydantic) → contracts/carlot.v1.schema.json
  selection.py          правила отбора (parse_filters), чистые, с id правил
  http.py               асинхронный HTTP: повторы, прокси, исходы Ok | Missing | Failed
  adapters/encar/       api, mapping (перенос старого разбора), pipeline, sweep, sample, replay, golden
  storage.py            MySQL: миграции, буфер, история, сырой слой, очередь задач
  runner.py service.py  прогон задачи, процесс сервиса (SIGTERM → чекпоинт)
migrations/*.sql        схема collector (владелец — сборщик)
```

Разработка (нужен [uv](https://docs.astral.sh/uv/)):

```bash
uv sync
uv run ruff check . && uv run ruff format --check . && uv run mypy
uv run pytest                      # тесты с MySQL берут COLLECTOR_TEST_DB_URL, иначе пропускаются
uv run python -m collector.contracts write   # после изменения contracts
```

Encar не отвечает из-за пределов Кореи: разработка идёт на записанном трафике (кассеты) и фейке
`tests/fakes.py`; запись с прода (`parser/tools/golden.py record`) не коммитится — в ней госномера и VIN.
