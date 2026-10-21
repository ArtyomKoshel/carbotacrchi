# Локальный стенд: веб + сборщик

Оба прод-образа (корневой `Dockerfile` и `collector/Dockerfile`) на одной MySQL 9.1, в режиме
`COLLECTOR_MODE=primary`: сборщик берёт задачи из `carbot.parse_jobs` и пишет в `collector.ingest_lots`,
ядро (`ingest:consume` в `schedule:work`) применяет буфер к `lots`. У сборщика те же права, что на проде
([cutover.md, шаг 1](../../docs/refactor/cutover.md#1-база-и-пользователь-сборщика-один-раз)).

```bash
docker compose -f docker/local-e2e/compose.yml up -d --build mysql redis web
# права сборщика на схему ядра: таблицы появляются после миграций веба
docker compose -f docker/local-e2e/compose.yml exec -T mysql mysql -uroot -proot-local < docker/local-e2e/grants.sql
# прокси из переменных Railway, без вывода на экран
railway run -s carbotacrchi -- docker compose -f docker/local-e2e/compose.yml up -d --build collector
```

Веб: http://localhost:8090 (`/admin`, `/api/filters`, `POST /api/search`). MySQL: `127.0.0.1:3320`, root / `root-local`.

Небольшие задачи — тем же кодом, что и админка:

```bash
docker compose -f docker/local-e2e/compose.yml exec web php artisan tinker --execute='(new \App\Services\ParseRuns)->launch("encar", "test");'
docker compose -f docker/local-e2e/compose.yml exec web php artisan tinker --execute='\App\Models\ParseJob::create(["source"=>"encar","type"=>"full","status"=>"pending","filters"=>["maker"=>"람보르기니"]]);'
```

Что проверено 21.10.2026 (реальный Encar через прокси, ~1 600 лотов):

| Проверка | Итог |
|---|---|
| Задача → сборщик → буфер → `lots`, `lot_inspections`, `filter_skip_log` | 100 записей → 89 лотов + 11 отклонено правилами, 0 отказов контракта |
| Повтор того же прогона (210 лотов марки) | история 0 скачано / 122 из хранилища, трафик прокси вдвое меньше, 0 строк `lot_changes` |
| Перепарсинг из админки (`LotReparseQueue`) | 2/2 обновлено |
| SIGTERM посреди прогона | страница дорабатывает, `interrupted` с курсором; продолжение с следующей страницы, без повторов |
| Процесс убит (SIGKILL) | задача остаётся `running`, через `COLLECTOR_STALE_MINUTES` забирается обратно и доходит до конца без повторов |
| Задача из админки в `primary` | с тегом `engine=collector`; `lots:lifecycle` видит её как идущий прогон |
| `/up`, `/admin/login`, `/api/filters`, `POST /api/search` | 200, 40–110 мс |

Остановить и удалить: `docker compose -f docker/local-e2e/compose.yml down -v`.
