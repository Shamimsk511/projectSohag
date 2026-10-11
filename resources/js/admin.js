/**
 * Admin panel entry (AdminLTE 4). Loaded by the package layout through
 * `laravel_asset_bundling = vite_js_only`, so every base asset lives here.
 */
import '@fontsource/source-sans-3/300.css';
import '@fontsource/source-sans-3/400.css';
import '@fontsource/source-sans-3/600.css';
import '@fontsource/source-sans-3/700.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import 'overlayscrollbars/overlayscrollbars.css';
import 'admin-lte/dist/css/adminlte.css';

import * as bootstrap from 'bootstrap';
import { OverlayScrollbars } from 'overlayscrollbars';
import 'admin-lte';

window.bootstrap = bootstrap;

// The package only initialises sidebar scrollbars when it loads OverlayScrollbars
// itself; with bundling it is our job. Skipped on mobile to keep touch scrolling native.
document.addEventListener('DOMContentLoaded', () => {
    const sidebarWrapper = document.querySelector('.sidebar-wrapper');

    if (sidebarWrapper && window.innerWidth > 992) {
        OverlayScrollbars(sidebarWrapper, {
            scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
        });
    }
});
