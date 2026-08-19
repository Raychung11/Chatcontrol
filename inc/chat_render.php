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

    // Cache the raw message text on the wrapper so JS can copy / reply
    // without a round-trip. `data-msg-text` is HTML-attr-escaped, and
    // JS reads it via .dataset — no innerText scraping (which would
    // include translation blocks + timestamps).
    $rawText = (string)($m['message_text'] ?? '');
    $html  = '<div class="msg ' . $cls . '" data-msg-id="' . (int)$m['id'] . '"'
           . ' data-msg-text="' . e($rawText) . '"'
           . ' data-msg-out="' . ($isOut ? '1' : '0') . '">';
    $html .= '<div class="msg-bubble">';

    // ⋯ Kebab trigger — always in DOM, revealed on hover via CSS. Tap
    // on mobile still shows it because the bubble gets .msg:focus-within.
    $html .= '<button type="button" class="msg-menu-trigger" aria-label="Message actions" title="Actions">⋯</button>';

    if ($isOut) {
        if (($m['sender_type'] ?? '') === 'ai') {
            $html .= '<div class="msg-sender msg-sender-ai">AI bot</div>';
        } elseif (!empty($m['sender_name'])) {
            $html .= '<div class="msg-sender">' . e($m['sender_name']) . '</div>';
        }
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
    $mime    = (string)($m['media_mime_type'] ?? '');
    $isImage = $mediaSrc && str_starts_with($mime, 'image/');
    $isAudio = $mediaSrc && (str_starts_with($mime, 'audio/')
                             || ($m['message_type'] ?? '') === 'audio');
    $isVideo = $mediaSrc && (str_starts_with($mime, 'video/')
                             || ($m['message_type'] ?? '') === 'video');

    if ($mediaSrc && $isImage) {
        // Button (not <a target="_blank">) - the raw new-tab approach hangs
        // installed PWAs on iOS/Android because standalone mode has no way
        // to close the new tab. The JS lightbox opens over the chat and
        // closes cleanly on tap-outside / X / Escape.
        $html .= '<div class="msg-media"><button type="button" class="msg-image-btn" '
               . 'data-media-src="' . e($mediaSrc) . '" '
               . 'aria-label="Open image">'
               . '<img src="' . e($mediaSrc) . '" alt="image" loading="lazy">'
               . '</button></div>';
    } elseif ($mediaSrc && $isAudio) {
        // WhatsApp voice notes ship as audio/ogg (Opus). All modern
        // browsers play it natively via <audio controls>.
        //
        // preload="metadata" (not "none") so the browser fetches just
        // the file header - enough to know duration - without pulling
        // the audio bytes. app.js reads the resulting audio.duration
        // in a loadedmetadata handler and writes it into the sibling
        // .msg-audio-duration span, giving the agent a "0:34" hint
        // BEFORE they decide to hit play. IntersectionObserver in the
        // JS lazy-loads only players that scroll into view, so a chat
        // with 50 voice notes doesn't blast bandwidth up front.
        $html .= '<div class="msg-media msg-audio">'
               . '<audio controls preload="none" data-lazy-meta="1" src="' . e($mediaSrc) . '"></audio>'
               . '<span class="msg-audio-duration" aria-hidden="true">🎙</span>'
               . '<a class="msg-media-download" href="' . e($mediaSrc) . '"'
               . ' download="' . e((string)($m['media_filename'] ?? 'voice.ogg')) . '"'
               . ' title="Download">⬇︎</a>'
               . '</div>';
    } elseif ($mediaSrc && $isVideo) {
        // Inline HTML5 player. preload="metadata" so agents get the
        // duration + first frame without paying full bandwidth.
        $html .= '<div class="msg-media msg-video">'
               . '<video controls preload="metadata" playsinline src="' . e($mediaSrc) . '"></video>'
               . '<a class="msg-media-download" href="' . e($mediaSrc) . '"'
               . ' download="' . e((string)($m['media_filename'] ?? 'video.mp4')) . '"'
               . ' title="Download">⬇︎</a>'
               . '</div>';
    } elseif ($mediaSrc) {
        // Non-media (PDF, doc, other) - standard download link. On PWAs
        // iOS handles the download via the Files app so no stranded tab.
        $label = $m['media_filename'] ?: ucfirst((string)$m['message_type']);
        $html .= '<div class="msg-media"><a href="' . e($mediaSrc) . '" '
               . 'download="' . e((string)($m['media_filename'] ?? 'file')) . '">'
               . '⬇︎ ' . e($label) . '</a></div>';
    } elseif (($m['message_type'] ?? '') === 'audio' || ($m['message_type'] ?? '') === 'video') {
        // Media didn't download (bytes not on disk yet, or gateway didn't
        // forward the audio). Show a helpful placeholder instead of the
        // useless "[audio]" body text.
        $kind = e((string)$m['message_type']);
        $html .= '<div class="msg-media msg-media-missing muted small">'
               . '🎙 ' . $kind . ' message — media not synced. '
               . 'Ask your partner gateway to include audio bytes in the webhook payload.'
               . '</div>';
    }

    $html .= '<div class="msg-body">' . nl2br(e((string)$m['message_text'])) . '</div>';

    // ---- Inline translation (click-to-translate) ----
    // Skip on empty text and on outgoing agent messages (we know what
    // WE wrote). Cached translation, if any, renders straight away;
    // otherwise a small "🌐 Translate" button lets the agent trigger
    // a single Haiku call. api/ai_translate.php caches the result on
    // the messages row so the button is a one-time cost per message.
    $bodyText = trim((string)$m['message_text']);
    if (!$isOut && $bodyText !== '') {
        $hasCached = !empty($m['translated_text']);
        $cachedText = $hasCached ? (string)$m['translated_text'] : '';
        $cachedLang = $hasCached ? (string)($m['translated_to_lang'] ?? '') : '';
        $html .= '<div class="msg-translate" data-msg-id="' . (int)$m['id'] . '">';
        if ($hasCached) {
            $html .= '<div class="msg-translate-out" data-lang="' . e($cachedLang) . '">'
                   . '<span class="msg-translate-flag">🌐 ' . e($cachedLang) . '</span> '
                   . nl2br(e($cachedText))
                   . '</div>';
        } else {
            $html .= '<button type="button" class="msg-translate-btn" '
                   .   'title="Translate this message">🌐 Translate</button>'
                   . '<span class="msg-translate-status muted small"></span>'
                   . '<div class="msg-translate-out" hidden></div>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="msg-meta"><span>' . e(fmt_dt($m['created_at'], 'M j, H:i')) . '</span>';
    if ($isOut) {
        // Rich tooltip explains what each tick state actually means -
        // "Sent" alone used to mislead operators into thinking the
        // customer had received it.
        $html .= '<span class="msg-status" title="' . e(delivery_tick_label((string)$m['status'])) . '">'
               . delivery_ticks((string)$m['status']) . '</span>';
    }
    if ($m['status'] === 'failed' && !empty($m['error_message'])) {
        $html .= '<span class="msg-error" title="' . e((string)$m['error_message']) . '">· '
               . e((string)$m['error_message']) . '</span>';
    }
    $html .= '</div></div></div>';
    return $html;
}
