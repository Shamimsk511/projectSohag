# Backup and restore

What to protect: the MySQL database (the whole ledger) and `storage/app/private/parties/` (member photos). Everything else rebuilds from code. `.env` (APP_KEY, DB password) is kept separately by the owner, never in a backup next to the dump.

## Backup
`scripts/backup.sh [dir] [keep_days]` (defaults `storage/backups`, 14 days) writes `db-STAMP.sql.gz` (`mysqldump --single-transaction`, no table locks while officers collect) and `photos-STAMP.tar.gz`, then deletes files older than `keep_days`. Reads DB credentials from `.env`; the password goes in `MYSQL_PWD`, never on the command line.

Production cron (as the app user), nightly after the day closes:
`37 1 * * * cd /path/to/app && scripts/backup.sh /var/backups/srijonshil 30 >> storage/logs/backup.log 2>&1`

Copy the backup directory off the server (second machine or cloud bucket) daily: a backup on the same disk is not a backup. Encrypt the copy if it leaves the NGO's control (dumps hold phone numbers and national IDs).

## Restore drill (do monthly and after any schema change)
1. `scripts/restore-drill.sh /path/to/db-STAMP.sql.gz` loads the dump into a scratch database `<DB_DATABASE>_restore_drill`, compares row counts of users, parties, accounts, transactions, audit_logs, schedules, handovers, closings and the sum of `transactions.amount` with live, then drops the scratch database. Live data is never touched. Counts differ only if data changed after the dump.
2. Photos: `tar -tzf photos-STAMP.tar.gz | head` and extract one file to a scratch dir; open it.
3. Optional deeper check: point a scratch `.env` at the restored database and open an account statement; closing balance must equal the account page.

## Real restore (disaster)
1. Put the app in maintenance mode: `php artisan down`.
2. New empty database, then `gunzip -c db-STAMP.sql.gz | mysql ... DB_NAME`.
3. `tar -xzf photos-STAMP.tar.gz -C storage/app/private`.
4. Restore `.env`, run `php artisan migrate --force` (applies migrations newer than the dump), `php artisan up`.
5. Work done after the dump is lost. Officers' unsynced phone queues survive on the devices and sync with `client_uuid` safety once the app is back; tell officers to open the app online and check `/field/sync`.

Drilled 2026-10-13 on the dev database: dump 106 KB, photos 168 KB, counts matched.
