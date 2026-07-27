FROM postgres:16-bookworm AS postgres-client

FROM php:8.3-fpm-bookworm AS php-base

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get -o Acquire::Retries=5 update \
    && apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
        curl \
        git \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=postgres-client /usr/lib/postgresql/16/bin/pg_dump /usr/local/bin/pg_dump
COPY --from=postgres-client /usr/lib/postgresql/16/bin/pg_restore /usr/local/bin/pg_restore

RUN groupmod -o -g "${APP_GID}" www-data \
    && usermod -o -u "${APP_UID}" -g www-data www-data

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/gn-system.ini
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint

FROM php-base AS development

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .

RUN composer install --no-interaction --prefer-dist --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM composer:2 AS composer-production

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts \
    --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM node:22-alpine AS frontend-production

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
COPY --from=composer-production /app/vendor ./vendor
RUN npm run build

FROM php-base AS production

COPY --from=composer-production --chown=www-data:www-data /app /var/www/html
COPY --from=frontend-production --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY docker/php/php.production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/php/production-entrypoint.sh /usr/local/bin/production-entrypoint

RUN chmod +x /usr/local/bin/production-entrypoint \
    && rm -f .env* public/hot public/fonts-manifest.dev.json \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["production-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web-production

COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend-production /app/public /var/www/html/public

RUN rm -f /var/www/html/public/hot /var/www/html/public/fonts-manifest.dev.json
