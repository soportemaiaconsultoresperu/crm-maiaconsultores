# syntax=docker/dockerfile:2

# CRM Maia Consultores production image.
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        zlib-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        gd \
        zip \
        intl \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

FROM node:20-alpine AS assets

WORKDIR /var/www/html
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM base AS prod

COPY --from=vendor /var/www/html/vendor vendor/
COPY . .
COPY --from=assets /var/www/html/public/build public/build
RUN composer dump-autoload --optimize --classmap-authoritative

RUN chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 775 {} \;

USER www-data

EXPOSE 9000

CMD ["php-fpm"]

FROM caddy:2.8-alpine AS caddy

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --from=prod /var/www/html/public /srv
