# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.3
ARG FRANKENPHP_VERSION=1

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS php-base

RUN install-php-extensions \
        bcmath \
        curl \
        dom \
        exif \
        fileinfo \
        gd \
        iconv \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        posix \
        redis \
        simplexml \
        xml \
        xmlreader \
        xmlwriter \
        zip

FROM php-base AS composer-build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install --no-install-recommends --yes git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . .

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM node:24-bookworm-slim AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

# Tailwind scans application views and the Vite inputs include files from vendor/.
COPY --from=composer-build /app /app

RUN npm run build

FROM php-base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer-build --chown=www-data:www-data /app /app
COPY --from=frontend-build --chown=www-data:www-data /app/public/build /app/public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/99-dnd.ini
COPY docker/entrypoint.sh /usr/local/bin/dnd-entrypoint

RUN setcap -r /usr/local/bin/frankenphp \
    && chmod +x /usr/local/bin/dnd-entrypoint \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/cache/laravel-excel \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /config/caddy \
        /data/caddy \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data \
        bootstrap/cache \
        storage \
        /config/caddy \
        /data/caddy

USER www-data

EXPOSE 8080

STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/local/bin/dnd-entrypoint"]
CMD ["web"]
