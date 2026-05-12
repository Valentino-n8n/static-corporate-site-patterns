# Service Worker — Offline-First Cache

The service worker (`sw.js`) is what makes a static site work when
the network doesn't. On first visit, it pre-caches the critical
assets. On subsequent visits, it serves from cache while updating
in the background. When the user is offline, it falls back to the
cached response.

## Lifecycle in three phases

```
┌── INSTALL ──────────────────────────────────────────────────┐
│  Browser fetches sw.js, runs the 'install' event handler.   │
│  Handler calls cache.addAll([list of critical assets]) —    │
│  every entry in STATIC_CACHE is downloaded and stored.      │
│  skipWaiting() makes the new SW take over immediately       │
│  instead of waiting for tabs to close.                      │
└─────────────────────────────────────────────────────────────┘
                        │
                        ▼
┌── ACTIVATE ─────────────────────────────────────────────────┐
│  When the new SW takes over, the 'activate' event fires.    │
│  Handler iterates over old caches and deletes any whose     │
│  name doesn't match the current CACHE_NAME. clients.claim() │
│  makes the new SW control all open tabs immediately.        │
└─────────────────────────────────────────────────────────────┘
                        │
                        ▼
┌── FETCH ────────────────────────────────────────────────────┐
│  Every network request from the page passes through here.   │
│  Strategy: try the network, cache what comes back, fall     │
│  back to cache only if the network fails.                   │
└─────────────────────────────────────────────────────────────┘
```

## The cache strategy: network-first with cache fallback

```js
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Update the cache with the fresh response (clone first —
        // the body stream can only be consumed once).
        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, clone);
        });
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});
```

This is "network-first, cache fallback" — the browser tries the
network first, returns the live response if it works, and only
falls back to cache if the network fails. The cache is also
silently updated on every successful network call.

The two main alternative strategies, and when to use them:

| Strategy | Behavior | When to use |
|---|---|---|
| **Network-first, cache fallback** *(used here)* | Try network, fall back to cache | Marketing sites where freshness matters more than offline; blog posts, prices, contact info |
| **Cache-first, network fallback** | Try cache, fall back to network | Web apps with heavy assets that rarely change; pre-cached content |
| **Stale-while-revalidate** | Return cache immediately, update cache from network in the background | App shells; content where "good enough" is OK while fresh data loads |

For a corporate site where the user wants the latest opening hours
and prices, network-first is the right default.

## What goes in `STATIC_CACHE`

The `STATIC_CACHE` array lists every file that should be cached
during the `install` event. Choose carefully:

- **Always cache:** the main HTML pages (`/`, `/services.html`,
  etc.), the CSS bundle, the JS bundle, the web fonts, and the
  hero images that appear above the fold.
- **Cache opportunistically (via fetch handler, not install):** other
  images, secondary pages.
- **Never cache:** form submission endpoints, dynamic content,
  user-specific data.

A good rule of thumb: if `STATIC_CACHE` is more than ~20 entries,
you're caching too much on install. The user is paying for that
download time on first visit.

## Cache versioning

The `CACHE_NAME` constant is the magic that makes updates work:

```js
const CACHE_NAME = 'site-v14.0.0';
```

When you change *any* file in `STATIC_CACHE`, bump this name.
When the new SW activates, it deletes every cache that doesn't
match the new name. The old cache is gone in seconds.

Common patterns for the version string:

- **Manual semver:** `'site-v1.0.0'` — bump when shipping. Reliable
  but requires discipline.
- **Build hash:** `'site-${BUILD_HASH}'` — automatic, but requires
  a build step that injects the hash into `sw.js`.
- **Date string:** `'site-2026-05-10'` — simplest if you control
  deployments.

Do *not* leave `CACHE_NAME` static across deploys. Users will be
served stale assets indefinitely.

## Lifecycle pitfalls

**The "first deploy is fine, second deploy serves stale" trap.**
On first visit, the user has no SW yet, so they get the new code.
On second visit, the *old* SW is still active for ~24 hours by
default. They see stale content. Fix: always call `skipWaiting()`
in `install` and `clients.claim()` in `activate`.

**The "registration runs before SW file is updated" trap.** If
the page registers `/sw.js` and the browser fetches it from cache,
no update happens. Fix: serve `/sw.js` with `Cache-Control:
max-age=0, must-revalidate`. (Most browsers do this automatically
for SW files, but some configs override it — check your `.htaccess`.)

**The "user is on a page when you deploy" trap.** The page is
controlled by the *old* SW. The new SW installs in the background
but doesn't take over until the page reloads. Fix: detect the
`waiting` state in the page's registration code, prompt the user
to reload, or call `skipWaiting` from a `message` event.

## Offline page

The fetch handler above falls back to `caches.match(event.request)`,
which returns the cached version if present, or `undefined` if not.
For requests with no cached version (e.g. a new page the user
hasn't visited yet), `undefined` becomes a network error.

A friendlier pattern is to return `/offline.html` as the last
fallback:

```js
.catch(() =>
  caches.match(event.request)
    .then((res) => res || caches.match('/offline.html'))
);
```

`offline.html` should be in `STATIC_CACHE` so it's available even
on the user's first offline experience.

## Testing the service worker

Browsers cache service workers aggressively, which makes testing
painful. Three tools:

- **DevTools → Application → Service Workers**: shows the active
  SW, lets you "Update on reload" (forces fresh fetch every page
  load) and "Unregister" (start fresh).
- **DevTools → Network → "Offline"** checkbox: simulates network
  failure to test cache fallback.
- **`localhost` is treated as secure**: SW works on `http://localhost`
  but requires `https://` everywhere else. Use `localhost`, not
  `127.0.0.1` or your local IP.

See the full SW in [`../snippets/sw.js`](../snippets/sw.js).

## What this SW is NOT

- **Not a substitute for a proper offline app.** This caches a
  read-only static site. For an app that needs to write data
  offline (forms, drafts, etc.), look at [Workbox](https://developer.chrome.com/docs/workbox)
  with Background Sync.
- **Not push notifications.** This SW doesn't handle push events.
  Adding push requires VAPID keys and a server-side push service.
- **Not a replacement for proper HTTP caching.** Most caching
  should still happen via `Cache-Control` headers (see the
  `.htaccess` doc). The SW is the layer below that — for offline
  resilience, not for everyday performance.
