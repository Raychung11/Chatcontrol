<?php
/**
 * Public invoice viewer.
 *
 * URL: /invoice.php?id=<id>&token=<view_token>
 *
 * Token-protected — anyone with the URL can view (like a Stripe hosted
 * invoice link). No login required so the customer's accountant can
 * download without an AiServe account.
 */
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/invoicing.php';

$id    = (int)($_GET['id']    ?? 0);
$token = (string)($_GET['token'] ?? '');
if ($id <= 0 || strlen($token) < 20) {
    http_response_code(404);
    exit('Invoice not found.');
}

$inv = invoicing_get_by_token($id, $token);
if (!$inv) {
    http_response_code(404);
    exit('Invoice not found or token expired.');
}

echo invoicing_render_html($inv, false);
