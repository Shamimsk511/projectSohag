/**
 * WhatsApp buttons (resources/views/components/whatsapp/button.blade.php): the link opens WhatsApp as usual;
 * the tap is also logged (best effort, never blocks the link) and the returned label is shown next to it.
 */
export function initWhatsAppButtons() {
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a.js-whatsapp') : null;
        if (!link) {
            return;
        }
        fetch(link.dataset.logUrl, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': link.dataset.csrf },
            body: link.dataset.log,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                const badge = link.closest('.js-whatsapp-wrap')?.querySelector('[data-whatsapp-badge]');
                if (data?.label && badge) {
                    badge.textContent = data.label;
                    badge.classList.remove('d-none');
                }
            })
            .catch(() => {});
    });
}
