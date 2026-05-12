# Code Snippets

Selected reusable patterns from the production prototype, sanitized
and generalized.

| File | Purpose |
|---|---|
| `send-contact.php` | PHP contact-form handler with PHPMailer SMTP, DSGVO-anonymized logging, rate limiting, honeypot, and Origin allow-list. |
| `config.example.php` | Configuration template for the contact handler — placeholders only, no real credentials. |
| `sw.js` | Service worker with network-first cache strategy, install/activate/fetch lifecycle, and offline fallback. |
| `htaccess-production.txt` | Apache `.htaccess` with security headers (CSP, HSTS, etc.), gzip compression, browser caching, HTTPS redirect, and clean URLs. |
| `robots.txt` | SEO `robots.txt` with explicit per-bot rules, SEO-spam bot blocking, and sitemap pointers. |
| `sitemap.xml` | XML sitemap with `<lastmod>`, `<changefreq>`, image schema, and hreflang for multilingual variants. |
| `site.webmanifest` | PWA Web App Manifest with icons, shortcuts, share_target, and protocol_handlers. |

## How to use

Each snippet is self-contained. Drop into a project, replace
placeholders (domain names, email addresses, brand colors, paths)
with real values, and connect to your stack.

For the PHP backend specifically:
1. Install PHPMailer via Composer: `composer require phpmailer/phpmailer`
2. Copy `config.example.php` to `config.php` and fill in real
   credentials. **Never commit `config.php` to source control.**
3. Ensure `logs/` directory exists and is writable by the web
   server, but not browseable (the `.htaccess` snippet handles
   the latter).

## Sanitization notes

All snippets have been processed to remove:

- Real domain names (replaced with `example.com`)
- Real email addresses (replaced with `info@example.com`)
- Real phone numbers (replaced with placeholders)
- Real SMTP credentials (replaced with `IONOS_*_HERE` placeholders)
- Real customer / brand data
- Internal-comment language inconsistencies (translated to English
  for international readers)
