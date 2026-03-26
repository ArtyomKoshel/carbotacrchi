FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    git \
    curl \
    unzip \
    mysql-client \
    netcat-openbsd \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        xml \
        pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

RUN chown -R www-data:www-data /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
