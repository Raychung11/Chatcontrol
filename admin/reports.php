<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// Conversation totals
$totals = $db->prepare(
    'SELECT
       COUNT(*) AS total,
       SUM(status = "open")      AS s_open,
       SUM(status = "pending")   AS s_pending,
       SUM(status = "closed")    AS s_closed,
       SUM(status = "escalated") AS s_escalated,
       SUM(assigned_user_id IS NULL AND status <> "closed") AS unassigned,
       AVG(TIMESTAMPDIFF(SECOND, last_customer_message_at, first_response_at)) AS avg_first_response_secs
     FROM conversations
     WHERE company_id = ?'
);
$totals->execute([$companyId]);
$totals = $totals->fetch() ?: [];

// Message totals
$mTotals = $db->prepare(
    'SELECT
       SUM(direction = "incoming") AS incoming,
       SUM(direction = "outgoing") AS outgoing,
       SUM(direction = "outgoing" AND status = "failed") AS failed
     FROM messages WHERE company_id = ?'
);
$mTotals->execute([$companyId]);
$mTotals = $mTotals->fetch() ?: [];

// Per-agent
$perAgent = $db->prepare(
    'SELECT u.name, u.role,
            (SELECT COUNT(*) FROM conversations c WHERE c.assigned_user_id = u.id) AS conversations_assigned,
            (SELECT COUNT(*) FROM messages m WHERE m.sender_user_id = u.id AND m.direction = "outgoing") AS replies_sent
     FROM users u
     WHERE u.company_id = ? AND u.status = "active"
     ORDER BY replies_sent DESC, u.name'
);
$perAgent->execute([$companyId]);
$perAgent = $perAgent->fetchAll();

// Daily volume (last 14 days)
$daily = $db->prepare(
    'SELECT DATE(last_customer_message_at) AS d, COUNT(*) AS n
     FROM conversations
     WHERE company_id = ? AND last_customer_message_at IS NOT NULL
       AND last_customer_message_at > (NOW() - INTERVAL 14 DAY)
     GROUP BY DATE(last_customer_message_at)
     ORDER BY d DESC'
);
$daily->execute([$companyId]);
$daily = $daily->fetchAll();

// Monthly volume (last 6 months)
$monthly = $db->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
     FROM conversations
     WHERE company_id = ? AND created_at > (NOW() - INTERVAL 6 MONTH)
     GROUP BY ym ORDER BY ym DESC"
);
$monthly->execute([$companyId]);
$monthly = $monthly->fetchAll();

$avgFirstResp = (int)($totals['avg_first_response_secs'] ?? 0);
$avgFirstRespHuman = $avgFirstResp > 0
    ? sprintf('%dm %ds', floor($avgFirstResp / 60), $avgFirstResp % 60)
    : '—';

layout_start($current_user, 'Reports', 'reports');
?>
<div class="report-grid">
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['total'] ?? 0) ?></div><div>Total conversations</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_open'] ?? 0) ?></div><div>Open</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_pending'] ?? 0) ?></div><div>Pending</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_closed'] ?? 0) ?></div><div>Closed</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['s_escalated'] ?? 0) ?></div><div>Escalated</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($totals['unassigned'] ?? 0) ?></div><div>Unassigned</div></div>
  <div class="stat-card"><div class="stat-num"><?= e($avgFirstRespHuman) ?></div><div>Avg first response</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($mTotals['incoming'] ?? 0) ?></div><div>Messages received</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($mTotals['outgoing'] ?? 0) ?></div><div>Messages sent</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)($mTotals['failed'] ?? 0) ?></div><div>Send failures</div></div>
</div>

<div class="card">
  <h2>Per-agent performance</h2>
  <table class="data-table">
    <thead><tr><th>Agent</th><th>Role</th><th>Conversations assigned</th><th>Replies sent</th></tr></thead>
    <tbody>
      <?php foreach ($perAgent as $a): ?>
        <tr>
          <td><?= e($a['name']) ?></td>
          <td><?= e(role_label($a['role'])) ?></td>
          <td><?= (int)$a['conversations_assigned'] ?></td>
          <td><?= (int)$a['replies_sent'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="report-row">
  <div class="card">
    <h2>Daily conversation volume (14d)</h2>
    <table class="data-table">
      <thead><tr><th>Date</th><th>Conversations with customer activity</th></tr></thead>
      <tbody>
        <?php if (!$daily): ?><tr><td colspan="2" class="muted">No data.</td></tr><?php endif; ?>
        <?php foreach ($daily as $d): ?>
          <tr><td><?= e($d['d']) ?></td><td><?= (int)$d['n'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Monthly volume (6m)</h2>
    <table class="data-table">
      <thead><tr><th>Month</th><th>New conversations</th></tr></thead>
      <tbody>
        <?php if (!$monthly): ?><tr><td colspan="2" class="muted">No data.</td></tr><?php endif; ?>
        <?php foreach ($monthly as $m): ?>
          <tr><td><?= e($m['ym']) ?></td><td><?= (int)$m['n'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_end(); ?>
