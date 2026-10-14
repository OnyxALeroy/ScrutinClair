FROM php:8.4-fpm-alpine

# Installation des dépendances système et de l'extension PDO Postgres
RUN apk add --no-cache libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Installation de Composer à l'intérieur du conteneur PHP
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/symfony
