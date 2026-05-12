/**
 * ============================================================
 * Service Worker — Network-first with cache fallback
 * ============================================================
 *
 * Pre-caches critical static assets on install, then uses a
 * network-first strategy at runtime. On every successful network
 * fetch, the response is also cached. When the network fails,
 * falls back to the cached version, then to /offline.html.
 *
 * Bump CACHE_NAME on every deploy that changes any pre-cached
 * file. The activate handler will delete every cache that doesn't
 * match.
 */

const CACHE_NAME = "site-v1.0.0";

// Critical assets pre-cached on install. Keep this list small —
// the user pays for these bytes on first visit.
const STATIC_CACHE = [
  "/",
  "/index.html",
  "/offline.html",
  "/css/style.min.css",
  "/js/script.min.js",
  "/fonts/inter-v13-latin-regular.woff2",
  "/fonts/inter-v13-latin-600.woff2",
];

// ── Install: pre-cache critical assets ───────────────────────
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_CACHE))
      .then(() => self.skipWaiting()),
  );
});

// ── Activate: delete old caches, claim clients ───────────────
self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) =>
        Promise.all(
          names.map((name) => {
            if (name !== CACHE_NAME) {
              return caches.delete(name);
            }
          }),
        ),
      )
      .then(() => self.clients.claim()),
  );
});

// ── Fetch: network-first, cache fallback, offline page last ──
self.addEventListener("fetch", (event) => {
  // Only handle GET — never cache POSTs (form submissions etc.)
  if (event.request.method !== "GET") return;

  // Skip cross-origin requests (analytics, fonts.googleapis.com…)
  // Let the browser handle them normally.
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Update the cache silently with the fresh response.
        // Clone first — the body stream is single-use.
        const clone = response.clone();
        caches
          .open(CACHE_NAME)
          .then((cache) => cache.put(event.request, clone));
        return response;
      })
      .catch(() =>
        // Network failed — try cache, then the offline page.
        caches
          .match(event.request)
          .then((cached) => cached || caches.match("/offline.html")),
      ),
  );
});

// ── Optional: handle messages from the page ──────────────────
// The page can call navigator.serviceWorker.controller.postMessage('skipWaiting')
// to make a newly-installed SW take over without a full reload.
self.addEventListener("message", (event) => {
  if (event.data === "skipWaiting") {
    self.skipWaiting();
  }
});
