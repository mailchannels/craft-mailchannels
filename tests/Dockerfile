ARG PHP_VERSION=8.3
FROM composer:2.9 AS composer
FROM php:${PHP_VERSION}-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev libpng-dev libxml2-dev git unzip \
    && docker-php-ext-install bcmath intl mbstring zip gd pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
