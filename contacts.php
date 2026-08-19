<?php
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/contacts_tags.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();
$canManage    = in_array($current_user['role'] ?? 'agent', ['super_admin', 'manager'], true);

$msg = '';
$err = '';

/**
 * Build the WHERE / params / joins for the contact query from a source
 * array (either $_GET for rendering the list, or $_POST for POST-back
 * actions like split_batches which include the same filter as hidden
 * inputs). Kept as a closure so both paths stay in sync when filter
 * columns change.
 */
$buildFilter = function (array $src) use ($companyId) {
    $q       = trim((string)($src['q'] ?? ''));
    $bId     = (int)($src['branch_id'] ?? 0);
    $tId     = (int)($src['tag_id'] ?? 0);
    $dField  = (string)($src['date_field'] ?? '');
    if (!in_array($dField, ['created', 'last_msg'], true)) $dField = '';
    $dFrom   = trim((string)($src['date_from'] ?? ''));
    $dTo     = trim((string)($src['date_to']   ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dFrom)) $dFrom = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dTo))   $dTo   = '';

    $where  = ['c.company_id = ?'];
    $params = [$companyId];
    $joins  = 'LEFT JOIN branches b ON b.id = c.branch_id';

    if ($q !== '') {
        $where[] = '(c.display_name LIKE ? OR c.profile_name LIKE ? OR c.phone LIKE ? OR c.wa_id LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($bId > 0) { $where[] = 'c.branch_id = ?'; $params[] = $bId; }
    if ($dField !== '' && ($dFrom !== '' || $dTo !== '')) {
        $col = $dField === 'last_msg' ? 'c.last_message_at' : 'c.created_at';
        if ($dFrom !== '') { $where[] = "$col >= ?"; $params[] = $dFrom . ' 00:00:00'; }
        if ($dTo   !== '') { $where[] = "$col <= ?"; $params[] = $dTo   . ' 23:59:59'; }
    }
    if ($tId > 0) {
        $joins .= ' INNER JOIN (
            SELECT DISTINCT cv.contact_id
            FROM conversations cv
            INNER JOIN conversation_tag_map ctm ON ctm.conversation_id = cv.id
            WHERE cv.company_id = ? AND ctm.tag_id = ?
        ) tagged ON tagged.contact_id = c.id';
        $params = array_merge([$companyId, $tId], $params);
    }
    return [$where, $params, $joins];
};

// -------------------- Bulk actions --------------------
if (is_post() && $canManage) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $ids    = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));

    if ($action === 'bulk_tag' && $ids) {
        $tagsRaw = trim((string)($_POST['tag_names'] ?? ''));
        $tagNames = array_filter(array_map('trim', preg_split('/[,|;]+/', $tagsRaw)));
        if (!$tagNames) {
            $err = 'Type at least one tag name.';
        } else {
            // Verify every id belongs to this workspace before writing.
            $place = implode(',', array_fill(0, count($ids), '?'));
            $chk = $db->prepare("SELECT id FROM contacts WHERE company_id = ? AND id IN ($place)");
            $chk->execute(array_merge([$companyId], $ids));
            $safeIds = array_map('intval', array_column($chk->fetchAll(), 'id'));

            $cache = [];
            $applied = 0;
            foreach ($safeIds as $cid) {
                foreach ($tagNames as $tag) {
                    if (contact_ensure_tagged($db, $companyId, $cid, $tag, $cache)) $applied++;
                }
            }
            log_activity($companyId, (int)$current_user['id'], 'contacts_bulk_tagged',
                null, null, count($safeIds) . ' contact(s), tags: ' . implode(', ', $tagNames));
            $msg = "Applied " . count($tagNames) . " tag(s) to " . count($safeIds) . " contact(s). "
                 . "({$applied} tag-attach operation(s) done — existing tags were skipped.)";
        }
    } elseif ($action === 'split_batches') {
        // Split ALL contacts matching the (POSTed-back) filter into
        // chunks of $batchSize, tagging each chunk 'prefix-1', 'prefix-2', …
        // Useful when the operator wants staged rollouts (send batch-1
        // today, batch-2 tomorrow) on a 10k+ list.
        $batchSize = max(100, min(50000, (int)($_POST['batch_size'] ?? 5000)));
        $prefix    = trim((string)($_POST['batch_prefix'] ?? ''));
        // Sanitize prefix — tag-safe chars only, cap length so 'prefix-N'
        // fits in the 60-char tags.name column even at 5 digits (batch-99999).
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '-', $prefix);
        $prefix = trim($prefix, '-');
        $prefix = mb_substr($prefix, 0, 50);
        if ($prefix === '') $prefix = 'batch';

        [$where, $params, $joins] = $buildFilter($_POST);
        $sql = 'SELECT c.id FROM contacts c ' . $joins
             . ' WHERE ' . implode(' AND ', $where)
             . ' ORDER BY c.id ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $allIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (!$allIds) {
            $err = 'No contacts match the current filter — nothing to split.';
        } else {
            // Bumped time + memory: 100k contacts × N inserts can take a
            // minute. Same envelope the import uses.
            @set_time_limit(300);
            @ini_set('memory_limit', '512M');
            $chunks   = array_chunk($allIds, $batchSize);
            $cache    = [];
            $tagged   = 0;
            $summary  = [];
            foreach ($chunks as $i => $chunk) {
                $tagName = $prefix . '-' . ($i + 1);
                foreach ($chunk as $cid) {
                    if (contact_ensure_tagged($db, $companyId, $cid, $tagName, $cache)) $tagged++;
                }
                $summary[] = $tagName . ' (' . number_format(count($chunk)) . ')';
            }
            log_activity($companyId, (int)$current_user['id'], 'contacts_split_batches',
                null, null, count($allIds) . ' contacts into ' . count($chunks) . ' batches, prefix=' . $prefix);
            $msg = 'Split ' . number_format(count($allIds)) . ' contact(s) into '
                 . count($chunks) . ' batch(es): ' . implode(' · ', $summary)
                 . '. Filter by any of these tags on this page → 📢 Broadcast to that batch.';
        }
    } elseif ($action === 'bulk_untag' && $ids) {
        $tagId = (int)($_POST['untag_id'] ?? 0);
        if ($tagId <= 0) {
            $err = 'Pick a tag to remove.';
        } else {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $chk = $db->prepare("SELECT id FROM contacts WHERE company_id = ? AND id IN ($place)");
            $chk->execute(array_merge([$companyId], $ids));
            $safeIds = array_map('intval', array_column($chk->fetchAll(), 'id'));
            foreach ($safeIds as $cid) {
                contact_remove_tag($db, $companyId, $cid, $tagId);
            }
            log_activity($companyId, (int)$current_user['id'], 'contacts_bulk_untagged',
                null, null, count($safeIds) . ' contact(s), tag_id=' . $tagId);
            $msg = "Removed tag from " . count($safeIds) . " contact(s).";
        }
    }
}

// -------------------- Filters --------------------
$search   = trim((string)($_GET['q'] ?? ''));
$branchId = (int)($_GET['branch_id'] ?? 0);
$tagId    = (int)($_GET['tag_id'] ?? 0);
// Date filter — "which date to filter on" + from + to
$dateField = (string)($_GET['date_field'] ?? '');  // '' | 'created' | 'last_msg'
if (!in_array($dateField, ['created', 'last_msg'], true)) $dateField = '';
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to']   ?? ''));
// Basic sanity — must be YYYY-MM-DD else treat as empty.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

$where  = ['c.company_id = ?'];
$params = [$companyId];
$joins  = 'LEFT JOIN branches b ON b.id = c.branch_id';

if ($search !== '') {
    $where[] = '(c.display_name LIKE ? OR c.profile_name LIKE ? OR c.phone LIKE ? OR c.wa_id LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($branchId > 0) {
    $where[]  = 'c.branch_id = ?';
    $params[] = $branchId;
}
if ($dateField !== '' && ($dateFrom !== '' || $dateTo !== '')) {
    // Map the UI value to the actual column name.
    $col = $dateField === 'last_msg' ? 'c.last_message_at' : 'c.created_at';
    if ($dateFrom !== '') {
        $where[]  = "$col >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[]  = "$col <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
}
if ($tagId > 0) {
    // Tags are on conversations; filter contacts that have ANY conversation
    // carrying the tag. DISTINCT so the JOIN doesn't multiply rows.
    $joins .= ' INNER JOIN (
        SELECT DISTINCT cv.contact_id
        FROM conversations cv
        INNER JOIN conversation_tag_map ctm ON ctm.conversation_id = cv.id
        WHERE cv.company_id = ? AND ctm.tag_id = ?
    ) tagged ON tagged.contact_id = c.id';
    // Params for the sub-query go BEFORE the outer WHERE params — rebuild.
    $paramsBefore = [$companyId, $tagId];
    $params = array_merge($paramsBefore, $params);
}

$sql = 'SELECT c.id, c.display_name, c.profile_name, c.wa_id, c.wa_lid, c.phone,
               c.branch_id, c.external_id, c.email, c.last_message_at,
               b.name AS branch_name
        FROM contacts c
        ' . $joins . '
        WHERE ' . implode(' AND ', $where)
     . ' ORDER BY c.last_message_at DESC, c.id DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$contacts = $stmt->fetchAll();

// Full count of contacts matching the current filter (page shows a max of
// 200 for perf; the split-into-batches action operates on ALL of them so
// operators need to see the real total up front).
$countSql = 'SELECT COUNT(*) FROM contacts c ' . $joins . ' WHERE ' . implode(' AND ', $where);
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalMatching = (int)$countStmt->fetchColumn();

// Preload tags for every contact on the page.
$contactIds = array_map(fn($r) => (int)$r['id'], $contacts);
$tagsByCid  = contact_tags_for_ids($db, $companyId, $contactIds);

// Branches for the filter dropdown.
$bstmt = $db->prepare(
    'SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name'
);
$bstmt->execute([$companyId]);
$branches = $bstmt->fetchAll();

// All tags on the workspace — dropdown + bulk-untag list.
$tstmt = $db->prepare(
    'SELECT id, name, color FROM conversation_tags WHERE company_id = ? ORDER BY name'
);
$tstmt->execute([$companyId]);
$allTags = $tstmt->fetchAll();

// Contact.email + external_id may not exist on pre-phase-51 workspaces.
$hasEnrich = false;
try {
    $c = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
           AND COLUMN_NAME IN ('email','external_id')"
    )->fetchColumn();
    $hasEnrich = ((int)$c) === 2;
} catch (Throwable $e) { /* leave false */ }

layout_start($current_user, 'Contacts', 'contacts');
?>
<div class="card">
  <div class="card-head">
    <h2>Contacts <small class="muted">(<?= number_format($totalMatching) ?><?= $totalMatching > count($contacts) ? ' matching · showing 200' : '' ?>)</small></h2>
    <?php if ($canManage): ?>
      <div style="display:flex; gap:6px; align-items:center;">
        <a class="btn btn-sm" href="/assets/templates/contact_import_template.xlsx"
           download="contact_import_template.xlsx" title="Download the import template — 6 example rows + instructions">📥 Template</a>
        <a class="btn btn-sm btn-primary" href="/contact_import.php">📄 Import CSV / XLSX</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="get" class="inline-form" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom: 10px; align-items:center;">
    <input type="search" name="q" placeholder="Search by name / phone…" value="<?= e($search) ?>" style="min-width:220px;">
    <select name="branch_id" onchange="this.form.submit()">
      <option value="0">All branches</option>
      <?php foreach ($branches as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>>
          <?= e($b['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="tag_id" onchange="this.form.submit()">
      <option value="0">All tags</option>
      <?php foreach ($allTags as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= $tagId === (int)$t['id'] ? 'selected' : '' ?>>
          <?= e($t['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <!-- Date filter — dropdown picks which column to filter on -->
    <span style="display:inline-flex; gap:4px; align-items:center; padding:4px 8px; background:#f6f9fb; border:1px solid #e3e8ee; border-radius:6px;">
      <select name="date_field" onchange="onDateFieldChange(this)" style="border:none; background:transparent; padding:2px 4px;">
        <option value="">Date: —</option>
        <option value="created"  <?= $dateField === 'created'  ? 'selected' : '' ?>>Created</option>
        <option value="last_msg" <?= $dateField === 'last_msg' ? 'selected' : '' ?>>Last msg</option>
      </select>
      <input type="date" name="date_from" value="<?= e($dateFrom) ?>"
             <?= $dateField === '' ? 'disabled' : '' ?>
             style="padding:3px 6px; font-size:12.5px;" title="From (inclusive)">
      <span class="muted small">→</span>
      <input type="date" name="date_to" value="<?= e($dateTo) ?>"
             <?= $dateField === '' ? 'disabled' : '' ?>
             style="padding:3px 6px; font-size:12.5px;" title="To (inclusive)">
    </span>

    <!-- Quick-picks (JS sets from+to and submits) -->
    <span style="display:inline-flex; gap:4px;">
      <button type="button" class="btn btn-sm" onclick="quickDate(7)"  title="Last 7 days">7d</button>
      <button type="button" class="btn btn-sm" onclick="quickDate(30)" title="Last 30 days">30d</button>
      <button type="button" class="btn btn-sm" onclick="quickDate(90)" title="Last 90 days">90d</button>
    </span>

    <button class="btn btn-primary" type="submit">Search</button>
    <?php if ($search !== '' || $branchId > 0 || $tagId > 0 || $dateField !== ''): ?>
      <a class="btn btn-sm" href="/contacts.php">Clear</a>
    <?php endif; ?>
  </form>

  <script>
  // Disable the two date inputs until the user picks WHICH date to filter on.
  window.onDateFieldChange = function (sel) {
    var f = sel.form;
    var disabled = !sel.value;
    f.querySelector('input[name="date_from"]').disabled = disabled;
    f.querySelector('input[name="date_to"]').disabled   = disabled;
  };
  // Quick-pick: last N days. Defaults date_field to 'last_msg' if unset —
  // that's what operators usually mean by "recent activity".
  window.quickDate = function (days) {
    var f = document.querySelector('form.inline-form');
    var df = f.querySelector('select[name="date_field"]');
    if (!df.value) df.value = 'last_msg';
    var to   = new Date();
    var from = new Date(); from.setDate(from.getDate() - days);
    var ymd = function (d) {
      return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
    };
    f.querySelector('input[name="date_from"]').value = ymd(from);
    f.querySelector('input[name="date_to"]').value   = ymd(to);
    onDateFieldChange(df);   // enable inputs
    f.submit();
  };
  </script>

  <?php if ($canManage && $tagId > 0): ?>
    <div style="margin: 6px 0 12px; padding: 10px 12px; background:#eff6ff; border-radius:8px; font-size:13px;">
      Filtering by <strong>
        <?php foreach ($allTags as $t): ?>
          <?php if ((int)$t['id'] === $tagId): ?>
            <span style="background: <?= e($t['color'] ?: '#25D366') ?>; color:#fff; padding: 2px 8px; border-radius: 999px;"><?= e($t['name']) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </strong>
      &nbsp;·&nbsp;
      <a href="/admin/broadcast_new.php?source=tag&tag_id=<?= $tagId ?>">📢 Broadcast to this tag →</a>
    </div>
  <?php endif; ?>

  <?php if ($canManage && $totalMatching > 0): ?>
  <!-- 🎯 Split-into-batches — operates on the ALL matching contacts under
       the current filter (not just the visible 200). Perfect for staged
       broadcasts on 10k+ lists: split 15,000 → 3 batches of 5,000, then
       broadcast to batch-1 today, batch-2 tomorrow, batch-3 the day after. -->
  <details style="margin: 6px 0 12px; padding: 10px 14px;
       background: linear-gradient(135deg, #faf5ff, #f5f3ff);
       border: 1px solid #ddd6fe; border-radius: 8px;">
    <summary style="cursor:pointer; color:#6b21a8; font-weight:600; font-size:14px;">
      🎯 Split all <?= number_format($totalMatching) ?> matching contact(s) into batches…
      <span class="muted small" style="font-weight:400; margin-left:6px;">for staged broadcasts</span>
    </summary>

    <form method="post" style="margin-top: 10px; display:flex; gap:8px; flex-wrap:wrap; align-items:end;"
          onsubmit="return splitConfirm(this);">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="split_batches">
      <!-- Mirror the current filter so the POST-side sees the same set -->
      <input type="hidden" name="q"          value="<?= e($search) ?>">
      <input type="hidden" name="branch_id"  value="<?= (int)$branchId ?>">
      <input type="hidden" name="tag_id"     value="<?= (int)$tagId ?>">
      <input type="hidden" name="date_field" value="<?= e($dateField) ?>">
      <input type="hidden" name="date_from"  value="<?= e($dateFrom) ?>">
      <input type="hidden" name="date_to"    value="<?= e($dateTo) ?>">

      <label style="font-size:13px;">
        <span class="muted small" style="display:block;">Batch size</span>
        <input type="number" name="batch_size" min="100" max="50000" value="5000" step="500"
               style="width: 110px; padding: 5px 8px;">
      </label>

      <label style="font-size:13px; flex:1; min-width:200px;">
        <span class="muted small" style="display:block;">Tag prefix (each batch gets prefix-1, prefix-2, …)</span>
        <input type="text" name="batch_prefix" required maxlength="50"
               value="batch-<?= date('Y-m') ?>"
               placeholder="e.g. batch-oct-2026"
               pattern="[a-zA-Z0-9_-]+"
               title="Letters, digits, underscore, hyphen only"
               style="width: 100%; padding: 5px 8px;">
      </label>

      <button type="submit" class="btn btn-sm btn-primary"
              style="background:#a855f7; border-color:#a855f7;">🎯 Split into batches</button>

      <div class="muted small" style="width:100%; padding-top:6px;">
        <strong>Preview:</strong>
        <span id="split-preview">
          <?= number_format($totalMatching) ?> ÷ 5,000 =
          <?= ceil($totalMatching / 5000) ?> batch(es) →
          <code>batch-<?= date('Y-m') ?>-1</code>,
          <code>batch-<?= date('Y-m') ?>-2</code>, …
        </span>
      </div>
    </form>
    <script>
      // Live-update the preview as the operator tweaks size/prefix.
      (function () {
        var total  = <?= (int)$totalMatching ?>;
        var form   = document.currentScript.closest('details').querySelector('form');
        var sizeEl = form.querySelector('input[name="batch_size"]');
        var prefEl = form.querySelector('input[name="batch_prefix"]');
        var out    = document.getElementById('split-preview');
        function upd() {
          var size = Math.max(100, Math.min(50000, parseInt(sizeEl.value, 10) || 5000));
          var count = Math.ceil(total / size);
          var pref = (prefEl.value || 'batch').replace(/[^a-zA-Z0-9_-]/g, '-').replace(/^-+|-+$/g, '');
          out.innerHTML = total.toLocaleString() + ' ÷ ' + size.toLocaleString() + ' = '
            + count + ' batch(es) → <code>' + pref + '-1</code>, <code>' + pref + '-2</code>, …';
        }
        sizeEl.addEventListener('input', upd);
        prefEl.addEventListener('input', upd);
      })();
      window.splitConfirm = function (f) {
        var total = <?= (int)$totalMatching ?>;
        var size  = Math.max(100, Math.min(50000, parseInt(f.batch_size.value, 10) || 5000));
        var pref  = (f.batch_prefix.value || 'batch').replace(/[^a-zA-Z0-9_-]/g, '-');
        var count = Math.ceil(total / size);
        return confirm('Split ' + total.toLocaleString() + ' contact(s) into ' + count
          + ' batches of ≤ ' + size.toLocaleString() + ' each?\n\nTags will be created: '
          + pref + '-1 … ' + pref + '-' + count + '\n\nThis takes ~' + Math.max(1, Math.round(total / 200))
          + ' seconds. Existing tags are not affected.');
      };
    </script>
  </details>
  <?php endif; ?>

  <?php if ($canManage): ?>
  <!-- Bulk-action bar — hidden until at least one row is checked, JS below.  -->
  <form method="post" id="bulk-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="bulk-action" value="bulk_tag">

    <div id="bulk-bar" style="display:none; margin: 6px 0 12px; padding: 12px 14px;
         background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px;
         align-items: center; gap: 10px; flex-wrap: wrap;">
      <strong style="color:#78350f;"><span id="bulk-count">0</span> selected</strong>

      <div style="display:flex; gap:6px; align-items:center;">
        <label style="font-size:13px; color:#78350f;">Add tag(s):</label>
        <input type="text" name="tag_names" placeholder="vip, member, hot-lead"
               style="min-width: 220px; padding: 5px 8px;" maxlength="500">
        <button type="button" class="btn btn-sm btn-primary" onclick="bulkTag()">🏷 Apply tag(s)</button>
      </div>

      <?php if ($allTags): ?>
        <div style="display:flex; gap:6px; align-items:center; padding-left:12px; margin-left:12px; border-left:1px solid #fbbf24;">
          <label style="font-size:13px; color:#78350f;">Remove tag:</label>
          <select name="untag_id" style="padding:5px 8px;">
            <option value="0">— pick —</option>
            <?php foreach ($allTags as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-sm btn-danger" onclick="bulkUntag()">🗑 Remove</button>
        </div>
      <?php endif; ?>

      <span style="margin-left:auto; font-size:12px; color:#78350f;">
        Tick "Select all on page" above the table to hit every visible row.
      </span>
    </div>

    <!-- The ids array is populated dynamically by the checkbox JS. -->
    <div id="bulk-ids-holder"></div>
  </form>
  <?php endif; ?>

  <table class="data-table">
    <thead>
      <tr>
        <?php if ($canManage): ?>
          <th style="width: 32px;">
            <input type="checkbox" id="check-all" title="Select all on page">
          </th>
        <?php endif; ?>
        <th>Name</th>
        <th>WhatsApp ID</th>
        <?php if ($hasEnrich): ?><th>External ID</th><?php endif; ?>
        <th>Branch</th>
        <th>Tags</th>
        <th>Last message</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$contacts): ?>
        <?php $isFiltered = $search !== '' || $branchId > 0 || $tagId > 0 || $dateField !== ''; ?>
        <tr><td colspan="<?= $canManage ? ($hasEnrich ? 7 : 6) : ($hasEnrich ? 6 : 5) ?>">
          <?php if ($isFiltered): ?>
            <span class="muted">No contacts match this view.</span>
          <?php else: ?>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:14px; border-radius:8px;">
              <div style="font-weight:600; margin-bottom:8px;">👤 No contacts yet — three ways to add them:</div>
              <ul style="margin:0; padding-left:20px; line-height:1.7;">
                <li><a href="/assets/templates/contact_import_template.xlsx">📥 Download template</a> → fill it → <a href="/contact_import.php">📄 Import CSV/XLSX</a></li>
                <li>Or wait for customers to message your WhatsApp — they auto-populate here</li>
                <li>Or WhatsApp your own number to test (yours becomes the first contact)</li>
              </ul>
            </div>
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($contacts as $c):
        $cid = (int)$c['id'];
        $tags = $tagsByCid[$cid] ?? [];
      ?>
        <tr>
          <?php if ($canManage): ?>
            <td><input type="checkbox" class="row-check" value="<?= $cid ?>" onchange="onCheckChange()"></td>
          <?php endif; ?>
          <td>
            <div><?= e($c['display_name'] ?: $c['profile_name'] ?: '—') ?></div>
            <?php if ($hasEnrich && !empty($c['email'])): ?>
              <div class="muted small">📧 <?= e((string)$c['email']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($c['wa_lid'])): ?>
              <span title="WhatsApp LID — Meta hides this customer's real phone. They can't be broadcast to."
                    style="background:#fef3c7; color:#78350f; padding:1px 6px; border-radius:999px; font-size:10.5px; font-weight:600;">
                🔒 LID
              </span>
              <span class="muted small" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
                <?= e($c['wa_id']) ?>
              </span>
            <?php else: ?>
              +<?= e($c['wa_id']) ?>
            <?php endif; ?>
            <?php if (!empty($c['phone']) && $c['phone'] !== $c['wa_id']): ?>
              <div class="muted small"><?= e((string)$c['phone']) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($hasEnrich): ?>
            <td class="muted small"><?= e((string)($c['external_id'] ?? '')) ?: '—' ?></td>
          <?php endif; ?>
          <td class="muted small"><?= e($c['branch_name'] ?: '—') ?></td>
          <td>
            <?php if (!$tags): ?>
              <span class="muted small">—</span>
            <?php else: ?>
              <div style="display:flex; gap:4px; flex-wrap:wrap;">
                <?php foreach ($tags as $t): ?>
                  <a href="/contacts.php?tag_id=<?= (int)$t['id'] ?>"
                     title="Filter by this tag" style="text-decoration:none;">
                    <span style="background: <?= e($t['color'] ?: '#25D366') ?>; color:#fff;
                                 padding: 2px 8px; border-radius: 999px; font-size:11px;
                                 white-space: nowrap;"><?= e($t['name']) ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="muted small"><?= e(fmt_dt($c['last_message_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<script>
(function () {
  var checkAll = document.getElementById('check-all');
  var rows     = document.querySelectorAll('.row-check');
  var bar      = document.getElementById('bulk-bar');
  var countEl  = document.getElementById('bulk-count');

  window.onCheckChange = function () {
    var n = 0;
    rows.forEach(function (r) { if (r.checked) n++; });
    countEl.textContent = n;
    bar.style.display = n > 0 ? 'flex' : 'none';
    // Sync the master checkbox state.
    checkAll.checked = (n === rows.length && rows.length > 0);
    checkAll.indeterminate = (n > 0 && n < rows.length);
  };

  checkAll.addEventListener('change', function () {
    rows.forEach(function (r) { r.checked = checkAll.checked; });
    window.onCheckChange();
  });

  // Populate ids[] inside the bulk-form on submit — cheaper than hidden
  // inputs kept in sync live, and doesn't leave stale ids around if
  // the operator scrolls through pages.
  function collectIds() {
    var holder = document.getElementById('bulk-ids-holder');
    holder.innerHTML = '';
    rows.forEach(function (r) {
      if (r.checked) {
        var i = document.createElement('input');
        i.type = 'hidden';
        i.name = 'ids[]';
        i.value = r.value;
        holder.appendChild(i);
      }
    });
    return document.querySelectorAll('#bulk-ids-holder input').length;
  }

  window.bulkTag = function () {
    var input = document.querySelector('#bulk-bar input[name="tag_names"]');
    if (!input.value.trim()) { alert('Type a tag name first.'); input.focus(); return; }
    var n = collectIds();
    if (!n) return;
    if (!confirm('Apply "' + input.value + '" to ' + n + ' contact(s)?')) return;
    document.getElementById('bulk-action').value = 'bulk_tag';
    // Copy the tag input into the form (it's inside the bulk-bar so it's
    // already inside the form, no move needed).
    document.getElementById('bulk-form').submit();
  };

  window.bulkUntag = function () {
    var sel = document.querySelector('#bulk-bar select[name="untag_id"]');
    if (!sel || sel.value === '0') { alert('Pick a tag to remove first.'); return; }
    var name = sel.options[sel.selectedIndex].text;
    var n = collectIds();
    if (!n) return;
    if (!confirm('Remove tag "' + name + '" from ' + n + ' contact(s)?')) return;
    document.getElementById('bulk-action').value = 'bulk_untag';
    document.getElementById('bulk-form').submit();
  };
})();
</script>
<?php endif; ?>

<?php layout_end(); ?>
