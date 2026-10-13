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
- `portal_accesses`: party_id (unique), token_hash (sha256, unique), token_encrypted, pin_hash, must_change_pin, failed_attempts, locked_at, issued_by, issued_at, last_login_at (member portal link + PIN; written only by PortalAccessService)
- `whatsapp_logs`: kind (reminder/receipt/notice/portal), party_id (portal rows carry only party_id), schedule_id null, transaction_id null, phone, sent_by, created_at (immutable; taps on wa.me buttons, not ledger rows)

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
- JSON endpoints for PWA sync: paths under `/api/field` but defined in routes/web.php (web middleware: session cookie + CSRF header), so no Sanctum and no token handling on devices. Protocol in docs/PWA.md.
- Member portal: `/m/{token}` + `/portal/*` (public web routes, own session keys + `portal_device` cookie + `portal.unlocked` middleware, never the staff guard), layout `layouts.portal`, Vite entry `resources/js/portal.js`, `public/portal-sw.js`.
- Scheduled commands: generate tomorrow's schedules, mark yesterday's unpaid as missed, daily summary.

## Testing
- Feature tests for every TransactionService rule (over-collection, reversal, idempotent client_uuid, locked day, role scoping).
- Policy tests: a field officer can never read another officer's party.
- Use factories; no seeding of real data.

## Libraries wiring
Bundle only what a page needs via Vite entry points per area (admin, field) to keep the field PWA light: the field entry must not import DataTables, ApexCharts, pdfmake or jQuery.
Single source: npm packages bundled by Vite (decided 2026-10-11). No CDN links, no `config/adminlte.php` plugins. Admin layout uses `laravel_asset_bundling = vite_js_only` with entry `resources/js/admin.js`; `public/vendor/` keeps only `adminlte/dist/assets` (logo images).

## Security baseline (reviewed 2026-10-13)
- Authz: roles + policies, enforced by `PermissionMatrixTest` (every route x role). Mass assignment: every model uses `#[Fillable]`; writers take `validated()` only. Raw SQL limited to constant `selectRaw` aggregates.
- Uploads: party photo only; `mimes:jpeg,png,webp`, size and dimension caps, re-encoded by GD (no EXIF, no original bytes kept), private disk, temp tokens bound to the session, served after a policy check.
- Headers: `SecurityHeaders` (nosniff, SAMEORIGIN, same-origin referrer, Permissions-Policy, HSTS on HTTPS). No CSP yet (inline admin scripts need a nonce pass first).
- Throttling: login (`ThrottlesLogins`), sync endpoint 60/min.
- `/m/{token}` member portal URLs are secrets: web-server access logs that record them are sensitive (restrict access or strip the path).
- Production `.env` must have: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `BCRYPT_ROUNDS>=12`, HTTPS only (also required by the service worker).
