# Static Corporate Site Patterns

A reference for building a fast, accessible, SEO-conscious corporate
website on a classic LAMP-style stack — static HTML + CSS + vanilla JS
for the front, PHP + PHPMailer for the contact backend, Apache
`.htaccess` for production hardening, and a Service Worker for
offline-first behavior.

This repository documents the architecture decisions, code patterns, and
design trade-offs from a multilingual corporate website prototype I
built for a small German services business. The prototype was never
deployed to production.

> The full source code of the prototype is not published here. This
> repository contains documentation and selected pattern snippets, not a
> deployable site. Contact details, credentials, customer imagery, and
> brand assets are not part of the public material.

---

## What this repo documents

A "static" corporate site sounds simple but quickly stops being simple
in practice:

- The contact form has to actually deliver email reliably, comply with
  GDPR (DSGVO), survive spam, and not leak credentials.
- The Apache config has to set proper security headers, compress
  responses, cache assets correctly, and redirect cleanly.
- The site has to work offline (or near-offline), pre-cache its fonts
  and CSS, and serve a useful 404 / offline fallback.
- SEO has to cover bot allow/disallow rules, dynamic sitemap with
  hreflang and image schema, and a PWA manifest that does more than
  name the app.

The patterns documented here are the parts that take time to get right.

---

## Repository structure

```
.
├── README.md                              ← you are here
├── docs/
│   ├── architecture.md                    ← how the pieces fit together
│   ├── phpmailer-contact-form.md          ← DSGVO-safe contact backend
│   ├── service-worker-offline-first.md    ← cache strategy + lifecycle
│   ├── apache-htaccess-production.md      ← security headers + caching
│   └── seo-files-setup.md                 ← robots, sitemap, manifest
└── snippets/
    ├── README.md
    ├── send-contact.php                   ← PHP form handler
    ├── config.example.php                 ← config template, no secrets
    ├── sw.js                              ← service worker
    ├── htaccess-production.txt            ← Apache .htaccess
    ├── robots.txt                         ← SEO robots
    ├── sitemap.xml                        ← SEO sitemap (image + hreflang)
    └── site.webmanifest                   ← PWA manifest
```

---

## Tech stack

- **Frontend:** HTML5, CSS3, vanilla JavaScript (no framework)
- **Backend:** PHP 7.4+ with [PHPMailer](https://github.com/PHPMailer/PHPMailer)
- **Server:** Apache 2.4+ with `mod_headers`, `mod_deflate`, `mod_expires`,
  `mod_rewrite`
- **Email:** SMTP (PHPMailer-compatible, e.g. IONOS, GMX, Gmail,
  Postmark, SES)
- **PWA:** Service Worker, Web App Manifest

This stack is intentionally not Node-based. For a small business site
that needs to be cheap to host and reliable to maintain, a
static-with-PHP-backend setup on shared hosting is still a sensible
choice in 2026.

---

## What this repo does NOT contain

Scope of this repository:

- **The full website source.** No HTML pages, no CSS files, no bundled
  JavaScript, no images. The patterns here are what made those parts
  work; the parts themselves are not republished.
- **SMTP credentials.** `config.example.php` has placeholders only. The
  actual `config.php` lived outside source control.
- **Customer or business data.** Sample data in the snippets is generic.
- **Assets.** Logos, photos, and 3D scans are not included.

---

## About

Built by [Valentino Veljanovski](https://valentinoveljanovski.de),
automation developer based in München. The case study for this prototype
is at
[valentinoveljanovski.de/projects/corporate-website](https://valentinoveljanovski.de/projects/corporate-website).

Companion repositories cover related patterns:

- [`Valentino-n8n/DISPO`](https://github.com/Valentino-n8n/DISPO) —
  Microsoft 365 + DocuSign + AI-assisted operations
- [`Valentino-n8n/Reklamation`](https://github.com/Valentino-n8n/Reklamation) —
  Slack-based case management
- [`Valentino-n8n/BauScope-Control-Center`](https://github.com/Valentino-n8n/BauScope-Control-Center) —
  Role-based Slack platform with DocuSign HMAC
- [`Valentino-n8n/BauScope-3D`](https://github.com/Valentino-n8n/BauScope-3D) —
  Next.js B2B landing page patterns

---

## Viewing Notice

This repository is published for portfolio demonstration and educational
viewing only.

All code, documentation, diagrams, and content in this repository remain
the intellectual property of the author. **All rights reserved.**

No license is granted, expressed or implied, for reuse, redistribution,
modification, or commercial use of any material in this repository
without prior written permission from the author.

For licensing or collaboration inquiries, contact:
[valentinoveljanovski@outlook.com](mailto:valentinoveljanovski@outlook.com)