# Field officer PWA

Goal: an officer installs the app on their phone, sees today's collection list, records payments even with no signal, and everything syncs safely later.

## Scope
- Routes under `/field`, separate Blade layout, separate Vite entry (`resources/js/field.js`). No jQuery, no DataTables, no AdminLTE sidebar.
- Screens: Today (summary + list), Party detail, Collect payment, Receipt, History (own), Sync status, Profile.

## Installability
- `public/manifest.webmanifest`: name, short_name, `start_url: /field`, `scope: /field/`, `display: standalone`, theme/background colors, 192 and 512 px icons plus a maskable icon.
- `<link rel="manifest">`, theme-color meta, apple-touch-icon in the field layout.
- Must be served over HTTPS (localhost is fine for dev).
- Hand-written service worker at `public/field-sw.js` registered with scope `/field/`. Do not add a PWA Vite plugin unless it is confirmed compatible with the installed Vite version.

## Offline strategy
- Cache the app shell (layout, JS, CSS, fonts, icons) with cache-first, versioned cache name.
- Cache today's assignment payload (schedules + party basics + outstanding) in IndexedDB after each successful load; show it when offline with a visible "offline" banner.
- Never cache authenticated HTML pages blindly; use network-first with offline fallback page.

## Offline collection queue
1. Officer submits a collection. Client generates `client_uuid` (crypto.randomUUID), amount, method, schedule_id, account_id, collected_at, optional note/location.
2. Stored in IndexedDB `pending_collections` with status `pending`.
3. UI shows it immediately as "saved, not synced" and issues a provisional receipt marked pending.
4. Sync runs on: app open, `online` event, manual "Sync now", and Background Sync where supported (do not rely on it; iOS Safari lacks it).
5. POST `/api/field/collections/sync` with a batch. Server processes each item through `TransactionService` (idempotent by `client_uuid`) and returns per-item result: created / duplicate / rejected(reason).
6. Created and duplicate -> mark synced, attach final receipt_no. Rejected -> keep, flag for officer and show reason (never silently drop).
7. Server is the source of truth; client never computes balances authoritatively.

## Security
- Session auth, CSRF token refreshed via meta tag; handle 419/401 by prompting re-login while keeping the queue intact.
- Officer can only submit for own scheduled parties (policy-checked server-side per item).
- Queue stored per user id; clear on logout only after confirming nothing is pending (warn otherwise).
- Optional: PIN or device lock on app open for lost-phone protection.
- Optional later: capture GPS at collection time (permission prompt, stored as metadata only).

## UX rules
- One-hand mobile layout, minimum 48px tap targets, Bengali-first labels, large amount input with numeric keypad (`inputmode="numeric"`).
- Quick-fill buttons: "full due" and "partial".
- Always show: expected, collected, remaining for today, and unsynced count.
- Confirm before submit; after submit show receipt with share/print option.

## Testing
- Feature tests for the sync endpoint: duplicate uuid, partial batch failure, other officer's party, locked day.
- Manual checklist in docs/ROADMAP.md: install on Android Chrome, go offline, collect, reconnect, verify single posting.