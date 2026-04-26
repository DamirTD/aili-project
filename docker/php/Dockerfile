FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libpq-dev \
    oniguruma-dev \
    postgresql-client \
    unzip \
    zip

RUN docker-php-ext-install \
    intl \
    mbstring \
    pdo \
    pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
