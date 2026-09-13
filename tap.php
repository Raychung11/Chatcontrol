<?php
/**
 * GET /tap.php?c=<CARD_TOKEN>
 *
 * The URL every NFC card / QR sticker points at. A single, stable,
 * short entrypoint so that once a batch of stickers is printed it
 * never needs to be re-programmed — routing decisions (which widget
 * channel to open, which branch to assign, which table number to
 * stamp) all live server-side keyed off the token.
 *
 * Flow:
 *   1. Look up the card by token. 404 if unknown or disabled.
 *   2. Record a tap event (for the per-card / campaign analytics
 *      chip shown on /admin/nfc_cards.php).
 *   3. Redirect to /chat.php?c=<channel_token>&nfc=<CARD_TOKEN>.
 *      The chat page passes the card token to widget_start, which
 *      is where the card→session link (web_chat_sessions.nfc_card_id)
 *      gets stamped so the eventual conversation picks up the
 *      card's label/table/branch/campaign at conversation-create
 *      time inside api/widget_send.php.
 *
 * Design notes:
 *   - We deliberately do NOT stuff table_number / branch_id into
 *     the URL. Everything is on the card row on the server, so an
 *     operator can rename "Table 5" → "VIP Booth" without asking
 *     anyone to re-write the physical tag.
 *   - Short token (16 hex chars) keeps the NFC URL under the NTAG213
 *     ~130-byte cap: "https://inbox.aiserve.my/tap.php?c=<16>" is
 *     ~52 bytes. Fits with plenty of room for a longer host name.
 */

require_once __DIR__ . '/inc/helpers.php';

$token = trim((string)($_GET['c'] ?? ''));

// A real tag URL is always /tap.php?c=<16 hex>. Reject anything else
// with a plain 404 (no exposition — a curious probe shouldn't get
// hints about the shape of valid tokens).
if (!preg_match('/^[a-f0-9]{16}$/i', $token)) {
    http_response_code(404);
    exit('Not found.');
}

$db = aiserve_db();
$stmt = $db->prepare(
    'SELECT c.*, ch.webhook_token AS channel_token, ch.status AS channel_status
     FROM nfc_cards c
     INNER JOIN channels ch ON ch.id = c.channel_id
     WHERE c.token = ? LIMIT 1'
);
$stmt->execute([strtolower($token)]);
$card = $stmt->fetch();

if (!$card || !(int)$card['enabled'] || $card['channel_status'] !== 'active') {
    http_response_code(404);
    exit('This card is not active.');
}

// Log the tap. Best-effort — if the analytics insert somehow errors
// we'd rather still land the customer in the widget than break the
// user-visible flow.
try {
    $db->prepare(
        'INSERT INTO nfc_tap_events (card_id, ip, user_agent)
         VALUES (?, ?, ?)'
    )->execute([
        (int)$card['id'],
        mb_substr(client_ip(), 0, 45),
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
} catch (Throwable $e) {
    error_log('[AiServe tap] event log failed: ' . $e->getMessage());
}

// 302 to the widget, carrying the card token so widget_start.php can
// stamp session.nfc_card_id.
$target = '/chat.php?c=' . rawurlencode((string)$card['channel_token'])
        . '&nfc=' . rawurlencode(strtolower($token));

header('Location: ' . $target, true, 302);
header('Cache-Control: no-store'); // never let a CDN cache the redirect
exit;
