/**
 * Dashboard: while looking at today, re-fetch the live fragment every minute (only when the tab is
 * visible). The server renders it; the page just swaps the HTML in.
 */
const INTERVAL_MS = 60_000;

document.addEventListener('DOMContentLoaded', () => {
    const live = document.getElementById('dashboard-live');
    if (!live || live.dataset.liveRefresh !== '1') {
        return;
    }

    let busy = false;

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
                live.innerHTML = await response.text();
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
