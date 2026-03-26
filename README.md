# CarBot — Telegram Auction Mini App

A Telegram Mini App for searching cars across 5 auction platforms, built with **Laravel 11** + Docker.

## Supported Sources

| Key       | Platform     | Region |
|-----------|-------------|--------|
| `copart`  | Copart      | 🇺🇸 USA |
| `iai`     | IAAI        | 🇺🇸 USA |
| `manheim` | Manheim     | 🇺🇸 USA |
| `encar`   | Encar       | 🇰🇷 Korea |
| `kbcha`   | KBChacha    | 🇰🇷 Korea |

## Quick Start

```bash
# 1. Copy env file
cp .env.example .env
# Edit .env → set TELEGRAM_BOT_TOKEN

# 2. Start all services
make up
# or: docker compose up -d

# 3. Open Mini App
open http://localhost:8080/miniapp/
```

On first start the PHP container will:
1. Run `composer install`
2. Generate `APP_KEY`
3. Wait for MySQL
4. Run `php artisan migrate`

## Project Structure

```
carbot/
├── docker/
│   ├── nginx/default.conf       # Nginx — serves miniapp + routes PHP
│   └── php/
│       ├── Dockerfile           # PHP 8.2-FPM + Composer
│       ├── entrypoint.sh        # Bootstrap script
│       └── php.ini
├── laravel/                     # Laravel 11 app
│   ├── app/
│   │   ├── AuctionProviders/    # 5 auction source adapters
│   │   ├── Dto/LotDTO.php
│   │   ├── Http/
│   │   │   ├── Controllers/Api/ # Search, Filters, Favorites
│   │   │   ├── Controllers/Bot/ # Webhook
│   │   │   └── Middleware/      # ValidateTelegramAuth
│   │   ├── Models/              # User, Search, Favorite
│   │   ├── Providers/           # AppServiceProvider, AuctionServiceProvider
│   │   └── Services/            # ProviderAggregator, SearchQuery, SearchResult, TelegramBot
│   ├── config/auction.php       # Bot token + data_dir
│   ├── database/migrations/     # users, searches, favorites
│   ├── routes/api.php           # /api/search, /api/filters, /api/favorites
│   ├── routes/web.php           # /bot/webhook
│   └── storage/app/data/        # Mock JSON data (5 files)
├── miniapp/                     # Telegram Mini App (static)
│   ├── index.html
│   ├── css/                     # app.css, cards.css, filters.css
│   ├── js/                      # telegram.js, api.js, filters.js, results.js, app.js
│   └── img/placeholder.svg
├── docker-compose.yml
├── .env.example
└── Makefile
```

## API Endpoints

| Method   | Path                    | Auth   | Description        |
|----------|-------------------------|--------|--------------------|
| `GET`    | `/api/filters`          | —      | Makes, models, sources |
| `POST`   | `/api/search`           | TG     | Search lots        |
| `GET`    | `/api/favorites`        | TG     | List saved lots    |
| `POST`   | `/api/favorites`        | TG     | Save a lot         |
| `DELETE` | `/api/favorites/{id}`   | TG     | Remove a lot       |
| `POST`   | `/bot/webhook`          | —      | Telegram webhook   |
| `GET`    | `/up`                   | —      | Health check       |

## Telegram Setup

1. Create a bot via [@BotFather](https://t.me/BotFather)
2. Set `TELEGRAM_BOT_TOKEN` in `.env`
3. Register webhook:
   ```bash
   curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
        -d "url=https://your-domain.com/bot/webhook"
   ```
4. Set Mini App URL in BotFather: `https://your-domain.com/miniapp/`

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

## Mock Data

In `local` environment, all 5 providers read from `laravel/storage/app/data/mock_*.json`.
To add more test lots, edit those files — no restart required.
