#!/usr/bin/env bash
#
# Nightly database dump and uploaded-files archive, kept for KEEP_DAYS days.
#
# Reads the MySQL credentials from ~/.my.cnf so the password never appears
# in the process list or in crontab. Copy the backups off the server too: a
# backup that lives on the disk it protects is not a backup.

set -euo pipefail

database="${DB_DATABASE:-peygir}"
backup_dir="${BACKUP_DIR:-$HOME/backups}"
keep_days="${KEEP_DAYS:-14}"
app_dir="${APP_DIR:-$(cd "$(dirname "$(readlink -f "$0")")/../.." && pwd)}"
stamp="$(date +%F-%H%M)"

mkdir -p "${backup_dir}"

target="${backup_dir}/${database}-${stamp}.sql.gz"

mysqldump --single-transaction --quick --routines --no-tablespaces --default-character-set=utf8mb4 "${database}" \
    | gzip > "${target}.part"

mv "${target}.part" "${target}"

# Uploads (sponsor logos) live on the private disk, not in the database.
files="${backup_dir}/${database}-files-${stamp}.tar.gz"

tar -czf "${files}.part" -C "${app_dir}/storage/app" private

mv "${files}.part" "${files}"

find "${backup_dir}" \( -name "${database}-*.sql.gz" -o -name "${database}-files-*.tar.gz" \) -mtime +"${keep_days}" -delete
