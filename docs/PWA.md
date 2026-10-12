# Field officer PWA

Goal: an officer installs the app on their phone, sees today's collection list, records payments even with no signal, and everything syncs safely later.

## Scope
- Routes under `/field`, separate Blade layout, separate Vite entry (`resources/js/field.js`). No jQuery, no DataTables, no AdminLTE sidebar.
- Screens: Today (summary + search + one card per client), Collect all (one client, every open account), Collect payment, Receipt, History (own), Sync status, Profile.

## Installability
- `public/manifest.webmanifest`: name, short_name, `start_url: /field`, `scope: /field` (no trailing slash: `/field` itself would fall outside `/field/`), `display: standalone`, theme/background colors, 192 and 512 px icons plus a maskable icon.
- `<link rel="manifest">`, theme-color meta, apple-touch-icon in the field layout.
- Must be served over HTTPS (localhost is fine for dev).
- Hand-written service worker at `public/field-sw.js` registered with scope `/field` as `/field-sw.js?v=<Vite manifest hash>` (cache version; not registered under the Vite dev server). Do not add a PWA Vite plugin unless it is confirmed compatible with the installed Vite version.

## Offline strategy
- Cache the app shell (layout, JS, CSS, fonts, icons) with cache-first, versioned cache name.
- Cache today's assignment payload (schedules + party basics + outstanding) in IndexedDB after each successful load; show it when offline with a visible "offline" banner.
- Never cache authenticated HTML pages blindly; use network-first with offline fallback page.

## Offline sync protocol (decided 2026-10-12)

### Transport and auth
- `POST /api/field/collections/sync`, route name `field.api.collections.sync`, defined in `routes/web.php` (web middleware: session cookie + CSRF). No Sanctum, no tokens on devices. Same `auth` + field-officer role gate as `/field`.
- Client sends `X-CSRF-TOKEN` from the page's meta tag, `Accept: application/json`, `credentials: 'same-origin'`.
- Sync runs only from a live (network-loaded) `/field` page. The cached offline page has no session token and never syncs; the service worker never syncs (no Background Sync: it cannot get a CSRF token and iOS lacks it).
- Triggers: page load of home/collect pages, `online` event, right after enqueueing while online, manual "Sync now". One run at a time per browser (`navigator.locks.request('field-sync')` where available; double runs are still safe because of `client_uuid`).

### Request
```json
{ "items": [ { "client_uuid": "uuid v4", "schedule_id": 12, "amount": "500", "payment_method": "cash",
               "description": null, "collected_at": "2026-10-12T10:15:00+06:00" } ] }
```
- 1..50 items, `client_uuid` distinct within the batch. Client sends items oldest `collected_at` first; server processes in that order (two collections on one row affect each other).
- `amount` is the taka string as entered (same `TakaAmount` rule as the online form). Server re-validates everything; nothing from the client is trusted beyond being a request.
- Malformed envelope (not an array, > 50, duplicate uuids in batch) -> 422 for the whole request. That is a client bug: keep the queue, show a generic error.

### Server rules per item
Each item runs alone through `TransactionService::record` (own DB transaction, own audit row); one bad item never blocks the rest.
- Schedule must exist and pass `CollectionSchedulePolicy::collect` for the signed-in officer *now* (a row reassigned while offline is rejected, not silently accepted).
- `transaction_date` = Dhaka date of `collected_at`, allowed only from server yesterday to server today (at most 1 day back; device clocks can lie, older collections go through the office). Out of range -> rejected `date_out_of_range`. Closed day -> rejected `day_closed` (admin posts it by hand). `field_officer_id` = signed-in user; `created_at` = real sync time, so backdating is visible.
- Duplicate `client_uuid`: if the stored row was created by the same officer on the same schedule with the same amount -> `duplicate` with its receipt. Anything else (other officer, other schedule/amount) -> rejected `uuid_conflict`, never revealing the other row.
- Every rejection is written to the application log (warning, officer id, uuid, code) so the office can follow up; there is no rejection table.

### Response (200 whenever the envelope is valid)
```json
{ "results": [ { "client_uuid": "...", "status": "created|duplicate", "transaction_id": 99, "receipt_no": "R-202610-00001",
                 "receipt_url": "/field/receipts/99", "amount_label": "৳ ৫০০.০০" },
               { "client_uuid": "...", "status": "rejected", "code": "exceeds_outstanding", "message": "translated text",
                 "errors": { "amount": ["..."] } },
               { "client_uuid": "...", "status": "error" } ] }
```
- `rejected` = permanent for this payload. `code` is `invalid` (field `errors` included), `forbidden`, `date_out_of_range`, `uuid_conflict`, or a TransactionService rule key (`app.transactions.<key>`, e.g. `exceeds_outstanding`, `schedule_not_open`, `day_closed`). Retrying the same item is pointless.
- `error` = unexpected server failure on that item; retry later.
- Whole-request failures: 401 (session gone) -> keep queue, show "sign in again"; 419 (stale CSRF) -> keep queue, reload the page once to get a fresh token, then sync; network error / 5xx / 429 -> retry with backoff.

### Client queue (IndexedDB store `pending_collections`, key `client_uuid`)
Record: client_uuid, user_id, schedule_id, account_id, party_name, account_number (display), amount (taka string), payment_method, description, collected_at, state, attempts, last_error, result (receipt_no, transaction_id, receipt_url) or rejection (code, message, errors), updated_at.

States (persisted):
```
pending --sync ok (created|duplicate)--> synced
pending --rejected--> rejected
pending --error/network/5xx--> pending (attempts+1, last_error, backoff)
rejected --officer "fix and resend"--> new pending item (NEW client_uuid, old record deleted in the same IDB transaction)
rejected --officer "discard" (confirm: the cash is still in hand, tell the office)--> deleted
synced --older than 7 days--> deleted (pruned on app open)
```
- No persisted `syncing` state: an in-flight batch is in memory only; a crash mid-request leaves items `pending`, and the resend is safe (`client_uuid`).
- Backoff while a page is open: 30 s doubling to 10 min; any trigger above resets it.
- Every query filters by the signed-in `user_id` (from the live page); other users' items stay untouched on the device.
- The UI shows queued items as "saved, not synced" next to the snapshot figures; it never recalculates the server's totals. A provisional receipt says "pending" and shows no receipt number until synced.

## Security
- Session auth, CSRF token from the page meta tag; 401/419 handled as above, queue always kept.
- Officer can only submit for own scheduled parties (policy-checked server-side per item, at sync time).
- Queue stored per user id. Logout with pending/rejected items for that user: warn with the count; "log out anyway" keeps the items (they sync when that user signs in again). The today snapshot is always cleared on logout.
- A different user signing in on the same phone never sees or sends another user's items.
- Optional: PIN or device lock on app open for lost-phone protection.
- Optional later: capture GPS at collection time (permission prompt, stored as metadata only).

## UX rules
- One-hand mobile layout, minimum 48px tap targets, Bengali-first labels, large amount input with numeric keypad (`inputmode="numeric"`).
- Quick-fill buttons: "full due" and "partial".
- Always show: expected, collected, remaining for today, and unsynced count.
- Confirm before submit; after submit show receipt with share/print option.

## Testing
- Feature tests for the sync endpoint: duplicate uuid (same and conflicting payload), partial batch failure, other officer's party, reassigned row, locked day, date window, malformed envelope, 401/419.
- Manual checklist in docs/ROADMAP.md: install on Android Chrome, go offline, collect, reconnect, verify single posting.