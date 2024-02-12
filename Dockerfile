FROM composer:latest AS composer

FROM php:8.3-alpine

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache \
    postgresql-dev \
    && docker-php-ext-install pdo_pgsql

COPY . /app
WORKDIR /app

RUN composer install -o -a --apcu-autoloader --no-dev

CMD ["php", "./index.php"]
