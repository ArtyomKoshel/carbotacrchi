#!/bin/sh
set -e

APP_DIR=/var/www/html

if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

if [ -n "$APP_KEY" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" "$APP_DIR/.env"
else
    APP_KEY_VAL=$(grep "^APP_KEY=" "$APP_DIR/.env" | cut -d= -f2)
    if [ -z "$APP_KEY_VAL" ] || [ "$APP_KEY_VAL" = "base64:PLACEHOLDER=" ]; then
        echo "[start] Generating application key..."
        php "$APP_DIR/artisan" key:generate --force
    fi
fi

if [ -n "$APP_ENV" ]; then
    sed -i "s|^APP_ENV=.*|APP_ENV=${APP_ENV}|" "$APP_DIR/.env"
fi
if [ -n "$APP_DEBUG" ]; then
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=${APP_DEBUG}|" "$APP_DIR/.env"
fi
if [ -n "$APP_URL" ]; then
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" "$APP_DIR/.env"
fi
if [ -n "$MINIAPP_URL" ]; then
    sed -i "s|^MINIAPP_URL=.*|MINIAPP_URL=${MINIAPP_URL}|" "$APP_DIR/.env"
fi
if [ -n "$TELEGRAM_BOT_TOKEN" ]; then
    sed -i "s|^TELEGRAM_BOT_TOKEN=.*|TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN}|" "$APP_DIR/.env"
fi
if [ -n "$ADMIN_TOKEN" ]; then
    grep -q "^ADMIN_TOKEN=" "$APP_DIR/.env" \
        && sed -i "s|^ADMIN_TOKEN=.*|ADMIN_TOKEN=${ADMIN_TOKEN}|" "$APP_DIR/.env" \
        || echo "ADMIN_TOKEN=${ADMIN_TOKEN}" >> "$APP_DIR/.env"
fi
if [ -n "$PARSER_LOG_FILE" ]; then
    grep -q "^PARSER_LOG_FILE=" "$APP_DIR/.env" \
        && sed -i "s|^PARSER_LOG_FILE=.*|PARSER_LOG_FILE=${PARSER_LOG_FILE}|" "$APP_DIR/.env" \
        || echo "PARSER_LOG_FILE=${PARSER_LOG_FILE}" >> "$APP_DIR/.env"
fi
if [ -n "$PARSER_SOURCES" ]; then
    grep -q "^PARSER_SOURCES=" "$APP_DIR/.env" \
        && sed -i "s|^PARSER_SOURCES=.*|PARSER_SOURCES=${PARSER_SOURCES}|" "$APP_DIR/.env" \
        || echo "PARSER_SOURCES=${PARSER_SOURCES}" >> "$APP_DIR/.env"
fi
if [ -n "$SESSION_DRIVER" ]; then
    grep -q "^SESSION_DRIVER=" "$APP_DIR/.env" \
        && sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=${SESSION_DRIVER}|" "$APP_DIR/.env" \
        || echo "SESSION_DRIVER=${SESSION_DRIVER}" >> "$APP_DIR/.env"
fi
if [ -n "$FLOPPYDATA_API_KEY" ]; then
    grep -q "^FLOPPYDATA_API_KEY=" "$APP_DIR/.env" \
        && sed -i "s|^FLOPPYDATA_API_KEY=.*|FLOPPYDATA_API_KEY=${FLOPPYDATA_API_KEY}|" "$APP_DIR/.env" \
        || echo "FLOPPYDATA_API_KEY=${FLOPPYDATA_API_KEY}" >> "$APP_DIR/.env"
fi
if [ -n "$REDIS_HOST" ]; then
    grep -q "^REDIS_HOST=" "$APP_DIR/.env" \
        && sed -i "s|^REDIS_HOST=.*|REDIS_HOST=${REDIS_HOST}|" "$APP_DIR/.env" \
        || echo "REDIS_HOST=${REDIS_HOST}" >> "$APP_DIR/.env"
fi
if [ -n "$REDIS_PORT" ]; then
    grep -q "^REDIS_PORT=" "$APP_DIR/.env" \
        && sed -i "s|^REDIS_PORT=.*|REDIS_PORT=${REDIS_PORT}|" "$APP_DIR/.env" \
        || echo "REDIS_PORT=${REDIS_PORT}" >> "$APP_DIR/.env"
fi
if [ -n "$REDIS_PASSWORD" ]; then
    grep -q "^REDIS_PASSWORD=" "$APP_DIR/.env" \
        && sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" "$APP_DIR/.env" \
        || echo "REDIS_PASSWORD=${REDIS_PASSWORD}" >> "$APP_DIR/.env"
fi
if [ -n "$DB_HOST" ]; then
    sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|" "$APP_DIR/.env"
    sed -i "s|^DB_PORT=.*|DB_PORT=${DB_PORT:-3306}|" "$APP_DIR/.env"
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" "$APP_DIR/.env"
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME}|" "$APP_DIR/.env"
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" "$APP_DIR/.env"
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
    php "$APP_DIR/artisan" migrate --force --no-interaction || \
        echo "[start] WARNING: migrate failed, continuing anyway"

    if [ -n "$TEST_TELEGRAM_ID" ]; then
        echo "[start] Seeding demo data for user $TEST_TELEGRAM_ID..."
        php "$APP_DIR/artisan" demo:seed --telegram-id="$TEST_TELEGRAM_ID" || true

        if [ "$DEMO_NOTIFY" = "1" ]; then
            echo "[start] Sending demo notifications..."
            php "$APP_DIR/artisan" demo:notify --telegram-id="$TEST_TELEGRAM_ID" || true
        fi
    fi
fi

echo "[start] Starting nginx + php-fpm via supervisord..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
