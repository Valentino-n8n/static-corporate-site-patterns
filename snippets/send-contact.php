<?php
/**
 * ============================================================
 * Contact Form Handler — DSGVO-compliant
 * ============================================================
 *
 * POST /send-contact.php
 *   Body: JSON with { name, email, message, ... }
 *   Returns: JSON { success: bool, message: string, timestamp: string }
 *
 * Pipeline (each step exits on failure):
 *   1. Method = POST
 *   2. Origin in ALLOWED_ORIGINS
 *   3. Rate limit (per anonymized IP, sliding window)
 *   4. Honeypot fields are empty
 *   5. Required fields present
 *   6. Email format valid
 *   7. Send via PHPMailer SMTP
 *   8. Log result (anonymized IP, no message content)
 *
 * Requires:
 *   - config.php with credentials (see config.example.php)
 *   - PHPMailer installed via Composer (vendor/autoload.php)
 *   - logs/ directory writable by the web server
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

// ── Response headers ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── CORS ─────────────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (isOriginAllowed($origin)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────

/** Send a JSON response and exit. */
function sendResponse(bool $success, string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'success'   => $success,
        'message'   => $message,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Get the client IP, anonymized per DSGVO if configured. */
function getClientIP(?bool $anonymize = null): string {
    if ($anonymize === null) {
        $anonymize = defined('IP_ANONYMIZATION') ? IP_ANONYMIZATION : false;
    }

    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    $ip = 'unknown';

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                break;
            }
        }
    }

    if ($anonymize && $ip !== 'unknown') {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // 192.168.1.123 → 192.168.1.xxx
            $ip = preg_replace('/\.\d+$/', '.xxx', $ip);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // 2001:db8::1234 → 2001:db8::xxxx
            $ip = preg_replace('/:[^:]+$/', ':xxxx', $ip);
        }
    }

    return $ip;
}

/** Append a structured log line. Never log message content. */
function logMessage(string $type, string $message, array $data = []): void {
    if (!LOG_ENABLED) return;

    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $entry = sprintf(
        "[%s] [%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $type,
        getClientIP(),
        $message,
        $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : ''
    );

    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

/** Sliding-window rate limit, persisted to a flat JSON file. */
function checkRateLimit(): bool {
    if (!RATE_LIMIT_ENABLED) return true;

    $ip   = getClientIP();
    $now  = time();
    $file = RATE_LIMIT_FILE;

    $logDir = dirname($file);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $data = file_exists($file)
        ? (json_decode(file_get_contents($file), true) ?: [])
        : [];

    // Prune old entries
    foreach ($data as $key => $entry) {
        if ($now - $entry['first_request'] > RATE_LIMIT_PERIOD) {
            unset($data[$key]);
        }
    }

    if (isset($data[$ip])) {
        if ($data[$ip]['count'] >= RATE_LIMIT_MAX) {
            return false;
        }
        $data[$ip]['count']++;
        $data[$ip]['last_request'] = $now;
    } else {
        $data[$ip] = [
            'count'         => 1,
            'first_request' => $now,
            'last_request'  => $now,
        ];
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

// ── Main pipeline ────────────────────────────────────────────

// 1. Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', 405);
}

// 2. Origin
if (!isOriginAllowed($origin)) {
    logMessage('REJECT', 'Bad origin', ['origin' => $origin]);
    sendResponse(false, 'Origin not allowed', 403);
}

// 3. Rate limit
if (!checkRateLimit()) {
    logMessage('REJECT', 'Rate limit exceeded');
    sendResponse(false, 'Too many requests', 429);
}

// 4. Parse body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    sendResponse(false, 'Invalid request body', 400);
}

// 5. Honeypot
foreach (HONEYPOT_FIELDS as $field) {
    if (!empty($body[$field])) {
        logMessage('REJECT', 'Honeypot triggered', ['field' => $field]);
        // Lie to the bot — claim success so it doesn't retry
        sendResponse(true, 'Message sent', 200);
    }
}

// 6. Required fields
$name    = trim($body['name']    ?? '');
$email   = trim($body['email']   ?? '');
$message = trim($body['message'] ?? '');
$subject = trim($body['subject'] ?? 'Contact form submission');

if ($name === '' || $email === '' || $message === '') {
    sendResponse(false, 'Required fields missing', 400);
}

// 7. Email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendResponse(false, 'Invalid email address', 400);
}

// 8. Length sanity check
if (strlen($name) > 200 || strlen($message) > 10_000) {
    sendResponse(false, 'Field length exceeds limits', 400);
}

// 9. Send via PHPMailer
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_TO, MAIL_TO_NAME);
    $mail->addReplyTo($email, $name);

    if (MAIL_CC !== '') {
        foreach (explode(',', MAIL_CC) as $cc) {
            $mail->addCC(trim($cc));
        }
    }
    if (MAIL_BCC !== '') {
        foreach (explode(',', MAIL_BCC) as $bcc) {
            $mail->addBCC(trim($bcc));
        }
    }

    $safeName    = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
    $safeEmail   = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = "<p><strong>From:</strong> $safeName &lt;$safeEmail&gt;</p>"
                   . "<p><strong>Message:</strong></p>"
                   . "<p style='white-space:pre-wrap'>$safeMessage</p>";
    $mail->AltBody = "From: $name <$email>\n\n$message";

    $mail->send();

    logMessage('SUCCESS', 'Email sent', ['to' => MAIL_TO]);
    sendResponse(true, 'Message sent', 200);

} catch (MailerException $e) {
    logMessage('ERROR', 'SMTP failure', ['error' => $mail->ErrorInfo]);
    sendResponse(false, 'Email could not be sent', 500);
} catch (Exception $e) {
    logMessage('ERROR', 'Unexpected failure', ['error' => $e->getMessage()]);
    sendResponse(false, 'Email could not be sent', 500);
}
