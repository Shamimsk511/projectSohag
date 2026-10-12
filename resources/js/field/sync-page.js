/**
 * Sync status page (/field/sync): this phone's queue for the signed-in officer, with "fix and resend"
 * for amount rejections and "discard" for everything the office has to handle (docs/PWA.md).
 */
import { queuedFor, removeQueued, replaceQueued } from './db.js';
import { fill, formatPoisha, localizeDigits, parseTaka, poishaToTaka, queueCollection, renderQueue, t, uuid } from './queue.js';

/** Rejections the officer can fix by changing the amount; anything else goes through the office. */
const FIXABLE = new Set(['invalid', 'amount_not_positive', 'exceeds_outstanding', 'exceeds_balance']);
const STATES = ['rejected', 'pending', 'synced'];

export function initSyncPage(userId, runSync) {
    const page = document.querySelector('[data-sync-page]');
    if (!page) {
        return;
    }
    const template = document.getElementById('queue-item');
    const dateTime = new Intl.DateTimeFormat(document.documentElement.lang === 'bn' ? 'bn-BD' : 'en-GB', {
        timeZone: page.dataset.timezone, dateStyle: 'short', timeStyle: 'short',
    });
    let items = new Map();

    const card = (item) => {
        const fragment = template.content.cloneNode(true);
        const root = fragment.querySelector('.card');
        root.dataset.clientUuid = item.client_uuid;
        fill(fragment, {
            party_name: item.party_name,
            account_number: item.account_number,
            amount_label: formatPoisha(item.amount_poisha),
            collected_at_label: dateTime.format(new Date(item.collected_at)),
            tries: t('tries', { count: localizeDigits(String(item.attempts)) }),
            updated_at_label: dateTime.format(new Date(item.updated_at)),
            reason: item.rejection?.message ?? '',
            receipt_no: item.result?.receipt_no ?? '',
        });
        fragment.querySelector('[data-pending-info]').classList.toggle('d-none', item.state !== 'pending' || item.attempts === 0);

        if (item.state === 'rejected') {
            const fixable = FIXABLE.has(item.rejection?.code);
            fragment.querySelector('[data-rejection]').classList.remove('d-none');
            fragment.querySelector('[data-errors]').replaceChildren(...Object.values(item.rejection?.errors ?? {}).flat().map((message) => {
                const li = document.createElement('li');
                li.textContent = message;
                return li;
            }));
            fragment.querySelector('[data-office-only]').classList.toggle('d-none', fixable);
            fragment.querySelector('[data-rejected-actions]').classList.remove('d-none');
            fragment.querySelector('.js-queue-fix').classList.toggle('d-none', !fixable);
        }
        if (item.state === 'synced' && item.result?.receipt_url) {
            const receipt = fragment.querySelector('[data-receipt]');
            receipt.href = item.result.receipt_url;
            receipt.classList.remove('d-none');
        }
        return fragment;
    };

    const render = async () => {
        const all = (await queuedFor(userId)).sort((a, b) => b.collected_at.localeCompare(a.collected_at));
        items = new Map(all.map((item) => [item.client_uuid, item]));
        STATES.forEach((state) => {
            const group = all.filter((item) => item.state === state);
            const section = page.querySelector(`[data-queue-section="${state}"]`);
            section.querySelector('[data-queue-items]').replaceChildren(...group.map(card));
            section.classList.toggle('d-none', group.length === 0);
        });
        page.querySelector('[data-queue-empty]').classList.toggle('d-none', all.length > 0);
    };
    const refresh = () => Promise.all([render(), renderQueue(userId)]).catch(() => {});

    const itemOf = (element) => {
        const root = element.closest('[data-client-uuid]');
        return root ? { root, item: items.get(root.dataset.clientUuid) } : {};
    };

    page.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }
        if (target.closest('.js-queue-fix')) {
            const { root, item } = itemOf(target);
            const form = root.querySelector('.js-queue-fix-form');
            form.elements.amount.value = poishaToTaka(item.amount_poisha);
            form.elements.amount.classList.remove('is-invalid');
            form.elements.payment_method.value = item.payment_method;
            form.elements.description.value = item.description ?? '';
            form.classList.remove('d-none');
            form.elements.amount.focus();
        } else if (target.closest('.js-queue-fix-cancel')) {
            target.closest('.js-queue-fix-form').classList.add('d-none');
        } else if (target.closest('.js-queue-discard')) {
            const button = target.closest('.js-queue-discard');
            const { item } = itemOf(target);
            if (item && window.confirm(button.dataset.confirm)) {
                await removeQueued(item.client_uuid);
                refresh();
            }
        }
    });

    page.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.js-queue-fix-form')) {
            return;
        }
        event.preventDefault();
        const { item } = itemOf(form);
        const amount = parseTaka(form.elements.amount.value);
        form.elements.amount.classList.toggle('is-invalid', !amount);
        if (!amount || !item) {
            return;
        }
        // A corrected entry is a new collection to the server: new client_uuid, same row and officer.
        await queueCollection({
            userId,
            clientUuid: uuid(),
            scheduleId: item.schedule_id,
            accountId: item.account_id,
            partyName: item.party_name,
            accountNumber: item.account_number,
            amount,
            paymentMethod: form.elements.payment_method.value,
            description: form.elements.description.value.trim(),
        }, (replacement) => replaceQueued(item.client_uuid, replacement));
        await refresh();
        runSync({ resetBackoff: true });
    });

    // Background sync runs re-render, except while the officer is editing a fix form.
    document.addEventListener('field:queue-changed', () => {
        if (!page.querySelector('.js-queue-fix-form:not(.d-none)')) {
            refresh();
        }
    });
    refresh();
}
