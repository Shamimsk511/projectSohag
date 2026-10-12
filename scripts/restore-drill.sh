#!/usr/bin/env bash
# Restore drill: load a dump into a scratch database and compare row counts with the live one.
# Never touches the live database. Usage: scripts/restore-drill.sh path/to/db-STAMP.sql.gz
set -euo pipefail
cd "$(dirname "$0")/.."

env_get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

dump="${1:?usage: restore-drill.sh db-STAMP.sql.gz}"
live="$(env_get DB_DATABASE)"
scratch="${live}_restore_drill"
export MYSQL_PWD="$(env_get DB_PASSWORD)"
conn=(-h "$(env_get DB_HOST)" -P "$(env_get DB_PORT)" -u "$(env_get DB_USERNAME)")

mysql "${conn[@]}" -e "DROP DATABASE IF EXISTS \`$scratch\`; CREATE DATABASE \`$scratch\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$dump" | mysql "${conn[@]}" "$scratch"

counts() {
  for t in users parties accounts transactions audit_logs collection_schedules cash_handovers daily_closings; do
    printf '%s %s\n' "$t" "$(mysql "${conn[@]}" -N -e "SELECT COUNT(*) FROM \`$1\`.\`$t\`")"
  done
  printf 'ledger_sum %s\n' "$(mysql "${conn[@]}" -N -e "SELECT COALESCE(SUM(amount),0) FROM \`$1\`.transactions")"
}

diff <(counts "$live") <(counts "$scratch") && echo "OK: restored copy matches live counts (differences above mean data changed since the dump)."
mysql "${conn[@]}" -e "DROP DATABASE \`$scratch\`"
