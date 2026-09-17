#!/usr/bin/env bash
set -euo pipefail

repository_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
environment_file=${1:-"${repository_directory}/.env.production"}
compose_file="${repository_directory}/compose.production.yaml"

if [[ ! -f ${environment_file} ]]; then
    echo "Deployment environment file not found: ${environment_file}" >&2
    exit 1
fi

environment_file=$(realpath "${environment_file}")
environment_mode=$(stat -c '%a' "${environment_file}")

if [[ ${environment_mode} != 600 ]]; then
    echo "Deployment environment file must have mode 0600." >&2
    exit 1
fi

release_tag=$(sed -n 's/^RELEASE_TAG=//p' "${environment_file}" | tail -n 1)
release_directory=$(sed -n 's/^RELEASE_STATE_PATH=//p' "${environment_file}" | tail -n 1)
app_url=$(sed -n 's/^APP_URL=//p' "${environment_file}" | tail -n 1)

if [[ -z ${release_tag} || ${release_tag} == latest ]]; then
    echo "RELEASE_TAG must be an explicit immutable version, never latest." >&2
    exit 1
fi

if [[ -z ${release_directory} ]]; then
    echo "RELEASE_STATE_PATH must be configured." >&2
    exit 1
fi

release_directory=$(realpath -m "${release_directory}")
case "${release_directory}" in
    /srv/gn-system/releases|/srv/gn-system/*/releases) ;;
    *)
        echo "RELEASE_STATE_PATH must be /srv/gn-system/releases or an isolated child environment releases directory." >&2
        exit 1
        ;;
esac

if [[ ${app_url} != https://* ]]; then
    echo "APP_URL must use HTTPS in production." >&2
    exit 1
fi

compose=(docker compose --env-file "${environment_file}" -f "${compose_file}")

cd "${repository_directory}"
"${compose[@]}" config --quiet

mkdir -p "${release_directory}"
previous_release=none
if [[ -f ${release_directory}/current ]]; then
    previous_release=$(<"${release_directory}/current")
fi

echo "Deploying ${release_tag}; previous release: ${previous_release}"

if "${compose[@]}" ps --status running --services | grep -qx app; then
    "${compose[@]}" exec -T app php artisan backup:run --no-interaction
    "${compose[@]}" exec -T app php artisan down --retry=30 --no-interaction
    "${compose[@]}" stop web queue scheduler
fi

"${compose[@]}" pull
"${compose[@]}" run --rm app php artisan migrate --force --isolated --no-interaction
"${compose[@]}" run --rm app php artisan optimize:clear --no-interaction
"${compose[@]}" up -d --remove-orphans
"${compose[@]}" exec -T app php artisan optimize --no-interaction
"${compose[@]}" exec -T app php artisan queue:restart --no-interaction
"${compose[@]}" exec -T app php artisan up --no-interaction

health_deadline=$((SECONDS + 210))
until curl --fail --silent --show-error "${app_url}/up" >/dev/null \
    && curl --fail --silent --show-error "${app_url}/health" >/dev/null \
    && curl --fail --silent --show-error "${app_url}/health/operations" >/dev/null
do
    if (( SECONDS >= health_deadline )); then
        echo "Deployment health checks failed. Services remain available for diagnosis." >&2
        exit 1
    fi

    sleep 5
done

printf '%s\n' "${release_tag}" > "${release_directory}/current"
printf '%s\t%s\t%s\n' "$(date --iso-8601=seconds)" "${release_tag}" "${previous_release}" \
    >> "${release_directory}/history.tsv"

echo "Deployment ${release_tag} completed successfully."
