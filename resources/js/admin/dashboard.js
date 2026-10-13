/**
 * Dashboard: while looking at today, re-fetch the live fragment every minute (only when the tab is
 * visible). The server renders it; the page just swaps the HTML in. Close buttons on paid-off loans ask first.
 */
import { initConfirmForms } from './confirm.js';

const INTERVAL_MS = 60_000;

document.addEventListener('DOMContentLoaded', () => {
    initConfirmForms();

    const live = document.getElementById('dashboard-live');
    if (!live || live.dataset.liveRefresh !== '1') {
        return;
    }

    let busy = false;

    // Sections the user expanded (officer member lists, details) stay open after a swap.
    const reopen = (ids) => {
        ids.forEach((id) => {
            document.getElementById(id)?.classList.add('show');
            live.querySelectorAll(`[data-bs-target="#${CSS.escape(id)}"]`).forEach((toggle) => {
                toggle.classList.remove('collapsed');
                toggle.setAttribute('aria-expanded', 'true');
            });
        });
    };

    const refresh = async () => {
        if (busy || document.hidden) {
            return;
        }

        busy = true;
        try {
            const response = await fetch(live.dataset.liveUrl, {
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                const open = [...live.querySelectorAll('.collapse.show')].map((el) => el.id);
                live.innerHTML = await response.text();
                reopen(open);
            }
        } catch {
            // Offline or server hiccup: keep what is shown, try again next tick.
        } finally {
            busy = false;
        }
    };

    window.setInterval(refresh, INTERVAL_MS);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refresh();
        }
    });
});
