# Architecture

A boring stack on purpose. Static HTML, vanilla JS, a thin PHP
backend for the contact form, an Apache `.htaccess` for everything
else. The interesting parts are how each piece is configured for
production — not how many pieces there are.

## High-level shape

```
Browser
  │
  ├── Static HTML (index.html, services.html, …)
  │   • CSS bundle (style.min.css)
  │   • JS bundle (script.min.js, form-validation.min.js, chatbot.min.js)
  │   • Web fonts (woff2)
  │
  ├── Service Worker (sw.js)
  │   • Pre-caches static assets on install
  │   • Network-first fetch with cache fallback
  │   • Serves /offline.html when offline
  │
  └── POST /send-contact.php  ──→ PHP backend
                                  │
                                  ├── config.php (secrets — never committed)
                                  ├── PHPMailer (composer-installed vendor)
                                  ├── logs/ (DSGVO-anonymized)
                                  └── SMTP relay (IONOS / GMX / Postmark / …)

Apache .htaccess wraps everything:
  • Security headers (CSP, HSTS, X-Frame-Options, Permissions-Policy)
  • Compression (mod_deflate)
  • Browser caching (mod_expires, immutable for static assets)
  • Hash-stripped pretty URLs (mod_rewrite)
  • 404 → /404.html
```

## Why this stack

Three reasons to choose this over a JS-framework SSR site for a
small-business marketing site:

**1. Hosting cost.** Shared PHP hosting on a major provider (IONOS,
1&1, Hetzner) costs €3–5/month and supports this stack natively.
A Next.js / SvelteKit deployment on Vercel starts free but scales
into real money once it's a client site (custom domain, analytics,
edge functions).

**2. Reliability.** The PHP backend has one job: receive a form,
send an email, write a log line. PHP 7.4 has been stable since
2019. Apache `.htaccess` has been stable since 1995. The main
failure mode is "the SMTP password expired", which is a one-line
fix. Compare to a Node.js stack that needs `npm audit fix` weekly
and breaks when Node minor versions change.

**3. Performance budget.** A static HTML site with a service worker
is faster than any framework SSR setup at the 10th–90th percentile.
First Contentful Paint is essentially the time to fetch one HTML
file plus inlined critical CSS. There's nothing to hydrate.

## Why this stack is NOT the right choice

Be honest about the trade-offs:

- **No reactive UI.** If the site needs filtering, search, dynamic
  forms with conditional fields, or interactive dashboards, the
  vanilla-JS-with-progressive-enhancement model gets painful fast.
- **No type safety.** JavaScript and PHP are both dynamically typed.
  For a 5-page marketing site, this is fine. For a 50-page site
  with shared logic, you'd want TypeScript.
- **No component reuse.** Header and footer are duplicated across
  every HTML page. A small build step (gulp + nunjucks, or even
  PHP includes) fixes this, but it's an extra moving part.
- **No SSR for personalization.** Every visitor sees the same HTML.
  If the site needs to know who you are, this stack stops working.

For a corporate marketing site that is fundamentally read-only
content with one form, the trade-offs are worth it.

## The one PHP backend

`send-contact.php` is the only dynamic endpoint. It does five things,
in order, and exits:

1. **Validate origin.** Reject requests not from the allowed
   domain list. (CORS preflight + Origin check.)
2. **Rate-limit.** Reject more than N requests per IP per hour.
   Stored in a flat JSON file in `logs/`.
3. **Spam-filter.** Honeypot fields (hidden inputs that must stay
   empty) catch the dumber bots; ReCAPTCHA or hCaptcha catches the
   smarter ones.
4. **Send.** PHPMailer + SMTP. Save sent items to the sender's
   mailbox (most SMTP servers support `imap_append`, otherwise
   PHPMailer's BCC handles it).
5. **Log.** Write a JSON line to `logs/contact.log` with timestamp,
   anonymized IP, and outcome. Never log message content.

See [`phpmailer-contact-form.md`](./phpmailer-contact-form.md) for
the full handler.

## SEO + PWA setup

Four coordinated files:

- **`robots.txt`** — explicit rules for major search bots
  (`Googlebot`, `Bingbot`, …), explicit blocks for SEO-spam bots
  (`AhrefsBot`, `MJ12bot`, `SemrushBot`), explicit allow-list for
  resource paths so search engines can render the page properly.
- **`sitemap.xml`** — every real HTML page (no hash-based anchors)
  with `<lastmod>`, `<changefreq>`, image schema for inlined images,
  and `xhtml:link` hreflang for multilingual variants.
- **`site.webmanifest`** — full PWA manifest with icons, app
  shortcuts (jump to specific sections), share_target for incoming
  shared links, and protocol handlers for `mailto:` / `tel:` URLs.
- **`sw.js`** — Service Worker that pre-caches the critical bundle
  on install and falls back to cache when network fails.

See [`seo-files-setup.md`](./seo-files-setup.md) and
[`service-worker-offline-first.md`](./service-worker-offline-first.md).

## What's not here

- **No CMS.** All copy is in the HTML files. For a 5-page site
  this is fine. For 50 pages, use a headless CMS (Sanity,
  Contentful) and a static site generator (Eleventy, Hugo,
  Astro) to render to HTML.
- **No analytics.** Add Plausible or Umami via a single `<script>`
  tag and an entry in the CSP.
- **No A/B testing.** Same reason.
- **No CI/CD.** Deployment is `rsync` or SFTP. For a site this
  small that's still the right answer.
