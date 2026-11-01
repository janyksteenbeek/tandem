/// <reference lib="webworker" />

/**
 * The service worker behind the installable app.
 *
 * Served through ServiceWorkerController, which replaces __PWA_CONFIG__ with
 * the cache version, the offline page and the shell files to precache. The
 * version follows each build, so a deploy retires every cache below.
 *
 * Strategy, by request:
 * - Built assets under /build are immutable: cache first.
 * - Shell files (offline page, manifest, icons) are precached on install.
 * - Page loads and Inertia visits go network first; the last good copy is
 *   kept so recently opened mail still renders without a connection, with
 *   the offline page as the final fallback for a page load.
 * - Images are served stale while revalidating.
 * - Anything else, including every write, is network only.
 *
 * Page and image caches hold data from the signed-in session; the app asks
 * for them to be dropped whenever it lands on a sign-in screen.
 */

/** @typedef {{ version: string, offlineUrl: string, vapidPublicKey: string|null, precache: string[] }} PwaConfig */

/** @type {PwaConfig} */
const config = __PWA_CONFIG__;

/** @type {ServiceWorkerGlobalScope} */
const worker = /** @type {unknown} */ (self);

const CACHES = {
    shell: `shell-${config.version}`,
    assets: `assets-${config.version}`,
    pages: `pages-${config.version}`,
    media: `media-${config.version}`,
};

const MAX_PAGES = 80;
const MAX_MEDIA = 120;
const NETWORK_TIMEOUT_MS = 10000;

/** Screens whose responses must never be replayed from cache. */
const UNCACHEABLE_PATHS =
    /^\/(login|register|forgot-password|reset-password|two-factor-challenge|email\/verify|user\/confirm|auth\/|passkeys\/|sw\.js|up$|horizon|broadcasting)/;

worker.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHES.shell);

            await cache.add(config.offlineUrl);
            await Promise.all(
                config.precache
                    .filter((url) => url !== config.offlineUrl)
                    .map((url) => cache.add(url).catch(() => undefined)),
            );
        })(),
    );
});

worker.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const current = new Set(Object.values(CACHES));
            const names = await caches.keys();

            await Promise.all(
                names
                    .filter((name) => !current.has(name))
                    .map((name) => caches.delete(name)),
            );

            await worker.clients.claim();
        })(),
    );
});

worker.addEventListener('message', (event) => {
    const type = event.data?.type;

    if (type === 'SKIP_WAITING') {
        void worker.skipWaiting();
    }

    if (type === 'CLEAR_PAGES') {
        event.waitUntil(
            Promise.all([
                caches.delete(CACHES.pages),
                caches.delete(CACHES.media),
            ]),
        );
    }
});

worker.addEventListener('push', (event) => {
    /** The payload is the notification's own fields, title alongside the options. */
    /** @type {{ title?: string } & NotificationOptions} */
    let payload = {};

    try {
        payload = event.data?.json() ?? {};
    } catch {
        payload = { body: event.data?.text() ?? '' };
    }

    const { title, ...options } = payload;

    event.waitUntil(
        worker.registration.showNotification(
            title || 'New notification',
            options,
        ),
    );
});

worker.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL(
        event.notification.data?.url ?? '/',
        worker.location.origin,
    ).href;

    event.waitUntil(
        (async () => {
            const clients = await worker.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const existing = clients.find((client) =>
                client.url.startsWith(worker.location.origin),
            );

            if (existing) {
                await existing.focus();
                await existing.navigate(url);

                return;
            }

            await worker.clients.openWindow(url);
        })(),
    );
});

worker.addEventListener('pushsubscriptionchange', (event) => {
    if (!config.vapidPublicKey) {
        return;
    }

    event.waitUntil(
        worker.registration.pushManager
            .subscribe({
                userVisibleOnly: true,
                applicationServerKey: serverKey(config.vapidPublicKey),
            })
            .then(async () => {
                const clients = await worker.clients.matchAll({
                    type: 'window',
                });
                clients.forEach((client) =>
                    client.postMessage({ type: 'PUSH_SUBSCRIPTION_CHANGED' }),
                );
            })
            .catch(() => undefined),
    );
});

worker.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== worker.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, CACHES.assets));

        return;
    }

    if (config.precache.includes(url.pathname)) {
        event.respondWith(staleWhileRevalidate(request, CACHES.shell));

        return;
    }

    if (UNCACHEABLE_PATHS.test(url.pathname)) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(navigation(request));

        return;
    }

    if (request.headers.get('X-Inertia') === 'true') {
        event.respondWith(inertiaVisit(request));

        return;
    }

    if (request.destination === 'image') {
        event.respondWith(
            staleWhileRevalidate(request, CACHES.media, MAX_MEDIA),
        );
    }
});

/**
 * @param {string} base64Url
 * @returns {Uint8Array}
 */
function serverKey(base64Url) {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const raw = atob(
        (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'),
    );

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

/**
 * @param {Request} request
 * @param {string} cacheName
 * @returns {Promise<Response>}
 */
async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (isCacheable(response)) {
        await cache.put(request, response.clone());
    }

    return response;
}

/**
 * @param {Request} request
 * @param {string} cacheName
 * @param {number} [limit]
 * @returns {Promise<Response>}
 */
async function staleWhileRevalidate(request, cacheName, limit) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const refresh = fetch(request)
        .then(async (response) => {
            if (isCacheable(response)) {
                await cache.put(request, response.clone());
                await trim(cache, limit);
            }

            return response;
        })
        .catch(() => undefined);

    if (cached) {
        return cached;
    }

    const response = await refresh;

    return response ?? Response.error();
}

/**
 * A full page load: the freshest copy if the network answers in time, else
 * the last copy of that page, else the offline page.
 *
 * @param {Request} request
 * @returns {Promise<Response>}
 */
async function navigation(request) {
    const cache = await caches.open(CACHES.pages);
    const cached = await cache.match(request);

    try {
        const response = await fetchWithTimeout(
            request,
            cached ? NETWORK_TIMEOUT_MS : undefined,
        );

        if (isCacheable(response) && !request.url.includes('?')) {
            await cache.put(request, response.clone());
            await trim(cache, MAX_PAGES);
        }

        return response;
    } catch {
        if (cached) {
            return cached;
        }

        const shell = await caches.open(CACHES.shell);
        const offline = await shell.match(config.offlineUrl);

        return offline ?? Response.error();
    }
}

/**
 * An Inertia visit returns the page as JSON. Full visits are kept per URL;
 * partial reloads carry only some props and are left out of the cache.
 *
 * @param {Request} request
 * @returns {Promise<Response>}
 */
async function inertiaVisit(request) {
    const partial = request.headers.has('X-Inertia-Partial-Component');
    const cache = await caches.open(CACHES.pages);
    const cached = partial ? undefined : await cache.match(request);

    try {
        const response = await fetchWithTimeout(
            request,
            cached ? NETWORK_TIMEOUT_MS : undefined,
        );

        if (
            !partial &&
            isCacheable(response) &&
            response.headers.get('X-Inertia') === 'true'
        ) {
            await cache.put(request, response.clone());
            await trim(cache, MAX_PAGES);
        }

        return response;
    } catch (error) {
        if (cached) {
            return cached;
        }

        throw error;
    }
}

/**
 * @param {Request} request
 * @param {number} [timeout]
 * @returns {Promise<Response>}
 */
function fetchWithTimeout(request, timeout) {
    if (!timeout) {
        return fetch(request);
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    return fetch(request, { signal: controller.signal }).finally(() =>
        clearTimeout(timer),
    );
}

/**
 * Only complete, same-origin, non-redirected successes are worth keeping.
 * A redirected response replayed to a navigation is rejected by the browser.
 *
 * @param {Response} response
 */
function isCacheable(response) {
    return (
        response.ok &&
        response.status === 200 &&
        response.type === 'basic' &&
        !response.redirected
    );
}

/**
 * Drop the oldest entries past the limit. Cache keys come back in insertion
 * order, so the front of the list is the stalest.
 *
 * @param {Cache} cache
 * @param {number} [limit]
 */
async function trim(cache, limit) {
    if (!limit) {
        return;
    }

    const keys = await cache.keys();

    if (keys.length <= limit) {
        return;
    }

    await Promise.all(
        keys.slice(0, keys.length - limit).map((key) => cache.delete(key)),
    );
}
