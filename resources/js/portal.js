/**
 * Member portal entry (read-only pages). Bootstrap CSS, icons and fonts only.
 */
import './fonts.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import '../css/portal.css';
import { initInstallBanner } from './install-banner.js';

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('.js-print')) {
        window.print();
    }
});

const serviceWorkerUrl = document.body.dataset.sw;
if (serviceWorkerUrl && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register(serviceWorkerUrl, { scope: '/portal' }).catch(() => {});
    });
}

initInstallBanner('portal');
