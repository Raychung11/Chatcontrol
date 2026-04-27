<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// ---- Date range filter --------------------------------------------------
$presets = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'custom' => 'Custom'];
$preset = (string)($_GET['range'] ?? '30d');
if (!isset($presets[$preset])) $preset = '30d';

$today  = date('Y-m-d');
$dateFrom = (string)($_GET['from'] ?? '');
$dateTo   = (string)($_GET['to']   ?? '');

if ($preset !== 'custom') {
    $dateTo   = $today;
    $dateFrom = match ($preset) {
        'today' => $today,
        '7d'    => date('Y-m-d', strtotime('-6 days')),
        '90d'   => date('Y-m-d', strtotime('-89 days')),
        default => date('Y-m-d', strtotime('-29 days')),
    };
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $today;

$rangeStart = $dateFrom . ' 00:00:00';
$rangeEnd   = $dateTo   . ' 23:59:59';

// ---- Conversation totals (within range) ---------------------------------
$totals = $db->prepare(
    'SELECT
       COUNT(*) AS total,
       SUM(status = "open")      AS s_open,
       SUM(status = "pending")   AS s_pending,
       SUM(status = "closed")    AS s_closed,
       SUM(status = "escalated") AS s_escalated,
       SUM(assigned_user_id IS NULL AND status <> "closed") AS unassigned,
       AVG(TIMESTAMPDIFF(SECOND, last_customer_message_at, first_response_at)) AS avg_first_response_secs,
       AVG(TIMESTAMPDIFF(SECOND, created_at, resolved_at))                     AS avg_resolution_secs
     FROM conversations
     WHERE company_id = ? AND created_at BETWEEN ? AND ?'
);
$totals->execute([$companyId, $rangeStart, $rangeEnd]);
$totals = $totals->fetch() ?: [];

// ---- Message totals (within range) --------------------------------------
$mTotals = $db->prepare(
    'SELECT
       SUM(direction = "incoming") AS incoming,
       SUM(direction = "outgoing") AS outgoing,
       SUM(direction = "outgoing" AND status = "failed") AS failed
     FROM messages WHERE company_id = ? AND created_at BETWEEN ? AND ?'
);
$mTotals->execute([$companyId, $rangeStart, $rangeEnd]);
$mTotals = $mTotals->fetch() ?: [];

// ---- Per-agent: replies + avg response time within range ---------------
$perAgent = $db->prepare(
    'SELECT u.name, u.role,
        (SELECT COUNT(*) FROM conversations c
           WHERE c.assigned_user_id = u.id AND c.created_at BETWEEN ? AND ?) AS conversations_assigned,
        (SELECT COUNT(*) FROM messages m
           WHERE m.sender_user_id = u.id AND m.direction = "outgoing"
             AND m.created_at BETWEEN ? AND ?) AS replies_sent,
        (SELECT AVG(TIMESTAMPDIFF(SECOND, c.last_customer_message_at, c.first_response_at))
           FROM conversations c
           WHERE c.assigned_user_id = u.id
             AND c.first_response_at IS NOT NULL
             AND c.created_at BETWEEN ? AND ?) AS avg_response_secs
     FROM users u
     WHERE u.company_id = ? AND u.status = "active"
     ORDER BY replies_sent DESC, u.name'
);
$perAgent->execute([
    $rangeStart, $rangeEnd,
    $rangeStart, $rangeEnd,
    $rangeStart, $rangeEnd,
    $companyId,
]);
$perAgent = $perAgent->fetchAll();

// ---- Daily volume (within range) ----------------------------------------
$daily = $db->prepare(
    'SELECT DATE(created_at) AS d, COUNT(*) AS n
     FROM conversations
     WHERE company_id = ? AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at) ORDER BY d ASC'
);
$daily->execute([$companyId, $rangeStart, $rangeEnd]);
$daily = $daily->fetchAll();
$maxDaily = 0;
foreach ($daily as $d) { $maxDaily = max($maxDaily, (int)$d['n']); }

// ---- Top tags (within range) --------------------------------------------
$topTags = $db->prepare(
    'SELECT t.name, t.color, COUNT(*) AS n
     FROM conversation_tag_map m
     INNER JOIN conversation_tags t ON t.id = m.tag_id
     INNER JOIN conversations c     ON c.id = m.conversation_id
     WHERE t.company_id = ? AND c.created_at BETWEEN ? AND ?
     GROUP BY t.id, t.name, t.color
     ORDER BY n DESC LIMIT 10'
);
$topTags->execute([$companyId, $rangeStart, $rangeEnd]);
$topTags = $topTags->fetchAll();

// ---- Helpers ------------------------------------------------------------
function fmt_secs(?int $s): string
{
    if ($s === null || $s <= 0) return '—';
    if ($s < 60)        return $s . 's';
    if ($s < 3600)      return floor($s / 60) . 'm ' . ($s % 60) . 's';
    if ($s < 86400)     return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    return floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
}

layout_start($current_user, 'Reports', 'reports');
?>

<form method="get" class="card report-filter">
  <div class="filter-row">
    <label>Range
      <select name="range" onchange="this.form.submit()">
        <?php foreach ($presets as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $preset === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($preset === 'custom'): ?>
      <label>From <input type="date" name="from" value="<?= e($dateFrom) ?>"></label>
      <label>To   <input type="date" name="to"   value="<?= e($dateTo)   ?>"></label>
    <?php else: ?>
      <input type="hidden" name="from" value="<?= e($dateFrom) ?>">
      <input type="hidden" name="to"   value="<?= e($dateTo)   ?>">
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Apply</button>
    <span class="muted small"><?= e($dateFrom) ?> → <?= e($dateTo) ?></span>
  </div>
</form>

<div class="report-grid">
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['total']        ?? 0) ?></div><div>Conversations created</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_open']       ?? 0) ?></div><div>Open</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_pending']    ?? 0) ?></div><div>Pending</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_closed']     ?? 0) ?></div><div>Closed</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_escalated']  ?? 0) ?></div><div>Escalated</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['unassigned']   ?? 0) ?></div><div>Unassigned</div></div>
  <div class="stat-card"><div class="stat-num"><?= e(fmt_secs((int)($totals['avg_first_response_secs'] ?? 0))) ?></div><div>Avg first response</div></div>
  <div class="stat-card"><div class="stat-num"><?= e(fmt_secs((int)($totals['avg_resolution_secs']     ?? 0))) ?></div><div>Avg resolution</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($mTotals['incoming']    ?? 0) ?></div><div>Messages received</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($mTotals['outgoing']    ?? 0) ?></div><div>Messages sent</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($mTotals['failed']      ?? 0) ?></div><div>Send failures</div></div>
</div>

<div class="card">
  <h2>Per-agent performance</h2>
  <table class="data-table">
    <thead><tr><th>Agent</th><th>Role</th><th>Conversations assigned</th><th>Replies sent</th><th>Avg response time</th></tr></thead>
    <tbody>
      <?php foreach ($perAgent as $a): ?>
        <tr>
          <td><?= e($a['name']) ?></td>
          <td><?= e(role_label($a['role'])) ?></td>
          <td><?= (int)$a['conversations_assigned'] ?></td>
          <td><?= (int)$a['replies_sent'] ?></td>
          <td><?= e(fmt_secs((int)$a['avg_response_secs'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="report-row">
  <div class="card">
    <h2>Daily conversation volume</h2>
    <?php if (!$daily): ?>
      <p class="muted">No conversations in this range.</p>
    <?php else: ?>
      <ul class="bar-chart">
        <?php foreach ($daily as $d):
          $pct = $maxDaily > 0 ? max(2, round(((int)$d['n'] / $maxDaily) * 100)) : 0; ?>
          <li>
            <span class="bar-label"><?= e(date('M j', strtotime($d['d']))) ?></span>
            <span class="bar-track"><span class="bar-fill" style="width: <?= (int)$pct ?>%"></span></span>
            <span class="bar-value"><?= (int)$d['n'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Top tags</h2>
    <?php if (!$topTags): ?>
      <p class="muted">No tags applied in this range.</p>
    <?php else: ?>
      <ul class="tag-rank">
        <?php foreach ($topTags as $t): ?>
          <li>
            <span class="tag-chip" style="background: <?= e($t['color']) ?>"><?= e($t['name']) ?></span>
            <span class="muted small"><?= (int)$t['n'] ?> conversations</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php layout_end(); ?>
