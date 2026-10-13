# Field PIN lock (design)

Date: 2026-10-13. Phase 7, sub-project 1 of 3 (then WhatsApp manual share, then member portal).

## Goal
A lost or borrowed phone must not show an officer's day list, member phones or unsynced collections. Every user of the field PWA must set a PIN; the app locks on open and after inactivity. Works offline.

## Decisions
- Device-local lock only (option A). No server PIN, no office reset. The server session/login is unchanged.
- Mandatory: any live `/field` page (`body[data-user-id]`) with no PIN stored for that user shows a setup screen (PIN 4-6 digits, entered twice, Bengali digits normalised) and nothing else until done. Applies to admins on `/field` too.
- Storage: IndexedDB `srijonshil-field`, new store `pins` (keyPath `user_id`; DB VERSION bump). Record: user_id, salt (16 random bytes), hash (WebCrypto PBKDF2-SHA-256, 100k iterations, 256 bits), failed (count), created_at. The PIN itself is never stored. One PIN per user on the device.
- Lock moments: app open (new tab / cold start: `sessionStorage` has no unlock marker) and after 5 minutes without interaction or hidden (`visibilitychange`). Last activity kept in `sessionStorage`.
- No flash: the layout renders with `html.pin-pending` (inline class, CSS hides the main content) when the lock applies; JS removes it after unlock or shows the setup/lock screen. Without JS the class is never added (no-JS form flow keeps working; no JS = no IndexedDB data to protect).
- Unlock screen: full-screen numeric keypad (48px+ targets, one hand), user name shown.
- 5 wrong tries: PIN record deleted, then sign out (live page: POST logout; offline: message "sign in online again"). Queue (`pending_collections`) never deleted.
- "Forgot PIN": same as 5 wrong tries after a confirm.
- Offline page: locks with the PIN of the snapshot's user_id. No PIN stored for that user -> snapshot list and queue are not shown; message "open the app online once to set a PIN".
- Logout keeps the PIN (same officer signs in again without new setup). Another user on the phone sets their own.
- Strings from `app.pin` (en/bn), embedded with the existing `#field-strings` JSON.

## Components
- `resources/js/field/pin.js`: hash/verify (WebCrypto), get/save/delete PIN record, activity tracking, lock/setup/unlock screen rendering (DOM built with textContent only).
- `db.js`: VERSION bump + `pins` store.
- `field.js` / `offline-page.js`: call the lock before rendering any data; offline page waits for unlock before reading the snapshot.
- Field layout: `pin-pending` class, lock container markup, CSS in field.css.

## Limits (accepted)
- Protects against casual access. A technical attacker with the unlocked phone can read IndexedDB (not encrypted, existing non-goal).
- Forgetting the PIN while offline blocks collecting until back online.

## Testing
- No JS unit runner in the project: browser-verified with Playwright (setup, lock after timeout, unlock, wrong tries -> sign out with queue kept, forgot PIN, offline page lock and no-PIN case, second user).
- PHP: field layout test asserts lock markup/strings present on live and offline pages.

## Non-goals
Server-side PIN, office reset, biometrics, encrypting IndexedDB, member portal PIN (designed with the portal).
