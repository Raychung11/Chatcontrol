<?php
/**
 * Meta (Facebook + Instagram) comment ingestion.
 *
 * Converts a Meta webhook change event into rows in contacts +
 * conversations + messages, so the same shared-inbox UI shows comment
 * threads next to WhatsApp conversations.
 *
 * Data model rules (see docs on `platform` columns in phase 23 migration):
 *   - contacts.platform disambiguates FB/IG users from WA numbers so
 *     the (company_id, wa_id) unique key still works for all three.
 *   - conversations.platform tags the thread; UI uses it to render the
 *     right icon and to pick the reply endpoint.
 *   - conversations.external_thread_id holds the TOP-LEVEL comment id.
 *     Every reply-to-a-comment on the same top-level thread appends
 *     into the same conversation.
 *   - conversations.parent_post_id/url/preview holds the enclosing post
 *     so agents see context before replying.
 */

require_once __DIR__ . '/../inc/meta_api.php';

/**
 * Entry point for a Page-object entry.
 *
 * Meta's feed change value shape (for a new comment):
 *   {
 *     item: 'comment', verb: 'add',
 *     comment_id: 'PAGEID_POSTID_COMMENTID',
 *     post_id:    'PAGEID_POSTID',
 *     parent_id:  <post_id if top-level, else parent comment_id>,
 *     message:    'the comment text',
 *     created_time: 1700000000,
 *     from: { id: 'USERID', name: 'Full Name' }
 *   }
 *
 * Non-comment events (posts, likes, reactions) are ignored for phase 1.
 */
function meta_ingest_page_change(array $channel, array $change): void
{
    if (($change['field'] ?? '') !== 'feed') {
        return;
    }
    $v = $change['value'] ?? [];
    if (!is_array($v) || ($v['item'] ?? '') !== 'comment') {
        return;
    }
    $verb = (string)($v['verb'] ?? '');
    if ($verb !== 'add') {
        // 'edited', 'remove', 'hide' are useful signals but out of scope
        // for the ingestion MVP. Store the raw event so we can add
        // reactions to them without re-plumbing the webhook.
        return;
    }

    $commentId = (string)($v['comment_id'] ?? '');
    $postId    = (string)($v['post_id']    ?? '');
    $parentId  = (string)($v['parent_id']  ?? $postId);
    $body      = (string)($v['message']    ?? '');
    $createdTs = isset($v['created_time']) ? (int)$v['created_time'] : time();
    $fromId    = (string)($v['from']['id']   ?? '');
    $fromName  = (string)($v['from']['name'] ?? '');

    if ($commentId === '' || $fromId === '') {
        return;
    }

    // Don't ingest our own Page's replies as customer comments — those
    // arrive as agent outbound messages via the reply-back API and
    // Meta echoes them here for consistency. Filter them out.
    if ($fromId === (string)($channel['meta_page_id'] ?? '')) {
        return;
    }

    meta_store_comment_message(
        $channel,
        'facebook',
        'fb_comment',
        $commentId,
        $parentId,
        $postId,
        $fromId,
        $fromName,
        $body,
        $createdTs
    );
}

/**
 * Entry point for an Instagram-object entry.
 *
 * IG's comments-field value shape:
 *   {
 *     id: 'COMMENT_ID',
 *     text: 'the comment',
 *     media: { id: 'MEDIA_ID', media_product_type: 'FEED' },
 *     from: { id: 'IG_USER_ID', username: 'handle' },
 *     parent_id: '<parent comment id if nested reply>'
 *   }
 *
 * IG payloads don't carry verb — every emitted event is a new comment.
 */
function meta_ingest_ig_change(array $channel, array $change): void
{
    if (($change['field'] ?? '') !== 'comments') {
        return;
    }
    $v = $change['value'] ?? [];
    if (!is_array($v)) {
        return;
    }

    $commentId = (string)($v['id']   ?? '');
    $body      = (string)($v['text'] ?? '');
    $mediaId   = (string)($v['media']['id'] ?? '');
    $parentId  = (string)($v['parent_id'] ?? $mediaId);
    $fromId    = (string)($v['from']['id']       ?? '');
    $fromName  = (string)($v['from']['username'] ?? '');
    $createdTs = time();

    if ($commentId === '' || $fromId === '') {
        return;
    }
    if ($fromId === (string)($channel['meta_ig_business_id'] ?? '')) {
        return;
    }

    meta_store_comment_message(
        $channel,
        'instagram',
        'ig_comment',
        $commentId,
        $parentId,
        $mediaId,
        $fromId,
        $fromName,
        $body,
        $createdTs
    );
}

/**
 * Insert the comment as one message on a per-thread conversation.
 *
 * Threading rules:
 *   - If parent_id references the enclosing post/media, this is a
 *     top-level comment -> open a new conversation keyed on comment_id.
 *   - If parent_id references another comment we've already stored,
 *     append to that comment's conversation (nested reply).
 *   - If parent_id references a comment we HAVEN'T seen (e.g. webhook
 *     came out of order), fall back to opening a new conversation.
 */
function meta_store_comment_message(
    array $channel,
    string $contactPlatform,   // 'facebook' | 'instagram'
    string $convPlatform,      // 'fb_comment' | 'ig_comment'
    string $commentId,
    string $parentId,
    string $enclosingPostId,
    string $fromId,
    string $fromName,
    string $body,
    int    $createdTs
): void {
    $db        = aiserve_db();
    $companyId = (int)$channel['company_id'];
    $channelId = (int)$channel['id'];

    // De-dupe: the comment_id is unique across FB Graph, so wa_message_id
    // (which is our generic external id column) is the natural dedupe key.
    $check = $db->prepare('SELECT id FROM messages WHERE wa_message_id = ? LIMIT 1');
    $check->execute([$commentId]);
    if ($check->fetchColumn()) {
        return;
    }

    // ---------- contact ----------
    $contactId = meta_upsert_contact($companyId, $contactPlatform, $fromId, $fromName);

    // ---------- conversation ----------
    // Find the parent conversation if this is a nested reply to a
    // comment we've already ingested.
    $parentConversationId = 0;
    if ($parentId !== '' && $parentId !== $enclosingPostId) {
        $q = $db->prepare(
            'SELECT c.id
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE m.wa_message_id = ? AND c.company_id = ?
             LIMIT 1'
        );
        $q->execute([$parentId, $companyId]);
        $parentConversationId = (int)$q->fetchColumn();
    }

    $previewText = mb_substr($body !== '' ? $body : '[empty comment]', 0, 500);
    $messageDate = date('Y-m-d H:i:s', $createdTs);

    if ($parentConversationId > 0) {
        $conversationId = $parentConversationId;
        $upd = $db->prepare(
            'UPDATE conversations
             SET status = IF(status = "closed", "open", status),
                 last_message_text = ?, last_message_at = ?,
                 last_customer_message_at = ?, unread_count = unread_count + 1
             WHERE id = ?'
        );
        $upd->execute([$previewText, $messageDate, $messageDate, $conversationId]);
    } else {
        // New top-level thread -> new conversation. Fetch the enclosing
        // post preview once so agents see what post the comment is on.
        $postPreview = meta_fetch_and_cache_post_preview($channel, $convPlatform, $enclosingPostId);

        $ins = $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, status, platform,
                 external_thread_id, parent_post_id, parent_post_url, parent_post_preview,
                 last_message_text, last_message_at,
                 last_customer_message_at, unread_count)
             VALUES (?, ?, ?, "open", ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $ins->execute([
            $companyId, $channelId, $contactId, $convPlatform,
            $commentId, $enclosingPostId,
            $postPreview['url']     ?? null,
            $postPreview['preview'] ?? null,
            $previewText, $messageDate, $messageDate,
        ]);
        $conversationId = (int)$db->lastInsertId();
    }

    // ---------- message ----------
    try {
        $ins = $db->prepare(
            'INSERT INTO messages
                (company_id, channel_id, conversation_id, contact_id, sender_type,
                 wa_message_id, direction, message_type, message_text, status, created_at)
             VALUES (?, ?, ?, ?, "customer", ?, "incoming", "comment", ?, "received", ?)'
        );
        $ins->execute([
            $companyId, $channelId, $conversationId, $contactId,
            $commentId, $body, $messageDate,
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe meta_ingest] message insert failed: ' . $e->getMessage());
    }
}

/**
 * Upsert a contact keyed on (company_id, platform, external_id).
 *
 * We store the external id in `wa_id` and disambiguate via `platform` to
 * avoid a mass rename. Existing WA contacts keep working; FB/IG contacts
 * add a `platform` row.
 */
function meta_upsert_contact(int $companyId, string $platform, string $externalId, string $name): int
{
    $db = aiserve_db();

    $stmt = $db->prepare(
        'SELECT id FROM contacts
         WHERE company_id = ? AND wa_id = ? AND platform = ?
         LIMIT 1'
    );
    $stmt->execute([$companyId, $externalId, $platform]);
    $existing = (int)$stmt->fetchColumn();

    if ($existing > 0) {
        if ($name !== '') {
            $upd = $db->prepare(
                'UPDATE contacts
                 SET profile_name = ?,
                     display_name = COALESCE(NULLIF(display_name, ""), ?, wa_id),
                     last_message_at = NOW()
                 WHERE id = ?'
            );
            $upd->execute([$name, $name, $existing]);
        }
        return $existing;
    }

    $ins = $db->prepare(
        'INSERT INTO contacts (company_id, wa_id, platform, profile_name, display_name, last_message_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $ins->execute([
        $companyId, $externalId, $platform,
        $name !== '' ? $name : null,
        $name !== '' ? $name : $externalId,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Fetch the enclosing post (FB) or media (IG) for its preview.
 *
 * Cached by memoizing on the process; the webhook is short-lived so a
 * per-request cache is enough. Returns:
 *   ['url' => permalink, 'preview' => json string]
 *
 * On failure returns an empty array so the caller falls back to null in DB.
 */
function meta_fetch_and_cache_post_preview(array $channel, string $convPlatform, string $enclosingId): array
{
    static $cache = [];
    $cacheKey = $convPlatform . ':' . $enclosingId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $pageToken = (string)($channel['meta_page_access_token'] ?? '');
    if ($pageToken === '' || $enclosingId === '') {
        return $cache[$cacheKey] = [];
    }

    if ($convPlatform === 'ig_comment') {
        $resp = meta_fetch_ig_media_preview($enclosingId, $pageToken);
        if (!$resp['ok']) {
            return $cache[$cacheKey] = [];
        }
        $d = $resp['data'];
        return $cache[$cacheKey] = [
            'url'     => (string)($d['permalink'] ?? ''),
            'preview' => json_encode([
                'caption'       => (string)($d['caption']        ?? ''),
                'media_type'    => (string)($d['media_type']     ?? ''),
                'thumbnail_url' => (string)($d['thumbnail_url']  ?? $d['media_url'] ?? ''),
                'timestamp'     => (string)($d['timestamp']      ?? ''),
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    // fb_comment path
    $resp = meta_fetch_post_preview($enclosingId, $pageToken);
    if (!$resp['ok']) {
        return $cache[$cacheKey] = [];
    }
    $d = $resp['data'];
    return $cache[$cacheKey] = [
        'url'     => (string)($d['permalink_url'] ?? ''),
        'preview' => json_encode([
            'caption'       => (string)($d['message'] ?? ''),
            'thumbnail_url' => (string)($d['full_picture'] ?? ''),
            'timestamp'     => (string)($d['created_time'] ?? ''),
        ], JSON_UNESCAPED_UNICODE),
    ];
}

/**
 * Look up the channel row for an incoming Meta webhook entry.
 *
 * `entry.id` is the Page ID for FB or the IG Business account id for IG.
 * We match against either meta_page_id (FB) or meta_ig_business_id (IG).
 * Returns null if we don't know that Page/account (webhook meant for a
 * different tenant, or the customer has since disconnected).
 */
function meta_channel_for_entry(string $object, string $entryId): ?array
{
    $db = aiserve_db();
    if ($object === 'instagram') {
        $stmt = $db->prepare(
            'SELECT * FROM channels
             WHERE meta_ig_business_id = ?
               AND provider = "instagram_business"
               AND status = "active"
             LIMIT 1'
        );
        $stmt->execute([$entryId]);
    } else {
        // 'page' object
        $stmt = $db->prepare(
            'SELECT * FROM channels
             WHERE meta_page_id = ?
               AND provider IN ("facebook_page","instagram_business")
               AND status = "active"
             LIMIT 1'
        );
        $stmt->execute([$entryId]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}
