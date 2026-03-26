#!/bin/sh
set -e

APP_DIR=/var/www/html

if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

APP_KEY_VAL=$(grep "^APP_KEY=" "$APP_DIR/.env" | cut -d= -f2)
if [ -z "$APP_KEY_VAL" ] || [ "$APP_KEY_VAL" = "base64:PLACEHOLDER=" ]; then
    echo "[start] Generating application key..."
    php "$APP_DIR/artisan" key:generate --force
fi

echo "[start] Clearing config cache..."
php "$APP_DIR/artisan" config:clear 2>/dev/null || true

if [ -n "$DB_HOST" ]; then
    echo "[start] Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
    MAX=30
    COUNT=0
    until nc -z "${DB_HOST}" "${DB_PORT:-3306}" 2>/dev/null; do
        COUNT=$((COUNT + 1))
        if [ "$COUNT" -ge "$MAX" ]; then
            echo "[start] MySQL not ready after $MAX attempts, continuing anyway..."
            break
        fi
        sleep 2
    done

    echo "[start] Running migrations..."
    php "$APP_DIR/artisan" migrate --force --no-interaction

    if [ -n "$TEST_TELEGRAM_ID" ]; then
        echo "[start] Seeding demo data for user $TEST_TELEGRAM_ID..."
        php "$APP_DIR/artisan" demo:seed --telegram-id="$TEST_TELEGRAM_ID" || true
    fi
fi

echo "[start] Starting nginx + php-fpm via supervisord..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
