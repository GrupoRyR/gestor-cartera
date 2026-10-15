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
 * - Compiled files under /build/ are served from the cache first: Vite puts
 *   a hash in their names, so a cached copy never goes stale.
 * - Icons and fonts keep fixed names: the cached copy answers at once and
 *   the network refreshes it in the background (stale-while-revalidate).
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
const HASHED_PREFIX = '/build/';
const MAX_ENTRIES = 120;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            // cache: 'reload' skips the browser HTTP cache, so a new VERSION stores fresh copies.
            .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' }))))
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

    if (url.pathname.startsWith(HASHED_PREFIX)) {
        event.respondWith(cacheFirst(request));
    } else if (STATIC_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        event.respondWith(staleWhileRevalidate(request, event));
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    return store(cache, request, await fetch(request));
}

async function staleWhileRevalidate(request, event) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(request);
    const refresh = fetch(request).then((response) => store(cache, request, response));

    if (cached) {
        event.waitUntil(refresh.catch(() => undefined));

        return cached;
    }

    return refresh;
}

// Only complete same-origin answers are stored: never an error page or a partial response.
async function store(cache, request, response) {
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
