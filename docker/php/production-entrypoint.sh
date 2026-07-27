#!/bin/sh
set -eu

required_variables="
APP_KEY
APP_URL
DB_HOST
DB_DATABASE
DB_USERNAME
DB_PASSWORD
REDIS_HOST
REDIS_PASSWORD
BACKUP_ARCHIVE_PASSWORD
"

for variable_name in $required_variables; do
    if [ -z "$(printenv "$variable_name" 2>/dev/null || true)" ]; then
        echo "Required production environment variable is missing: $variable_name" >&2
        exit 1
    fi
done

if [ "${APP_ENV:-}" != production ] \
    || [ "${APP_DEBUG:-}" != false ] \
    || [ "${SESSION_ENCRYPT:-}" != true ] \
    || [ "${SESSION_SECURE_COOKIE:-}" != true ]
then
    echo "Production requires APP_ENV=production, APP_DEBUG=false, and encrypted secure sessions." >&2
    exit 1
fi

case "${APP_URL}" in
    https://*) ;;
    *)
        echo "APP_URL must use HTTPS in production." >&2
        exit 1
        ;;
esac

if [ -z "${SENTRY_LARAVEL_DSN:-}" ] \
    && { [ -z "${MAIL_HOST:-}" ] || [ -z "${BACKUP_NOTIFICATION_EMAIL:-}" ]; }
then
    echo "Configure Sentry or SMTP plus BACKUP_NOTIFICATION_EMAIL before production startup." >&2
    exit 1
fi

for writable_path in \
    storage/app/private \
    storage/app/backups \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    mkdir -p "$writable_path"

    if [ ! -w "$writable_path" ]; then
        echo "Production path is not writable by www-data: $writable_path" >&2
        exit 1
    fi
done

php artisan optimize --no-interaction

exec "$@"
