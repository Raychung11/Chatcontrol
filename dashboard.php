<?php
require_once __DIR__ . '/inc/layout.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$stats = $db->prepare(
    'SELECT
       SUM(status <> "closed") AS open_total,
       SUM(assigned_user_id IS NULL AND status <> "closed") AS unassigned,
       SUM(assigned_user_id = ? AND status <> "closed") AS mine,
       SUM(status = "escalated") AS escalated,
       SUM(status = "closed" AND DATE(updated_at) = CURDATE()) AS closed_today
     FROM conversations WHERE company_id = ?'
);
$stats->execute([(int)$current_user['id'], $companyId]);
$stats = $stats->fetch() ?: [];

$recent = $db->prepare(
    'SELECT c.id, c.status, c.last_message_text, c.last_message_at, ct.display_name, ct.wa_id, u.name AS agent_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     LEFT  JOIN users u ON u.id = c.assigned_user_id
     WHERE c.company_id = ?
     ORDER BY c.last_message_at DESC LIMIT 10'
);
$recent->execute([$companyId]);
$recent = $recent->fetchAll();

layout_start($current_user, 'Dashboard', 'dashboard');
?>
<div class="report-grid">
  <a class="stat-card link" href="/inbox/index.php?filter=all">
    <div class="stat-num"><?= (int)($stats['open_total']  ?? 0) ?></div><div>Open conversations</div>
  </a>
  <a class="stat-card link" href="/inbox/index.php?filter=unassigned">
    <div class="stat-num"><?= (int)($stats['unassigned']  ?? 0) ?></div><div>Unassigned</div>
  </a>
  <a class="stat-card link" href="/inbox/index.php?filter=mine">
    <div class="stat-num"><?= (int)($stats['mine']        ?? 0) ?></div><div>Assigned to me</div>
  </a>
  <a class="stat-card link" href="/inbox/index.php?filter=escalated">
    <div class="stat-num"><?= (int)($stats['escalated']   ?? 0) ?></div><div>Escalated</div>
  </a>
  <div class="stat-card"><div class="stat-num"><?= (int)($stats['closed_today'] ?? 0) ?></div><div>Closed today</div></div>
</div>

<div class="card">
  <div class="card-head">
    <h2>Latest activity</h2>
    <a class="btn btn-sm" href="/inbox/index.php">Open inbox</a>
  </div>
  <table class="data-table">
    <thead><tr><th>Customer</th><th>Last message</th><th>Status</th><th>Assigned</th><th>Time</th></tr></thead>
    <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="5" class="muted">No conversations yet. Inbound WhatsApp messages will appear here.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><a href="/inbox/chat.php?id=<?= (int)$r['id'] ?>"><?= e($r['display_name'] ?: $r['wa_id']) ?></a></td>
          <td><?= e(mb_strimwidth((string)$r['last_message_text'], 0, 80, '…')) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= e($r['agent_name'] ?: 'Unassigned') ?></td>
          <td><?= e(relative_time($r['last_message_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
