<?php
/**
 * Message templates — enhanced management page.
 *
 * Adds to the original list:
 *   - Search + status + language + category filters
 *   - Category summary chips (Utility 5 / Marketing 3 / ...)
 *   - Usage-in-last-30-days count per template
 *   - Last-used date per template
 *   - Full-body expand toggle inline (no separate page load)
 *   - Duplicate action
 *   - Per-row quick status change (draft/pending/approved/rejected)
 *   - Bulk actions: delete or set-status on selected rows
 *   - CSV export of the current filtered set
 *
 * All mutations still gated by user_can_edit_settings(), same as before.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

// -------------------- POST actions --------------------
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // Everything mutating requires edit-settings permission.
    $mutating = ['delete', 'seed', 'duplicate', 'set_status', 'bulk_delete', 'bulk_status'];
    if (in_array($action, $mutating, true) && !user_can_edit_settings($current_user)) {
        http_response_code(403); exit('Forbidden.');
    }

    if ($action === 'delete') {
        $tid = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM message_templates WHERE id = ? AND company_id = ?')
           ->execute([$tid, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'template_deleted', 'template', $tid);
        redirect('/admin/templates.php');
    }

    if ($action === 'duplicate') {
        $tid = (int)($_POST['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM message_templates WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$tid, $companyId]);
        $src = $st->fetch();
        if ($src) {
            // Find a unique name by suffixing _copy / _copy_2 / ...
            $base = $src['template_name'] . '_copy';
            $newName = $base;
            $i = 2;
            $check = $db->prepare('SELECT COUNT(*) FROM message_templates WHERE company_id = ? AND template_name = ?');
            while (true) {
                $check->execute([$companyId, $newName]);
                if ((int)$check->fetchColumn() === 0) break;
                $newName = $base . '_' . $i;
                $i++;
                if ($i > 100) { $newName = $base . '_' . time(); break; }
            }
            $db->prepare(
                'INSERT INTO message_templates
                    (company_id, template_name, category, language, body_text, variables_json, status)
                 VALUES (?, ?, ?, ?, ?, ?, "draft")'
            )->execute([
                $companyId, $newName, $src['category'], $src['language'],
                $src['body_text'], $src['variables_json'],
            ]);
            log_activity($companyId, (int)$current_user['id'], 'template_duplicated', 'template', $tid, 'as=' . $newName);
        }
        redirect('/admin/templates.php');
    }

    if ($action === 'set_status') {
        $tid    = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (in_array($status, ['draft', 'pending', 'approved', 'rejected'], true)) {
            $db->prepare('UPDATE message_templates SET status = ? WHERE id = ? AND company_id = ?')
               ->execute([$status, $tid, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'template_status_changed', 'template', $tid, 'status=' . $status);
        }
        redirect('/admin/templates.php');
    }

    if ($action === 'bulk_delete' || $action === 'bulk_status') {
        $ids = array_map('intval', (array)($_POST['selected'] ?? []));
        $ids = array_values(array_filter($ids, fn($i) => $i > 0));
        if (!$ids) { redirect('/admin/templates.php'); }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'bulk_delete') {
            $st = $db->prepare("DELETE FROM message_templates WHERE company_id = ? AND id IN ($placeholders)");
            $st->execute(array_merge([$companyId], $ids));
            log_activity($companyId, (int)$current_user['id'], 'templates_bulk_deleted', null, null, 'count=' . count($ids));
        } else {
            $newStatus = (string)($_POST['bulk_status_value'] ?? '');
            if (in_array($newStatus, ['draft','pending','approved','rejected'], true)) {
                $st = $db->prepare("UPDATE message_templates SET status = ? WHERE company_id = ? AND id IN ($placeholders)");
                $st->execute(array_merge([$newStatus, $companyId], $ids));
                log_activity($companyId, (int)$current_user['id'], 'templates_bulk_status', null, null, 'status=' . $newStatus . ' count=' . count($ids));
            }
        }
        redirect('/admin/templates.php');
    }

    if ($action === 'seed') {
        // Starter pack (unchanged from the original)
        $seeds = [
            ['welcome_greeting', 'UTILITY', 'en',
             "Hi {{1}}! 👋 Welcome to {{2}} — how can we help you today?",
             '{"1":"customer_name","2":"business_name"}'],
            ['welcome_greeting_bm', 'UTILITY', 'ms',
             "Hi {{1}}! 👋 Selamat datang ke {{2}} — bagaimana kami boleh bantu?",
             '{"1":"customer_name","2":"business_name"}'],
            ['business_hours', 'UTILITY', 'en',
             "Thanks for reaching out! Our team is available {{1}}. We'll get back to you as soon as we're back online.",
             '{"1":"business_hours"}'],
            ['catalog_menu', 'MARKETING', 'en',
             "Hi {{1}}, here's our latest menu 👇 Let us know if anything catches your eye!",
             '{"1":"customer_name"}'],
            ['price_inquiry', 'UTILITY', 'en',
             "Hi {{1}}, thanks for asking about pricing. Our full price list is attached. For custom quotes, just tell us what you need!",
             '{"1":"customer_name"}'],
            ['order_confirmation', 'UTILITY', 'en',
             "Hi {{1}}, we've received your order #{{2}}. Total: {{3}}. We'll notify you when it's ready — thanks for shopping with us!",
             '{"1":"customer_name","2":"order_id","3":"total_amount"}'],
            ['delivery_ready', 'UTILITY', 'en',
             "Good news {{1}}! Your order #{{2}} is on the way. Expected delivery: {{3}}. Track it here: {{4}} — thanks for your patience!",
             '{"1":"customer_name","2":"order_id","3":"eta","4":"tracking_url"}'],
            ['booking_confirmed', 'UTILITY', 'en',
             "Hi {{1}}, your booking on {{2}} at {{3}} is confirmed ✅ Reply here if you need to change anything.",
             '{"1":"customer_name","2":"date","3":"time"}'],
            ['payment_reminder', 'UTILITY', 'en',
             "Hi {{1}}, a friendly reminder that invoice {{2}} for {{3}} is due on {{4}}. Reply here for payment options.",
             '{"1":"customer_name","2":"invoice_id","3":"amount","4":"due_date"}'],
            ['thanks_review', 'MARKETING', 'en',
             "Thanks for choosing {{1}}, {{2}}! If you had a great experience, a quick Google review helps us a lot ⭐ Leave one here: {{3}} — we appreciate it!",
             '{"1":"business_name","2":"customer_name","3":"review_url"}'],
        ];
        $ins = $db->prepare(
            'INSERT IGNORE INTO message_templates
                (company_id, template_name, category, language, body_text, variables_json, status)
             VALUES (?, ?, ?, ?, ?, ?, "draft")'
        );
        $added = 0;
        foreach ($seeds as [$n, $cat, $lang, $body, $vars]) {
            $ins->execute([$companyId, $n, $cat, $lang, $body, $vars]);
            if ($ins->rowCount() > 0) $added++;
        }
        log_activity($companyId, (int)$current_user['id'], 'templates_seeded', 'company', $companyId, 'added=' . $added);
        redirect('/admin/templates.php?seeded=' . $added);
    }
}

// -------------------- Filters --------------------
$fSearch   = trim((string)($_GET['q']        ?? ''));
$fStatus   = (string)($_GET['status']        ?? '');
$fLang     = (string)($_GET['language']      ?? '');
$fCategory = (string)($_GET['category']      ?? '');

$where  = ['company_id = ?'];
$params = [$companyId];
if ($fSearch !== '') {
    $where[] = '(template_name LIKE ? OR body_text LIKE ?)';
    $like = '%' . $fSearch . '%';
    $params[] = $like; $params[] = $like;
}
if (in_array($fStatus, ['draft', 'pending', 'approved', 'rejected'], true)) {
    $where[] = 'status = ?'; $params[] = $fStatus;
}
if ($fLang !== '') {
    $where[] = 'language = ?'; $params[] = $fLang;
}
if ($fCategory !== '') {
    $where[] = 'category = ?'; $params[] = $fCategory;
}

$sql = 'SELECT * FROM message_templates
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY template_name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$templates = $stmt->fetchAll();

// Full unfiltered list for the category / language chip totals so
// operators can see what other buckets exist even when filtered.
$allSt = $db->prepare('SELECT category, language, status FROM message_templates WHERE company_id = ?');
$allSt->execute([$companyId]);
$allT = $allSt->fetchAll();

$byCat = []; $byLang = []; $byStatus = [];
foreach ($allT as $t) {
    $byCat[(string)($t['category'] ?? '(none)')] = ($byCat[(string)($t['category'] ?? '(none)')] ?? 0) + 1;
    $byLang[(string)($t['language'] ?? '(none)')] = ($byLang[(string)($t['language'] ?? '(none)')] ?? 0) + 1;
    $byStatus[(string)$t['status']] = ($byStatus[(string)$t['status']] ?? 0) + 1;
}
ksort($byCat); ksort($byLang);

// Usage stats: count messages sent per template_name in the last 30 days,
// and grab the most recent sent_at per template.
$usage30 = []; $lastUsed = [];
try {
    $u = $db->prepare(
        "SELECT template_name,
                COUNT(*) AS n30,
                MAX(sent_at) AS last_used
         FROM messages
         WHERE company_id = ?
           AND message_type = 'template'
           AND template_name IS NOT NULL
           AND created_at >= NOW() - INTERVAL 30 DAY
         GROUP BY template_name"
    );
    $u->execute([$companyId]);
    foreach ($u->fetchAll() as $r) {
        $usage30[(string)$r['template_name']]  = (int)$r['n30'];
        $lastUsed[(string)$r['template_name']] = (string)$r['last_used'];
    }
    // For last_used outside the 30-day window, backfill with a broader query
    // so old-but-stable templates don't render "never" when they were sent
    // 60 days ago.
    $u2 = $db->prepare(
        "SELECT template_name, MAX(sent_at) AS last_used
         FROM messages
         WHERE company_id = ? AND template_name IS NOT NULL
         GROUP BY template_name"
    );
    $u2->execute([$companyId]);
    foreach ($u2->fetchAll() as $r) {
        if (!isset($lastUsed[(string)$r['template_name']])) {
            $lastUsed[(string)$r['template_name']] = (string)$r['last_used'];
        }
    }
} catch (Throwable $e) { /* messages table missing = ignore */ }

// -------------------- CSV export --------------------
if (($_GET['export'] ?? '') === 'csv') {
    $fname = 'aiserve-templates-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Category', 'Language', 'Status', 'Body', 'Variables (JSON)', 'Used 30d', 'Last used']);
    foreach ($templates as $t) {
        fputcsv($out, [
            $t['template_name'],
            $t['category'] ?? '',
            $t['language'] ?? '',
            $t['status']   ?? '',
            $t['body_text'] ?? '',
            $t['variables_json'] ?? '',
            (int)($usage30[(string)$t['template_name']] ?? 0),
            (string)($lastUsed[(string)$t['template_name']] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// Preserve the current filter set on links.
$qsKeep = http_build_query(array_filter([
    'q'        => $fSearch,
    'status'   => $fStatus,
    'language' => $fLang,
    'category' => $fCategory,
]));

layout_start($current_user, 'Message templates', 'templates');
$seeded = isset($_GET['seeded']) ? (int)$_GET['seeded'] : null;
?>

<style>
.tpl-wrap { display: grid; gap: 16px; }
.tpl-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.tpl-chip {
  display: inline-flex; gap: 6px; align-items: center;
  padding: 4px 10px; border-radius: 999px;
  background: var(--c-surface-2, #f6f9fb);
  border: 1px solid var(--c-border, #e3e8ee);
  color: inherit; text-decoration: none; font-size: 12.5px;
}
.tpl-chip.active { border-color: #25D366; color: #16A34A; font-weight: 600; }
.tpl-chip .n { color: #64748b; font-size: 11px; }
.tpl-filter { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items: end; }
.tpl-filter label { font-size: 12px; color: #64748b; display: block; }
.tpl-filter input, .tpl-filter select { width: 100%; padding: 6px 8px; margin-top: 4px; }

.tpl-body-full {
  white-space: pre-wrap; background: #f6f9fb; padding: 10px 12px;
  border-radius: 6px; border: 1px solid #e3e8ee; font-size: 13px;
  color: #0f172a; margin: 6px 0;
}
.tpl-inline-form { display: inline-flex; align-items: center; gap: 4px; }
.tpl-inline-form select { padding: 2px 6px; font-size: 12px; }
.tpl-usage {
  display: inline-block; min-width: 32px; text-align: center;
  padding: 1px 6px; border-radius: 4px; font-size: 12px;
  background: rgba(37, 211, 102, .1); color: #16A34A;
}
.tpl-usage.zero { background: #f6f9fb; color: #94a3b8; }
</style>

<div class="tpl-wrap">

  <div class="card">
    <?php if ($seeded !== null && $seeded > 0): ?>
      <div class="alert alert-success">Added <?= (int)$seeded ?> starter template<?= $seeded === 1 ? '' : 's' ?>. Review each, tweak if needed, then submit to Meta for approval.</div>
    <?php elseif ($seeded === 0): ?>
      <div class="alert alert-info">Starter pack already loaded — no duplicates added.</div>
    <?php endif; ?>

    <div class="card-head">
      <h2>WhatsApp message templates <small class="muted">(<?= count($allT) ?> total)</small></h2>
      <?php if (user_can_edit_settings($current_user)): ?>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn btn-sm" href="?<?= $qsKeep ? $qsKeep . '&' : '' ?>export=csv">📤 Export CSV</a>
          <?php if (!$allT): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="seed">
              <button class="btn" type="submit" title="Add 10 common customer-service templates">📦 Load starter pack</button>
            </form>
          <?php endif; ?>
          <a class="btn btn-primary" href="/admin/template_edit.php">+ New template</a>
        </div>
      <?php endif; ?>
    </div>

    <p class="muted small">
      Templates are pre-approved by Meta and required when replying outside the
      24-hour window. Save the approved template name and body here so agents
      can reference them.
      <?php if (!$allT && user_can_edit_settings($current_user)): ?>
        <br>New workspace? Click <strong>📦 Load starter pack</strong> or use
        <strong>+ New template</strong> and describe your template in plain
        English — AI will draft it.
      <?php endif; ?>
    </p>

    <!-- Status filter chips -->
    <?php if ($allT): ?>
      <div class="tpl-chips" style="margin: 8px 0 12px;">
        <a class="tpl-chip <?= $fStatus === '' ? 'active' : '' ?>" href="?<?= http_build_query(array_filter(['q'=>$fSearch,'language'=>$fLang,'category'=>$fCategory])) ?>">
          All <span class="n"><?= count($allT) ?></span>
        </a>
        <?php foreach (['approved', 'pending', 'draft', 'rejected'] as $st): $n = (int)($byStatus[$st] ?? 0); ?>
          <?php if ($n === 0) continue; ?>
          <a class="tpl-chip <?= $fStatus === $st ? 'active' : '' ?>"
             href="?<?= http_build_query(array_filter(['q'=>$fSearch,'status'=>$st,'language'=>$fLang,'category'=>$fCategory])) ?>">
            <?= e(ucfirst($st)) ?> <span class="n"><?= $n ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Filter form -->
    <?php if ($allT): ?>
      <form method="get" class="tpl-filter" style="margin-bottom: 12px;">
        <label>Search
          <input type="search" name="q" value="<?= e($fSearch) ?>" placeholder="Name or body…">
        </label>
        <label>Status
          <select name="status" onchange="this.form.submit()">
            <option value="">Any status</option>
            <?php foreach (['approved', 'pending', 'draft', 'rejected'] as $st): ?>
              <option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if (count($byLang) > 1): ?>
          <label>Language
            <select name="language" onchange="this.form.submit()">
              <option value="">Any language</option>
              <?php foreach ($byLang as $lang => $n):
                if ($lang === '(none)') continue; ?>
                <option value="<?= e($lang) ?>" <?= $fLang === $lang ? 'selected' : '' ?>>
                  <?= e($lang) ?> (<?= (int)$n ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <?php if (count($byCat) > 1): ?>
          <label>Category
            <select name="category" onchange="this.form.submit()">
              <option value="">Any category</option>
              <?php foreach ($byCat as $cat => $n):
                if ($cat === '(none)') continue; ?>
                <option value="<?= e($cat) ?>" <?= $fCategory === $cat ? 'selected' : '' ?>>
                  <?= e($cat) ?> (<?= (int)$n ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label>&nbsp;
          <button class="btn btn-primary" type="submit" style="width:100%;">Search</button>
        </label>
      </form>
    <?php endif; ?>

    <?php if (!$templates): ?>
      <p class="muted" style="margin-top:8px;">
        <?= $allT ? 'No templates match this filter.' : 'No templates yet.' ?>
      </p>
    <?php else: ?>

      <!-- Bulk action bar (hidden until something is selected) -->
      <?php if (user_can_edit_settings($current_user)): ?>
        <form id="tpl-bulk-form" method="post">
          <?= csrf_field() ?>
          <div id="tpl-bulk-bar" style="display:none; background: #eef7f0; border:1px solid #cfe6d7; padding: 8px 12px; border-radius: 8px; margin-bottom: 8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong id="tpl-bulk-count">0</strong> selected.
            <select name="bulk_status_value">
              <option value="approved">Mark as approved</option>
              <option value="pending">Mark as pending</option>
              <option value="draft">Mark as draft</option>
              <option value="rejected">Mark as rejected</option>
            </select>
            <button type="submit" class="btn btn-sm" name="action" value="bulk_status">Change status</button>
            <button type="submit" class="btn btn-sm btn-danger" name="action" value="bulk_delete"
                    onclick="return confirm('Delete the selected templates? This cannot be undone.');">
              Delete selected
            </button>
            <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('.tpl-check').forEach(c=>c.checked=false); tplBulkSync();">Clear</button>
          </div>

          <table class="data-table">
            <thead>
              <tr>
                <th style="width:24px;"><input type="checkbox" id="tpl-check-all" onchange="document.querySelectorAll('.tpl-check').forEach(c=>c.checked=this.checked); tplBulkSync();"></th>
                <th>Name</th>
                <th>Category</th>
                <th>Language</th>
                <th>Body</th>
                <th style="width:80px;">Status</th>
                <th class="num" style="width:70px;" title="Times sent in last 30 days">30d</th>
                <th style="width:110px;">Last used</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($templates as $t):
                $tid = (int)$t['id'];
                $n30 = (int)($usage30[(string)$t['template_name']] ?? 0);
                $lu  = (string)($lastUsed[(string)$t['template_name']] ?? '');
              ?>
                <tr>
                  <td>
                    <input type="checkbox" class="tpl-check" name="selected[]" value="<?= $tid ?>" onchange="tplBulkSync();">
                  </td>
                  <td><code><?= e($t['template_name']) ?></code></td>
                  <td><?= e($t['category'] ?? '—') ?></td>
                  <td><?= e($t['language']) ?></td>
                  <td>
                    <div style="max-width: 360px;">
                      <span class="tpl-preview" data-id="<?= $tid ?>"
                            style="cursor:pointer; color: var(--c-ink, #0f172a);"
                            onclick="var f=document.getElementById('tpl-full-<?= $tid ?>'); f.style.display=(f.style.display==='block'?'none':'block');">
                        <?= e(mb_strimwidth((string)$t['body_text'], 0, 100, '…')) ?>
                      </span>
                      <div class="tpl-body-full" id="tpl-full-<?= $tid ?>" style="display:none;"><?= e((string)$t['body_text']) ?></div>
                    </div>
                  </td>
                  <td>
                    <?php if (user_can_edit_settings($current_user)): ?>
                      <form method="post" class="tpl-inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <select name="status" onchange="this.form.submit()">
                          <?php foreach (['draft','pending','approved','rejected'] as $st): ?>
                            <option value="<?= $st ?>" <?= (string)$t['status'] === $st ? 'selected' : '' ?>>
                              <?= e(ucfirst($st)) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </form>
                    <?php else: ?>
                      <?= status_badge($t['status']) ?>
                    <?php endif; ?>
                  </td>
                  <td class="num">
                    <span class="tpl-usage <?= $n30 === 0 ? 'zero' : '' ?>"
                          title="<?= $n30 ?> template send(s) in last 30 days">
                      <?= $n30 ?>
                    </span>
                  </td>
                  <td class="muted small">
                    <?= $lu !== '' ? e(relative_time($lu)) : '<span style="color:#94a3b8;">never</span>' ?>
                  </td>
                  <td class="actions">
                    <?php if (user_can_edit_settings($current_user)): ?>
                      <a class="btn btn-sm" href="/admin/template_edit.php?id=<?= $tid ?>">Edit</a>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <button class="btn btn-sm" type="submit" title="Create a draft copy of this template">Duplicate</button>
                      </form>
                      <form method="post" style="display:inline" onsubmit="return confirm('Delete this template?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $tid ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      <?php else: ?>
        <!-- Read-only for non-admins: table without checkboxes / status dropdown -->
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th><th>Category</th><th>Language</th><th>Body</th>
              <th>Status</th><th class="num" style="width:70px;">30d</th><th style="width:110px;">Last used</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($templates as $t):
              $n30 = (int)($usage30[(string)$t['template_name']] ?? 0);
              $lu  = (string)($lastUsed[(string)$t['template_name']] ?? '');
            ?>
              <tr>
                <td><code><?= e($t['template_name']) ?></code></td>
                <td><?= e($t['category'] ?? '—') ?></td>
                <td><?= e($t['language']) ?></td>
                <td><?= e(mb_strimwidth((string)$t['body_text'], 0, 100, '…')) ?></td>
                <td><?= status_badge($t['status']) ?></td>
                <td class="num"><span class="tpl-usage <?= $n30 === 0 ? 'zero' : '' ?>"><?= $n30 ?></span></td>
                <td class="muted small">
                  <?= $lu !== '' ? e(relative_time($lu)) : '<span style="color:#94a3b8;">never</span>' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<script>
// Show the bulk-action bar when at least one checkbox is ticked; also
// keep the header checkbox in tri-state.
function tplBulkSync() {
  var checks = Array.prototype.slice.call(document.querySelectorAll('.tpl-check'));
  var ticked = checks.filter(function (c) { return c.checked; }).length;
  var bar    = document.getElementById('tpl-bulk-bar');
  var count  = document.getElementById('tpl-bulk-count');
  var master = document.getElementById('tpl-check-all');
  if (bar) bar.style.display = ticked > 0 ? 'flex' : 'none';
  if (count) count.textContent = ticked;
  if (master) {
    master.checked = ticked > 0 && ticked === checks.length;
    master.indeterminate = ticked > 0 && ticked < checks.length;
  }
}
</script>

<?php layout_end(); ?>
