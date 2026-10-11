# Architecture

Flow: Party -> Account -> CollectionSchedule -> Transaction -> Ledger -> Reports

## Tables (first pass; confirm with `database-schema` before changing)
All money columns are BIGINT poisha.

- `users` (+ Spatie tables): name, phone, email, password, status
- `parties`: party_code (unique), name, phone, address, area, national_id, photo_path, party_type, status
- `party_assignments`: party_id, field_officer_id, assigned_from, assigned_until, status
- `accounts`: party_id, account_number (unique), account_type, contract_amount, opened_at, closed_at, status
- `account_adjustments`: account_id, old_amount, new_amount, reason, created_by, approved_by
- `collection_schedules`: account_id, party_id, field_officer_id, due_date, expected_amount, status, notes
- `transactions`: account_id nullable, party_id nullable, field_officer_id nullable, schedule_id nullable, type, amount, payment_method, reference, receipt_no, description, transaction_date, client_uuid nullable unique, reverses_transaction_id nullable, created_by, approved_by, created_at (no updated_at, no soft deletes)
- `cash_handovers`: field_officer_id, amount, handover_date, confirmed_by
- `daily_closings`: date, closed_by, closed_at
- `audit_logs`: user_id, action, auditable_type, auditable_id, before, after, ip, user_agent, created_at

Indexes: transactions(transaction_date), (account_id, transaction_date), (field_officer_id, transaction_date); schedules(field_officer_id, due_date, status); assignments(field_officer_id, status).

## Services (app/Services)
- `TransactionService`: only writer of transactions. Methods: record, reverse. Wraps DB transaction, validates rules from DOMAIN_RULES, writes audit, updates schedule status.
- `AccountService`: open, close, adjust contract amount (with reason).
- `AssignmentService`: assign/reassign party, closes previous assignment.
- `CollectionService`: generate schedules (daily/weekly/monthly/custom), mark missed, officer's daily list, bulk sync from PWA.
- `LedgerService`: balances, outstanding, statements computed from transactions.
- `ReportService`: all reports, query-based, cacheable.
- `Money` (app/Support): poisha <-> display, Bengali numeral display helper, Eloquent cast.

## Layout
- Models in app/Models, Policies in app/Policies, Form Requests in app/Http/Requests.
- Admin web UI: routes/web.php, prefix `/admin`, AdminLTE layout.
- Field officer PWA: routes under `/field`, own minimal mobile layout (not AdminLTE sidebar).
- JSON endpoints for PWA sync: routes/api.php under `/api/field`, session-cookie auth (same-origin, Sanctum SPA mode) so no token handling on devices.
- Scheduled commands: generate tomorrow's schedules, mark yesterday's unpaid as missed, daily summary.

## Testing
- Feature tests for every TransactionService rule (over-collection, reversal, idempotent client_uuid, locked day, role scoping).
- Policy tests: a field officer can never read another officer's party.
- Use factories; no seeding of real data.

## Libraries wiring
Bundle only what a page needs via Vite entry points per area (admin, field) to keep the field PWA light: the field entry must not import DataTables, ApexCharts, pdfmake or jQuery.
Single source: npm packages bundled by Vite (decided 2026-10-11). No CDN links, no `config/adminlte.php` plugins. When the admin layout moves to Vite (`laravel_asset_bundling`), delete the remaining `public/vendor/` AdminLTE core copies.
