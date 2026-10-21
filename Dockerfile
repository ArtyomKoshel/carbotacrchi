FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    git curl unzip \
    mysql-client netcat-openbsd \
    libpng-dev oniguruma-dev libxml2-dev \
    icu-dev libzip-dev \
    nginx supervisor \
    python3 py3-pip \
    && docker-php-ext-install pdo pdo_mysql mbstring xml pcntl intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY laravel/ /var/www/html/
COPY miniapp/ /var/www/html/miniapp/
COPY analysis/ /var/www/analysis/

RUN composer install --no-interaction --no-plugins --prefer-dist --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY parser/ /app/parser/
RUN pip3 install --no-cache-dir --break-system-packages -r /app/parser/requirements.txt \
    && chown -R www-data:www-data /app

COPY docker/nginx/railway.conf /etc/nginx/http.d/default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
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
