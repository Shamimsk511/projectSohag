/**
 * Admin top bar: member search suggestions and the Dhaka clock. Imported by admin.js, so it runs on
 * every admin page; both parts do nothing when their markup is absent.
 */

const DEBOUNCE_MS = 250;

function initSearch(form) {
    const input = form.querySelector('input[name="q"]');
    const list = form.querySelector('[role="listbox"]');
    let timer = null;
    let controller = null;
    let active = -1;

    const close = () => {
        list.classList.remove('show');
        input.setAttribute('aria-expanded', 'false');
        active = -1;
    };

    const items = () => [...list.querySelectorAll('a.dropdown-item')];

    const highlight = (index) => {
        const options = items();
        options.forEach((option, i) => option.classList.toggle('active', i === index));
        active = index;
        options[index]?.scrollIntoView({ block: 'nearest' });
    };

    // textContent only: names come from user input.
    const render = (results) => {
        list.replaceChildren();

        if (results.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'dropdown-item-text text-body-secondary';
            empty.textContent = form.dataset.empty;
            list.append(empty);
        }

        results.forEach((result) => {
            const link = document.createElement('a');
            link.className = 'dropdown-item py-2';
            link.href = result.url;
            link.setAttribute('role', 'option');

            const name = document.createElement('div');
            name.className = 'fw-semibold';
            name.textContent = result.name;

            const meta = document.createElement('div');
            meta.className = 'small text-body-secondary';
            meta.textContent = [result.code, result.client_number, result.phone, result.area].filter(Boolean).join(' · ');

            link.append(name, meta);
            list.append(link);
        });

        list.classList.add('show');
        input.setAttribute('aria-expanded', 'true');
        active = -1;
    };

    const lookup = async () => {
        const term = input.value.trim();
        controller?.abort();

        if (term.length < 2) {
            close();
            return;
        }

        controller = new AbortController();
        try {
            const url = new URL(form.action);
            url.searchParams.set('q', term);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (response.ok) {
                render((await response.json()).results);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                close();
            }
        }
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(lookup, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', (event) => {
        const options = items();
        if (event.key === 'ArrowDown' && options.length) {
            event.preventDefault();
            highlight(Math.min(active + 1, options.length - 1));
        } else if (event.key === 'ArrowUp' && options.length) {
            event.preventDefault();
            highlight(Math.max(active - 1, 0));
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            window.location.assign(options[active].href);
        } else if (event.key === 'Escape') {
            close();
        }
    });

    document.addEventListener('click', (event) => {
        if (!form.contains(event.target)) {
            close();
        }
    });

    // "/" focuses the search from anywhere outside a field.
    document.addEventListener('keydown', (event) => {
        if (event.key === '/' && !event.target.closest('input, textarea, select, [contenteditable]')) {
            event.preventDefault();
            input.focus();
        }
    });
}

function initClock(clock) {
    const locale = document.documentElement.lang || 'bn';
    const options = { timeZone: clock.dataset.timezone };
    const date = new Intl.DateTimeFormat(locale, { ...options, day: 'numeric', month: 'long', year: 'numeric' });
    const day = new Intl.DateTimeFormat(locale, { ...options, weekday: 'long' });
    const time = new Intl.DateTimeFormat(locale, { ...options, hour: 'numeric', minute: '2-digit' });

    const tick = () => {
        const now = new Date();
        clock.querySelector('[data-clock-date]').textContent = date.format(now);
        clock.querySelector('[data-clock-day]').textContent = day.format(now);
        const timeElement = clock.querySelector('[data-clock-time]');
        timeElement.textContent = time.format(now);
        timeElement.dateTime = now.toISOString();
    };

    tick();
    setInterval(tick, 15_000);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form.js-global-search').forEach(initSearch);
    document.querySelectorAll('.js-clock').forEach(initClock);
});
