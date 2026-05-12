# Apache `.htaccess` for Production

The `.htaccess` file is what turns a generic Apache install into
a production-grade web server: security headers, response
compression, browser caching, clean URLs, and graceful 404s.

This doc walks through each block, explains *why* it's there,
and notes where production sites get this wrong.

## Block 1 — Security headers

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=(), fullscreen=(self), payment=()"
    Header always set Content-Security-Policy "default-src 'self'; …"
</IfModule>
```

What each one does and why:

| Header | What it does | Why it matters |
|---|---|---|
| `X-Content-Type-Options: nosniff` | Tells browsers to trust the declared `Content-Type` | Stops "this looks like HTML, let's render it" attacks on uploaded files |
| `X-Frame-Options: DENY` | Refuses to be embedded in `<iframe>` | Stops clickjacking |
| `X-XSS-Protection: 1; mode=block` | Tells the browser's built-in XSS filter to block, not sanitize | Legacy header — modern CSP supersedes it, but harmless to keep |
| `Referrer-Policy: strict-origin-when-cross-origin` | Sends the full referrer to same-origin, only the origin to cross-origin | Privacy — doesn't leak the page path to third parties |
| `Strict-Transport-Security` | Forces HTTPS for the next year, including subdomains | Prevents downgrade attacks |
| `Permissions-Policy` | Disables specific browser APIs (geolocation, mic, camera, payment) | If a third-party script gets injected, it can't use these |
| `Content-Security-Policy` | Whitelists what scripts/styles/images/etc. can load | Last line of defense against XSS |

The `always` keyword matters: without it, the headers are only set
on 2xx responses. With it, they're set on 4xx and 5xx too — which
is what you want for security headers.

### CSP — the one that gets misconfigured

The Content-Security-Policy is the most powerful and the most
likely to break things. The pattern in the snippet is conservative:

```
default-src 'self';
script-src 'self' 'unsafe-inline' https://www.googletagmanager.com;
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
font-src 'self' https://fonts.gstatic.com;
img-src 'self' data: https: blob:;
connect-src 'self' https://www.google-analytics.com;
frame-src 'none';
object-src 'none';
base-uri 'self';
form-action 'self';
```

Three notes:

- **`'unsafe-inline'` is in `script-src` and `style-src`.** It
  shouldn't be, ideally — but inline `<script>` and `<style>` are
  pervasive in legacy code, and removing them is a separate
  refactor. For new sites, use a nonce-based CSP instead and
  remove `'unsafe-inline'`.
- **`img-src https:`** is broad on purpose — corporate sites embed
  images from many CDNs.
- **`frame-src 'none'`** prevents the page from embedding any
  iframes, which is paranoid but safe for marketing sites.

Test your CSP at [csp-evaluator.withgoogle.com](https://csp-evaluator.withgoogle.com/).

## Block 2 — Compression (`mod_deflate`)

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/json
    AddOutputFilterByType DEFLATE application/xml image/svg+xml

    SetEnvIfNoCase Request_URI \.(?:gif|jpe?g|png|webp|ico)$ no-gzip
    SetEnvIfNoCase Request_URI \.(?:exe|t?gz|zip|bz2|sit|rar)$ no-gzip
</IfModule>
```

`mod_deflate` is gzip compression. On a typical marketing-site
HTML/CSS/JS payload, it cuts response size by 60–80%, which
roughly halves load time on slow connections.

The `SetEnvIfNoCase` lines are equally important: don't waste CPU
re-compressing already-compressed formats. Images (JPEG, PNG, WebP)
are already compressed; archives (ZIP, RAR) are too.

Modern alternative: `mod_brotli` does the same job at higher
compression ratios. If the host supports it, prefer it:

```apache
<IfModule mod_brotli.c>
    AddOutputFilterByType BROTLI_COMPRESS text/html text/css …
</IfModule>
```

Brotli only works over HTTPS (which is the modern norm anyway).

## Block 3 — Browser caching (`mod_expires`)

```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/html "access plus 0 seconds"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
```

Three tiers of cacheability, picked deliberately:

| Asset type | Cache duration | Why |
|---|---|---|
| HTML | 0 seconds | Always fetch fresh — content changes |
| CSS / JS | 1 month | Hash-named bundles change rarely; browser revalidates monthly |
| Images / fonts | 1 year | Filenames are content-addressed; never change in practice |

The `Cache-Control: immutable` directive is even better than long
`ExpiresByType` for static assets — it tells browsers "don't even
bother revalidating, just use the cache":

```apache
<FilesMatch "\.(jpg|jpeg|png|gif|ico|svg|webp|avif)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
```

`immutable` requires that assets be content-hashed (e.g.
`/js/script.abc123.min.js`). For non-hashed assets, drop
`immutable` and rely on the `max-age`.

## Block 4 — URL rewrites (`mod_rewrite`)

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Force www. (or strip www. — pick one)
    RewriteCond %{HTTP_HOST} !^www\. [NC]
    RewriteRule ^ https://www.%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Strip .html extension from URLs
    RewriteCond %{REQUEST_FILENAME}.html -f
    RewriteRule ^(.+?)/?$ $1.html [L]

    # Custom 404
    ErrorDocument 404 /404.html
</IfModule>
```

The HTTPS and www redirects use `301` (permanent) — search engines
treat 301 as canonical. Don't use `302` (temporary) for these;
search engines treat them as "this is not the final URL" and
won't update their index.

The "strip `.html`" rewrite lets `/services` serve `/services.html`,
which is cleaner. The flag `[L]` means "this is the last rule —
don't try to apply more rewrites".

## Failure modes

| Problem | Cause | Fix |
|---|---|---|
| Headers not appearing | `mod_headers` not enabled, or hosting plan strips them | Check with `curl -I https://yoursite.com`; contact hosting if missing |
| 500 Internal Server Error after deploy | Syntax error in `.htaccess` | Run `apachectl configtest`; if no shell, comment out the file in halves until you find the bad line |
| CSP blocks legitimate scripts | Allow-list missing the source | Open DevTools console, copy the CSP violation report, add the host to the relevant directive |
| HTTPS redirect loop | Hosting terminates SSL upstream and `%{HTTPS}` is always `off` | Use `%{HTTP:X-Forwarded-Proto}` instead |
| Compression not working | The hosting setup uses Nginx, not Apache | `.htaccess` is ignored; configure compression in the Nginx config or hosting panel |
| Cache headers ignored | `mod_expires` not enabled | Check with `curl -I` for `Cache-Control` and `Expires` headers; ask hosting to enable |

See the full file in
[`../snippets/htaccess-production.txt`](../snippets/htaccess-production.txt).
