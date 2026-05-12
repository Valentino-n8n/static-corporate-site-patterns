# SEO Files Setup

Three coordinated files give a corporate site solid SEO without a
framework:

1. **`robots.txt`** — explicit allow/disallow rules per bot.
2. **`sitemap.xml`** — full URL list with image schema and hreflang.
3. **`site.webmanifest`** — PWA manifest with shortcuts and share
   targets.

Done well, these are 5 minutes of careful editing. Done poorly,
they leak admin paths to crawlers, send the wrong canonical
signals, and make the site invisible to search.

## `robots.txt` — beyond `User-agent: * / Allow: /`

A good `robots.txt` does three things, in order:

**1. Allow the bots you want, with crawl delays.**

Different search engines have different ideal crawl rates. The
defaults work fine, but a `Crawl-delay` for high-volume bots can
prevent server load spikes:

```
User-agent: Googlebot
Allow: /
Crawl-delay: 1

User-agent: Bingbot
Allow: /
Crawl-delay: 1
```

Note: Google has stated they ignore `Crawl-delay`. They use
Search Console settings instead. Other engines respect it.

**2. Block paths that should never be indexed.**

Even if your `.htaccess` blocks browser access, search engines may
still find `/admin/` or `/logs/` URLs through other means
(referrers, leaked links). Explicitly disallow them:

```
Disallow: /admin/
Disallow: /api/
Disallow: /logs/
Disallow: /.git/
Disallow: /.env
Disallow: /config.php
```

This is defense in depth — the URL still 403s, but it's also not
in any search index.

**3. Block SEO-spam bots.**

There's a small but persistent set of crawlers that scrape your
site to feed competitive-intelligence dashboards. Block them
explicitly — they ignore `User-agent: *` rules but obey their own
agent name:

```
User-agent: AhrefsBot
Disallow: /

User-agent: MJ12bot
Disallow: /

User-agent: SemrushBot
Disallow: /

User-agent: dotbot
Disallow: /
```

Don't block legitimate social media bots (`facebookexternalhit`,
`Twitterbot`, `LinkedInBot`, `WhatsApp`) — they fetch your site
to build link previews when users share URLs. If they're blocked,
your shared links don't get rich previews.

**4. Point to your sitemaps.**

The last block of `robots.txt` lists every sitemap URL:

```
Sitemap: https://example.com/sitemap.xml
Sitemap: https://example.com/sitemap-images.xml
```

Multiple sitemaps are fine — one for pages, one for images, one
per language, etc.

## `sitemap.xml` — what beyond `<loc>` matters

The minimum viable sitemap is one `<url>` per page with `<loc>`.
A *useful* sitemap has three more things:

**`<lastmod>`** — when the page actually changed. Search engines
use this to decide whether to re-crawl. Don't set it to today's
date for every page — that signals "everything changed
constantly", which crawlers eventually distrust. Set it to the
real last-modified date.

**`<changefreq>` + `<priority>`** — rough hints for crawlers.
Largely ignored by Google, but Bing and others do use them. Reasonable values:

| Page type | `changefreq` | `priority` |
|---|---|---|
| Homepage | `weekly` | `1.0` |
| Service pages | `monthly` | `0.8` |
| About / Contact | `monthly` | `0.5` |
| Legal (Impressum, Privacy) | `yearly` | `0.3` |

**`xhtml:link` hreflang** — for multilingual sites. Tells search
engines which version of the page to show users in different
languages or regions:

```xml
<url>
  <loc>https://example.com/</loc>
  <xhtml:link rel="alternate" hreflang="de" href="https://example.com/" />
  <xhtml:link rel="alternate" hreflang="en" href="https://example.com/?lang=en" />
  <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/" />
</url>
```

`x-default` is the fallback for users whose language doesn't match
any of the listed variants.

**Image schema** — embed key images in the sitemap so Google
Images can find them:

```xml
<url>
  <loc>https://example.com/</loc>
  <image:image>
    <image:loc>https://example.com/assets/team.jpg</image:loc>
    <image:title>Team photo</image:title>
    <image:caption>Our team at work</image:caption>
  </image:image>
</url>
```

This is the single highest-leverage SEO addition for image-rich
sites — most don't bother, and Google rewards the ones that do.

## `site.webmanifest` — beyond name and icons

A minimal manifest has `name`, `short_name`, `icons`, and
`start_url`. Add the rest for a professional PWA:

**`display: standalone`** — when the user installs the site as an
app, it opens in its own window without browser chrome. This is
what makes a PWA feel like a native app.

**`theme_color` + `background_color`** — `theme_color` sets the
browser address bar color on mobile; `background_color` is the
splash-screen color before the page loads. Use the brand's primary
color and a neutral dark or light, respectively.

**`shortcuts`** — long-press the installed app icon (mobile) or
right-click (desktop) to jump to specific pages:

```json
"shortcuts": [
  {
    "name": "Request quote",
    "short_name": "Quote",
    "url": "/#contact",
    "icons": [{ "src": "/assets/shortcut-quote.png", "sizes": "96x96" }]
  },
  {
    "name": "Call us",
    "short_name": "Call",
    "url": "tel:+49000000000"
  }
]
```

For a corporate site, the highest-value shortcuts are "go to
contact form" and "call us" — they shave taps off the most common
user goals.

**`share_target`** — register the site as a destination for
Android's Share menu. Useful if the site has a feature that takes
URLs or text from elsewhere (e.g. a contact form that pre-fills
from clipboard):

```json
"share_target": {
  "action": "/contact/share",
  "method": "GET",
  "params": {
    "title": "title",
    "text": "text",
    "url": "url"
  }
}
```

When the user shares from another app, Android sends the data to
the registered URL with the chosen params. Your handler parses
them and pre-fills the form.

**`protocol_handlers`** — register the site to handle
`mailto:` and `tel:` links system-wide:

```json
"protocol_handlers": [
  { "protocol": "mailto", "url": "/contact?email=%s" },
  { "protocol": "tel", "url": "/contact?phone=%s" }
]
```

Aggressive but useful for service businesses — clicking any
`mailto:` link routes to your contact form pre-filled.

## What this setup does NOT do

- **It doesn't get you to page 1 of Google.** Real ranking requires
  content + backlinks + page-experience metrics. This is the
  technical-correctness floor.
- **It doesn't help if your hosting blocks it.** Some shared hosts
  override `.htaccess` headers or refuse to serve `application/manifest+json`
  for `.webmanifest` files. Check after upload.
- **It doesn't replace structured data on the pages themselves.**
  JSON-LD `<script>` blocks in HTML are still required for
  `LocalBusiness`, `Product`, `FAQ`, etc. Sitemap and manifest are
  *meta* — they say "here's a page" and "here's what type of app
  we are", not "here's what the content means".

## Validation tools

- **robots.txt:** [Google Search Console robots tester](https://www.google.com/webmasters/tools/robots-testing-tool)
- **sitemap.xml:** [Google Search Console sitemap submission](https://search.google.com/search-console)
- **manifest:** Chrome DevTools → Application → Manifest tab
- **structured data on pages:** [Rich Results Test](https://search.google.com/test/rich-results)

See the full files in
[`../snippets/robots.txt`](../snippets/robots.txt),
[`../snippets/sitemap.xml`](../snippets/sitemap.xml), and
[`../snippets/site.webmanifest`](../snippets/site.webmanifest).
