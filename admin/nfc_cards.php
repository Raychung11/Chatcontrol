<?php
/**
 * NFC / QR cards admin — CRUD + bulk create + tap history.
 *
 * Physical cards (NFC stickers, printed QRs, table tents) each carry
 * an opaque short token in the URL /tap.php?c=<TOKEN>. Tapping /
 * scanning lands the customer in this workspace's web-chat widget
 * with the card's metadata (label, table number, branch, campaign)
 * attributed to the conversation.
 *
 * The URL on the physical medium is STABLE — this page is where an
 * operator renames "Table 5" to "VIP Booth" or reassigns a card to
 * a different branch without needing to re-program a single sticker.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$msg = '';

// ---- Resolve the workspace's default web_chat channel ----
// Cards MUST land in a widget, which requires a web_chat channel.
$wcStmt = $db->prepare(
    "SELECT id, webhook_token, name FROM channels
     WHERE company_id = ? AND provider = 'web_chat' AND status = 'active'
     ORDER BY is_default DESC, id ASC LIMIT 1"
);
$wcStmt->execute([$companyId]);
$webChatChannel = $wcStmt->fetch();

// Branches for the dropdown (optional card metadata).
$branches = $db->prepare('SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name');
$branches->execute([$companyId]);
$branches = $branches->fetchAll();

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if (!$webChatChannel) {
        $err = 'This workspace has no active web-chat channel. Open Channels → add a Web chat channel first.';
    } elseif ($action === 'create') {
        $label    = trim((string)($_POST['label']        ?? ''));
        $tableNum = (int)($_POST['table_number'] ?? 0);
        $branchId = (int)($_POST['branch_id']    ?? 0);
        $campaign = trim((string)($_POST['campaign']     ?? ''));
        $qty      = max(1, min(100, (int)($_POST['quantity'] ?? 1)));

        $created = 0;
        for ($i = 0; $i < $qty; $i++) {
            // 16 hex chars = 64 bits of entropy. Enough headroom that
            // even with a billion cards the collision probability is
            // astronomically low. `UNIQUE` on the column catches the
            // theoretical collision anyway.
            $token = bin2hex(random_bytes(8));
            try {
                $db->prepare(
                    'INSERT INTO nfc_cards
                        (company_id, channel_id, token, label, table_number, branch_id, campaign,
                         enabled, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
                )->execute([
                    $companyId, (int)$webChatChannel['id'], $token,
                    $label ?: null,
                    $tableNum > 0 ? $tableNum : null,
                    $branchId > 0 ? $branchId : null,
                    $campaign ?: null,
                    (int)$current_user['id'],
                ]);
                $created++;
            } catch (PDOException $e) {
                if ((int)$e->errorInfo[1] === 1062) { $i--; continue; } // rare token collision — retry
                $err = 'Could not create card: ' . $e->getMessage();
                break;
            }
        }
        if ($created > 0) {
            log_activity($companyId, (int)$current_user['id'], 'nfc_cards_created',
                'company', $companyId, 'created ' . $created);
            $msg = $created === 1
                ? 'Card created.'
                : $created . ' cards created.';
        }
    } elseif ($action === 'update') {
        $cardId   = (int)($_POST['card_id'] ?? 0);
        $label    = trim((string)($_POST['label']        ?? ''));
        $tableNum = (int)($_POST['table_number'] ?? 0);
        $branchId = (int)($_POST['branch_id']    ?? 0);
        $campaign = trim((string)($_POST['campaign']     ?? ''));
        $enabled  = !empty($_POST['enabled']) ? 1 : 0;

        $db->prepare(
            'UPDATE nfc_cards
             SET label = ?, table_number = ?, branch_id = ?, campaign = ?, enabled = ?
             WHERE id = ? AND company_id = ?'
        )->execute([
            $label ?: null,
            $tableNum > 0 ? $tableNum : null,
            $branchId > 0 ? $branchId : null,
            $campaign ?: null,
            $enabled,
            $cardId, $companyId,
        ]);
        $msg = 'Card updated.';
    } elseif ($action === 'delete') {
        $cardId = (int)($_POST['card_id'] ?? 0);
        // Scope the DELETE by company_id so a forged form can't drop
        // another workspace's card.
        $db->prepare('DELETE FROM nfc_cards WHERE id = ? AND company_id = ?')
           ->execute([$cardId, $companyId]);
        $msg = 'Card deleted.';
    }
}

// ---- Load the list with tap counts ----
$cards = [];
if ($webChatChannel) {
    $stmt = $db->prepare(
        'SELECT c.*,
                b.name AS branch_name,
                (SELECT COUNT(*) FROM nfc_tap_events t WHERE t.card_id = c.id) AS taps_total,
                (SELECT COUNT(*) FROM nfc_tap_events t
                   WHERE t.card_id = c.id
                     AND t.tapped_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS taps_30d,
                (SELECT MAX(tapped_at) FROM nfc_tap_events t WHERE t.card_id = c.id) AS last_tap
         FROM nfc_cards c
         LEFT JOIN branches b ON b.id = c.branch_id
         WHERE c.company_id = ?
         ORDER BY c.id DESC'
    );
    $stmt->execute([$companyId]);
    $cards = $stmt->fetchAll();
}

$page_title = 'NFC / QR cards';
$active_nav = 'nfc_cards';
layout_start($current_user, $page_title, $active_nav);

$baseUrl = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'inbox.aiserve.my'));
?>

<style>
  .nfc-page { max-width: 1000px; }
  .nfc-hero { background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px;
              padding:16px 18px; margin-bottom:18px; }
  .nfc-hero h3 { margin: 0 0 6px 0; }
  .nfc-hero code { background: #fff; padding: 2px 6px; border-radius: 4px; border: 1px solid #e5e7eb; }
  .nfc-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
  .nfc-form-grid label.full { grid-column: 1 / -1; }
  @media (max-width: 640px) { .nfc-form-grid { grid-template-columns: 1fr; } }
  .nfc-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  .nfc-table th, .nfc-table td { padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 13.5px; }
  .nfc-table th { background: #f8fafc; text-align: left; font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .3px; }
  .nfc-url { font-family: ui-monospace, Menlo, monospace; font-size: 11.5px; color:#334155; word-break: break-all; }
  .nfc-copy { background: #eef2ff; color: #4338ca; border: none; padding: 3px 8px; border-radius: 5px; cursor: pointer; font-size: 11.5px; }
  .nfc-copy:active { background: #4338ca; color: #fff; }
  .nfc-chip { display: inline-block; background: #eef2ff; color: #4338ca; padding: 1px 8px; border-radius: 10px; font-size: 11.5px; margin-right: 4px; }
  .nfc-chip.taps { background: #ecfdf5; color: #047857; }
  .nfc-chip.off  { background: #fee2e2; color: #b91c1c; }
  .nfc-inline-edit { display: none; margin-top: 8px; background: #f8fafc; border-radius: 8px; padding: 10px 12px; }
  .nfc-inline-edit.open { display: block; }
  .nfc-inline-actions { display: flex; gap: 8px; margin-top: 8px; }
  .nfc-print-link { color: #4338ca; text-decoration: none; font-size: 12px; }
  .nfc-print-link:hover { text-decoration: underline; }
</style>

<div class="nfc-page">
  <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>

  <?php if (!$webChatChannel): ?>
    <div class="alert alert-warning">
      This workspace has no active web-chat channel yet. Cards need one to land customers in.
      <a href="/admin/channels.php">Set up a Web chat channel →</a>
    </div>
  <?php else: ?>
    <div class="nfc-hero">
      <h3>📇 How this works</h3>
      <p>Each card carries a stable short URL like <code><?= e($baseUrl) ?>/tap.php?c=&lt;token&gt;</code>.
         Program the URL onto NFC stickers (using any NFC writer app like
         <em>NFC Tools</em>) or print it as a QR sticker. When a customer
         taps or scans, the tap opens your web-chat widget and stamps the
         card's label / table / branch / campaign on the conversation for
         the agent to see.</p>
      <p>Cards created here belong to channel
         <strong><?= e((string)$webChatChannel['name']) ?></strong>.</p>
    </div>

    <details>
      <summary><strong>➕ Create card(s)</strong></summary>
      <form method="post" style="margin-top: 12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="nfc-form-grid">
          <label>Label <small class="muted">shown on the internal note ("Table 5", "VIP booth")</small>
            <input type="text" name="label" maxlength="120" placeholder="e.g. Table 5">
          </label>
          <label>Table number <small class="muted">(optional; F&B dine_in flow uses this)</small>
            <input type="number" name="table_number" min="1" max="9999">
          </label>
          <label>Branch <small class="muted">(optional; card taps auto-assign to this branch's agents)</small>
            <select name="branch_id">
              <option value="0">— none —</option>
              <?php foreach ($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e((string)$b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Campaign <small class="muted">group cards by name for analytics</small>
            <input type="text" name="campaign" maxlength="120" placeholder="e.g. Merdeka 2026">
          </label>
          <label class="full">Quantity <small class="muted">(1-100 — create several at once for a batch of stickers)</small>
            <input type="number" name="quantity" min="1" max="100" value="1">
          </label>
        </div>
        <div style="margin-top: 12px;">
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </details>

    <?php if (!$cards): ?>
      <div class="alert alert-info" style="margin-top:14px;">No cards yet. Create your first batch above.</div>
    <?php else: ?>
      <p style="margin-top:14px;">
        <?= count($cards) ?> card<?= count($cards) === 1 ? '' : 's' ?> ·
        <a class="nfc-print-link" href="/admin/nfc_card_print.php">🖨 Printable QR sheet →</a>
      </p>
      <table class="nfc-table">
        <thead>
          <tr>
            <th>Label / URL</th>
            <th>Table</th>
            <th>Branch</th>
            <th>Campaign</th>
            <th style="text-align:right;">Taps</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($cards as $c): ?>
          <?php $url = $baseUrl . '/tap.php?c=' . $c['token']; ?>
          <tr>
            <td>
              <strong><?= e((string)($c['label'] ?? 'Untitled card')) ?></strong>
              <?php if (!(int)$c['enabled']): ?><span class="nfc-chip off">disabled</span><?php endif; ?>
              <div class="nfc-url"><?= e($url) ?></div>
              <button type="button" class="nfc-copy" data-copy="<?= e($url) ?>">Copy URL</button>
            </td>
            <td><?= $c['table_number'] ? (int)$c['table_number'] : '—' ?></td>
            <td><?= $c['branch_name'] ? e((string)$c['branch_name']) : '—' ?></td>
            <td><?= $c['campaign'] ? e((string)$c['campaign']) : '—' ?></td>
            <td style="text-align:right;">
              <span class="nfc-chip taps"><?= (int)$c['taps_30d'] ?> / 30d</span>
              <div style="font-size:11.5px;color:#64748b;">
                <?= (int)$c['taps_total'] ?> total
                <?php if ($c['last_tap']): ?><br>last: <?= e((string)$c['last_tap']) ?><?php endif; ?>
              </div>
            </td>
            <td>
              <button type="button" class="btn btn-secondary" data-edit="<?= (int)$c['id'] ?>">Edit</button>
            </td>
          </tr>
          <tr>
            <td colspan="6" style="padding:0;">
              <div class="nfc-inline-edit" id="edit-<?= (int)$c['id'] ?>">
                <form method="post" class="nfc-form-grid">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
                  <label>Label<input type="text" name="label" value="<?= e((string)($c['label'] ?? '')) ?>" maxlength="120"></label>
                  <label>Table number<input type="number" name="table_number" value="<?= $c['table_number'] ? (int)$c['table_number'] : '' ?>" min="1" max="9999"></label>
                  <label>Branch
                    <select name="branch_id">
                      <option value="0">— none —</option>
                      <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)$c['branch_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e((string)$b['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Campaign<input type="text" name="campaign" value="<?= e((string)($c['campaign'] ?? '')) ?>" maxlength="120"></label>
                  <label class="full">
                    <input type="checkbox" name="enabled" value="1" <?= (int)$c['enabled'] ? 'checked' : '' ?>>
                    Enabled (a disabled card returns 404 on tap)
                  </label>
                  <div class="nfc-inline-actions full">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" data-close="<?= (int)$c['id'] ?>">Cancel</button>
                  </div>
                </form>
                <form method="post" onsubmit="return confirm('Delete this card? Its URL will start returning 404 to any customer who taps it. This cannot be undone.');" style="margin-top:8px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-danger">Delete permanently</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
  // Copy-URL buttons — plain clipboard, no external lib. Falls back to
  // a select-and-prompt on browsers where writeText is unavailable
  // (which in 2026 is essentially none, but the check keeps this
  // page working on very old field phones an operator might use).
  document.querySelectorAll('.nfc-copy').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const url = btn.getAttribute('data-copy');
      try {
        await navigator.clipboard.writeText(url);
        const orig = btn.textContent;
        btn.textContent = 'Copied ✓';
        setTimeout(() => { btn.textContent = orig; }, 1600);
      } catch (_) {
        window.prompt('Copy the URL:', url);
      }
    });
  });
  // Toggle inline edit form on Edit button click.
  document.querySelectorAll('[data-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-edit');
      const panel = document.getElementById('edit-' + id);
      if (panel) panel.classList.toggle('open');
    });
  });
  document.querySelectorAll('[data-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-close');
      const panel = document.getElementById('edit-' + id);
      if (panel) panel.classList.remove('open');
    });
  });
</script>

<?php layout_end(); ?>
