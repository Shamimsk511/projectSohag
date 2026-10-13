# Field PIN Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every user of the field PWA must set a device-local PIN; the app locks on open and after 5 minutes idle or away, online and offline.

**Architecture:** Server only adds markup (lock screen, strings, a `pin-pending` class set by an inline head script so nothing flashes) to the field layout for `/field` paths. A new `resources/js/field/pin.js` stores a PBKDF2 hash per user in a new IndexedDB store `pins`, renders setup/unlock on the keypad, tracks idle time in `sessionStorage`, and on 5 wrong tries or "forgot PIN" signs the user out (online) or leaves a `locked` record (offline) so a still-valid session can never set a new PIN without the password. `field.js` locks live pages; `offline-page.js` unlocks with the snapshot user's PIN before rendering anything.

**Tech Stack:** Laravel 13 Blade, vanilla JS (Vite `field` entry), IndexedDB, WebCrypto PBKDF2, Bootstrap 5 CSS, PHPUnit, Playwright MCP for the browser check.

**Spec:** docs/superpowers/specs/2026-10-13-field-pin-lock-design.md

## Global Constraints

- Field entry stays light: no jQuery, no new npm packages, no CDN. Vanilla DOM, `textContent` only (never `innerHTML` with data).
- UI text only via `lang/{en,bn}/app.php` (new group `pin`); `LangFilesTest` enforces en/bn parity.
- Lock applies only on `/field` paths (`request()->is('field', 'field/*')`). Admin pages that reuse `layouts.field` (`/admin/.../receipt`, `/admin/schedules/print`) are never locked.
- PIN 4-6 digits; Bengali digits accepted (`toAsciiDigits`). Hash: PBKDF2-SHA-256, 100000 iterations, 16-byte random salt, 256 bits, base64. PIN never stored.
- Idle/away limit 5 minutes; 5 wrong tries max.
- Never delete `pending_collections` items anywhere in this feature.
- Insecure context (no `crypto.subtle`, e.g. plain-http dev host) or no IndexedDB: the lock is skipped. Production is HTTPS-only (docs/ARCHITECTURE.md), where it always applies.
- Before any task commit: the working tree holds uncommitted Phase 6.8 work in the same files (`resources/js/field.js`, `lang/*/app.php`, `docs/ROADMAP.md`). Commit only if that work was committed first; otherwise leave this feature's changes uncommitted and say so in the handoff.
- Done = `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse` (0 errors), `npm run build` all pass.

---

### Task 1: Lock markup, strings and styles on the field layout

**Files:**
- Modify: `resources/views/layouts/field.blade.php`
- Modify: `resources/views/field/offline.blade.php` (add the "set a PIN online first" alert)
- Modify: `resources/css/field.css` (append lock styles)
- Modify: `lang/en/app.php`, `lang/bn/app.php` (new top-level `pin` group)
- Test: `tests/Feature/FieldPwaTest.php`

**Interfaces:**
- Produces (DOM contract used by Task 2):
  - `<html class="pin-pending">` added by inline head script on `/field` paths.
  - `body[data-pin-user]` (user id) and `body[data-pin-name]` (user name) on live signed-in `/field` pages only (not on `hide_logout` pages).
  - `#pin-lock` (hidden `d-none`) containing `[data-pin-title]`, `[data-pin-name]`, `[data-pin-dots]`, `[data-pin-message]`, `[data-pin-pad]` with buttons `[data-pin-key="0".."9"|"back"|"ok"]`, and `[data-pin-forgot]`.
  - `<script type="application/json" id="field-pin-strings">` = `__('app.pin')`.
  - Offline page: `[data-pin-missing]` alert (hidden `d-none`).
  - Lang keys `app.pin.*`: setup_title, confirm_title, unlock_title, too_short, mismatch, wrong (`:left`), forgot, forgot_confirm, locked_out, missing, back, ok.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/FieldPwaTest.php`)

```php
    public function test_field_pages_carry_the_pin_lock(): void
    {
        $this->withoutVite();
        $officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Rina Officer']);

        $this->actingAs($officer)->get('/field')->assertOk()
            ->assertSee("document.documentElement.classList.add('pin-pending')", false)
            ->assertSee('id="pin-lock"', false)
            ->assertSee('id="field-pin-strings"', false)
            ->assertSee('data-pin-user="'.$officer->id.'"', false)
            ->assertSee('data-pin-name="Rina Officer"', false);

        // Offline page: locked with the saved list's user (set by JS), never with the session user.
        $this->get(route('field.offline'))->assertOk()
            ->assertSee('id="pin-lock"', false)
            ->assertSee('data-pin-missing', false)
            ->assertDontSee('data-pin-user=', false);
    }

    public function test_admin_pages_on_the_field_layout_are_not_pin_locked(): void
    {
        $this->withoutVite();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->withRole(Role::Admin)->create())
            ->get(route('admin.schedules.print'))->assertOk()
            ->assertDontSee('id="pin-lock"', false)
            ->assertDontSee('pin-pending', false);
    }
```

(If `FieldPwaTest` already seeds roles in another way, follow that; `withRole` needs the role rows. Add `use Database\Seeders\RolesAndPermissionsSeeder;` at the top instead of the FQCN if the file imports seeders elsewhere.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter='pin_lock|not_pin_locked'`
Expected: FAIL (`id="pin-lock"` not found).

- [ ] **Step 3: Add lang strings**

In `lang/en/app.php`, add a top-level group (next to `'queue' => [`):

```php
    'pin' => [
        'setup_title' => 'Set a PIN for this phone',
        'confirm_title' => 'Enter the same PIN again',
        'unlock_title' => 'Enter your PIN',
        'too_short' => 'Use 4 to 6 digits.',
        'mismatch' => 'The two PINs did not match. Try again.',
        'wrong' => 'Wrong PIN. :left tries left.',
        'forgot' => 'Forgot PIN?',
        'forgot_confirm' => 'Sign out and sign in again with your password? Collections not yet synced stay on this phone.',
        'locked_out' => 'The PIN was reset. Connect to the internet and sign in again with your password. Collections not yet synced stay on this phone.',
        'missing' => 'Open the app once while online to set a PIN. The saved list stays hidden until then.',
        'back' => 'Delete',
        'ok' => 'OK',
    ],
```

In `lang/bn/app.php`, same keys:

```php
    'pin' => [
        'setup_title' => 'এই ফোনের জন্য একটি পিন দিন',
        'confirm_title' => 'একই পিন আবার দিন',
        'unlock_title' => 'আপনার পিন দিন',
        'too_short' => '৪ থেকে ৬ অঙ্ক দিন।',
        'mismatch' => 'দুটি পিন মেলেনি। আবার চেষ্টা করুন।',
        'wrong' => 'ভুল পিন। আর :left বার চেষ্টা করা যাবে।',
        'forgot' => 'পিন ভুলে গেছেন?',
        'forgot_confirm' => 'সাইন আউট করে পাসওয়ার্ড দিয়ে আবার সাইন ইন করবেন? সিঙ্ক না হওয়া আদায় এই ফোনেই থাকবে।',
        'locked_out' => 'পিন বাতিল হয়েছে। ইন্টারনেটে যুক্ত হয়ে পাসওয়ার্ড দিয়ে আবার সাইন ইন করুন। সিঙ্ক না হওয়া আদায় এই ফোনেই থাকবে।',
        'missing' => 'পিন দিতে একবার অনলাইনে অ্যাপটি খুলুন। ততক্ষণ সংরক্ষিত তালিকা দেখা যাবে না।',
        'back' => 'মুছুন',
        'ok' => 'ঠিক আছে',
    ],
```

- [ ] **Step 4: Layout markup**

In `resources/views/layouts/field.blade.php`:

1. First line inside `<head>` before `<meta charset>` is fine to keep; add right after the `<title>` line:

```blade
    @php($pinLock = request()->is('field', 'field/*'))
    @if ($pinLock)
        {{-- PIN lock (resources/js/field/pin.js): hide the page before it paints; JS removes the class once unlocked. --}}
        <script>document.documentElement.classList.add('pin-pending');</script>
    @endif
```

2. On `<body ...>` add, after the `data-user-id` attribute block (inside the same tag):

```blade
    @if ($pinLock) @auth @sectionMissing('hide_logout') data-pin-user="{{ auth()->id() }}" data-pin-name="{{ auth()->user()->name }}" @endif @endauth @endif
```

3. After `<script type="application/json" id="field-strings">...</script>` and before `</body>`:

```blade
    @if ($pinLock)
        <div id="pin-lock" class="pin-lock d-none" role="dialog" aria-modal="true" aria-labelledby="pin-lock-title">
            <div class="pin-lock-inner">
                <i class="bi bi-shield-lock display-5 text-primary" aria-hidden="true"></i>
                <h1 id="pin-lock-title" class="h4 mt-2 mb-1" data-pin-title></h1>
                <div class="text-body-secondary" data-pin-name></div>
                <div class="pin-dots" data-pin-dots aria-live="polite"></div>
                <div class="text-danger mb-2" role="alert" data-pin-message></div>
                <div class="pin-pad" data-pin-pad>
                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digit)
                        <button type="button" class="btn btn-outline-primary" data-pin-key="{{ $digit }}">{{ \App\Support\Numerals::localize($digit) }}</button>
                    @endforeach
                    <button type="button" class="btn btn-outline-secondary" data-pin-key="back" aria-label="{{ __('app.pin.back') }}">
                        <i class="bi bi-backspace" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-pin-key="0">{{ \App\Support\Numerals::localize('0') }}</button>
                    <button type="button" class="btn btn-primary" data-pin-key="ok">{{ __('app.pin.ok') }}</button>
                </div>
                <button type="button" class="btn btn-link mt-3" data-pin-forgot>{{ __('app.pin.forgot') }}</button>
            </div>
        </div>
        <script type="application/json" id="field-pin-strings">@json(__('app.pin'))</script>
    @endif
```

- [ ] **Step 5: Offline page alert**

In `resources/views/field/offline.blade.php`, as the first element inside `@section('content')` (before `<div class="d-none" data-offline-today ...>`):

```blade
    {{-- Shown by pin.js when the saved list's user has no PIN on this phone yet (list stays hidden). --}}
    <div class="alert alert-warning d-none" role="status" data-pin-missing>{{ __('app.pin.missing') }}</div>
```

- [ ] **Step 6: Styles** (append to `resources/css/field.css`)

```css
/* PIN lock (resources/js/field/pin.js): page content stays hidden until unlocked. */
.pin-pending body > header,
.pin-pending body > main {
    visibility: hidden;
}

.pin-lock {
    position: fixed;
    inset: 0;
    z-index: 1090;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    overflow-y: auto;
    background: var(--bs-body-bg);
}

.pin-lock-inner {
    width: 100%;
    max-width: 20rem;
    text-align: center;
}

.pin-dots {
    min-height: 3rem;
    margin: 1rem 0 0.5rem;
    font-size: 2rem;
    letter-spacing: 0.5rem;
}

.pin-pad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
}

.pin-pad .btn {
    min-height: 64px;
    font-size: 1.5rem;
}
```

(`.d-none` is `display: none !important`, so it still hides `.pin-lock`.)

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/FieldPwaTest.php tests/Feature/LangFilesTest.php`
Expected: PASS.

- [ ] **Step 8: Commit** (only if Phase 6.8 work is already committed; see Global Constraints)

```bash
git add resources/views/layouts/field.blade.php resources/views/field/offline.blade.php resources/css/field.css lang/en/app.php lang/bn/app.php tests/Feature/FieldPwaTest.php
git commit -m "feat: field PIN lock markup and strings"
```

---

### Task 2: PIN store, lock logic and wiring

**Files:**
- Modify: `resources/js/field/db.js` (VERSION 3, store `pins`)
- Create: `resources/js/field/pin.js`
- Modify: `resources/js/field.js` (lock live pages)
- Modify: `resources/js/field/offline-page.js` (unlock before rendering the snapshot)

**Interfaces:**
- Consumes: DOM contract and `app.pin` strings from Task 1; `clearToday`, `loadToday` (db.js); `localizeDigits`, `toAsciiDigits` (queue.js).
- Produces:
  - db.js: `getPin(userId) -> Promise<record|undefined>`, `savePin(record) -> Promise`, `deletePin(userId) -> Promise`. Record: `{ user_id, user_name, salt, hash, failed, created_at }` or `{ user_id, locked: true }`.
  - pin.js: `requirePin(userId, userName, { live }) -> Promise<'unlocked'|'missing'|'locked_out'>`, `releaseLock() -> void`.

- [ ] **Step 1: db.js store**

In `resources/js/field/db.js`: update the header comment list with
` * - \`pins\`: one record per user_id, the hashed field PIN (resources/js/field/pin.js).`, set `const VERSION = 3;`, add `const PINS = 'pins';`, inside `onupgradeneeded` add

```js
            if (!db.objectStoreNames.contains(PINS)) {
                db.createObjectStore(PINS, { keyPath: 'user_id' });
            }
```

and at the end of the file:

```js
/** Field PIN record of one user ({ user_id, user_name, salt, hash, failed, created_at } or { user_id, locked: true }). */
export const getPin = (userId) => run(PINS, 'readonly', (store) => store.get(userId));
export const savePin = (record) => run(PINS, 'readwrite', (store) => store.put(record));
export const deletePin = (userId) => run(PINS, 'readwrite', (store) => store.delete(userId));
```

- [ ] **Step 2: Create `resources/js/field/pin.js`**

```js
/**
 * Field PIN lock (docs/superpowers/specs/2026-10-13-field-pin-lock-design.md). One PIN per user on this
 * phone, PBKDF2-hashed in IndexedDB. Locks on app open (new tab: no sessionStorage marker) and after
 * 5 minutes away or idle. Guards against casual access to a lost or borrowed phone; stored data is not
 * encrypted. 5 wrong tries or "forgot PIN" end the session (online) or leave a `locked` record (offline),
 * so a session that is still valid can never set a new PIN without the password.
 */
import { clearToday, deletePin, getPin, savePin } from './db.js';
import { localizeDigits, toAsciiDigits } from './queue.js';

const IDLE_MS = 5 * 60 * 1000;
const MAX_TRIES = 5;
const ITERATIONS = 100000;
const ACTIVE_KEY = 'field-pin-active'; // sessionStorage "userId:timestamp" of the last activity while unlocked

let strings = null;

function t(key, replacements = {}) {
    strings ??= JSON.parse(document.getElementById('field-pin-strings')?.textContent || '{}');
    return Object.entries(replacements).reduce(
        (text, [name, value]) => text.replace(`:${name}`, value),
        strings[key] ?? key,
    );
}

const toBase64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)));
const fromBase64 = (text) => Uint8Array.from(atob(text), (character) => character.charCodeAt(0));

async function hashPin(pin, salt) {
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits']);
    const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations: ITERATIONS }, key, 256);
    return toBase64(bits);
}

function session(action) {
    try {
        return action(window.sessionStorage);
    } catch {
        return null;
    }
}

const isLocked = () => document.documentElement.classList.contains('pin-pending');
const markActive = (userId) => session((storage) => storage.setItem(ACTIVE_KEY, `${userId}:${Date.now()}`));
const forgetActive = () => session((storage) => storage.removeItem(ACTIVE_KEY));

function recentlyActive(userId) {
    const [id, at] = (session((storage) => storage.getItem(ACTIVE_KEY)) ?? '').split(':');
    return Number(id) === userId && Date.now() - Number(at) < IDLE_MS;
}

function showLock(show) {
    document.getElementById('pin-lock')?.classList.toggle('d-none', !show);
    document.documentElement.classList.toggle('pin-pending', show);
}

/** Shows the page without a lock (nothing to protect on this page, or no PIN support). */
export function releaseLock() {
    showLock(false);
}

/** Asks for 4-6 digits on the keypad (or keyboard). Resolves with the digits, or null for "forgot PIN". */
function ask(title, name, message = '', { allowForgot = true } = {}) {
    const root = document.getElementById('pin-lock');
    const dots = root.querySelector('[data-pin-dots]');
    const messageBox = root.querySelector('[data-pin-message]');
    root.querySelector('[data-pin-title]').textContent = title;
    root.querySelector('[data-pin-name]').textContent = name ?? '';
    root.querySelector('[data-pin-pad]').classList.remove('d-none');
    root.querySelector('[data-pin-forgot]').classList.toggle('d-none', !allowForgot);
    messageBox.textContent = message;
    let value = '';
    const render = () => {
        dots.textContent = '●'.repeat(value.length) || ' ';
    };
    render();
    showLock(true);

    return new Promise((resolve) => {
        const finish = (result) => {
            root.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        };
        const press = (key) => {
            if (key === 'ok') {
                if (value.length >= 4) {
                    finish(value);
                } else {
                    messageBox.textContent = t('too_short');
                }
                return;
            }
            if (key === 'back') {
                value = value.slice(0, -1);
            } else if (/^\d$/.test(key) && value.length < 6) {
                value += key;
            }
            render();
        };
        const onClick = (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const key = target?.closest('[data-pin-key]');
            if (key) {
                press(key.dataset.pinKey);
            } else if (target?.closest('[data-pin-forgot]')) {
                finish(null);
            }
        };
        const onKey = (event) => {
            const key = toAsciiDigits(event.key);
            if (/^\d$/.test(key)) {
                press(key);
            } else if (event.key === 'Backspace') {
                press('back');
            } else if (event.key === 'Enter') {
                press('ok');
            } else {
                return;
            }
            event.preventDefault();
        };
        root.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
    });
}

function postLogout() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/logout';
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    form.append(token);
    document.body.append(form);
    form.submit(); // form.submit() fires no submit event, so the .js-logout prompt is skipped on purpose
}

/**
 * Ends access after 5 wrong tries or "forgot PIN". Online on a live page: drop the PIN and sign out (the
 * password is needed to come back). Otherwise keep a `locked` record so no page shows data or offers a new
 * PIN until a live page signs the user out. The saved day list goes; queued collections never do.
 */
async function lockOut(userId, live) {
    forgetActive();
    await clearToday().catch(() => {});
    if (live && navigator.onLine) {
        await deletePin(userId).catch(() => {});
        postLogout();
        return 'locked_out';
    }
    await savePin({ user_id: userId, locked: true }).catch(() => {});
    const root = document.getElementById('pin-lock');
    root.querySelector('[data-pin-title]').textContent = t('unlock_title');
    root.querySelector('[data-pin-name]').textContent = '';
    root.querySelector('[data-pin-dots]').textContent = '';
    root.querySelector('[data-pin-message]').textContent = t('locked_out');
    root.querySelector('[data-pin-pad]').classList.add('d-none');
    root.querySelector('[data-pin-forgot]').classList.add('d-none');
    showLock(true);
    return 'locked_out';
}

async function setup(userId, userName) {
    let message = '';
    for (;;) {
        const first = await ask(t('setup_title'), userName, message, { allowForgot: false });
        const second = await ask(t('confirm_title'), userName, '', { allowForgot: false });
        if (first === second) {
            const salt = crypto.getRandomValues(new Uint8Array(16));
            await savePin({
                user_id: userId,
                user_name: userName,
                salt: toBase64(salt),
                hash: await hashPin(first, salt),
                failed: 0,
                created_at: new Date().toISOString(),
            });
            return;
        }
        message = t('mismatch');
    }
}

/** Resolves 'unlocked' or 'locked_out'. Wrong tries are counted in the stored record (survive reloads). */
async function unlock(record, live) {
    let current = record;
    let message = current.failed > 0 ? t('wrong', { left: localizeDigits(String(MAX_TRIES - current.failed)) }) : '';
    for (;;) {
        const pin = await ask(t('unlock_title'), current.user_name, message);
        if (pin === null) {
            if (window.confirm(t('forgot_confirm'))) {
                return lockOut(current.user_id, live);
            }
            continue;
        }
        if (await hashPin(pin, fromBase64(current.salt)) === current.hash) {
            if (current.failed > 0) {
                await savePin({ ...current, failed: 0 });
            }
            return 'unlocked';
        }
        current = { ...current, failed: current.failed + 1 };
        if (current.failed >= MAX_TRIES) {
            return lockOut(current.user_id, live);
        }
        await savePin(current);
        message = t('wrong', { left: localizeDigits(String(MAX_TRIES - current.failed)) });
    }
}

/** Relocks after 5 minutes idle or away; activity only counts while unlocked. */
function watchIdle(userId, live) {
    let checking = false;
    const touch = () => {
        if (!isLocked()) {
            markActive(userId);
        }
    };
    ['pointerdown', 'keydown', 'scroll'].forEach((type) => document.addEventListener(type, touch, { passive: true }));
    const check = async () => {
        if (checking || document.hidden || isLocked() || recentlyActive(userId)) {
            return;
        }
        checking = true;
        forgetActive();
        const record = await getPin(userId).catch(() => undefined);
        const outcome = record && !record.locked ? await unlock(record, live) : await lockOut(userId, live);
        if (outcome === 'unlocked') {
            showLock(false);
            markActive(userId);
        }
        checking = false;
    };
    document.addEventListener('visibilitychange', check);
    window.setInterval(check, 30 * 1000);
}

/**
 * Locks the page for this user until the PIN is entered (or set, the first time, on a live page).
 * `live`: a signed-in page that can sign out and may set a PIN; false on the offline page.
 */
export async function requirePin(userId, userName, { live }) {
    if (!('indexedDB' in window) || !window.crypto?.subtle) {
        releaseLock(); // insecure dev host or no storage: nothing stored to protect, production is HTTPS
        return 'unlocked';
    }
    const record = await getPin(userId).catch(() => undefined);
    if (record?.locked) {
        return lockOut(userId, live);
    }
    if (!record) {
        if (!live) {
            releaseLock();
            return 'missing';
        }
        await setup(userId, userName);
    } else if (!recentlyActive(userId) && await unlock(record, live) === 'locked_out') {
        return 'locked_out';
    }
    showLock(false);
    markActive(userId);
    watchIdle(userId, live);
    return 'unlocked';
}
```

- [ ] **Step 3: Wire live pages in `resources/js/field.js`**

Add the import next to the other `./field/*` imports:

```js
import { requirePin } from './field/pin.js';
```

and directly after the import block (before the `.js-confirm` submit listener):

```js
// PIN lock on live /field pages (the layout sets data-pin-user only there). Runs first so nothing shows unlocked.
const pinUser = Number(document.body.dataset.pinUser) || null;
if (pinUser) {
    requirePin(pinUser, document.body.dataset.pinName, { live: true });
}
```

- [ ] **Step 4: Wire the offline page in `resources/js/field/offline-page.js`**

Add the import: `import { releaseLock, requirePin } from './pin.js';` and replace `initOfflinePage` with:

```js
export function initOfflinePage() {
    const list = document.querySelector('[data-offline-today]');
    if (!list) {
        return;
    }
    if (!('indexedDB' in window)) {
        releaseLock();
        return;
    }
    loadToday().then(async (snapshot) => {
        if (!snapshot) {
            releaseLock(); // nothing saved: the generic offline text is all there is
            return;
        }
        const outcome = await requirePin(snapshot.user_id, '', { live: false });
        if (outcome === 'missing') {
            document.querySelector('[data-pin-missing]')?.classList.remove('d-none');
        } else if (outcome === 'unlocked') {
            start(list, snapshot);
        }
    }).catch(() => releaseLock());
}
```

(The unlock screen on the offline page shows the stored `user_name`; `requirePin` receives `''` and `unlock()` uses `record.user_name`.)

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: build succeeds, no errors.

- [ ] **Step 6: Browser check (Playwright MCP, phone width 390x844)**

Setup a scratch app on localhost (secure context for WebCrypto/IndexedDB):

```bash
SCRATCH=/tmp/claude-1001/-home-shamim-code-srijonshil/87256535-0d61-4234-9fa3-f74496946c2b/scratchpad
touch $SCRATCH/pin.sqlite
export DB_CONNECTION=sqlite DB_DATABASE=$SCRATCH/pin.sqlite
php artisan migrate:fresh --seed --force
php artisan tinker --execute 'foreach (["o1","o2"] as $n) { App\Models\User::factory()->withRole(App\Enums\Role::FieldOfficer)->create(["name" => "Officer ".$n, "email" => $n."@test.local", "password" => bcrypt("password")]); }'
php artisan serve --port=8123   # run in background with the same env
```

Check, in this order (override `window.confirm = () => true` before tapping "Forgot PIN"; Playwright intercepts dialogs):
1. Log in as o1@test.local -> `/field` shows only "Set a PIN"; header/main hidden. Enter 1234, confirm 1235 -> "did not match"; 1234 twice -> list shows.
2. Navigate to another `/field` page within 5 min -> no lock.
3. `sessionStorage.setItem('field-pin-active', '<id>:0')` then dispatch `visibilitychange` (or reload) -> lock; enter Bengali digits via keyboard `১২৩৪` -> unlocks.
4. New tab on `/field` -> lock (sessionStorage is per tab).
5. Enter a wrong PIN 5 times -> signed out to /login; IndexedDB `pending_collections` untouched (enqueue a test item before via the console to verify), `pins` has no o1 record. Log in again -> setup screen.
6. Go offline (`context.setOffline(true)`), open `/field` -> offline page asks for o1's PIN; correct PIN shows the saved list. Wrong x5 offline -> "PIN was reset" message, no list; back online, `/field` -> immediately signed out.
7. Log in as o2 on the same browser -> o2 sets own PIN; offline page after o2's home load locks with o2's PIN.
8. Admin receipt page / `/admin/schedules/print` as admin -> no lock.
9. Screenshot of lock screen at 390px: keypad fits, no horizontal scroll.

- [ ] **Step 7: Commit** (only if Phase 6.8 work is already committed)

```bash
git add resources/js/field/db.js resources/js/field/pin.js resources/js/field.js resources/js/field/offline-page.js
git commit -m "feat: mandatory device PIN lock on the field app"
```

---

### Task 3: Docs and final checks

**Files:**
- Modify: `docs/PWA.md` (Security section, line "Optional: PIN or device lock...")
- Modify: `docs/ROADMAP.md` (tick "Field PIN lock", handoff line)

- [ ] **Step 1: PWA.md**

Replace `- Optional: PIN or device lock on app open for lost-phone protection.` with:

```markdown
- Mandatory device PIN (`resources/js/field/pin.js`, IndexedDB store `pins`, PBKDF2): set on the first live `/field` page, asked on app open and after 5 minutes idle/away, also on the offline page (snapshot user's PIN; no PIN -> saved list hidden). 5 wrong tries or "forgot PIN" -> sign out (online) or a `locked` record until a live page signs out. Queue never deleted. Casual-access guard only; IndexedDB is not encrypted.
```

- [ ] **Step 2: ROADMAP.md**

Tick `- [ ] Field PIN lock` under Phase 7 and append to Handoff notes:

```markdown
- 2026-10-13: Field PIN lock: `resources/js/field/pin.js` + IndexedDB v3 store `pins` (PBKDF2 hash per user), lock markup/strings in `layouts.field` only on `/field` paths (`data-pin-user` on live pages, `app.pin`), offline page unlocks with the snapshot user's PIN. Skipped on insecure origins (no `crypto.subtle`): browser-check on localhost or HTTPS. No server PIN/reset by design.
```

- [ ] **Step 3: Full checks**

Run: `php artisan test --compact && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --no-progress && npm run build`
Expected: all pass, PHPStan 0 errors.

- [ ] **Step 4: Commit** (PWA.md always; ROADMAP.md only if the earlier uncommitted roadmap edits were committed first)

```bash
git add docs/PWA.md
git commit -m "docs: field PIN lock in PWA notes"
```
