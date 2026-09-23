# syntax=docker/dockerfile:1
# Standalone test image for the leadscaptain package (no host app needed).
#   docker build -t leadscaptain-package .
#   docker run --rm leadscaptain-package
FROM php:8.4-cli-alpine

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        bcmath \
        intl \
        pcntl \
        pcov \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -g "${GID}" app \
    && adduser -D -u "${UID}" -G app app \
    && mkdir -p /package \
    && chown app:app /package

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /package
USER app

# Dependencies first for layer caching
COPY --chown=app:app composer.json ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY --chown=app:app . .
RUN composer dump-autoload

CMD ["vendor/bin/phpunit"]
