# syntax=docker/dockerfile:1
# Demo Laravel app that hosts the local packages/leadscaptain package.

############################################
# Base: PHP 8.4 FPM + required extensions
############################################
FROM php:8.4-fpm-alpine AS base

WORKDIR /var/www/html

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Writable Composer home for any user (www-data has no home directory)
ENV COMPOSER_HOME=/tmp/composer

############################################
# Dev: code is bind-mounted, coverage driver included
############################################
FROM base AS dev

ARG UID=1000
ARG GID=1000

# Match www-data to the host user so bind-mounted files stay writable (WSL/Linux)
RUN install-php-extensions pcov \
    && apk add --no-cache shadow \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data \
    && echo "opcache.validate_timestamps=1" > /usr/local/etc/php/conf.d/zz-dev.ini

############################################
# Prod: code baked into the image
############################################
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

# Local packages are copied before install because composer.json
# references them through a "path" repository.
COPY composer.json composer.lock ./
COPY packages/ packages/
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && echo "opcache.validate_timestamps=0" > /usr/local/etc/php/conf.d/zz-prod.ini

USER www-data
