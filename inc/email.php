<?php
/**
 * Tiny mail() wrapper.
 *
 * Hostinger's PHP mail() works out of the box for transactional mail as long
 * as the From: domain matches the hosting account's domain. We set a sensible
 * From and Reply-To so mail clients don't flag the message.
 *
 * For higher deliverability later, swap this for a small SMTP client
 * (e.g. PHPMailer) without changing the call sites.
 */

require_once __DIR__ . '/helpers.php';

function send_email(string $to, string $subject, string $bodyText, ?string $fromName = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $host = parse_url(APP_BASE_URL ?: '', PHP_URL_HOST) ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $from = 'noreply@' . $host;
    $name = $fromName ?: APP_NAME;

    $headers   = [];
    $headers[] = 'From: ' . sprintf('%s <%s>', $name, $from);
    $headers[] = 'Reply-To: ' . $from;
    $headers[] = 'X-Mailer: AiServe';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=utf-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail($to, $safeSubject, $bodyText, implode("\r\n", $headers));
}

/**
 * Same as send_email() but the body is treated as HTML. Text/plain
 * fallback is auto-generated from strip_tags($html).
 */
function send_email_html(string $to, string $subject, string $html, ?string $fromName = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $host = parse_url(APP_BASE_URL ?: '', PHP_URL_HOST) ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $from = 'noreply@' . $host;
    $name = $fromName ?: APP_NAME;

    $boundary = 'aiserve-' . bin2hex(random_bytes(8));
    $textFallback = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    $headers   = [];
    $headers[] = 'From: ' . sprintf('%s <%s>', $name, $from);
    $headers[] = 'Reply-To: ' . $from;
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
    return @mail($to, $safeSubject, $body, implode("\r\n", $headers));
}
