/**
 * Member portal service worker (scope /portal). Same strategy as field-sw.js: shell assets cache-first,
 * navigations network-first with /portal/offline fallback, member HTML never cached.
 */
const CACHE_PREFIX = 'portal-shell-';
const CACHE = CACHE_PREFIX + (new URL(self.location.href).searchParams.get('v') || 'unversioned');
const OFFLINE_URL = '/portal/offline';
const MANIFEST_URL = '/portal.webmanifest';
const ICONS = ['/icons/icon-192.png', '/icons/icon-512.png', '/icons/apple-touch-icon.png', '/icons/logo-mark.png', '/icons/favicon-32x32.png', '/icons/favicon-16x16.png'];

const isShellAsset = (url) => url.pathname.startsWith('/build/assets/') || url.pathname.startsWith('/icons/');

/** Fonts referenced by the shell's stylesheets (woff2 only; every target browser supports it). */
async function fontsIn(cssUrls) {
    const fonts = await Promise.all(cssUrls.map(async (cssUrl) => {
        const css = await (await fetch(cssUrl)).text();
        return [...css.matchAll(/url\(["']?([^"')]+\.woff2(?:\?[^"')]*)?)["']?\)/g)].map((match) => {
            const font = new URL(match[1], new URL(cssUrl, self.location.origin));
            return font.pathname + font.search; // bootstrap-icons adds a ?hash the page requests verbatim
        });
    }));
    return fonts.flat();
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        // Fetched without cookies: the cached page must not carry anyone's session data.
        const offline = await fetch(OFFLINE_URL, { credentials: 'omit', cache: 'no-store' });
        if (!offline.ok) {
            throw new Error(`Offline page returned ${offline.status}`);
        }
        const html = await offline.clone().text();
        const assets = [...new Set([...html.matchAll(/\/build\/assets\/[^"'\s?#)]+/g)].map((match) => match[0]))];
        const fonts = await fontsIn(assets.filter((asset) => asset.endsWith('.css')));
        await cache.addAll([...new Set([...assets, ...fonts, ...ICONS, MANIFEST_URL])]);
        await cache.put(OFFLINE_URL, offline);
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE)
            .map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

async function cacheFirst(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);
    if (cached) {
        return cached;
    }
    const response = await fetch(request);
    if (response.ok) {
        await cache.put(request, response.clone());
    }
    return response;
}

async function networkFirst(request, fallbackUrl) {
    try {
        return await fetch(request);
    } catch (error) {
        const cache = await caches.open(CACHE);
        return (await cache.match(fallbackUrl)) || Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    if (isShellAsset(url)) {
        event.respondWith(cacheFirst(request));
    } else if (url.pathname === MANIFEST_URL) {
        event.respondWith(networkFirst(request, MANIFEST_URL));
    } else if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request, OFFLINE_URL));
    }
});
