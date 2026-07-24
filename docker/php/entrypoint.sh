#!/bin/sh
set -eu

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    php artisan key:generate --force --no-interaction
fi

# Compose loads APP_KEY before the first-run key is written to .env. Export the
# persisted value so the current PHP-FPM, queue, and scheduler processes use it.
APP_KEY="$(sed -n 's/^APP_KEY=//p' .env | head -n 1)"
export APP_KEY

mkdir -p \
    storage/app/backups \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ "${APP_ENV:-local}" = "local" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
