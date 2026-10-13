/**
 * Field PIN lock (docs/superpowers/specs/2026-10-13-field-pin-lock-design.md). One PIN per user on this
 * phone, PBKDF2-hashed in IndexedDB. Locks on app open (new tab: no sessionStorage marker) and after
 * 5 minutes away or idle. Guards against casual access to a lost or borrowed phone; stored data is not
 * encrypted. 5 wrong tries or "forgot PIN" always save a `locked` record (the PIN is never deleted) and, on a live
 * online page, sign out. A `locked` record signs out again on the next live page and is only replaced by
 * PIN setup on the first page after a password login (server flag data-pin-fresh-login), so a session that
 * is still valid can never set a new PIN without the password. Storage errors fail closed (showLockError).
 */
import { clearToday, getPin, savePin } from './db.js';
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

/** Fail closed: storage or crypto broke, so the page stays hidden behind a message (no keypad). */
export function showLockError() {
    const root = document.getElementById('pin-lock');
    if (!root) {
        return;
    }
    root.querySelector('[data-pin-title]').textContent = t('unlock_title');
    root.querySelector('[data-pin-name]').textContent = '';
    root.querySelector('[data-pin-dots]').textContent = '';
    root.querySelector('[data-pin-message]').textContent = t('error');
    root.querySelector('[data-pin-pad]').classList.add('d-none');
    root.querySelector('[data-pin-forgot]').classList.add('d-none');
    showLock(true);
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
        dots.textContent = '●'.repeat(value.length) || ' ';
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
 * Ends access after 5 wrong tries or "forgot PIN". Always saves a `locked` record first (never deletes the
 * PIN: if the sign-out fails, the still-valid session must not be offered PIN setup). Online on a live page
 * it then signs out; otherwise it shows the locked-out screen until a live page signs the user out. The
 * saved day list goes; queued collections never do.
 */
async function lockOut(userId, live) {
    await savePin({ user_id: userId, locked: true });
    await clearToday().catch(() => {});
    forgetActive();
    if (live && navigator.onLine) {
        postLogout();
        return 'locked_out';
    }
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
        showLock(true); // hide the page at once, before the PIN record is read
        try {
            const record = await getPin(userId);
            const outcome = record && !record.locked ? await unlock(record, live) : await lockOut(userId, live);
            if (outcome === 'unlocked') {
                showLock(false);
                markActive(userId);
            }
        } catch {
            showLockError();
        } finally {
            checking = false;
        }
    };
    document.addEventListener('visibilitychange', check);
    window.setInterval(check, 30 * 1000);
}

/**
 * Locks the page for this user until the PIN is entered (or set, the first time, on a live page).
 * `live`: a signed-in page that can sign out and may set a PIN; false on the offline page.
 */
export async function requirePin(userId, userName, { live, freshLogin = false }) {
    if (!('indexedDB' in window) || !window.crypto?.subtle) {
        releaseLock(); // insecure dev host or no storage: nothing stored to protect, production is HTTPS
        return 'unlocked';
    }
    let record = await getPin(userId);
    if (record?.locked && live && freshLogin) {
        // First page after a password login: set a new PIN. setup() overwrites the locked record only once the new
        // PIN is saved, so an interrupted setup leaves it locked (next page signs out again).
        record = undefined;
    } else if (record?.locked) {
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
