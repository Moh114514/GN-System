FROM postgres:16-bookworm AS postgres-client

FROM debian:bookworm-slim AS cjk-font

RUN apt-get update \
    && apt-get install -y --no-install-recommends fonts-arphic-gbsn00lp fonts-unfonts-core python3-fonttools \
    && python3 -c "from fontTools.merge import Merger; from fontTools.ttLib import TTFont; from fontTools.ttLib.scaleUpem import scale_upem; paths = ['/usr/share/fonts/truetype/arphic-gbsn00lp/gbsn00lp.ttf', '/usr/share/fonts/truetype/unfonts-core/UnBatang.ttf']; fonts = [TTFont(path) for path in paths]; target_upem = fonts[0]['head'].unitsPerEm; [scale_upem(font, target_upem) for font in fonts[1:]]; normalized = ['/tmp/gn-cjk-chinese.ttf', '/tmp/gn-cjk-korean.ttf']; [font.save(path) for font, path in zip(fonts, normalized)]; font = Merger().merge(normalized); cmap = font.getBestCmap(); assert all(ord(char) in cmap for char in '简体中文한글₩123,456GN-System'); assert 'glyf' in font and 'CFF ' not in font; font.save('/tmp/GNSystemCJK.ttf')" \
    && mkdir -p /usr/local/share/fonts/gn-system \
    && cp /tmp/GNSystemCJK.ttf /usr/local/share/fonts/gn-system/GNSystemCJK.ttf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

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
        poppler-utils \
        unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=postgres-client /usr/lib/postgresql/16/bin/pg_dump /usr/local/bin/pg_dump
COPY --from=postgres-client /usr/lib/postgresql/16/bin/pg_restore /usr/local/bin/pg_restore
COPY --from=cjk-font /usr/local/share/fonts/gn-system/GNSystemCJK.ttf /usr/local/share/fonts/gn-system/GNSystemCJK.ttf

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

FROM php-base AS composer-production

WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
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

COPY docker/nginx/production.conf /etc/nginx/templates/default.conf.template
COPY --from=frontend-production /app/public /var/www/html/public

RUN rm -f /var/www/html/public/hot /var/www/html/public/fonts-manifest.dev.json
