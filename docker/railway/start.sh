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
if [ -n "$TELEGRAM_WEBHOOK_SECRET" ]; then
    grep -q "^TELEGRAM_WEBHOOK_SECRET=" "$APP_DIR/.env" \
        && sed -i "s|^TELEGRAM_WEBHOOK_SECRET=.*|TELEGRAM_WEBHOOK_SECRET=${TELEGRAM_WEBHOOK_SECRET}|" "$APP_DIR/.env" \
        || echo "TELEGRAM_WEBHOOK_SECRET=${TELEGRAM_WEBHOOK_SECRET}" >> "$APP_DIR/.env"
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

# Set PARSER_DIR to Railway mount point
grep -q "^PARSER_DIR=" "$APP_DIR/.env" \
    && sed -i "s|^PARSER_DIR=.*|PARSER_DIR=/app/parser|" "$APP_DIR/.env" \
    || echo "PARSER_DIR=/app/parser" >> "$APP_DIR/.env"
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

echo "[start] Preparing log & storage directories..."
mkdir -p /app/logs
mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/storage/framework/cache" "$APP_DIR/storage/framework/sessions" "$APP_DIR/storage/framework/views" "$APP_DIR/bootstrap/cache"
touch "$APP_DIR/storage/logs/laravel.log"
chown -R www-data:www-data /app/logs "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# Read-only mode: disable parser before supervisord starts
if [ "$APP_READONLY" = "true" ]; then
    echo "[start] READ-ONLY mode: disabling parser process..."
    sed -i '/\[program:parser\]/,/^\[/{s/autostart=true/autostart=false/}' /etc/supervisord.conf
fi

# Start web server
echo "[start] Starting nginx + php-fpm via supervisord..."
/usr/bin/supervisord -c /etc/supervisord.conf &
SUPER_PID=$!
sleep 2  # give php-fpm a moment to bind

# Keep container alive — wait on supervisord
wait $SUPER_PID
