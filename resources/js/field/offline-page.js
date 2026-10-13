/**
 * Offline page (service worker fallback for /field): shows the saved day list and lets the officer
 * queue collections on its rows, with a provisional receipt. Nothing here talks to the server.
 */
import { loadToday } from './db.js';
import { releaseLock, requirePin, showLockError } from './pin.js';
import { fill, formatPoisha, parseTaka, poishaToTaka, queueCollection, renderQueue, uuid } from './queue.js';

const STATUS_BADGES = { paid: 'text-bg-success', partially_paid: 'text-bg-warning', missed: 'text-bg-danger' };

const details = (row) => [row.party_code, row.area, row.account_number].join(' · ');

function rowCard(template, row) {
    const fragment = template.content.cloneNode(true);
    fill(fragment, {
        ...row,
        details: details(row),
        expected: row.expected.label,
        collected: row.collected.label,
        remaining: row.remaining.label,
    });
    fragment.querySelector('.card').dataset.scheduleCard = row.schedule_id;
    fragment.querySelector('[data-field="status_label"]').classList.add(STATUS_BADGES[row.status] ?? 'text-bg-secondary');
    fragment.querySelector('[data-due]').classList.toggle('d-none', !row.earlier);
    if (row.party_phone) {
        const phone = fragment.querySelector('[data-phone]');
        phone.href = `tel:${row.party_phone}`;
        phone.classList.remove('d-none');
    }
    if (row.collectable) {
        const collect = fragment.querySelector('.js-offline-collect');
        collect.dataset.scheduleId = row.schedule_id;
        collect.classList.remove('d-none');
    }
    return fragment;
}

function renderList(list, snapshot) {
    const template = document.getElementById('offline-row');
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: list.dataset.timezone }).format(new Date());
    fill(list, { generated_label: snapshot.generated_label, ...snapshot.summary });
    list.querySelector('[data-stale]').classList.toggle('d-none', snapshot.date === today);
    list.querySelector('[data-closed]').classList.toggle('d-none', !snapshot.today_closed);
    const todayRows = snapshot.rows.filter((row) => !row.earlier);
    const earlierRows = snapshot.rows.filter((row) => row.earlier);
    list.querySelector('[data-rows="today"]').replaceChildren(...todayRows.map((row) => rowCard(template, row)));
    list.querySelector('[data-rows="earlier"]').replaceChildren(...earlierRows.map((row) => rowCard(template, row)));
    list.querySelector('[data-empty]').classList.toggle('d-none', todayRows.length > 0);
    list.querySelector('[data-earlier-title]').classList.toggle('d-none', earlierRows.length === 0);
}

function start(list, snapshot) {
    const collect = document.querySelector('[data-offline-collect]');
    const receipt = document.querySelector('[data-offline-receipt]');
    const form = collect.querySelector('.js-offline-collect-form');
    const amountInput = form.elements.amount;
    const fullDue = form.querySelector('.js-full-due');
    const rows = new Map(snapshot.rows.map((row) => [row.schedule_id, row]));
    const refresh = () => renderQueue(snapshot.user_id).catch(() => {});
    let current = null;

    const show = (section) => {
        [list, collect, receipt].forEach((element) => element.classList.toggle('d-none', element !== section));
        document.querySelector('[data-offline-generic]')?.classList.add('d-none');
        window.scrollTo(0, 0);
    };

    const open = (row) => {
        current = row;
        fill(collect, {
            party_name: row.party_name,
            details: details(row),
            expected: row.expected.label,
            collected: row.collected.label,
            remaining: row.remaining.label,
        });
        form.reset();
        amountInput.classList.remove('is-invalid');
        amountInput.dataset.fullDue = poishaToTaka(row.remaining.poisha);
        fullDue.classList.toggle('d-none', row.remaining.poisha <= 0);
        show(collect);
    };

    renderList(list, snapshot);
    show(list);
    refresh();

    list.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('.js-offline-collect') : null;
        if (button) {
            open(rows.get(Number(button.dataset.scheduleId)));
        }
    });
    document.querySelectorAll('.js-offline-back').forEach((button) => button.addEventListener('click', () => {
        show(list);
        refresh();
    }));

    // Document level, registered after the .js-confirm handler: a cancelled confirm arrives defaultPrevented.
    document.addEventListener('submit', async (event) => {
        if (event.target !== form || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        const amount = parseTaka(amountInput.value);
        amountInput.classList.toggle('is-invalid', !amount);
        if (!amount || !current) {
            return;
        }
        const item = await queueCollection({
            userId: snapshot.user_id,
            clientUuid: uuid(),
            scheduleId: current.schedule_id,
            accountId: current.account_id,
            partyName: current.party_name,
            accountNumber: current.account_number,
            amount,
            paymentMethod: form.elements.payment_method.value,
            description: form.elements.description.value.trim(),
        });
        fill(receipt, {
            party_name: item.party_name,
            account_number: item.account_number,
            amount_label: formatPoisha(item.amount_poisha),
            saved_at_label: new Intl.DateTimeFormat(document.documentElement.lang === 'bn' ? 'bn-BD' : 'en-GB', {
                timeZone: list.dataset.timezone, dateStyle: 'short', timeStyle: 'short',
            }).format(new Date(item.collected_at)),
        });
        show(receipt);
        refresh();
    });

    // Offline while opening a collect page: go straight to that row's form.
    const wanted = window.location.pathname.match(/^\/field\/schedules\/(\d+)\/collect$/);
    const row = wanted ? rows.get(Number(wanted[1])) : null;
    if (row?.collectable) {
        open(row);
    }
}

export function initOfflinePage() {
    const list = document.querySelector('[data-offline-today]');
    if (!list) {
        return;
    }
    if (!('indexedDB' in window)) {
        releaseLock();
        return;
    }
    loadToday().catch(() => null).then(async (snapshot) => {
        if (!snapshot) {
            releaseLock(); // nothing saved (or unreadable): the generic offline text is all there is
            return;
        }
        const outcome = await requirePin(snapshot.user_id, '', { live: false });
        if (outcome === 'missing') {
            document.querySelector('[data-pin-missing]')?.classList.remove('d-none');
        } else if (outcome === 'unlocked') {
            start(list, snapshot);
        }
    }).catch(showLockError);
}
