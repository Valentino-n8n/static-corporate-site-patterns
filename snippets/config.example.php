<?php
/**
 * ============================================================
 * Contact Form Configuration — Template
 * ============================================================
 *
 * Copy this file to config.php and fill in your real credentials.
 * NEVER commit config.php to source control.
 *
 * The example values below are placeholders — replace every line
 * marked with HERE before deployment.
 */

// ── Prevent direct browser access ────────────────────────────
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    http_response_code(403);
    die('Access denied');
}

// ── Environment ──────────────────────────────────────────────
define('ENVIRONMENT', 'production');  // 'development' or 'production'
define('DEBUG_MODE', false);           // true → verbose error output

if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ── SMTP credentials (PHPMailer) ─────────────────────────────
// For IONOS:  smtp.ionos.de port 587 with TLS
// For GMX:    mail.gmx.net  port 587 with TLS
// For Gmail:  smtp.gmail.com port 587 with TLS (use app password, not main password)
// For SES:    email-smtp.<region>.amazonaws.com port 587 with TLS
define('SMTP_HOST',     'smtp.example.com');
define('SMTP_PORT',     587);
define('SMTP_SECURE',   'tls');         // 'tls' for 587, 'ssl' for 465
define('SMTP_USERNAME', 'SMTP_USER_HERE');
define('SMTP_PASSWORD', 'SMTP_PASSWORD_HERE');

// ── Email addresses ──────────────────────────────────────────
// MAIL_FROM must be a real mailbox on the SMTP host. The form
// submitter's email goes into Reply-To, not From — otherwise
// SPF/DKIM will reject the message.
define('MAIL_FROM',      'info@example.com');
define('MAIL_FROM_NAME', 'Contact Form');
define('MAIL_TO',        'info@example.com');
define('MAIL_TO_NAME',   'Recipient');
define('MAIL_CC',        '');           // Comma-separated emails or empty
define('MAIL_BCC',       '');           // Comma-separated emails or empty

// ── DSGVO / GDPR settings ────────────────────────────────────
define('LOG_RETENTION_DAYS', 30);       // Auto-delete log entries older than this
define('IP_ANONYMIZATION',   true);     // Replace last octet of IP in logs

// ── Rate limiting ────────────────────────────────────────────
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_MAX',     5);        // Max submissions per IP
define('RATE_LIMIT_PERIOD',  3600);     // Time window, in seconds (3600 = 1 hour)
define('RATE_LIMIT_FILE',    __DIR__ . '/logs/rate_limits.json');

// ── Logging ──────────────────────────────────────────────────
define('LOG_ENABLED',  true);
define('LOG_FILE',     __DIR__ . '/logs/contact.log');
define('LOG_MAX_SIZE', 5_242_880);      // 5 MB

// ── Allowed origins (CORS) ───────────────────────────────────
// The form is rejected if its Origin header isn't in this list.
define('ALLOWED_ORIGINS', [
    'https://www.example.com',
    'https://example.com',
]);

// Add localhost for local dev only
if (ENVIRONMENT === 'development') {
    define('DEV_ORIGINS', [
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:3000',
        'http://localhost:5500',
    ]);
}

// ── Honeypot field names ─────────────────────────────────────
// Hidden form inputs by these names. Bots fill them; humans don't.
define('HONEYPOT_FIELDS', ['website', 'url', 'homepage']);

// ── Paths ────────────────────────────────────────────────────
define('BASE_PATH',   __DIR__);
define('LOGS_PATH',   __DIR__ . '/logs');
define('VENDOR_PATH', __DIR__ . '/vendor');

// ── Timezone ─────────────────────────────────────────────────
date_default_timezone_set('Europe/Berlin');

// ── Session security ─────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure',   1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
}

// ── Helpers ──────────────────────────────────────────────────
function getAllowedOrigins() {
    $origins = ALLOWED_ORIGINS;
    if (defined('DEV_ORIGINS')) {
        $origins = array_merge($origins, DEV_ORIGINS);
    }
    return $origins;
}

function isOriginAllowed($origin) {
    return in_array($origin, getAllowedOrigins(), true);
}
