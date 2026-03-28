FROM php:8.2-fpm-alpine AS base

RUN apk add --no-cache \
    git curl unzip \
    mysql-client netcat-openbsd \
    libpng-dev oniguruma-dev libxml2-dev \
    nginx supervisor \
    python3 py3-pip \
    && docker-php-ext-install pdo pdo_mysql mbstring xml pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY laravel/ /var/www/html/
COPY miniapp/ /var/www/html/miniapp/

RUN composer install --no-interaction --no-plugins --prefer-dist --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY parser/ /app/parser/
RUN pip3 install --no-cache-dir --break-system-packages -r /app/parser/requirements.txt

COPY docker/nginx/railway.conf /etc/nginx/http.d/default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/railway/supervisord.conf /etc/supervisord.conf
COPY docker/railway/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]
