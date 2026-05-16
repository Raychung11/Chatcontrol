<?php
/**
 * Shared chat message bubble renderer.
 * Used by /inbox/chat.php (initial render) and /api/poll.php (live append)
 * so server-rendered and JS-appended bubbles are byte-identical.
 */

require_once __DIR__ . '/helpers.php';

function message_bubble_html(array $m): string
{
    $isOut = $m['direction'] === 'outgoing';
    $cls   = $isOut ? 'msg-out' : 'msg-in';
    $cls  .= ' status-' . e((string)$m['status']);

    $html  = '<div class="msg ' . $cls . '" data-msg-id="' . (int)$m['id'] . '">';
    $html .= '<div class="msg-bubble">';

    if ($isOut && !empty($m['sender_name'])) {
        $html .= '<div class="msg-sender">' . e($m['sender_name']) . '</div>';
    }

    if (($m['message_type'] ?? 'text') !== 'text' && $m['message_type'] !== '') {
        $html .= '<div class="msg-type-tag">' . e(strtoupper((string)$m['message_type']));
        if (!empty($m['media_filename'])) {
            $html .= ' · ' . e($m['media_filename']);
        }
        if (!empty($m['template_name'])) {
            $html .= ' · ' . e($m['template_name']);
        }
        $html .= '</div>';
    }

    $mediaSrc = null;
    if (!empty($m['media_local_path']) && is_readable($m['media_local_path'])) {
        $mediaSrc = '/api/media.php?msg=' . (int)$m['id'];
    }
    $isImage = $mediaSrc && str_starts_with((string)($m['media_mime_type'] ?? ''), 'image/');

    if ($mediaSrc && $isImage) {
        $html .= '<div class="msg-media"><a href="' . e($mediaSrc) . '" target="_blank">'
               . '<img src="' . e($mediaSrc) . '" alt="image"></a></div>';
    } elseif ($mediaSrc) {
        $label = $m['media_filename'] ?: ucfirst((string)$m['message_type']);
        $html .= '<div class="msg-media"><a href="' . e($mediaSrc) . '" target="_blank">Download '
               . e($label) . '</a></div>';
    }

    $html .= '<div class="msg-body">' . nl2br(e((string)$m['message_text'])) . '</div>';

    $html .= '<div class="msg-meta"><span>' . e(fmt_dt($m['created_at'], 'M j, H:i')) . '</span>';
    if ($isOut) {
        $html .= '<span class="msg-status" title="' . e(ucfirst((string)$m['status'])) . '">'
               . delivery_ticks((string)$m['status']) . '</span>';
    }
    if ($m['status'] === 'failed' && !empty($m['error_message'])) {
        $html .= '<span class="msg-error" title="' . e((string)$m['error_message']) . '">· '
               . e((string)$m['error_message']) . '</span>';
    }
    $html .= '</div></div></div>';
    return $html;
}
