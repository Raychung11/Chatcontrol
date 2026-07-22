<?php
/**
 * Find + merge duplicate contacts.
 *
 * How duplicates arise:
 *   - Same phone typed differently on different inbound paths
 *     (WhatsApp shows `60123456789`; CSV import brings `+60 12-345-6789`;
 *     an agent typed `0123456789`). Even after normalization, older
 *     rows may still hold the pre-normalized wa_id.
 *   - Phase-22 LID phantoms that got a real phone later but the merge
 *     step didn't fire (Baileys quirk).
 *   - Migrated data from a previous CRM.
 *
 * Detection: group contacts by `contact_normalize_phone(wa_id)`. Any
 * normalized number with 2+ contact rows on the same platform inside
 * the same workspace is a duplicate group.
 *
 * Merge: pick a "keeper" (the row with the most recent activity by
 * default). Reassign every conversation, message, note, activity_log,
 * broadcast_recipient row from the losers to the keeper. Delete losers.
 * Everything runs in a single transaction so a mid-merge failure rolls
 * back cleanly.
 */

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/contact_import.php';   // reuses contact_normalize_phone()

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$flash = '';
$flashErr = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'merge') {
        $keeperId = (int)($_POST['keeper_id'] ?? 0);
        $loserIds = array_map('intval', $_POST['loser_ids'] ?? []);
        $loserIds = array_values(array_filter($loserIds, fn($id) => $id > 0 && $id !== $keeperId));
        if ($keeperId <= 0 || !$loserIds) {
            $flashErr = 'Pick one contact to keep and one or more to merge into it.';
        } else {
            [$ok, $msg] = contact_merge($db, $companyId, (int)$current_user['id'], $keeperId, $loserIds);
            $ok ? $flash = $msg : $flashErr = $msg;
        }
    }
}

// -------------------- Scan for duplicate groups --------------------
// Pull every whatsapp contact once and group in PHP: normalization
// includes stripping leading 00, so pure SQL grouping would miss some.
$rows = $db->prepare(
    'SELECT c.id, c.wa_id, c.display_name, c.profile_name, c.phone,
            c.last_message_at, c.created_at,
            (SELECT COUNT(*) FROM conversations WHERE contact_id = c.id) AS conv_count,
            (SELECT COUNT(*) FROM messages      WHERE contact_id = c.id) AS msg_count
     FROM contacts c
     WHERE c.company_id = ? AND c.platform = "whatsapp"
     ORDER BY c.id ASC'
);
$rows->execute([$companyId]);
$all = $rows->fetchAll();

$groups = [];   // normalized_phone => [ contact rows ]
foreach ($all as $c) {
    $norm = contact_normalize_phone((string)$c['wa_id']);
    if ($norm === '') continue;
    $groups[$norm][] = $c;
}
// Only keep groups with 2+
$dupGroups = array_filter($groups, fn($g) => count($g) >= 2);
uksort($dupGroups, fn($a, $b) => strcmp($a, $b));

layout_start($current_user, 'Deduplicate contacts', 'contacts');
?>
<div class="card">
  <div class="card-head">
    <h2>Find &amp; merge duplicate contacts</h2>
    <a class="btn" href="/contacts.php">← Back to contacts</a>
  </div>

  <?php if ($flash): ?><div class="alert alert-success"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="alert alert-error"><?= e($flashErr) ?></div><?php endif; ?>

  <p class="muted small">
    Groups below share the same normalized WhatsApp number. Pick which
    row to <strong>keep</strong>; the others merge into it. All their
    conversations, messages, notes, and broadcast history follow the keeper.
    The losing rows are deleted. This is <strong>not reversible</strong>
    without a database backup.
  </p>

  <?php if (!$dupGroups): ?>
    <div class="alert alert-info">
      No duplicates found. Every WhatsApp number in this workspace is unique.
    </div>
  <?php else: ?>
    <p class="muted small"><strong><?= count($dupGroups) ?></strong> duplicate group(s) found.</p>

    <?php foreach ($dupGroups as $norm => $group): ?>
      <?php
        // Suggest the keeper: the contact with the most recent activity,
        // else the one with the most conversations.
        usort($group, function ($a, $b) {
            $ta = strtotime((string)($a['last_message_at'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['last_message_at'] ?? '')) ?: 0;
            if ($ta !== $tb) return $tb - $ta;
            return (int)$b['conv_count'] - (int)$a['conv_count'];
        });
        $suggested = (int)$group[0]['id'];
      ?>
      <form method="post" class="card" style="margin: 12px 0; border:1px solid var(--c-border);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="merge">
        <div style="margin-bottom:8px;">
          <strong>Normalized number:</strong> <code>+<?= e($norm) ?></code>
          <span class="muted small">· <?= count($group) ?> matching contacts</span>
        </div>
        <table class="data-table" style="margin-bottom:8px;">
          <thead>
            <tr>
              <th style="width:80px;">Keep</th>
              <th style="width:80px;">Merge</th>
              <th>Name</th>
              <th>wa_id</th>
              <th>Convs</th>
              <th>Msgs</th>
              <th>Last activity</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group as $c): ?>
              <?php $id = (int)$c['id']; ?>
              <tr>
                <td><input type="radio" name="keeper_id" value="<?= $id ?>" <?= $id === $suggested ? 'checked' : '' ?>></td>
                <td><input type="checkbox" name="loser_ids[]" value="<?= $id ?>" <?= $id !== $suggested ? 'checked' : '' ?>></td>
                <td>
                  <a href="/contacts.php?q=<?= e($c['wa_id']) ?>">
                    <?= e((string)($c['display_name'] ?: $c['profile_name'] ?: '—')) ?>
                  </a>
                </td>
                <td><code><?= e((string)$c['wa_id']) ?></code></td>
                <td><?= (int)$c['conv_count'] ?></td>
                <td><?= (int)$c['msg_count'] ?></td>
                <td class="muted small"><?= e(fmt_dt($c['last_message_at']) ?: '—') ?></td>
                <td class="muted small"><?= e(fmt_dt($c['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Merge these contacts? This cannot be undone.');">
          Merge → the keeper
        </button>
      </form>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>

<?php
/**
 * Perform the merge inside a single transaction. Reassign every
 * child row from the losers to the keeper, then delete the loser
 * contact rows.
 *
 * @return array{0:bool,1:string}  [ok, human-readable message]
 */
function contact_merge(PDO $db, int $companyId, int $userId, int $keeperId, array $loserIds): array
{
    // Sanity: keeper + all losers must be inside this workspace + on
    // the whatsapp platform. Prevents URL tampering.
    $ids = array_merge([$keeperId], $loserIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $verify = $db->prepare(
        "SELECT id FROM contacts
         WHERE company_id = ? AND platform = 'whatsapp' AND id IN ($placeholders)"
    );
    $verify->execute(array_merge([$companyId], $ids));
    $found = array_map('intval', array_column($verify->fetchAll(), 'id'));
    if (count($found) !== count($ids)) {
        return [false, 'One or more contacts do not belong to this workspace.'];
    }

    $db->beginTransaction();
    try {
        $losersIn = implode(',', array_map('intval', $loserIds));

        // Reassign every table that references contacts.id.
        $db->exec("UPDATE conversations SET contact_id = $keeperId WHERE contact_id IN ($losersIn)");
        $db->exec("UPDATE messages      SET contact_id = $keeperId WHERE contact_id IN ($losersIn)");
        // internal_notes references contact indirectly via conversation_id,
        // so the conversation UPDATE above already relocates them.
        // Broadcast recipient rows carry contact_id directly.
        $db->exec("UPDATE broadcast_recipients SET contact_id = $keeperId WHERE contact_id IN ($losersIn)");

        // Refresh keeper's last_message_at / display fallbacks so the
        // merged view looks right.
        $db->exec(
            "UPDATE contacts k
             SET k.last_message_at = (SELECT MAX(last_message_at)
                                       FROM contacts WHERE id IN ($keeperId, $losersIn))
             WHERE k.id = $keeperId"
        );

        // Delete losers.
        $db->exec("DELETE FROM contacts WHERE id IN ($losersIn)");

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[AiServe contact_merge] ' . $e->getMessage());
        return [false, 'Merge failed: ' . mb_substr($e->getMessage(), 0, 200)];
    }

    log_activity(
        $companyId, $userId, 'contact_merged', 'contact', $keeperId,
        'kept=' . $keeperId . ' merged_in=' . implode(',', $loserIds)
    );

    return [true, 'Merged ' . count($loserIds) . ' contact(s) into #' . $keeperId . '.'];
}
