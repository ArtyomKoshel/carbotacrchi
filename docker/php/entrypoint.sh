#!/bin/sh
set -e

APP_DIR=/var/www/html

# ── 1. Create .env if missing ────────────────────────────────────────────────
if [ ! -f "$APP_DIR/.env" ]; then
    echo "[entrypoint] Creating .env from .env.example..."
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

# ── 2. Inject runtime env vars into .env ────────────────────────────────────
sed -i "s|^APP_ENV=.*|APP_ENV=${APP_ENV:-local}|"                    "$APP_DIR/.env"
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=${APP_DEBUG:-true}|"               "$APP_DIR/.env"
sed -i "s|^APP_URL=.*|APP_URL=${APP_URL:-http://localhost:8080}|"    "$APP_DIR/.env"
sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST:-mysql}|"                    "$APP_DIR/.env"
sed -i "s|^DB_PORT=.*|DB_PORT=${DB_PORT:-3306}|"                     "$APP_DIR/.env"
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE:-auction_bot}|"  "$APP_DIR/.env"
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME:-carbot}|"       "$APP_DIR/.env"
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD:-carbot_pass}|"  "$APP_DIR/.env"
sed -i "s|^TELEGRAM_BOT_TOKEN=.*|TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN:-}|" "$APP_DIR/.env"
sed -i "s|^MINIAPP_URL=.*|MINIAPP_URL=${MINIAPP_URL:-}|" "$APP_DIR/.env"

# ── 3. Composer install ───────────────────────────────────────────────────────
if [ ! -d "$APP_DIR/vendor" ]; then
    echo "[entrypoint] Installing Composer dependencies..."
    composer install --working-dir="$APP_DIR" --no-interaction --no-plugins --prefer-dist
fi

# ── 4. Generate app key if empty ─────────────────────────────────────────────
APP_KEY_VAL=$(grep "^APP_KEY=" "$APP_DIR/.env" | cut -d= -f2)
if [ -z "$APP_KEY_VAL" ] || [ "$APP_KEY_VAL" = "base64:PLACEHOLDER=" ]; then
    echo "[entrypoint] Generating application key..."
    php "$APP_DIR/artisan" key:generate --force
fi

# ── 5. Wait for MySQL ─────────────────────────────────────────────────────────
echo "[entrypoint] Waiting for MySQL at ${DB_HOST:-mysql}..."
MAX=30
COUNT=0
until nc -z "${DB_HOST:-mysql}" "${DB_PORT:-3306}" 2>/dev/null; do
    COUNT=$((COUNT + 1))
    if [ "$COUNT" -ge "$MAX" ]; then
        echo "[entrypoint] MySQL not ready after $MAX attempts, aborting."
        exit 1
    fi
    echo "[entrypoint] Retry $COUNT/$MAX..."
    sleep 2
done
echo "[entrypoint] MySQL is ready."

# ── 6. Run migrations ────────────────────────────────────────────────────────
echo "[entrypoint] Running migrations..."
php "$APP_DIR/artisan" migrate --force --no-interaction

# ── 7. Clear config cache ─────────────────────────────────────────────────────
php "$APP_DIR/artisan" config:clear 2>/dev/null || true

echo "[entrypoint] Bootstrap complete. Starting PHP-FPM..."
exec "$@"
