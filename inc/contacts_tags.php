<?php
/**
 * Shared contact-tag helpers — used by /contact_import.php,
 * /contacts.php's bulk-tag actions, and any future tagging surface.
 *
 * Tags live on conversations (via conversation_tag_map), NOT on
 * contacts directly. To tag a "pure" contact (one that has never
 * exchanged a message), we open a placeholder conversation on the
 * workspace's default channel so the tag has somewhere to hang. This
 * matches how the importer has always worked and keeps every
 * consumer of tags (broadcast recipient picker, inbox filters,
 * reports) uniform.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Idempotent: attach $tagName to $contactId. Creates the tag if new,
 * finds or opens a placeholder conversation, then inserts into
 * conversation_tag_map. Caches tag lookups in $tagsCache (pass a
 * shared array across a batch to skip repeat SELECTs).
 *
 * Returns true on success (or when it was already tagged),
 * false when there's no channel on the workspace to hang the
 * placeholder conversation on.
 */
function contact_ensure_tagged(PDO $db, int $companyId, int $contactId, string $tagName, array &$tagsCache): bool
{
    $tagName = mb_substr($tagName, 0, 60);
    if ($tagName === '') return false;

    if (!isset($tagsCache[$tagName])) {
        $s = $db->prepare(
            'SELECT id FROM conversation_tags WHERE company_id = ? AND name = ? LIMIT 1'
        );
        $s->execute([$companyId, $tagName]);
        $tid = (int)$s->fetchColumn();
        if (!$tid) {
            $db->prepare(
                'INSERT INTO conversation_tags (company_id, name, color) VALUES (?, ?, "#25D366")'
            )->execute([$companyId, $tagName]);
            $tid = (int)$db->lastInsertId();
        }
        $tagsCache[$tagName] = $tid;
    }
    $tid = $tagsCache[$tagName];

    // Find or open the conversation the tag will attach to. Prefer the
    // contact's most recent conversation; if none exists (fresh
    // import), open a placeholder on the workspace's default channel.
    $s = $db->prepare(
        'SELECT id FROM conversations WHERE company_id = ? AND contact_id = ?
         ORDER BY id DESC LIMIT 1'
    );
    $s->execute([$companyId, $contactId]);
    $convId = (int)$s->fetchColumn();
    if (!$convId) {
        $chId = (int)$db->query(
            'SELECT id FROM channels WHERE company_id = ' . $companyId
            . ' AND status = "active" ORDER BY is_default DESC, id ASC LIMIT 1'
        )->fetchColumn();
        if (!$chId) return false;   // no channels yet — nowhere to hang
        $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, status,
                 last_message_text, last_message_at, unread_count)
             VALUES (?, ?, ?, "open", "(imported contact)", NOW(), 0)'
        )->execute([$companyId, $chId, $contactId]);
        $convId = (int)$db->lastInsertId();
    }

    $db->prepare(
        'INSERT IGNORE INTO conversation_tag_map (conversation_id, tag_id) VALUES (?, ?)'
    )->execute([$convId, $tid]);
    return true;
}

/**
 * Remove a tag from every conversation of the given contact. Idempotent.
 */
function contact_remove_tag(PDO $db, int $companyId, int $contactId, int $tagId): void
{
    $db->prepare(
        'DELETE m FROM conversation_tag_map m
         INNER JOIN conversations c ON c.id = m.conversation_id
         WHERE c.company_id = ? AND c.contact_id = ? AND m.tag_id = ?'
    )->execute([$companyId, $contactId, $tagId]);
}

/**
 * Return every distinct tag currently attached to the given set of
 * contacts, as [contact_id => [{id, name, color}, ...]]. One query
 * per page — cheap for the ~200-row contacts view.
 */
function contact_tags_for_ids(PDO $db, int $companyId, array $contactIds): array
{
    $out = [];
    if (!$contactIds) return $out;
    $place = implode(',', array_fill(0, count($contactIds), '?'));
    $s = $db->prepare(
        "SELECT c.contact_id, t.id, t.name, t.color
         FROM conversations c
         INNER JOIN conversation_tag_map m ON m.conversation_id = c.id
         INNER JOIN conversation_tags t ON t.id = m.tag_id
         WHERE c.company_id = ? AND c.contact_id IN ($place)
         GROUP BY c.contact_id, t.id, t.name, t.color
         ORDER BY t.name"
    );
    $s->execute(array_merge([$companyId], $contactIds));
    foreach ($s->fetchAll() as $r) {
        $cid = (int)$r['contact_id'];
        $out[$cid][] = [
            'id'    => (int)$r['id'],
            'name'  => (string)$r['name'],
            'color' => (string)$r['color'],
        ];
    }
    return $out;
}
