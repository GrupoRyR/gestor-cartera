/*
 * Service worker of the portfolio manager (A.6).
 *
 * It caches ONLY static files (the compiled CSS and JS, fonts and icons) and
 * the "Sin conexión" page. It never caches pages, API answers or any other
 * data: the team chose no offline mode, and portfolio figures are financial
 * data that must not stay on a phone.
 *
 * - Pages (navigations) always go to the network; without a connection the
 *   cached offline page answers instead.
 * - Files under STATIC_PREFIXES are served from the cache first. Vite gives
 *   compiled files a hash in their name, so a cached copy never goes stale.
 * - Everything else (API calls, forms, exports...) is left to the browser.
 *
 * Bump VERSION when PRECACHE changes; the old cache is dropped on activate.
 */
const VERSION = 'v1';
const CACHE_PREFIX = 'gestor-cartera-estaticos-';
const CACHE = `${CACHE_PREFIX}${VERSION}`;
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/neutral/favicon.svg'];
const STATIC_PREFIXES = ['/build/', '/fonts/', '/icons/'];
const MAX_ENTRIES = 120;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    if (STATIC_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        event.respondWith(cacheFirst(request));
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    // Only complete same-origin answers: never an error page or a partial response.
    if (response.ok && response.status === 200 && response.type === 'basic') {
        await cache.put(request, response.clone());
        await trim(cache);
    }

    return response;
}

// Each deploy adds new hashed files: drop the oldest ones, but never the precached files.
async function trim(cache) {
    const keys = await cache.keys();
    const removable = keys.filter((key) => !PRECACHE.includes(new URL(key.url).pathname));
    const excess = keys.length - MAX_ENTRIES;

    await Promise.all(removable.slice(0, Math.max(excess, 0)).map((key) => cache.delete(key)));
}
