/**
 * Field officer entry (mobile/PWA). Keep it light: no jQuery, DataTables,
 * ApexCharts, pdfmake or AdminLTE here; those belong to the admin entry.
 */
import './fonts.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import '../css/field.css';
import { clearToday, loadToday, queuedFor, removeQueued, saveToday } from './field/db.js';
import { initOfflinePage } from './field/offline-page.js';
import { localizeDigits, parseTaka, queueCollection, renderQueue, t, uuid } from './field/queue.js';
import { initSyncPage } from './field/sync-page.js';
import { pruneSynced, syncQueue } from './field/sync.js';

// Ask before saving forms that cannot be undone (collections are immutable).
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.matches('.js-confirm') && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

// "Full due" quick fill: copy the remaining amount into the amount input.
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('.js-full-due') : null;
    const input = button ? document.getElementById(button.dataset.target) : null;
    if (input) {
        input.value = input.dataset.fullDue;
        input.focus();
    }
});

// Receipt buttons: print, and share through the phone's share sheet where supported.
document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('.js-print')) {
        window.print();
    }
    const share = target?.closest('.js-share');
    if (share) {
        navigator.share({ title: share.dataset.shareTitle, text: share.dataset.shareText }).catch(() => {});
    }
});
if (navigator.share) {
    document.querySelectorAll('.js-share').forEach((button) => button.classList.remove('d-none'));
}

// Offline shell (public/field-sw.js). The layout only sets data-sw for built assets.
const serviceWorkerUrl = document.body.dataset.sw;
if (serviceWorkerUrl && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(serviceWorkerUrl, { scope: '/field' }).catch(() => {});
    });
}

// Offline day list: /field embeds a snapshot of the officer's rows; keep the latest copy in IndexedDB.
const todaySnapshot = document.getElementById('field-today');
if (todaySnapshot && 'indexedDB' in window) {
    saveToday(JSON.parse(todaySnapshot.textContent)).catch(() => {});
}

// Another user's saved list must never show on this phone (signed in without the previous user logging out).
if (!todaySnapshot && document.body.dataset.userId && 'indexedDB' in window) {
    loadToday().then((snapshot) => {
        if (snapshot && snapshot.user_id !== Number(document.body.dataset.userId)) {
            return clearToday();
        }
        return null;
    }).catch(() => {});
}

// Signing out: warn when this user still has unsynced or rejected collections (they are kept, never
// deleted here), then remove the saved day list from the phone.
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('.js-logout') || !('indexedDB' in window)) {
        return;
    }
    event.preventDefault();
    const signedIn = Number(document.body.dataset.userId);
    const open = signedIn
        ? (await queuedFor(signedIn).catch(() => [])).filter((item) => item.state !== 'synced').length
        : 0;
    if (open > 0 && !window.confirm(t('logout_pending', { count: localizeDigits(String(open)) }))) {
        return;
    }
    clearToday().catch(() => {}).finally(() => form.submit());
});

initOfflinePage();

// Offline queue sync: only on live signed-in pages (session cookie + this page's CSRF token).
const userId = Number(document.body.dataset.userId) || null;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
const RETRY_MIN_MS = 30 * 1000;
const RETRY_MAX_MS = 10 * 60 * 1000;
const RELOADED_KEY = 'field-sync-reloaded';
const FLASH_KEY = 'field-flash';
let retryDelay = RETRY_MIN_MS;
let retryTimer = null;

function storage(action) {
    try {
        return action(window.sessionStorage);
    } catch {
        return null;
    }
}

async function runSync({ resetBackoff = false } = {}) {
    clearTimeout(retryTimer);
    if (resetBackoff) {
        retryDelay = RETRY_MIN_MS;
    }
    await renderQueue(userId, 'syncing').catch(() => {});

    let outcome;
    try {
        outcome = await syncQueue(userId, csrfToken);
    } catch {
        outcome = { status: 'failed', results: new Map() };
    }

    // The day list shows server figures: reload it once anything was recorded.
    const recorded = [...outcome.results.values()].some((result) => result.status === 'created' || result.status === 'duplicate');
    if (recorded && todaySnapshot) {
        window.location.reload();
        return outcome;
    }

    let shown = outcome.status;
    if (outcome.status === 'ok') {
        retryDelay = RETRY_MIN_MS;
        storage((session) => session.removeItem(RELOADED_KEY));
    } else if (outcome.status === 'offline' || outcome.status === 'failed') {
        retryTimer = setTimeout(() => runSync(), retryDelay);
        retryDelay = Math.min(retryDelay * 2, RETRY_MAX_MS);
    } else if (outcome.status === 'stale_token') {
        // A fresh page brings a fresh CSRF token; reload once, then ask the officer.
        if (!storage((session) => session.getItem(RELOADED_KEY))) {
            storage((session) => session.setItem(RELOADED_KEY, '1'));
            window.location.reload();
            return outcome;
        }
        shown = 'reload';
    }
    await renderQueue(userId, shown).catch(() => {});
    document.dispatchEvent(new CustomEvent('field:queue-changed'));
    return outcome;
}

if (userId && csrfToken && 'indexedDB' in window) {
    const flash = storage((session) => session.getItem(FLASH_KEY));
    storage((session) => session.removeItem(FLASH_KEY));
    renderQueue(userId, null, flash ? t(flash) : null).catch(() => {});
    pruneSynced(userId).catch(() => {}).finally(() => runSync());
    window.addEventListener('online', () => runSync({ resetBackoff: true }));
    initSyncPage(userId, runSync);
    document.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('.js-sync-now')) {
            runSync({ resetBackoff: true });
        }
    });

    // Collect form goes through the queue too, so a dropped connection never loses the entry.
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.js-queue-collect') || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        const error = document.querySelector('[data-queue-error]');
        const showError = (message) => {
            error.textContent = message;
            error.classList.remove('d-none');
            window.scrollTo(0, 0);
        };
        const amount = parseTaka(form.elements.amount.value);
        if (!amount) {
            showError(t('invalid_amount'));
            return;
        }
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        error.classList.add('d-none');

        const clientUuid = form.elements.client_uuid.value.toLowerCase();
        try {
            await queueCollection({
                userId,
                clientUuid,
                scheduleId: form.dataset.scheduleId,
                accountId: form.dataset.accountId,
                partyName: form.dataset.partyName,
                accountNumber: form.dataset.accountNumber,
                amount,
                paymentMethod: form.elements.payment_method.value,
                description: form.elements.description.value.trim(),
            });
        } catch {
            form.submit(); // IndexedDB unusable (e.g. private mode): plain POST, still idempotent by client_uuid
            return;
        }

        const result = (await runSync({ resetBackoff: true })).results.get(clientUuid);
        if (result?.status === 'created' || result?.status === 'duplicate') {
            window.location.href = result.receipt_url;
        } else if (result?.status === 'rejected') {
            // The officer is still on the form: drop the item, show why, and allow a corrected resend.
            await removeQueued(clientUuid).catch(() => {});
            showError(Object.values(result.errors ?? {}).flat()[0] ?? result.message);
            form.elements.client_uuid.value = uuid();
            submit.disabled = false;
            renderQueue(userId).catch(() => {});
        } else {
            storage((session) => session.setItem(FLASH_KEY, 'saved_offline'));
            window.location.href = '/field';
        }
    });
}
