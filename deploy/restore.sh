#!/usr/bin/env bash
set -euo pipefail

repository_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
environment_file=${1:-"${repository_directory}/.env.production"}
archive=${2:-}

if [[ -z ${archive} || ! -f ${archive} ]]; then
    echo "Usage: $0 /path/to/.env.production /path/to/backup.zip" >&2
    exit 1
fi

environment_file=$(realpath "${environment_file}")
archive=$(realpath "${archive}")
compose_file="${repository_directory}/compose.production.yaml"
compose=(docker compose --env-file "${environment_file}" -f "${compose_file}")

read -r -p "Type RESTORE to replace the target database and private files: " confirmation
if [[ ${confirmation} != RESTORE ]]; then
    echo "Restore cancelled."
    exit 1
fi

table_count=$(
    "${compose[@]}" exec -T postgres sh -ec \
        'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from pg_tables where schemaname = '\''public'\'';"'
)

if [[ ${table_count} != 0 ]]; then
    echo "Target database is not empty; refusing to restore." >&2
    exit 1
fi

read -r -s -p "Backup archive password: " archive_password
echo

restore_directory=$(mktemp -d /tmp/gn-system-restore.XXXXXX)
cleanup() {
    if [[ ${restore_directory} == /tmp/gn-system-restore.* ]]; then
        rm -rf -- "${restore_directory}"
    fi
}
trap cleanup EXIT

printf '%s' "${archive_password}" | "${compose[@]}" run --rm --no-deps -T \
    --entrypoint php \
    --volume "${archive}:/restore/backup.zip:ro" \
    --volume "${restore_directory}:/restore/output" \
    app -r '
        $password = stream_get_contents(STDIN);
        $archive = new ZipArchive();
        $result = $archive->open("/restore/backup.zip");

        if ($result !== true || ! $archive->setPassword($password) || ! $archive->extractTo("/restore/output")) {
            fwrite(STDERR, "Unable to decrypt and extract the backup archive.\n");
            exit(1);
        }

        $archive->close();
    '
unset archive_password

database_dump=$(find "${restore_directory}" -type f -path '*/db-dumps/*.sql' -print -quit)
private_directory=$(find "${restore_directory}" -type d -name private -print -quit)

if [[ -z ${database_dump} ]]; then
    echo "No PostgreSQL SQL dump was found in the archive." >&2
    exit 1
fi

"${compose[@]}" stop web queue scheduler app
"${compose[@]}" start postgres redis
"${compose[@]}" exec -T postgres sh -ec \
    'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" --set ON_ERROR_STOP=on' < "${database_dump}"

private_target=$(sed -n 's/^PRIVATE_DATA_PATH=//p' "${environment_file}" | tail -n 1)
if [[ -n ${private_directory} && -n ${private_target} ]]; then
    rsync --archive --delete "${private_directory}/" "${private_target}/"
fi

"${compose[@]}" up -d
"${compose[@]}" exec -T app php artisan migrate:status --no-interaction
"${compose[@]}" exec -T app php artisan optimize --no-interaction

echo "Restore completed. Run all three health checks and verify an encrypted 2FA account."
