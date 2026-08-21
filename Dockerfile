# syntax=docker/dockerfile:2

# CRM Maia Consultores — PHP-FPM image (RNF-OPS-001).
#
# Stages:
#   vendor — composer install (prod deps, cached by composer.lock)
#   dev    — dev dependencies + xdebug available (off by default)
#   prod   — final runtime image

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

# --------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

# --------------------------------------------------------------------------
FROM base AS dev

ENV XDEBUG_MODE=off

RUN apk add --no-cache linux-headers $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS

# --------------------------------------------------------------------------
FROM base AS prod

COPY --from=vendor /var/www/html/vendor vendor/

COPY . .
COPY --from=vendor /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump || true

RUN chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 775 {} \;

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
