#!/bin/sh
# Never writes laravel/.env: settings come from the container environment, which Laravel
# prefers over .env. vendor, node_modules and bootstrap/cache are volumes, so the host
# checkout only gets storage/ files and the gitignored published assets in public/.
set -e
cd /var/www/html

stamp() { md5sum "$1" | cut -d' ' -f1; }

if [ "$(cat vendor/.lock-md5 2>/dev/null)" != "$(stamp composer.lock)" ]; then
    echo "[dev] composer install (composer.lock changed)"
    composer install --no-interaction --prefer-dist --optimize-autoloader
    stamp composer.lock > vendor/.lock-md5
fi

if [ "$(cat node_modules/.lock-md5 2>/dev/null)" != "$(stamp package-lock.json)" ]; then
    echo "[dev] npm ci (package-lock.json changed)"
    npm ci --no-audit --no-fund
    stamp package-lock.json > node_modules/.lock-md5
    rm -f public/css/filament/panel/theme.css
fi

if [ ! -f public/css/filament/panel/theme.css ]; then
    echo "[dev] building the admin panel theme"
    npm run build:panel
fi

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

php artisan optimize:clear >/dev/null
php artisan migrate --force --no-interaction
# PDO, not the alpine mariadb client: that one cannot do MySQL 9 caching_sha2_password
php -r '(new PDO("mysql:host=".getenv("DB_HOST"), "root", getenv("MYSQL_ROOT_PASSWORD")))->exec(file_get_contents("/grants.sql"));' \
    || echo "[dev] collector grants failed"

export DEV_SCHEDULER="${DEV_SCHEDULER:-false}"
echo "[dev] code: ${CARBOT_SRC_LABEL:-?} · scheduler: $DEV_SCHEDULER"
exec /usr/bin/supervisord -c /etc/supervisord.conf
