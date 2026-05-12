# PHPMailer Contact Form — DSGVO-Compliant

A contact-form backend that takes a POST submission, sends an email
through SMTP, and survives spam, scraping, and German privacy law.

## What "DSGVO-compliant" actually means here

DSGVO (the German GDPR) doesn't dictate how to write a contact form.
It dictates *outcomes*: don't store more personal data than
necessary, don't keep it longer than necessary, anonymize where
possible, document what you do.

For a contact form, that translates to four concrete rules:

1. **Anonymize logged IP addresses.** Replace the last octet
   (`192.168.1.123` → `192.168.1.xxx`) before writing to logs.
2. **Time-limit log retention.** Auto-delete log entries older
   than 30 days.
3. **Don't log message content.** Logs prove the request happened;
   they don't preserve what was said.
4. **Document the data flow.** The privacy policy says where the
   data goes; the code matches.

The handler implements all four. None of them is much code; the
discipline is in remembering to do them.

## Anatomy of the request

The form on the page POSTs JSON to `/send-contact.php`. The
handler runs through these checks in order, exiting at the first
failure:

```
1. Method check          POST only — anything else gets 405
2. Content-Type check    application/json only
3. Origin check          Must match ALLOWED_ORIGINS list
4. Rate limit            Max N submissions per IP per hour
5. Honeypot              Hidden field must be empty (bots fill it)
6. Required fields       name, email, message at minimum
7. Email format          filter_var(..., FILTER_VALIDATE_EMAIL)
8. Length limits         Reject obvious overflow attempts
9. SMTP send             PHPMailer with TLS to configured server
10. Log + respond        JSON success/error, exit
```

Each check is a function call that either returns or calls
`sendResponse(false, "reason", $http_code)` and exits. No nested
ifs, no else branches.

## Origin check vs CSRF token

The handler uses an Origin allow-list (`ALLOWED_ORIGINS` in config)
rather than a CSRF token because:

- The form is the same on every page; tokens would have to be
  injected per-page.
- The endpoint never modifies server state — it only sends an email.
  The CSRF risk is "spam from the user's own domain", which is
  small.
- Origin checks are free and catch most cross-origin abuse.

For an authenticated, state-modifying endpoint, use real CSRF
tokens (or SameSite cookies). For an unauthenticated contact form,
Origin + rate limit + honeypot is enough.

## Rate limiting in a flat file

The handler stores rate-limit counters in `logs/rate_limits.json`,
keyed by anonymized IP, with a sliding window:

```json
{
  "192.168.1.xxx": {
    "count": 3,
    "first_request": 1738341600,
    "last_request": 1738341845
  }
}
```

On each request:
1. Read the file, locking with `flock` to avoid race conditions.
2. Prune entries where `first_request > now - RATE_LIMIT_PERIOD`.
3. Find or create the entry for the current IP.
4. If `count >= RATE_LIMIT_MAX`, reject.
5. Otherwise increment, write, continue.

A flat file is fine for sites with low contact-form volume (< 100
submissions/day). For higher volume, move to Redis or a real DB.

## Honeypot vs CAPTCHA

The handler uses a honeypot (hidden form field — bots fill it,
humans don't see it) before falling back to CAPTCHA.

- **Honeypot:** zero user friction, catches ~80% of automated spam.
  Free.
- **CAPTCHA (reCAPTCHA / hCaptcha):** catches the rest, but adds
  user friction (clicks, image grids), and sends user data to a
  third party — which adds privacy-policy complexity.

For a low-traffic corporate site, honeypot + rate limit is usually
enough. Add CAPTCHA only if you start seeing through-spam in your
inbox.

## SMTP setup with PHPMailer

PHPMailer supports every SMTP server you'd realistically encounter.
The configuration matters more than the code:

```php
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host       = SMTP_HOST;        // smtp.example.com
$mail->SMTPAuth   = true;
$mail->Username   = SMTP_USERNAME;     // SMTP login email
$mail->Password   = SMTP_PASSWORD;     // SMTP password / app password
$mail->SMTPSecure = SMTP_SECURE;       // 'tls' for 587, 'ssl' for 465
$mail->Port       = SMTP_PORT;         // 587 (TLS) or 465 (SSL)
$mail->CharSet    = 'UTF-8';

$mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
$mail->addAddress(MAIL_TO, MAIL_TO_NAME);
$mail->addReplyTo($form_email, $form_name);

$mail->isHTML(true);
$mail->Subject = "Contact form: " . $form_subject;
$mail->Body    = $html_body;
$mail->AltBody = $plain_text_body;

$mail->send();
```

A few things that bite:

- **Port 587 with TLS** is the modern default. Port 465 with SSL
  works but is older. Don't use port 25 — most providers block it.
- **The `setFrom` address must be a real mailbox** on the SMTP
  server, otherwise SPF / DKIM will reject the email or send it
  to spam. Don't use the form submitter's email as `From` —
  that's `Reply-To`.
- **`isHTML(true)` requires both `Body` (HTML) and `AltBody`
  (plain text)** for clients that strip HTML.
- **Catch `PHPMailer\Exception` separately from generic
  `Exception`.** SMTP errors give detailed messages; surface them
  in logs but never to the user (they leak SMTP server hostnames,
  account names, etc.).

See the full handler in
[`../snippets/send-contact.php`](../snippets/send-contact.php).

## Failure modes

| Failure | Cause | Response |
|---|---|---|
| 401 SMTP authentication failed | Wrong username/password, or password expired | Update `config.php`, re-test |
| 535 5.7.8 Authentication required | Account locked by the SMTP provider (too many failed attempts) | Wait or contact provider |
| Email accepted but never arrives | SPF/DKIM not set up for the sending domain | Add SPF + DKIM records to DNS, verify with [mail-tester.com](https://mail-tester.com) |
| Email arrives in spam folder | Same as above, plus DMARC and content quality | Check headers, simplify HTML |
| 429 Too many requests | The form's own rate limit fired | Reset by deleting `logs/rate_limits.json`, or wait the period out |
| 403 Origin not allowed | Form is on a domain not in `ALLOWED_ORIGINS` | Add domain to config |
| Submissions disappear silently | PHP error before `sendResponse` runs | Check `logs/contact.log` and PHP error log |

## What this pattern is NOT

- **Not a bulk-mail tool.** PHPMailer through a regular SMTP
  provider has rate limits (IONOS: ~100/hour, Gmail: ~500/day).
  For newsletters or transactional volume, use a dedicated provider
  like Postmark, SES, or SendGrid.
- **Not a replacement for a proper contact backend.** If the site
  needs a CRM, ticket tracking, or auto-replies that branch on
  content, this is too thin. Connect to a real service.
- **Not safe behind only a honeypot in 2026.** Sophisticated spam
  bots fill honeypots correctly. Pair it with a rate limit and
  monitor your inbox; add a CAPTCHA when through-spam appears.
