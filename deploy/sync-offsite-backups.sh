#!/usr/bin/env bash
set -euo pipefail

source_directory=${1:-/srv/gn-system/production/data/backups}
destination_mount=${2:-/mnt/gn-system-offsite}
destination_directory="${destination_mount}/gn-system"

if [[ ! -d ${source_directory} ]]; then
    echo "Backup source does not exist: ${source_directory}" >&2
    exit 1
fi

if ! mountpoint --quiet "${destination_mount}"; then
    echo "Offsite destination is not a mounted filesystem: ${destination_mount}" >&2
    exit 1
fi

probe_file="${destination_mount}/.gn-system-write-probe-$$"
trap 'rm -f -- "${probe_file}"' EXIT
touch "${probe_file}"

mkdir -p "${destination_directory}"
rsync --archive --checksum --partial "${source_directory}/" "${destination_directory}/"

touch "${source_directory}/.offsite-sync-success"
rsync --archive "${source_directory}/.offsite-sync-success" \
    "${destination_directory}/.offsite-sync-success"

logger --tag gn-system-backup "Offsite backup sync completed."
