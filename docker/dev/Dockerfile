# Runtime of the prod web image (root Dockerfile) without baked-in code: the checkout is mounted.
# Build context: the repo root.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git curl unzip \
    libpng-dev oniguruma-dev libxml2-dev \
    icu-dev libzip-dev \
    nginx supervisor nodejs npm \
    && docker-php-ext-install pdo pdo_mysql mbstring xml pcntl intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/nginx/railway.conf /etc/nginx/http.d/default.conf
COPY docker/dev/nginx-dev.conf /etc/nginx/http.d/00-dev.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/dev/php-dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
COPY docker/dev/supervisord.conf /etc/supervisord.conf
COPY docker/dev/start.sh /usr/local/bin/start.sh
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

ENV COMPOSER_HOME=/composer
WORKDIR /var/www/html
EXPOSE 8080
CMD ["/usr/local/bin/start.sh"]
