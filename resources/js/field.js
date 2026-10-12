/**
 * Field officer entry (mobile/PWA). Keep it light: no jQuery, DataTables,
 * ApexCharts, pdfmake or AdminLTE here; those belong to the admin entry.
 */
import './fonts.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import '../css/field.css';

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
