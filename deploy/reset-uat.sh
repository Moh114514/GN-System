#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

readonly UAT_ROOT='/srv/gn-system'
readonly REPOSITORY_DIR='/srv/gn-system/repository'
readonly ENV_FILE="$REPOSITORY_DIR/.env.uat"
readonly COMPOSE_FILE="$REPOSITORY_DIR/compose.production.yaml"
readonly COMPOSE_PROJECT='gn-system-uat'
readonly UAT_DATABASE='gn_system_uat'
readonly CONFIRMATION='RESET gn_system_uat'

operator_value="${SUDO_USER:-${USER:-unknown}}"
if [[ -z "$operator_value" ]]; then
    printf 'An operator identifier is required.\n' >&2
    exit 1
fi

scope='business-data'
if [[ "${1:-}" == '--full' ]]; then
    scope='full'
elif [[ "${1:-}" == '--business-data' || -z "${1:-}" ]]; then
    scope='business-data'
else
    printf 'Usage: %s [--business-data|--full]\n' "$0" >&2
    exit 2
fi

current_dir="$(pwd -P)"
if [[ "$current_dir" == '/srv/gn-system/production' || "$current_dir" == '/srv/gn-system/production/'* ]]; then
    printf 'Production directories are forbidden.\n' >&2
    exit 1
fi
if [[ "$current_dir" != "$REPOSITORY_DIR" ]]; then
    printf 'Run this script from %s.\n' "$REPOSITORY_DIR" >&2
    exit 1
fi
if [[ ! -d "$UAT_ROOT" || ! -d "$REPOSITORY_DIR" || ! -f "$ENV_FILE" || ! -f "$COMPOSE_FILE" ]]; then
    printf 'UAT repository, environment file, or Compose file is missing.\n' >&2
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

project_value="$(env_value COMPOSE_PROJECT_NAME)"
app_env="$(env_value APP_ENV)"
database_value="$(env_value DB_DATABASE)"
app_url="$(env_value APP_URL)"
if [[ "$project_value" != "$COMPOSE_PROJECT" || "$app_env" != 'production' || "$database_value" != "$UAT_DATABASE" ]]; then
    printf 'The UAT Compose project or database does not match the protected values.\n' >&2
    exit 1
fi
if [[ "$database_value" == 'gn_system' ]]; then
    printf 'The production database gn_system is forbidden.\n' >&2
    exit 1
fi
if [[ "${app_url,,}" != *uat* ]]; then
    printf 'APP_URL must identify UAT.\n' >&2
    exit 1
fi
compose=(docker compose --project-name "$COMPOSE_PROJECT" --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
restore_services_on_failure() {
    local status=$?
    if [[ "$status" -ne 0 ]]; then
        printf 'Reset failed; attempting to restore UAT services.\n' >&2
        "${compose[@]}" up -d --remove-orphans >/dev/null 2>&1 || true
    fi
    exit "$status"
}
trap restore_services_on_failure EXIT

"${compose[@]}" config --quiet
if ! "${compose[@]}" ps --status running postgres --services | grep -qx 'postgres'; then
    printf 'The UAT PostgreSQL container must already be running.\n' >&2
    exit 1
fi

actual_database="$("${compose[@]}" exec -T postgres sh -ec 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc "select current_database()"' 2>/dev/null | tr -d '\r' | tail -n 1)"
if [[ "$actual_database" != "$UAT_DATABASE" ]]; then
    printf 'PostgreSQL current_database() is %s, expected %s.\n' "$actual_database" "$UAT_DATABASE" >&2
    exit 1
fi

read -r -p "Type $CONFIRMATION to continue: " typed_confirmation
if [[ "$typed_confirmation" != "$CONFIRMATION" ]]; then
    printf 'Reset cancelled.\n' >&2
    exit 1
fi

report_dir="$UAT_ROOT/releases/uat-reset-reports"
mkdir -p "$report_dir"
report_file="$report_dir/$(date -u +%Y%m%dT%H%M%SZ)-$scope.txt"
exec > >(tee "$report_file") 2>&1

started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'UAT reset started: scope=%s at %s\n' "$scope" "$started_at"
printf 'Repository=%s ComposeProject=%s Database=%s\n' "$REPOSITORY_DIR" "$COMPOSE_PROJECT" "$UAT_DATABASE"
printf 'Operator=%s\n' "$operator_value"

backup_command=("${compose[@]}" run --rm --no-deps app php artisan backup:run --only-db --disable-notifications)
if [[ "$scope" == 'full' ]]; then
    backup_command=("${compose[@]}" run --rm --no-deps app php artisan backup:run --disable-notifications)
fi
"${backup_command[@]}"

"${compose[@]}" stop queue scheduler

if [[ "$scope" == 'business-data' ]]; then
    "${compose[@]}" exec -T app php artisan app:reset-uat-data --business-data --confirm="$CONFIRMATION" --operator="$operator_value" --no-interaction
else
    "${compose[@]}" stop web app
    "${compose[@]}" exec -T postgres sh -ec '
        db="$POSTGRES_DB"
        test "$db" = gn_system_uat
        psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres \
            -c "select pg_terminate_backend(pid) from pg_stat_activity where datname = '\''$db'\'' and pid <> pg_backend_pid();" \
            -c "drop database \"$db\";" \
            -c "create database \"$db\" owner \"$POSTGRES_USER\";"
    '
    "${compose[@]}" up -d postgres redis
    "${compose[@]}" run --rm --no-deps app php artisan migrate --force
    "${compose[@]}" run --rm --no-deps app php artisan app:reset-uat-data --business-data --confirm="$CONFIRMATION" --operator="$operator_value" --no-interaction
    printf 'Create the replacement administrator now.\n'
    "${compose[@]}" run --rm --no-deps app php artisan app:create-admin
fi

redis_password="$(env_value REDIS_PASSWORD)"
if [[ -z "$redis_password" ]]; then
    printf 'REDIS_PASSWORD must be set before clearing UAT Redis.\n' >&2
    exit 1
fi
"${compose[@]}" exec -T redis sh -ec 'redis-cli -a "$REDIS_PASSWORD" --no-auth-warning FLUSHALL'
"${compose[@]}" up -d --force-recreate --remove-orphans
"${compose[@]}" exec -T app php artisan optimize:clear

base_url="${app_url%/}"
tls_cert_path="$(env_value TLS_CERT_PATH)"
if [[ -z "$tls_cert_path" || ! -f "$tls_cert_path" ]]; then
    printf 'TLS_CERT_PATH must point to an existing UAT certificate.\n' >&2
    exit 1
fi

# Redis was flushed above; seed both liveness records and retain a bounded wait
# for queue/scheduler workers to publish their normal heartbeats.
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

finished_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'UAT reset completed: scope=%s at %s\n' "$scope" "$finished_at"
printf 'Operation report: %s\n' "$report_file"
