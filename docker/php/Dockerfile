FROM php:8.4-fpm-alpine

# Ajout de libzip-dev et de l'extension zip
RUN apk add --no-cache libpq-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/symfony
