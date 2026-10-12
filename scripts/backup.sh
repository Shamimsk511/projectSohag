#!/usr/bin/env bash
# Nightly backup: consistent MySQL dump + private photos. Usage: scripts/backup.sh [backup_dir] [keep_days]
set -euo pipefail
cd "$(dirname "$0")/.."

env_get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

dir="${1:-storage/backups}"
keep="${2:-14}"
stamp="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$dir"

export MYSQL_PWD="$(env_get DB_PASSWORD)"
mysqldump --single-transaction --routines --no-tablespaces \
  -h "$(env_get DB_HOST)" -P "$(env_get DB_PORT)" -u "$(env_get DB_USERNAME)" "$(env_get DB_DATABASE)" \
  | gzip > "$dir/db-$stamp.sql.gz"

# Party photos only; tmp/ holds abandoned uploads (pruned by photos:prune-temp).
if [ -d storage/app/private/parties ]; then
  tar -czf "$dir/photos-$stamp.tar.gz" -C storage/app/private parties
fi

find "$dir" -type f \( -name 'db-*.sql.gz' -o -name 'photos-*.tar.gz' \) -mtime +"$keep" -delete
echo "backup written: $dir (stamp $stamp)"
