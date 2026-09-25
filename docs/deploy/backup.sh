#!/usr/bin/env bash
#
# Nightly database dump, kept for KEEP_DAYS days.
#
# Reads the MySQL credentials from ~/.my.cnf so the password never appears
# in the process list or in crontab. Copy the dumps off the server too: a
# backup that lives on the disk it protects is not a backup.

set -euo pipefail

database="${DB_DATABASE:-peygir}"
backup_dir="${BACKUP_DIR:-$HOME/backups}"
keep_days="${KEEP_DAYS:-14}"

mkdir -p "${backup_dir}"

target="${backup_dir}/${database}-$(date +%F-%H%M).sql.gz"

mysqldump --single-transaction --quick --routines --no-tablespaces --default-character-set=utf8mb4 "${database}" \
    | gzip > "${target}.part"

mv "${target}.part" "${target}"

find "${backup_dir}" -name "${database}-*.sql.gz" -mtime +"${keep_days}" -delete
