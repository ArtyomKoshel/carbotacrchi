# CarBot — Telegram Auction Mini App

Telegram Mini App + Bot for searching cars across Korean auction platforms. A company uses the bot to find cars for clients instead of manually browsing multiple auction sites.

**Sources:** Encar (collector)
**Stack:** Laravel 13 · PHP 8.4 · MySQL 9 · Python 3.13 (collector) · Docker

## Local stack

```bash
make up        # docker compose -f docker/dev/compose.yml up -d --build
make dump      # prod dump into the local database
```

Mini app, admin and API on http://localhost:8100 (`/miniapp/`, `/admin`, `/api/*`), MySQL on `127.0.0.1:3318`.
Everything about it — which checkout it serves, sign-in without Telegram, refreshing the dump — is in
**[docs/local-stack.md](./docs/local-stack.md)**. `make help` lists the other targets.

## Project Structure

```
carbot/
├── laravel/          # Laravel 13 — API + Admin (Filament) + Bot webhook
├── collector/        # Python collector — fetches lots from Encar
├── parser/           # old Python parser, replaced by the collector
├── miniapp/          # Telegram Mini App (static HTML/JS/CSS)
├── docker/           # dev = local stack, railway = prod image parts
├── Dockerfile        # prod web image (Railway)
├── Makefile
└── docs/             # Full technical documentation
```

## Artisan Commands

Locally: `docker compose -f docker/dev/compose.yml exec web php artisan …`.

```bash
# Rebuild the reference catalog after editing resources/catalog
php artisan catalog:remap

# Check it: files, years, and that the filters lose no car
php artisan catalog:validate
php artisan catalog:reconcile
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
