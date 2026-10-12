/**
 * Offline collection queue helpers (docs/PWA.md "Client queue"): item records, amount parsing,
 * display formatting and the queue status/row badges. Display only: the server owns every total.
 */
import { enqueue, queuedFor } from './db.js';

const BENGALI_DIGITS = '০১২৩৪৫৬৭৮৯';

let cachedStrings = null;

/** UI strings from `app.queue`, embedded by the field layout. */
export function t(key, replacements = {}) {
    cachedStrings ??= JSON.parse(document.getElementById('field-strings')?.textContent || '{}');
    return Object.entries(replacements).reduce(
        (text, [name, value]) => text.replace(`:${name}`, value),
        cachedStrings[key] ?? key,
    );
}

export const localizeDigits = (text) => (document.documentElement.lang === 'bn'
    ? text.replace(/[0-9]/g, (digit) => BENGALI_DIGITS[digit])
    : text);

/** Fills every [data-field] inside root whose name is in values (text only, never HTML). */
export function fill(root, values) {
    root.querySelectorAll('[data-field]').forEach((element) => {
        if (element.dataset.field in values) {
            element.textContent = values[element.dataset.field] ?? '';
        }
    });
}

/** Same output as App\Support\Money::format(): "৳" + lakh grouping + locale digits. */
export function formatPoisha(poisha) {
    const absolute = Math.abs(poisha);
    const whole = String(Math.floor(absolute / 100));
    let grouped = whole.slice(-3);
    for (let rest = whole.slice(0, -3); rest !== ''; rest = rest.slice(0, -2)) {
        grouped = `${rest.slice(-2)},${grouped}`;
    }
    const fraction = String(absolute % 100).padStart(2, '0');
    return `${poisha < 0 ? '-' : ''}৳${localizeDigits(`${grouped}.${fraction}`)}`;
}

/** Taka input (Bengali digits and commas allowed) -> { taka: "1234.5", poisha: 123450 }, or null if not > 0. */
export function parseTaka(input) {
    const taka = String(input)
        .replace(/[০-৯]/g, (digit) => String(BENGALI_DIGITS.indexOf(digit)))
        .replace(/[,\s]/g, '');
    const match = taka.match(/^(\d+)(?:\.(\d{1,2}))?$/);
    if (!match) {
        return null;
    }
    const poisha = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
    return poisha > 0 && Number.isSafeInteger(poisha) ? { taka, poisha } : null;
}

/** taka string for poisha, as the full-due quick fill puts it into the amount input. */
export const poishaToTaka = (poisha) => (poisha / 100).toFixed(2);

/** RFC 4122 v4 uuid; crypto.randomUUID needs a secure context, getRandomValues does not. */
export function uuid() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Stores a new pending collection and returns the record; `store` overrides how it is saved (e.g. replacing a rejected item). */
export async function queueCollection({ userId, clientUuid, scheduleId, accountId, partyName, accountNumber, amount, paymentMethod, description }, store = null) {
    const now = new Date();
    const item = {
        client_uuid: clientUuid.toLowerCase(),
        user_id: Number(userId),
        schedule_id: Number(scheduleId),
        account_id: Number(accountId),
        party_name: partyName,
        account_number: accountNumber,
        amount: amount.taka,
        amount_poisha: amount.poisha,
        payment_method: paymentMethod,
        description: description || null,
        collected_at: now.toISOString(),
        state: 'pending',
        attempts: 0,
        last_error: null,
        result: null,
        rejection: null,
        updated_at: now.getTime(),
    };
    await (store ?? enqueue)(item);
    return item;
}

const RUN_MESSAGES = {
    offline: 'offline',
    failed: 'failed',
    unauthenticated: 'sign_in',
    reload: 'reload',
    syncing: 'syncing',
};

/**
 * Shows pending/rejected counts and the last sync outcome, and marks rows with unsynced amounts
 * ([data-schedule-card] > [data-unsynced]). Returns the user's queue.
 */
export async function renderQueue(userId, runStatus = null, flash = null) {
    const items = await queuedFor(Number(userId));
    const pending = items.filter((item) => item.state === 'pending');
    const rejected = items.filter((item) => item.state === 'rejected');

    const unsynced = new Map();
    pending.forEach((item) => unsynced.set(item.schedule_id, (unsynced.get(item.schedule_id) ?? 0) + item.amount_poisha));
    document.querySelectorAll('[data-schedule-card]').forEach((card) => {
        const badge = card.querySelector('[data-unsynced]');
        const amount = unsynced.get(Number(card.dataset.scheduleCard));
        if (badge) {
            badge.textContent = amount ? `${t('not_synced')}: ${formatPoisha(amount)}` : '';
            badge.classList.toggle('d-none', !amount);
        }
    });

    const status = document.querySelector('[data-queue-status]');
    if (status) {
        const lines = [
            flash,
            pending.length ? t('pending_count', { count: localizeDigits(String(pending.length)) }) : null,
            rejected.length ? t('rejected_count', { count: localizeDigits(String(rejected.length)) }) : null,
            RUN_MESSAGES[runStatus] && (pending.length || runStatus === 'unauthenticated') ? t(RUN_MESSAGES[runStatus]) : null,
        ].filter(Boolean);
        status.querySelector('[data-queue-message]').replaceChildren(...lines.map((line) => {
            const div = document.createElement('div');
            div.textContent = line;
            return div;
        }));
        status.classList.toggle('d-none', lines.length === 0);
        status.classList.toggle('alert-danger', rejected.length > 0);
        status.classList.toggle('alert-warning', rejected.length === 0 && pending.length > 0);
        status.classList.toggle('alert-info', rejected.length === 0 && pending.length === 0);
        status.querySelector('[data-queue-sign-in]')?.classList.toggle('d-none', runStatus !== 'unauthenticated');
        status.querySelector('[data-queue-details]')?.classList.toggle('d-none', pending.length + rejected.length === 0);
        const button = status.querySelector('.js-sync-now');
        if (button) {
            button.classList.toggle('d-none', pending.length === 0);
            button.disabled = runStatus === 'syncing';
        }
    }

    return items;
}
