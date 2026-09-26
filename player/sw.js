/**
 * Shared offline shell for every installed station. Live radio stays
 * network-first.
 *
 * This file has to sit at the top of player/, not in a subfolder beside the
 * other scripts: a service worker may only claim a scope at or below its own
 * address, and this one claims all of /player/. Widening it from a subfolder
 * needs a `Service-Worker-Allowed` header, which needs mod_headers, which the
 * cheap hosting this product is for cannot be relied on to have.
 */
const CACHE_PREFIX = '52hertz-player-shell-';
const CACHE = CACHE_PREFIX + 'v6';
const CORE = [
  './index.html',
  './styles.css',
  './pwa.js',
  './app.js',
  '../engine/schedule.js',
  '../engine/clock.js',
  '../assets/img/favicon.ico',
];
const CORE_URLS = new Set(CORE.map((path) => new URL(path, self.location.href).href));
// A station's typeface is whichever its language names, so it is kept the
// first time the player loads it rather than listed here.
const FONTS = new URL('../assets/fonts/', self.location.href).href;
const OFFLINE_DOCUMENT = new URL('./index.html', self.location.href).href;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    await Promise.allSettled(CORE.map((path) => cache.add(path)));
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

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        if (response.ok) {
          const cache = await caches.open(CACHE);
          await cache.put(OFFLINE_DOCUMENT, response.clone());
        }
        return response;
      } catch {
        return (await caches.match(OFFLINE_DOCUMENT)) || Response.error();
      }
    })());
    return;
  }

  if (!CORE_URLS.has(url.href) && !url.href.startsWith(FONTS)) return;
  event.respondWith((async () => {
    const cached = await caches.match(request);
    const refresh = fetch(request).then(async (response) => {
      if (response.ok) {
        const cache = await caches.open(CACHE);
        await cache.put(request, response.clone());
      }
      return response;
    });

    // If an old shell exists, return it immediately and let a successful
    // network request refresh it. Catch the refresh because going offline is
    // expected here, not an unhandled service-worker failure.
    if (cached) {
      event.waitUntil(refresh.catch(() => undefined));
      return cached;
    }
    return refresh;
  })());
});
