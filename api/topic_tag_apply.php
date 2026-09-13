<?php
/**
 * POST /api/topic_tag_apply.php
 *
 * Body:
 *   topic            : string (becomes the tag name, capped at 64 chars)
 *   conversation_ids : JSON array of int  (AI-suggested matches)
 *   _csrf            : token
 *
 * Validates that every conversation belongs to the user's workspace, creates
 * or reuses a tag with the topic name (colored deterministically from the
 * name so the same topic always gets the same color), and attaches the tag
 * to each conversation. Idempotent: re-running with the same input doesn't
 * duplicate map rows.
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_role(['super_admin', 'manager']);

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$topic = trim((string)($_POST['topic'] ?? ''));
$ids   = json_decode((string)($_POST['conversation_ids'] ?? '[]'), true);
if (!is_array($ids)) $ids = [];

if ($topic === '' || mb_strlen($topic) > 64) {
    json_response(['ok' => false, 'error' => 'Topic name must be 1-64 characters.'], 400);
}
$ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));
if (!$ids) {
    json_response(['ok' => false, 'error' => 'No conversation ids supplied.'], 400);
}
if (count($ids) > 500) {
    $ids = array_slice($ids, 0, 500);
}

$companyId = (int)$user['company_id'];
$db        = aiserve_db();

// Verify all the ids belong to this workspace.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$check = $db->prepare(
    "SELECT id FROM conversations WHERE company_id = ? AND id IN ($placeholders)"
);
$check->execute(array_merge([$companyId], $ids));
$validIds = array_map('intval', array_column($check->fetchAll(), 'id'));
if (!$validIds) {
    json_response(['ok' => false, 'error' => 'None of the supplied conversations belong to this workspace.'], 400);
}

// Deterministic color from the topic name so the same topic always gets the
// same color, but different topics get different colors.
$palette = [
    '#6f42c1', // purple
    '#4a90e2', // blue
    '#1f7a3f', // green
    '#f0ad4e', // orange
    '#d9534f', // red
    '#138496', // teal
    '#e83e8c', // pink
    '#fd7e14', // amber
    '#20c997', // mint
    '#6c757d', // grey
];
$color = $palette[crc32(mb_strtolower($topic)) % count($palette)];

// Find-or-create the tag.
$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT id FROM conversation_tags WHERE company_id = ? AND name = ? LIMIT 1');
    $stmt->execute([$companyId, $topic]);
    $tagId = (int)($stmt->fetchColumn() ?: 0);

    if ($tagId === 0) {
        $ins = $db->prepare(
            'INSERT INTO conversation_tags (company_id, name, color) VALUES (?, ?, ?)'
        );
        $ins->execute([$companyId, $topic, $color]);
        $tagId = (int)$db->lastInsertId();
    }

    // Apply tag to each conversation. INSERT IGNORE skips dupes.
    $map = $db->prepare(
        'INSERT IGNORE INTO conversation_tag_map (conversation_id, tag_id) VALUES (?, ?)'
    );
    $tagged = 0;
    foreach ($validIds as $cid) {
        $map->execute([$cid, $tagId]);
        if ($map->rowCount() > 0) $tagged++;
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[topic_tag_apply] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not apply tag.'], 500);
}

log_activity($companyId, (int)$user['id'], 'topic_tag_applied', 'tag', $tagId,
    'topic=' . $topic . ' tagged=' . $tagged . '/' . count($validIds));

json_response([
    'ok'             => true,
    'tag_id'         => $tagId,
    'tag_name'       => $topic,
    'tag_color'      => $color,
    'requested'      => count($ids),
    'valid'          => count($validIds),
    'newly_tagged'   => $tagged,
    'filter_url'     => '/inbox/index.php?tag_id=' . $tagId,
]);
