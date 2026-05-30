# CarBot — Telegram Auction Mini App

Telegram Mini App + Bot for searching cars across Korean auction platforms. A company uses the bot to find cars for clients instead of manually browsing multiple auction sites.

**Sources:** Encar (API) · KBChacha (scraper)
**Stack:** Laravel 11 · PHP 8.2 · MySQL 8 · Python 3.11 · Docker

## Quick Start

```bash
cp .env.example .env           # Edit: TELEGRAM_BOT_TOKEN, DB_*, AI_API_KEY
make up                        # Start all containers
```

On first start the PHP container runs `composer install`, generates `APP_KEY`, waits for MySQL, and runs `php artisan migrate`.

Open Mini App: `http://localhost:8080/miniapp/`

## Project Structure

```
carbot/
├── laravel/          # Laravel 11 — API + Admin + Bot webhook
├── parser/           # Python parser — fetches lots from auctions
├── miniapp/          # Telegram Mini App (static HTML/JS/CSS)
├── docker/           # Nginx + PHP-FPM Dockerfiles
├── docker-compose.yml
├── Makefile
└── docs/             # Full technical documentation
```

## Make Commands

```
make up        Start all containers
make down      Stop containers
make build     Rebuild images (no cache)
make restart   Restart PHP + Nginx
make logs      Tail logs
make shell     Shell into PHP container
make migrate   Run migrations
make fresh     Drop + re-migrate all tables
make status    Container status
```

## Artisan Commands

```bash
# Run parser once (Python)
docker compose exec parser python main.py --once

# Normalize taxonomy for Encar lots (dry-run)
php artisan lots:normalize-encar-taxonomy --limit=1000

# Apply normalization
php artisan lots:normalize-encar-taxonomy --limit=1000 --apply

# Bootstrap taxonomy rules from anomalies
php artisan taxonomy:bootstrap-rules --source=encar --min-seen=5 --apply

# AI-classify unrecognized tails
php artisan taxonomy:classify-anomalies --source=encar --batch=20 --limit=200

# Import catalog seed data
php artisan catalog:import --file=../analysis/encar_taxonomy_raw.json --fresh --apply
```

## Telegram Setup

1. Create bot via [@BotFather](https://t.me/BotFather)
2. Set `TELEGRAM_BOT_TOKEN` in `.env`
3. Register webhook:
   ```bash
   curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
        -d "url=https://your-domain.com/bot/webhook"
   ```
4. Set Mini App URL in BotFather

## Deployment

Deployed on Railway. Migrations run on Railway — **never run `migrate:fresh` locally against production DB.**

See **[docs/](./docs/index.md)** for full technical documentation.
