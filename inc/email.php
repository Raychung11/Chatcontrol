<?php
/**
 * Outbound email — text + HTML variants.
 *
 * Two backends:
 *   - PHP mail()  — default, works on Hostinger shared where the domain
 *                   inherits the local Postfix outbound reputation
 *   - SMTP relay  — when platform_settings.smtp_host is set, we
 *                   authenticate + submit through that MTA (e.g.
 *                   smtp.hostinger.com on port 465 for
 *                   noreply@aiserve.my). Guarantees SPF/DKIM pass
 *                   because the mail comes from Hostinger's own
 *                   servers with the mailbox's authenticated identity.
 *
 * Configure via platform_settings (edit in Adminer or /admin/pricing.php):
 *
 *   mail_from_address   noreply@aiserve.my
 *   mail_from_name      AiServe
 *   smtp_host           smtp.hostinger.com          (leave blank for mail())
 *   smtp_port           465                         (or 587 for STARTTLS)
 *   smtp_secure         ssl                         (ssl | tls | none)
 *   smtp_user           noreply@aiserve.my
 *   smtp_pass           <mailbox-password>
 */

require_once __DIR__ . '/helpers.php';

/**
 * Read the configured From address / name. Falls back to
 * noreply@<current-host> if unset.
 */
function mail_from_config(): array
{
    $host = parse_url(APP_BASE_URL ?: '', PHP_URL_HOST) ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $addr = trim((string)platform_setting('mail_from_address', ''));
    if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        $addr = 'noreply@' . $host;
    }
    $name = trim((string)platform_setting('mail_from_name', APP_NAME));
    if ($name === '') $name = APP_NAME;
    return ['address' => $addr, 'name' => $name];
}

function send_email(string $to, string $subject, string $bodyText, ?string $fromName = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $from = mail_from_config();
    if ($fromName) $from['name'] = $fromName;

    $headers   = [];
    $headers[] = 'From: ' . sprintf('%s <%s>', $from['name'], $from['address']);
    $headers[] = 'Reply-To: ' . $from['address'];
    $headers[] = 'X-Mailer: AiServe';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=utf-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // SMTP if configured, else PHP mail().
    if (mail_smtp_configured()) {
        return mail_smtp_send($from, $to, $safeSubject, $bodyText, $headers);
    }
    return @mail($to, $safeSubject, $bodyText, implode("\r\n", $headers));
}

/**
 * Same as send_email() but body is HTML with an auto-generated text
 * fallback (from strip_tags($html)).
 */
function send_email_html(string $to, string $subject, string $html, ?string $fromName = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $from = mail_from_config();
    if ($fromName) $from['name'] = $fromName;

    $boundary     = 'aiserve-' . bin2hex(random_bytes(8));
    $textFallback = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    $headers   = [];
    $headers[] = 'From: ' . sprintf('%s <%s>', $from['name'], $from['address']);
    $headers[] = 'Reply-To: ' . $from['address'];
    $headers[] = 'X-Mailer: AiServe';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
    $body .= $textFallback . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=utf-8\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--{$boundary}--";

    $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (mail_smtp_configured()) {
        return mail_smtp_send($from, $to, $safeSubject, $body, $headers);
    }
    return @mail($to, $safeSubject, $body, implode("\r\n", $headers));
}

// ---------------------------------------------------------------------
// Minimal SMTP client — fsockopen + AUTH LOGIN. No external libs.
// ---------------------------------------------------------------------

function mail_smtp_configured(): bool
{
    return trim((string)platform_setting('smtp_host', '')) !== '';
}

/**
 * Submit one message through SMTP relay. Returns true on 2xx from the
 * DATA response. Best-effort: logs any protocol error to error_log
 * so failures are visible in /var/log/nginx/aiserve.error.log.
 */
function mail_smtp_send(array $from, string $to, string $subject, string $body, array $headers): bool
{
    $host   = trim((string)platform_setting('smtp_host', ''));
    $port   = (int)platform_setting('smtp_port', '465');
    $secure = strtolower(trim((string)platform_setting('smtp_secure', 'ssl'))); // ssl | tls | none
    $user   = (string)platform_setting('smtp_user', '');
    $pass   = (string)platform_setting('smtp_pass', '');
    if ($host === '') return false;

    $connectHost = ($secure === 'ssl') ? 'ssl://' . $host : $host;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client(
        $connectHost . ':' . $port,
        $errno, $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );
    if (!$fp) {
        error_log('[AiServe SMTP] connect failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }
    stream_set_timeout($fp, 15);

    $expect = function (int $wantedCode) use ($fp): bool {
        $line = '';
        do {
            $chunk = fgets($fp, 1024);
            if ($chunk === false) return false;
            $line = $chunk;
        } while (isset($line[3]) && $line[3] === '-');
        return (int)substr($line, 0, 3) === $wantedCode;
    };
    $send = function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    if (!$expect(220)) { fclose($fp); return false; }

    $ehloHost = parse_url(APP_BASE_URL ?: '', PHP_URL_HOST) ?: 'localhost';
    $send('EHLO ' . $ehloHost);
    if (!$expect(250)) { fclose($fp); return false; }

    if ($secure === 'tls') {
        $send('STARTTLS');
        if (!$expect(220)) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('[AiServe SMTP] STARTTLS failed');
            fclose($fp); return false;
        }
        $send('EHLO ' . $ehloHost);
        if (!$expect(250)) { fclose($fp); return false; }
    }

    if ($user !== '') {
        $send('AUTH LOGIN');
        if (!$expect(334)) { fclose($fp); return false; }
        $send(base64_encode($user));
        if (!$expect(334)) { fclose($fp); return false; }
        $send(base64_encode($pass));
        if (!$expect(235)) {
            error_log('[AiServe SMTP] auth failed for ' . $user);
            fclose($fp); return false;
        }
    }

    $send('MAIL FROM:<' . $from['address'] . '>');
    if (!$expect(250)) { fclose($fp); return false; }
    $send('RCPT TO:<' . $to . '>');
    if (!$expect(250)) { fclose($fp); return false; }
    $send('DATA');
    if (!$expect(354)) { fclose($fp); return false; }

    // Build the full message: our headers + a Subject/To/Date line +
    // the body. Any leading dot on a data line must be escaped per
    // RFC 5321 5.1 (dot-stuffing).
    $msg = 'To: ' . $to . "\r\n"
         . 'Subject: ' . $subject . "\r\n"
         . 'Date: ' . date('r') . "\r\n"
         . implode("\r\n", $headers) . "\r\n\r\n"
         . $body;
    $msg = preg_replace('/^\./m', '..', $msg);
    fwrite($fp, $msg . "\r\n.\r\n");
    $ok = $expect(250);

    $send('QUIT');
    fclose($fp);
    return $ok;
}
