/**
 * "Install app" banner for the field and portal PWAs (markup: x-install-banner).
 * Android/Chrome: keeps the browser's install event and opens it from our button.
 * iPhone Safari has no install event: show the Share -> Add to Home Screen hint.
 * Hidden when running installed, after install, or once closed (remembered per app on this phone).
 */
export function initInstallBanner(app) {
    const banner = document.querySelector('[data-install-banner]');
    if (!banner) {
        return;
    }

    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const dismissKey = `install-banner-closed:${app}`;
    if (standalone || readDismissed(dismissKey)) {
        return;
    }

    const button = banner.querySelector('[data-install-button]');
    const hide = () => banner.classList.add('d-none');
    let deferred = null;

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        button.classList.remove('d-none');
        banner.classList.remove('d-none');
    });

    button.addEventListener('click', async () => {
        if (!deferred) {
            return;
        }
        deferred.prompt();
        const { outcome } = await deferred.userChoice;
        deferred = null;
        if (outcome === 'accepted') {
            hide();
        }
    });

    window.addEventListener('appinstalled', hide);

    banner.querySelector('[data-install-close]').addEventListener('click', () => {
        hide();
        try {
            localStorage.setItem(dismissKey, '1');
        } catch {
            // Storage blocked: the banner simply shows again next time.
        }
    });

    const ua = navigator.userAgent;
    const iOS = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (iOS) {
        banner.querySelector('[data-install-ios]').classList.remove('d-none');
        banner.classList.remove('d-none');
    }
}

function readDismissed(key) {
    try {
        return localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}
