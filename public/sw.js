/**
 * Service worker for the field-staff install.
 *
 * The person this exists for is a technician standing in a basement plant
 * room with one bar of signal. Two things matter there: the app opens at all,
 * and closing a task does not silently evaporate when the connection drops
 * halfway through the request.
 *
 * Deliberately not cached: anything that shows task state. A stale task list
 * served from a cache is worse than no list — it tells someone work is still
 * open when they finished it an hour ago.
 */

const VERSION = 'v1';
const SHELL_CACHE = `shell-${VERSION}`;
const OFFLINE_URL = '/offline.html';

// Only the frame and the assets. Never a page that carries task data.
const SHELL_ASSETS = [OFFLINE_URL];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) => cache.addAll(SHELL_ASSETS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== SHELL_CACHE)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        // A POST that fails offline must fail loudly. Quietly swallowing a
        // "done" the technician just pressed is the worst thing this file
        // could do.
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Build assets are content-hashed, so a cache hit is always the right
    // file and a miss is worth the round trip.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        const copy = response.clone();
                        caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy));

                        return response;
                    }),
            ),
        );

        return;
    }

    // Everything else is live data. Network first, and the offline page only
    // when the network is genuinely gone — never a stale copy of a task list.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
    }
});
