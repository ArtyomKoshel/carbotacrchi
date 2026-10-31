# Alpine's prebuilt PHP packages instead of the official php image: that one
# compiles intl, mbstring, zip, pcntl and pdo_mysql from source on every deploy
# (~110 s of a 2 min 47 s Railway build, without a layer cache hit). Same Alpine and PHP as the
# official image, same modules (php -m).
FROM alpine:3.24 AS base

# php/php-fpm keep their usual names; no php.ini, like the official image (PHP
# defaults plus conf.d); www-data with the official image's uid.
RUN apk add --no-cache \
    git curl unzip \
    mysql-client netcat-openbsd \
    nginx supervisor \
    python3 py3-pip \
    php84 php84-fpm php84-opcache \
    php84-ctype php84-curl php84-dom php84-fileinfo php84-iconv php84-intl php84-mbstring \
    php84-openssl php84-pcntl php84-pdo_mysql php84-pdo_sqlite php84-phar php84-posix php84-session \
    php84-simplexml php84-sodium php84-sqlite3 php84-tokenizer php84-xml php84-xmlreader \
    php84-xmlwriter php84-zip \
    && ln -s /usr/bin/php84 /usr/bin/php \
    && ln -s /usr/sbin/php-fpm84 /usr/sbin/php-fpm \
    && rm /etc/php84/php.ini \
    && adduser -u 82 -D -S -G www-data -h /var/www www-data

# PHP2Shell (CVE-2026-109618, CVE-2026-109619): fixed in 8.4.27.
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' \
    || { echo "PHP $(php -r 'echo PHP_VERSION;') is older than 8.4.27 (PHP2Shell)"; exit 1; }

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies before the code: a code change keeps this layer when the cache is there.
COPY laravel/composer.json laravel/composer.lock /var/www/html/
RUN composer install --no-interaction --no-plugins --no-scripts --no-autoloader --prefer-dist --no-dev

COPY laravel/ /var/www/html/
COPY miniapp/ /var/www/html/miniapp/
COPY analysis/ /var/www/analysis/
# CarLot contract the core validates collector records against (config/ingest.php)
COPY contracts/ /var/www/contracts/

RUN composer dump-autoload --no-interaction --no-plugins --no-dev --optimize

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY parser/ /app/parser/
RUN pip3 install --no-cache-dir --break-system-packages -r /app/parser/requirements.txt \
    && chown -R www-data:www-data /app

COPY docker/nginx/railway.conf /etc/nginx/http.d/default.conf
COPY docker/php/php.ini /etc/php84/conf.d/zz-custom.ini
COPY docker/php/fpm-pool.conf /etc/php84/php-fpm.d/zz-docker.conf
COPY docker/railway/supervisord.conf /etc/supervisord.conf
COPY docker/railway/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]

# Theme of the /panel admin (Filament + Tailwind v4). It is compiled against the
# Filament CSS in vendor/, so it is built after composer install.
FROM node:22-alpine AS panel-assets
WORKDIR /app
COPY laravel/package.json laravel/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY laravel/resources/css ./resources/css
COPY laravel/resources/views/filament ./resources/views/filament
COPY laravel/app/Filament ./app/Filament
COPY laravel/app/Livewire ./app/Livewire
COPY laravel/resources/views/livewire ./resources/views/livewire
COPY laravel/scripts ./scripts
COPY --from=base /var/www/html/vendor/filament ./vendor/filament
RUN npm run build:panel

FROM base
COPY --from=panel-assets /app/public/css/filament/panel /var/www/html/public/css/filament/panel
COPY --from=panel-assets /app/public/fonts/onest /var/www/html/public/fonts/onest
