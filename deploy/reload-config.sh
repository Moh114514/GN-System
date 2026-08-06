#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

if [[ "${1:-}" != 'uat' || "${2:-}" != '' ]]; then
    printf 'Usage: %s uat\n' "$0" >&2
    exit 2
fi

readonly UAT_ROOT='/srv/gn-system'
readonly REPOSITORY_DIR='/srv/gn-system/repository'
readonly ENV_FILE="$REPOSITORY_DIR/.env.uat"
readonly COMPOSE_FILE="$REPOSITORY_DIR/compose.production.yaml"
readonly COMPOSE_PROJECT='gn-system-uat'
readonly UAT_DATABASE='gn_system_uat'

current_dir="$(pwd -P)"
if [[ "$current_dir" == '/srv/gn-system/production' || "$current_dir" == '/srv/gn-system/production/'* ]]; then
    printf 'Production directories are forbidden.\n' >&2
    exit 1
fi
if [[ "$current_dir" != "$REPOSITORY_DIR" || ! -f "$ENV_FILE" || ! -f "$COMPOSE_FILE" ]]; then
    printf 'Run from the UAT repository with its environment and Compose files.\n' >&2
    exit 1
fi
if [[ "$(stat -c '%a' "$UAT_ROOT/$ENV_FILE")" != '600' ]]; then
    printf '%s must have mode 0600.\n' "$ENV_FILE" >&2
    exit 1
fi

env_value() {
    local key="$1"
    awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "$ENV_FILE"
}

required_vars=(COMPOSE_PROJECT_NAME PRODUCTION_ENV_FILE APP_ENV APP_KEY APP_URL APP_IMAGE WEB_IMAGE RELEASE_TAG DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD REDIS_HOST REDIS_PORT REDIS_PASSWORD PRIVATE_DATA_PATH BACKUP_DATA_PATH TLS_CERT_PATH TLS_KEY_PATH BACKUP_ARCHIVE_PASSWORD)
for key in "${required_vars[@]}"; do
    if [[ -z "$(env_value "$key")" ]]; then
        printf 'Required variable %s is empty in %s.\n' "$key" "$ENV_FILE" >&2
        exit 1
    fi
done

project_value="$(env_value COMPOSE_PROJECT_NAME)"
app_env="$(env_value APP_ENV)"
database_value="$(env_value DB_DATABASE)"
app_url="$(env_value APP_URL)"
if [[ "$project_value" != "$COMPOSE_PROJECT" || "$app_env" != 'production' || "$database_value" != "$UAT_DATABASE" || "${app_url,,}" != *uat* ]]; then
    printf 'UAT project, database, or APP_URL protection check failed.\n' >&2
    exit 1
fi
if [[ "$database_value" == 'gn_system' ]]; then
    printf 'The production database gn_system is forbidden.\n' >&2
    exit 1
fi

compose=(docker compose --project-name "$COMPOSE_PROJECT" --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
"${compose[@]}" config --quiet
"${compose[@]}" up -d --force-recreate --no-deps app queue scheduler
"${compose[@]}" exec -T app php artisan optimize:clear
"${compose[@]}" exec -T app php artisan config:cache

actual_database="$("${compose[@]}" exec -T postgres sh -ec 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "select current_database()"' | tr -d '\r' | tail -n 1)"
if [[ "$actual_database" != "$UAT_DATABASE" ]]; then
    printf 'PostgreSQL current_database() is %s, expected %s.\n' "$actual_database" "$UAT_DATABASE" >&2
    exit 1
fi
"${compose[@]}" exec -T redis sh -ec 'test "$(redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping)" = PONG'

base_url="${app_url%/}"
tls_cert_path="$(env_value TLS_CERT_PATH)"
if [[ ! -f "$tls_cert_path" ]]; then
    printf 'TLS_CERT_PATH must point to an existing UAT certificate.\n' >&2
    exit 1
fi
"${compose[@]}" exec -T app php artisan app:queue-heartbeat || true
"${compose[@]}" exec -T app php artisan app:scheduler-heartbeat || true
health_deadline=$((SECONDS + 180))
health_ok=0
while (( SECONDS < health_deadline )); do
    if curl --fail --silent --show-error --max-time 20 --cacert "$tls_cert_path" "$base_url/up" >/dev/null \
        && curl --fail --silent --show-error --max-time 20 --cacert "$tls_cert_path" "$base_url/health" >/dev/null \
        && curl --fail --silent --show-error --max-time 20 --cacert "$tls_cert_path" "$base_url/health/operations" >/dev/null; then
        health_ok=1
        break
    fi
    sleep 5
done
if [[ "$health_ok" -ne 1 ]]; then
    printf 'UAT health checks did not pass within 180 seconds.\n' >&2
    exit 1
fi

printf 'Configuration reloaded for UAT.\n'
printf 'APP_URL=%s\n' "$app_url"
printf 'COMPOSE_PROJECT_NAME=%s\n' "$project_value"
printf 'DB_DATABASE=%s\n' "$database_value"
printf 'DB_CONNECTION=%s\n' "$(env_value DB_CONNECTION)"
printf 'REDIS_HOST=%s\n' "$(env_value REDIS_HOST)"
printf 'Health checks: /up /health /health/operations\n'
