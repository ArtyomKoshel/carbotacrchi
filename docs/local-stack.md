# Локальный стек

Один стек `carbot-dev` (файлы — `docker/dev/`) — единственный локальный Docker для CarBot: мини-апп, админка
и API на данных прода плюс все базы для тестов. База и redis живут постоянно; под новую фичу пересоздаётся
только контейнер `web` (секунды), без пересборки образа и без новой базы. Других стеков CarBot не поднимать.

| Сервис | Адрес | Что |
|---|---|---|
| `web` | http://localhost:8100 | nginx + php-fpm, как прод-образ: `/miniapp/`, `/admin`, `/api/*` на одном порту |
| `mysql` | `127.0.0.1:3318` | MySQL 9.1, все базы (ниже) |
| `redis` | только внутри стека | кэш |
| `collector` | — | сборщик, необязательный (профиль `collector`) |

## Базы

| База | Логин | Что |
|---|---|---|
| `carbot` | root / `root-local` | рабочая: дамп прода, справочник ветки `feat/reference-catalog` разложен |
| `prodbak` | root / `root-local` | полный бэкап прода до выкатки справочника (только смотреть) |
| `collector` | `collector` / `collector-local` | схема сборщика |
| `carbot_test`, `carbot_test_release` | carbot / `carbot` | PHPUnit: `phpunit.xml`, `phpunit.release.xml`, `.env.testing` |
| `collector_test`, `collector_test_release` | carbot / `carbot` | тесты сборщика и парсера |
| `carbot_admin` | carbot / `carbot` | старая локальная база worktree `filament-admin` |

Порт `3318` выбран потому, что он уже зашит в конфиги тестов и в CI (`.github/workflows/ci.yml`).

## Запуск

Docker Desktop должен быть запущен. Из корня репозитория:

```bash
docker compose -f docker/dev/compose.yml up -d --build     # или: make up
```

Первый старт `web` ставит composer- и npm-зависимости и собирает тему админки (несколько минут),
дальше старт занимает секунды. Миграции кода, который обслуживает `web`, выполняются при каждом старте.
Контейнеры с `restart: unless-stopped`: после перезагрузки Docker стек поднимается сам.

Первые запросы после старта медленные (прогрев opcache на томе Windows): «Обзор» админки ~20 с,
дальше API отвечает за 0,15–0,35 с.

Войти без Telegram (только при `APP_ENV=local`, он задан в стеке):
- мини-апп: http://localhost:8100/miniapp/ → кнопка «Войти локально» (пользователь «Local tester», `100000001`);
- админка: http://localhost:8100/dev/admin-login → `/admin` как супер-админ `local`.

Вход без Telegram есть в коде ветки `feat/reference-catalog` (`laravel/routes/dev.php`). В `main` его пока нет.

## Какой код обслуживается

`docker/dev/.env` (не в git, шаблон — `docker/dev/.env.example`):

```
CARBOT_SRC=../../.claude/worktrees/release   # путь от docker/dev или абсолютный
DEV_SCHEDULER=false
```

- Без `.env` (или `CARBOT_SRC=../..`) обслуживается тот checkout, где лежит `compose.yml`.
  Сейчас стек запущен из main (`D:\work\carbot`) и обслуживает worktree `release` (ветка `feat/reference-catalog`).
- Запускать стек из одного места: имя проекта `carbot-dev` общее, `up` из другого checkout пересоздаст те же контейнеры.
- Код подключается томом, правки видны сразу, без перезапуска.
- Сменить код: поправить `CARBOT_SRC` и выполнить `docker compose -f docker/dev/compose.yml up -d web`.
  Если у кода другой `composer.lock` или `package-lock.json`, зависимости переставятся сами при старте.
  Миграции не откатываются: код со старыми миграциями на базе с новыми может не работать.
- `DEV_SCHEDULER=true` включает `schedule:work` (`ingest:consume`, `lots:lifecycle`, уведомления), как на проде.

Что стек не трогает в checkout:
- `laravel/.env` не переписывается (в отличие от `docker/railway/start.sh`). Переменные из `compose.yml` важнее его:
  база, redis, `APP_ENV` — всегда стековые. Остальное (`AI_API_KEY` и т.п.) берётся из `laravel/.env` checkout'а;
- `vendor/`, `node_modules/`, `bootstrap/cache/` лежат в томах стека, а не в checkout.

Пишет в checkout только `laravel/storage/` и опубликованные ассеты `public/js|css|fonts` (они в `.gitignore`).
`TELEGRAM_BOT_TOKEN` в стеке пустой, чтобы локальный стенд не писал людям от настоящего бота.

Код можно запускать и с хоста (`php artisan`, PHPUnit, встроенный сервер для `e2e/`): `laravel/.env` checkout'а
указывает на `127.0.0.1:3318`, база `carbot`, root / `root-local`.

## С нуля

На новой машине или после `down -v`:

1. `cp docker/dev/.env.example docker/dev/.env`, при необходимости поправить `CARBOT_SRC`;
2. `docker compose -f docker/dev/compose.yml up -d --build` — MySQL создаёт пустые базы и пользователей (`docker/dev/init.sql`),
   `web` ставит зависимости и выполняет миграции;
3. `bash docker/dev/load-dump.sh prod` — данные прода в `carbot` и `catalog:remap`;
4. открыть http://localhost:8100/miniapp/ → «Войти локально».

Справочник (`ref_*`, `lots.ref_*_id`) всегда можно пересобрать из файлов `laravel/resources/catalog/` кода:
`docker compose -f docker/dev/compose.yml exec web php artisan catalog:remap` (~1,5 мин).

## Обновить данные

```bash
bash docker/dev/load-dump.sh prod              # свежий дамп прода с Railway, только чтение; нужен `railway login`
bash docker/dev/load-dump.sh file dump.sql.gz  # из файла (.sql или .sql.gz)
```

Скрипт:
1. останавливает `web` и пересоздаёт базу `carbot` (остальные базы не трогает);
2. заливает дамп. Для `prod`: `mysqldump --single-transaction` (прод не блокируется и не меняется),
   поток идёт внутри контейнера `carbot-dev-mysql-1`, без файлов на диске, креды прода есть только в окружении `railway run`;
3. поднимает `web` — миграции кода из `CARBOT_SRC` доводят схему дампа;
4. если в коде есть `catalog:remap`, раскладывает лоты по справочнику (~2 мин).

Сохранить текущую базу в файл, чтобы потом вернуться к ней:

```bash
docker exec carbot-dev-mysql-1 sh -c "mysqldump -uroot -proot-local --single-transaction carbot | gzip" > carbot.sql.gz
```

## Команды

```bash
docker compose -f docker/dev/compose.yml exec web php artisan <команда>
docker compose -f docker/dev/compose.yml exec web php artisan tinker
docker compose -f docker/dev/compose.yml logs -f web
docker compose -f docker/dev/compose.yml exec web npm run build:panel   # тема админки после правок Filament-шаблонов
docker compose -f docker/dev/compose.yml restart web                    # перезапуск с миграциями
```

`make help` — то же короче.

Сборщик (нужны `FLOPPY_*` из Railway; гранты на `parse_jobs`/`parse_filters` `web` выдаёт при старте):

```bash
railway run -s carbotacrchi -- docker compose -f docker/dev/compose.yml --profile collector up -d --build collector
```

Остановить, не теряя данных: `docker compose -f docker/dev/compose.yml stop`.
Удалить вместе со всеми базами: `docker compose -f docker/dev/compose.yml down -v`.

## История (2026-10-25)

Свели в этот стек всё, что было:
- `carbot-e2e-mysql-1` (:3320): `carbot_dump` → `carbot`, `prodbak` → `prodbak`. В `carbot_dump` не было журналов
  `lot_changes`, `filter_skip_log`, `notification_events`, `notification_log` (без них падает админка) — взяты из `prodbak`;
- `carbot-filament-mysql` (:3318): тестовые базы и `carbot_admin` скопированы как есть, пользователь `carbot` тот же.

Удалены как устаревшие: стек корневого `docker-compose.yml` (MySQL 8 с базой 264 лотов, meilisearch — Scout
из кода убран, старый parser — заменён сборщиком, php, nginx), стенд `docker/local-e2e` (прод-образ с запечённым
кодом), `carbot-storage-mysql` (копия `lots` от 19.10 для замеров хранения), их тома и образы. Файлы
`docker-compose.yml`, `docker/php/Dockerfile`, `entrypoint.sh`, `docker/nginx/default.conf`, `docker/local-e2e/` удалены из main.
