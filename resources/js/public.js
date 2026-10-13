/**
 * Public pages (homepage, staff login). Bootstrap CSS, icons and fonts only; no jQuery.
 */
import './fonts.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import '../css/public.css';

document.addEventListener('change', (event) => {
    const toggle = event.target;
    if (!(toggle instanceof HTMLInputElement) || !toggle.matches('[data-show-password]')) {
        return;
    }

    const input = document.getElementById(toggle.dataset.showPassword);
    if (input instanceof HTMLInputElement) {
        input.type = toggle.checked ? 'text' : 'password';
    }
});
