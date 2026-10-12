# Roadmap

Work top to bottom. One item per Claude Code session. Tick when done and add a one-line handoff note.

## Phase 0: Project setup
- [x] Confirm Laravel version, DB config, Spatie Permission migrated, roles/permissions seeder (admin, manager, accountant, field_officer)
- [x] Integrate AdminLTE 4 layout via Vite (admin entry), remove unused starter UI
- [x] Separate Vite entries: `admin` and `field`
- [x] Money helper + cast, Bengali lang files skeleton, Asia/Dhaka timezone
- [x] Pint + test baseline green, graphify graph built

## Phase 1: Foundation
- [x] Login, logout, role-based redirect (admin -> /admin, officer -> /field)
- [x] Users CRUD + role assignment (admin only)
  - Plan: users += `phone` (nullable unique, BD 01XXXXXXXXX), `status` (`UserStatus` active/inactive). No delete (future ledger FKs): deactivate. One role per user. `UserService` create/update (role sync in DB transaction), `UserPolicy` via `users.manage`, Form Requests (admin cannot demote/deactivate self). Global web middleware signs out inactive users; login rejects them. Plain Bootstrap table + pagination (staff list is small). Menu item via BuildingMenu (translated). `app:create-admin` command for the first admin.
- [ ] Parties CRUD, search, photo upload (FilePond), DataTable list
  - Plan: `areas` (name unique, status) + admin CRUD (plain table, like users). `parties`: party_code `P-00001` (from id, set inside create transaction), required name/phone/address/area_id; optional father_or_husband_name, mother_name, date_of_birth, gender, national_id (nullable unique), photo_path, nominee_name/relation/phone/national_id; status (`PartyStatus`). No party_type (derived from accounts later). No delete (deactivate). Phone not unique, BD format + Bengali digits normalised. `PartyPolicy`/`AreaPolicy`: view = `parties.view_all` or `parties.manage`, write = `parties.manage`; field officers no /admin party access. `PartyService` create/update (code, photo swap, old file deleted after commit). Photo: `intervention/image` ^3 (GD; prod needs ext-gd), scale down to 800px, re-encode JPEG q80 (strips EXIF), private disk `parties/`, streamed by `admin.parties.photo` after policy check. FilePond process/revert endpoint stores temp in `private/tmp/` keyed to session; form submits token. List: DataTables server-side via hand-written JSON endpoint + `PartyTableQuery` (search code/name/phone/NID, filter area/status, whitelisted order). Page JS `resources/js/admin/parties.js` (DataTables bs5, Select2, FilePond) loaded only on party pages. Show page: profile + nominee, placeholders for accounts/assignment. Non-goals: assignment, accounts, import, delete, NID scans.
- [ ] Party assignment + reassignment with history

## Phase 2: Accounts
- [ ] Account open/close (saving, loan), contract_amount, account_adjustments with reason
- [ ] Account statement view (ledger-driven)

## Phase 3: Ledger core
- [ ] `TransactionService` (record, reverse) with all DOMAIN_RULES + tests
- [ ] Audit log writer
- [ ] Disbursement, deposit, withdrawal screens (admin/accountant)
- [ ] Office income/expense

## Phase 4: Collections
- [ ] Schedule generator (daily/weekly/monthly/custom) + scheduled commands
- [ ] Admin: view/edit schedules, reassign day lists
- [ ] Officer daily list (web, mobile layout) and collect-payment flow
- [ ] Partial payment, missed marking, receipts
- [ ] Cash handover + daily closing

## Phase 5: PWA
- [ ] Manifest, icons, `/field` layout, service worker shell caching
- [ ] IndexedDB cache of today's assignments
- [ ] Offline collection queue + `/api/field/collections/sync`
- [ ] Sync status UI, conflict/rejection handling
- [ ] Device test checklist: Android Chrome install, offline collect, reconnect, single posting, logout with pending queue

## Phase 6: Reporting
- [ ] Admin dashboard (expected vs collected, outstanding, officer performance)
- [ ] Daily, monthly, officer-wise, customer ledger, outstanding, missed/partial, cash balance
- [ ] Export: DataTables Buttons (Excel/PDF), print-friendly statement

## Phase 7: Later
- [ ] SMS/WhatsApp reminders and receipts
- [ ] Excel import of existing spreadsheet data (one-time migration tool)
- [ ] Customer portal
- [ ] GPS verification on collection
- [ ] Named Shariah-reviewed contract types (only with a qualified scholar)

## Handoff notes
(append one line per finished item: date, what changed, anything the next session must know)
- 2026-10-11: Laravel 13.35, MySQL (tests: sqlite memory), Spatie 8.3 migrated. Roles/permissions live in `App\Enums\Role` + `App\Enums\Permission::roles()`; `RolesAndPermissionsSeeder` is idempotent (rerun after adding enum cases). Admin bypasses all checks via `Gate::before` in AppServiceProvider. Check permissions with `$user->can(Permission::X->value)`. Also added Larastan lvl 6, npm-only assets, `app.display_timezone`, locale bn. No admin user seeded yet (Phase 1 users item).
- 2026-10-12: AdminLTE via `resources/js/admin.js` (vite_js_only; bootstrap/icons/overlayscrollbars/fonts from npm, package CDN/local loading disabled). Pages extend `layouts.admin`; auth views are one-line `@extends('adminlte::auth.*')`. Public registration off. `/admin` is auth-only for now (no role gate yet: Phase 1). Menu in config/adminlte.php has only dashboard. Tests need `withoutVite()`. Bengali glyphs via @fontsource/noto-sans-bengali; CSS overrides go in resources/css/admin.css (adminlte `css_variables` HTML-escapes quotes, unusable for font names).
- 2026-10-12: Vite entries `resources/js/admin.js` + `resources/js/field.js` (field = Bootstrap CSS + icons + fonts only, no JS libs yet; add Bootstrap JS there only when a component needs it). Shared fonts in `resources/js/fonts.js`. `layouts.field` is a plain Blade layout (no AdminLTE); `/field` is an auth-only placeholder (`field.home`), role gate comes in Phase 1. `ViteEntriesTest` fakes a hot file to assert each layout loads only its own entry.
- 2026-10-12: `App\Support\Money` (final readonly, int poisha; `fromTaka()` parses "1,234.50"/Bengali digits exactly, `format()` = ৳ + lakh grouping + locale digits, `toTaka()` for form values). Cast with `'col' => Money::class`; setter accepts only Money|null (raw ints throw, avoids taka/poisha mixups). `App\Support\Numerals` for digit localize/toAscii. Lang: published en core files + bn auth/pagination/passwords/validation (common rules only; rest falls back to en); UI strings go in `lang/{en,bn}/app.php` as `__('app.key')`, `LangFilesTest` enforces en/bn parity. AdminLTE menu labels still use package `adminlte::menu` translations. Timezone config was already set; no date display helper yet (add when first date is shown).
- 2026-10-12: Baseline green (53 tests, Pint clean, PHPStan 0). graphify graph in `graphify-out/` (gitignored, 348 nodes/53 communities); rebuild with `/graphify . --update` after big refactors. Phase 0 done; next: Phase 1 login + role redirect.
- 2026-10-12: `/` (`home`, HomeController) redirects by `User::homeRoute()` (admin/manager/accountant -> `admin.dashboard`, field_officer -> `field.home`, no role -> null). Login refuses role-less users (`app.no_role`), logout -> /login. Route gates via `RoleMiddleware::using(Role::names(...))`: /admin = `Role::office()`, /field = field_officer + admin (Gate::before does not cover role middleware). Tests: `User::factory()->withRole(Role::X)`. Still email login only; phone login + user `status` (block inactive) belong to Users CRUD.
- 2026-10-12: Users CRUD at `/admin/users` (`Admin\UserController`, no show/destroy), `UserService` create/update (one role via syncRoles), `UserPolicy` on `users.manage`, Store/UpdateUserRequest (phone `01[3-9]XXXXXXXX`, Bengali digits normalised; admin can't demote/deactivate self). `users.phone`/`users.status` (`UserStatus`); `EnsureUserIsActive` appended to web group signs out deactivated users. Translated menu items go in AppServiceProvider `BuildingMenu` listener; AdminLTE caches the menu per app instance, so one request per menu assertion in tests. Bootstrap 5 paginator. First admin: `php artisan db:seed && php artisan app:create-admin`. Login still email-only.
