#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this script as root." >&2
    exit 1
fi

base_directory=${1:-/srv/gn-system}
app_uid=${APP_UID:-1000}
app_gid=${APP_GID:-1000}

if [[ ${base_directory} != /srv/gn-system && ${base_directory} != /srv/gn-system/* ]]; then
    echo "The production base directory must be /srv/gn-system or a child of it." >&2
    exit 1
fi

install -d -m 0750 -o root -g root "${base_directory}"
install -d -m 0750 -o "${app_uid}" -g "${app_gid}" "${base_directory}/repository"
install -d -m 0770 -o "${app_uid}" -g "${app_gid}" "${base_directory}/data/private"
install -d -m 0770 -o "${app_uid}" -g "${app_gid}" "${base_directory}/data/backups"
install -d -m 0750 -o root -g root "${base_directory}/tls"
install -d -m 0750 -o root -g root "${base_directory}/releases"

echo "Deployment directories are ready under ${base_directory}."
echo "Install the TLS certificate and key as root, then set the key mode to 0600."
