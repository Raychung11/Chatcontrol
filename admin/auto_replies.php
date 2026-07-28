<?php
/**
 * Keyword auto-replies — enhanced management page.
 *
 * Additions on top of the original (rule list + edit/toggle/delete):
 *   - Search + status + channel + match-type filters
 *   - Status chips (Active X / Inactive Y) as one-click filters
 *   - Priority up/down buttons per row to reorder without editing
 *   - "Test what fires" simulator at the top: type a customer message,
 *     see which rule would win against the current active rule set
 *   - Duplicate action + bulk enable/disable/delete
 *   - Last-triggered relative time column
 *   - CSV export of the filtered set
 *
 * Reuses inc/auto_replies.php's auto_reply_match() for the simulator so
 * "what would fire in production" and "what the tester says would fire"
 * are the same code path.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/auto_replies.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// -------------------- POST actions --------------------
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    // Verify rule ownership before any mutation.
    $own = function (int $id) use ($db, $companyId): bool {
        $s = $db->prepare('SELECT id FROM auto_replies WHERE id = ? AND company_id = ?');
        $s->execute([$id, $companyId]);
        return (bool)$s->fetchColumn();
    };

    if ($id > 0 && $own($id)) {
        if ($action === 'toggle') {
            $db->prepare('UPDATE auto_replies SET status = IF(status = "active","inactive","active") WHERE id = ?')->execute([$id]);
            log_activity($companyId, (int)$current_user['id'], 'auto_reply_toggled', 'auto_reply', $id);
        } elseif ($action === 'delete') {
            $db->prepare('DELETE FROM auto_replies WHERE id = ?')->execute([$id]);
            log_activity($companyId, (int)$current_user['id'], 'auto_reply_deleted', 'auto_reply', $id);
        } elseif ($action === 'duplicate') {
            $s = $db->prepare('SELECT * FROM auto_replies WHERE id = ?');
            $s->execute([$id]);
            $src = $s->fetch();
            if ($src) {
                $newName = $src['name'] . ' (copy)';
                $db->prepare(
                    'INSERT INTO auto_replies
                        (company_id, channel_id, name, match_type, match_value, reply_text,
                         media_kind, media_path, media_filename, media_mime,
                         priority, cooldown_min, status, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "inactive", ?)'
                )->execute([
                    $companyId, $src['channel_id'], $newName,
                    $src['match_type'], $src['match_value'], $src['reply_text'],
                    $src['media_kind'], $src['media_path'], $src['media_filename'], $src['media_mime'],
                    (int)$src['priority'] + 1, (int)$src['cooldown_min'],
                    (int)$current_user['id'],
                ]);
                log_activity($companyId, (int)$current_user['id'], 'auto_reply_duplicated', 'auto_reply', $id);
            }
        } elseif ($action === 'move_up' || $action === 'move_down') {
            // Swap priority with the neighbor rule (asc by priority = higher-priority
            // rules first). "Up" means smaller priority number, "Down" larger.
            $s = $db->prepare('SELECT priority FROM auto_replies WHERE id = ?');
            $s->execute([$id]);
            $curPriority = (int)$s->fetchColumn();

            $neighborStmt = $db->prepare(
                'SELECT id, priority FROM auto_replies
                 WHERE company_id = ? AND priority ' . ($action === 'move_up' ? '<' : '>') . ' ?
                 ORDER BY priority ' . ($action === 'move_up' ? 'DESC' : 'ASC') . ' LIMIT 1'
            );
            $neighborStmt->execute([$companyId, $curPriority]);
            $neighbor = $neighborStmt->fetch();
            if ($neighbor) {
                $upd = $db->prepare('UPDATE auto_replies SET priority = ? WHERE id = ? AND company_id = ?');
                // Ensure distinct values even if the current and neighbor share the
                // same priority (default 100 for every fresh rule).
                $newSelf     = (int)$neighbor['priority'];
                $newNeighbor = $curPriority;
                if ($newSelf === $newNeighbor) {
                    $newSelf     = $action === 'move_up' ? $curPriority - 1 : $curPriority + 1;
                }
                $upd->execute([$newSelf, $id, $companyId]);
                $upd->execute([$newNeighbor, (int)$neighbor['id'], $companyId]);
                log_activity($companyId, (int)$current_user['id'], 'auto_reply_reordered', 'auto_reply', $id);
            }
        }
    }

    // Bulk actions apply to selected[] IDs.
    if (in_array($action, ['bulk_enable', 'bulk_disable', 'bulk_delete'], true)) {
        $ids = array_map('intval', (array)($_POST['selected'] ?? []));
        $ids = array_values(array_filter($ids, fn($i) => $i > 0));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'bulk_delete') {
                $s = $db->prepare("DELETE FROM auto_replies WHERE company_id = ? AND id IN ($ph)");
                $s->execute(array_merge([$companyId], $ids));
                log_activity($companyId, (int)$current_user['id'], 'auto_replies_bulk_deleted', null, null, 'count=' . count($ids));
            } else {
                $newStatus = $action === 'bulk_enable' ? 'active' : 'inactive';
                $s = $db->prepare("UPDATE auto_replies SET status = ? WHERE company_id = ? AND id IN ($ph)");
                $s->execute(array_merge([$newStatus, $companyId], $ids));
                log_activity($companyId, (int)$current_user['id'], 'auto_replies_bulk_status', null, null, 'status=' . $newStatus . ' count=' . count($ids));
            }
        }
    }

    redirect('/admin/auto_replies.php' . ($_SERVER['QUERY_STRING'] ? '?' . preg_replace('/^&/', '', str_replace(['action=', 'selected='], ['xa=', 'xs='], $_SERVER['QUERY_STRING'])) : ''));
}

// -------------------- Filters --------------------
$fSearch = trim((string)($_GET['q'] ?? ''));
$fStatus = (string)($_GET['status'] ?? '');
$fChannel = (int)($_GET['channel_id'] ?? 0);
$fType   = (string)($_GET['match_type'] ?? '');

$where  = ['r.company_id = ?'];
$params = [$companyId];
if ($fSearch !== '') {
    $where[] = '(r.name LIKE ? OR r.match_value LIKE ? OR r.reply_text LIKE ?)';
    $like = '%' . $fSearch . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($fStatus, ['active', 'inactive'], true)) {
    $where[] = 'r.status = ?'; $params[] = $fStatus;
}
if ($fChannel > 0) {
    // fChannel = -1 conceptually means "all channels" (channel_id IS NULL);
    // we don't offer that as a filter — > 0 filters to that specific channel.
    $where[] = 'r.channel_id = ?'; $params[] = $fChannel;
}
if (in_array($fType, ['contains', 'starts_with', 'equals', 'regex'], true)) {
    $where[] = 'r.match_type = ?'; $params[] = $fType;
}

$rows = $db->prepare(
    'SELECT r.*, ch.name AS channel_name
     FROM auto_replies r
     LEFT JOIN channels ch ON ch.id = r.channel_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY r.priority ASC, r.id ASC'
);
$rows->execute($params);
$rows = $rows->fetchAll();

// Unfiltered aggregates for the chip counts.
$allRows = $db->query(
    "SELECT status, channel_id, match_type FROM auto_replies WHERE company_id = $companyId"
)->fetchAll();
$byStatus = ['active' => 0, 'inactive' => 0];
foreach ($allRows as $r) { $byStatus[(string)$r['status']] = ($byStatus[(string)$r['status']] ?? 0) + 1; }

// Channels for the filter dropdown.
$channels = $db->prepare('SELECT id, name FROM channels WHERE company_id = ? AND status = "active" ORDER BY name');
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

// -------------------- Test simulator --------------------
// Simulates what would fire for a given customer message against the
// currently ACTIVE rule set (respecting priority). We ignore cooldowns
// here — the tester is a "what rule matches" answer, not a "would this
// actually send now given cooldown" one.
$testInput = (string)($_GET['test'] ?? '');
$testResult = null;
if ($testInput !== '') {
    $activeRules = $db->prepare(
        'SELECT * FROM auto_replies
         WHERE company_id = ? AND status = "active"
         ORDER BY priority ASC, id ASC'
    );
    $activeRules->execute([$companyId]);
    $activeRules = $activeRules->fetchAll();
    $testResult  = auto_reply_match($activeRules, $testInput);
}

// -------------------- CSV export --------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aiserve-auto-replies-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Priority','Name','Match type','Match value','Channel','Reply text','Media kind','Media file','Cooldown (min)','Status','Fires','Last triggered']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (int)$r['priority'], $r['name'], $r['match_type'], $r['match_value'],
            $r['channel_name'] ?? 'All channels',
            $r['reply_text'] ?? '',
            $r['media_kind'], $r['media_filename'] ?? '',
            (int)$r['cooldown_min'], $r['status'],
            (int)$r['trigger_count'], $r['last_triggered_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Preserve query string on links.
$qsKeep = http_build_query(array_filter([
    'q'          => $fSearch,
    'status'     => $fStatus,
    'channel_id' => $fChannel ?: null,
    'match_type' => $fType,
]));

layout_start($current_user, 'Auto replies', 'auto_replies');
?>

<style>
.ar-wrap { display: grid; gap: 16px; }
.ar-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.ar-chip {
  display: inline-flex; gap: 6px; align-items: center;
  padding: 4px 10px; border-radius: 999px;
  background: #f6f9fb; border: 1px solid #e3e8ee;
  color: inherit; text-decoration: none; font-size: 12.5px;
}
.ar-chip.active { border-color: #25D366; color: #16A34A; font-weight: 600; }
.ar-chip .n { color: #64748b; font-size: 11px; }

.ar-filter { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items: end; }
.ar-filter label { font-size: 12px; color: #64748b; display: block; }
.ar-filter input, .ar-filter select { width: 100%; padding: 6px 8px; margin-top: 4px; }

.ar-test-panel {
  background: linear-gradient(135deg, #f0f9ff 0%, #eef7f0 100%);
  border: 1px solid #cfe6d7; border-radius: 10px; padding: 14px;
}
.ar-test-input { display: flex; gap: 8px; align-items: center; }
.ar-test-input input {
  flex: 1; padding: 8px 10px; border-radius: 6px;
  border: 1px solid #cfe6d7; font-size: 14px;
}
.ar-test-hit {
  margin-top: 10px; padding: 10px 12px; background: rgba(37,211,102,.1);
  border: 1px solid rgba(37,211,102,.4); border-radius: 6px; color: #14532D;
}
.ar-test-miss {
  margin-top: 10px; padding: 10px 12px; background: #FEF3C7;
  border: 1px solid #FCD34D; border-radius: 6px; color: #78350F;
}

.ar-inline-form { display: inline-flex; align-items: center; gap: 4px; }
.ar-arrow { padding: 0 6px; font-size: 12px; line-height: 1; }

.ar-match-code { font-size: 11px; padding: 1px 6px; background: #f1f5f9; border-radius: 4px; color: #475569; }
.ar-media-badge {
  display: inline-flex; gap: 4px; align-items: center;
  font-size: 11.5px; padding: 2px 6px; background: #f1f5f9;
  border-radius: 4px; color: #334155;
}
.ar-fires {
  display: inline-block; padding: 1px 8px; border-radius: 999px;
  background: rgba(37,211,102,.1); color: #16A34A; font-size: 12px; font-weight: 600;
}
.ar-fires.zero { background: #f6f9fb; color: #94a3b8; font-weight: normal; }
</style>

<div class="ar-wrap">

  <!-- ============ Simulator ============ -->
  <?php if ($allRows): ?>
    <form method="get" class="ar-test-panel">
      <?php foreach (['q','status','channel_id','match_type'] as $keep):
        if (isset($_GET[$keep]) && $_GET[$keep] !== '' && $_GET[$keep] !== '0'): ?>
          <input type="hidden" name="<?= e($keep) ?>" value="<?= e((string)$_GET[$keep]) ?>">
        <?php endif; endforeach; ?>
      <div style="font-size:12px; color:#0369a1; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px;">
        🧪 Test what fires
      </div>
      <div class="ar-test-input">
        <input type="text" name="test" value="<?= e($testInput) ?>"
               placeholder="Type a customer message… (e.g. what's your menu today?)">
        <button class="btn btn-primary" type="submit">Simulate</button>
      </div>
      <?php if ($testInput !== ''): ?>
        <?php if ($testResult): ?>
          <div class="ar-test-hit">
            ✓ <strong><?= e($testResult['name']) ?></strong> would fire
            (priority <?= (int)$testResult['priority'] ?>, matched
            <span class="ar-match-code"><?= e($testResult['match_type']) ?></span>
            "<?= e(mb_strimwidth((string)$testResult['match_value'], 0, 40, '…')) ?>").
            <?php if (!empty($testResult['reply_text'])): ?>
              <br><span class="muted small">Would reply:</span>
              <em>"<?= e(mb_strimwidth((string)$testResult['reply_text'], 0, 120, '…')) ?>"</em>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="ar-test-miss">
            No active rule matches "<?= e(mb_strimwidth($testInput, 0, 60, '…')) ?>".
            The customer would get no auto reply.
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="muted small" style="margin-top: 8px;">
        Simulates against your <strong>active</strong> rules in priority order.
        Cooldowns are ignored — this answers "which rule matches", not "would it
        actually send right now".
      </div>
    </form>
  <?php endif; ?>

  <!-- ============ Main card ============ -->
  <div class="card">
    <div class="card-head">
      <h2>Keyword auto replies <small class="muted">(<?= count($allRows) ?> total)</small></h2>
      <div style="display:flex; gap:8px;">
        <?php if ($allRows): ?>
          <a class="btn btn-sm" href="?<?= $qsKeep ? $qsKeep . '&' : '' ?>export=csv">📤 Export CSV</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="/admin/auto_reply_edit.php">+ New rule</a>
      </div>
    </div>

    <p class="muted small">
      Send a canned message + optional catalog file the moment a customer
      types a matching keyword. Rules are checked in <strong>priority
      order</strong> — the first match wins and stops further rules. Each
      rule has a per-conversation cooldown so a repeat keyword doesn't spam.
    </p>

    <!-- Status chips -->
    <?php if ($allRows): ?>
      <div class="ar-chips" style="margin: 8px 0 12px;">
        <a class="ar-chip <?= $fStatus === '' ? 'active' : '' ?>"
           href="?<?= http_build_query(array_filter(['q'=>$fSearch,'channel_id'=>$fChannel ?: null,'match_type'=>$fType])) ?>">
          All <span class="n"><?= count($allRows) ?></span>
        </a>
        <?php foreach (['active', 'inactive'] as $st): $n = (int)$byStatus[$st]; if ($n === 0) continue; ?>
          <a class="ar-chip <?= $fStatus === $st ? 'active' : '' ?>"
             href="?<?= http_build_query(array_filter(['q'=>$fSearch,'status'=>$st,'channel_id'=>$fChannel ?: null,'match_type'=>$fType])) ?>">
            <?= e(ucfirst($st)) ?> <span class="n"><?= $n ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Filter form -->
      <form method="get" class="ar-filter" style="margin-bottom: 12px;">
        <label>Search
          <input type="search" name="q" value="<?= e($fSearch) ?>" placeholder="Name / match / reply">
        </label>
        <?php if (count($channels) > 0): ?>
          <label>Channel
            <select name="channel_id" onchange="this.form.submit()">
              <option value="0">Any channel</option>
              <?php foreach ($channels as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $fChannel === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label>Match type
          <select name="match_type" onchange="this.form.submit()">
            <option value="">Any type</option>
            <?php foreach (['contains','starts_with','equals','regex'] as $mt): ?>
              <option value="<?= $mt ?>" <?= $fType === $mt ? 'selected' : '' ?>><?= e($mt) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($fStatus): ?>
          <input type="hidden" name="status" value="<?= e($fStatus) ?>">
        <?php endif; ?>
        <label>&nbsp;
          <button class="btn btn-primary" type="submit" style="width:100%;">Search</button>
        </label>
      </form>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <p class="muted" style="margin-top: 10px;">
        <?= $allRows ? 'No rules match this filter.' : 'No auto-reply rules yet. Click "+ New rule" — e.g. keyword "menu" → send menu.pdf.' ?>
      </p>
    <?php else: ?>

      <form id="ar-bulk-form" method="post">
        <?= csrf_field() ?>
        <div id="ar-bulk-bar" style="display:none; background: #eef7f0; border:1px solid #cfe6d7; padding: 8px 12px; border-radius: 8px; margin-bottom: 8px; align-items:center; gap:8px; flex-wrap:wrap;">
          <strong id="ar-bulk-count">0</strong> selected.
          <button type="submit" class="btn btn-sm" name="action" value="bulk_enable">Enable</button>
          <button type="submit" class="btn btn-sm" name="action" value="bulk_disable">Disable</button>
          <button type="submit" class="btn btn-sm btn-danger" name="action" value="bulk_delete"
                  onclick="return confirm('Delete the selected rules? Uploaded media files are not removed.');">
            Delete
          </button>
          <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('.ar-check').forEach(c=>c.checked=false); arBulkSync();">Clear</button>
        </div>

        <table class="data-table">
          <thead>
            <tr>
              <th style="width:24px;"><input type="checkbox" id="ar-check-all" onchange="document.querySelectorAll('.ar-check').forEach(c=>c.checked=this.checked); arBulkSync();"></th>
              <th style="width:100px;">Priority</th>
              <th>Rule</th>
              <th>Match</th>
              <th>Channel</th>
              <th>Media</th>
              <th class="num" title="All-time fire count">Fires</th>
              <th>Last</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
              <tr>
                <td><input type="checkbox" class="ar-check" name="selected[]" value="<?= $rid ?>" onchange="arBulkSync();"></td>
                <td>
                  <span style="font-weight:600; margin-right:4px;"><?= (int)$r['priority'] ?></span>
                </td>
                <td>
                  <a href="/admin/auto_reply_edit.php?id=<?= $rid ?>"><strong><?= e($r['name']) ?></strong></a>
                  <?php if (!empty($r['cooldown_min'])): ?>
                    <br><span class="muted small">cooldown: <?= (int)$r['cooldown_min'] ?>m</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="ar-match-code"><?= e($r['match_type']) ?></span>
                  "<?= e(mb_strimwidth((string)$r['match_value'], 0, 40, '…')) ?>"
                </td>
                <td>
                  <?= $r['channel_name'] ? e($r['channel_name']) : '<span class="muted small">All channels</span>' ?>
                </td>
                <td>
                  <?php if ($r['media_kind'] === 'none' || !$r['media_path']): ?>
                    <span class="muted small">text only</span>
                  <?php else: ?>
                    <span class="ar-media-badge">
                      <?= e($r['media_kind']) ?>
                    </span>
                    <?php if ($r['media_filename']): ?>
                      <br><span class="muted small"><?= e(mb_strimwidth((string)$r['media_filename'], 0, 26, '…')) ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <span class="ar-fires <?= (int)$r['trigger_count'] === 0 ? 'zero' : '' ?>">
                    <?= (int)$r['trigger_count'] ?>
                  </span>
                </td>
                <td class="muted small">
                  <?= $r['last_triggered_at']
                        ? e(relative_time($r['last_triggered_at']))
                        : '<span style="color:#94a3b8;">never</span>' ?>
                </td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="actions" style="white-space: nowrap;">
                  <!-- Priority up/down (independent forms to avoid nesting) -->
                  <form method="post" class="ar-inline-form" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="move_up">
                    <input type="hidden" name="id" value="<?= $rid ?>">
                    <button class="btn btn-sm ar-arrow" type="submit" title="Higher priority (checked first)">▲</button>
                  </form>
                  <form method="post" class="ar-inline-form" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="move_down">
                    <input type="hidden" name="id" value="<?= $rid ?>">
                    <button class="btn btn-sm ar-arrow" type="submit" title="Lower priority">▼</button>
                  </form>
                  <a class="btn btn-sm" href="/admin/auto_reply_edit.php?id=<?= $rid ?>">Edit</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="duplicate">
                    <input type="hidden" name="id" value="<?= $rid ?>">
                    <button class="btn btn-sm" type="submit" title="Create an inactive copy of this rule">Duplicate</button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $rid ?>">
                    <button class="btn btn-sm" type="submit">
                      <?= $r['status'] === 'active' ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this rule? Uploaded media file is not removed.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $rid ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
function arBulkSync() {
  var checks = Array.prototype.slice.call(document.querySelectorAll('.ar-check'));
  var ticked = checks.filter(function (c) { return c.checked; }).length;
  var bar    = document.getElementById('ar-bulk-bar');
  var count  = document.getElementById('ar-bulk-count');
  var master = document.getElementById('ar-check-all');
  if (bar) bar.style.display = ticked > 0 ? 'flex' : 'none';
  if (count) count.textContent = ticked;
  if (master) {
    master.checked = ticked > 0 && ticked === checks.length;
    master.indeterminate = ticked > 0 && ticked < checks.length;
  }
}
</script>

<?php layout_end(); ?>
