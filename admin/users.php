<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

if (is_post() && ($_POST['action'] ?? '') === 'toggle_status') {
    csrf_check();
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0 && $uid !== (int)$current_user['id']) {
        $stmt = $db->prepare(
            'UPDATE users
             SET status = IF(status = "active", "inactive", "active")
             WHERE id = ? AND company_id = ?'
        );
        $stmt->execute([$uid, $companyId]);
        log_activity($companyId, (int)$current_user['id'], 'user_status_toggled', 'user', $uid);
    }
    redirect('/admin/users.php');
}

$stmt = $db->prepare(
    'SELECT u.*, d.name AS department_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.company_id = ?
     ORDER BY u.role, u.name'
);
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();

$cstmt = $db->prepare('SELECT name, slug, plan FROM companies WHERE id = ?');
$cstmt->execute([$companyId]);
$company = $cstmt->fetch() ?: ['plan' => 'starter', 'name' => '', 'slug' => ''];
$plan      = (string)$company['plan'];
$seatLimit = plan_seat_limit($plan);
$seatUsed  = company_user_count($companyId);
$seatFull  = $seatUsed >= $seatLimit;

// Pricing math for the panel below the seat badge.
$pp        = pricing_get();
$cur       = $pp['currency'];
$per       = $pp['period_label'];
$currentCost = match ($plan) {
    'growth'     => $pp['bundle_price'],
    'enterprise' => $pp['bundle_price'] + max(0, $seatUsed - $pp['bundle_seats']) * $pp['extra_seat_price'],
    default      => $pp['per_seat'] * $seatUsed, // starter or unknown
};
// What does adding the next seat actually add to the bill?
// - Starter: each seat is per-seat, so + per_seat (until limit hit, then upgrade required)
// - Growth: bundle is flat - extra seats inside the bundle cost 0
// - Enterprise: every seat above bundle_seats adds extra_seat_price
$nextSeatCost = match ($plan) {
    'growth'     => 0.0,
    'enterprise' => $pp['extra_seat_price'],
    default      => $pp['per_seat'],
};

// Suggest the cheapest plan that fits seatUsed + 1.
$nextPlan = null;
$nextPlanCost = null;
if ($seatFull) {
    $wantedSeats = $seatUsed + 1;
    if ($plan === 'starter' && $wantedSeats <= $pp['bundle_seats']) {
        $nextPlan     = 'growth';
        $nextPlanCost = $pp['bundle_price'];
    } else {
        $nextPlan     = 'enterprise';
        $extras       = max(0, $wantedSeats - $pp['bundle_seats']);
        $nextPlanCost = $pp['bundle_price'] + ($extras * $pp['extra_seat_price']);
    }
}

layout_start($current_user, 'Users', 'users');
?>
<div class="card">
  <div class="card-head">
    <h2>Portal users</h2>
    <div>
      <span class="badge <?= $seatFull ? 'badge-failed' : 'badge-open' ?>" style="margin-right:8px;">
        <?= (int)$seatUsed ?> / <?= (int)$seatLimit ?> seats used (<?= e(ucfirst($plan)) ?>)
      </span>
      <a class="btn" href="/admin/user_import.php" style="margin-right:6px;">📄 Import CSV</a>
      <a class="btn btn-primary" href="/admin/user_edit.php" <?= $seatFull ? 'title="Seat limit reached"' : '' ?>>+ New user</a>
    </div>
  </div>

  <div class="seat-pricing" style="background:#f6f9fb;border:1px solid #e3e8ee;border-radius:8px;padding:10px 14px;margin:0 0 14px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div class="small">
      <strong>Current plan:</strong> <?= e(ucfirst($plan)) ?>
      · <strong><?= e(fmt_price($currentCost, $cur)) ?> <?= e($per) ?></strong>
      <span class="muted">
        <?php if ($plan === 'starter'): ?>
          (<?= (int)$seatUsed ?> seats × <?= e(fmt_price($pp['per_seat'], $cur)) ?>)
        <?php elseif ($plan === 'growth'): ?>
          (<?= (int)$pp['bundle_seats'] ?>-seat bundle, flat)
        <?php else: ?>
          (<?= e(fmt_price($pp['bundle_price'], $cur)) ?> base + <?= (int)max(0, $seatUsed - $pp['bundle_seats']) ?> extra × <?= e(fmt_price($pp['extra_seat_price'], $cur)) ?>)
        <?php endif; ?>
      </span>
    </div>
    <div class="small">
      <?php if ($seatFull && $nextPlan): ?>
        <span class="muted">Next seat?</span>
        <strong>Upgrade to <?= e(ucfirst($nextPlan)) ?></strong> ·
        <strong><?= e(fmt_price($nextPlanCost, $cur)) ?> <?= e($per) ?></strong>
        <span class="muted">(+<?= e(fmt_price($nextPlanCost - $currentCost, $cur)) ?>)</span>
      <?php elseif ($plan === 'growth'): ?>
        <span class="muted">Next seat:</span>
        <strong>included</strong>
        <span class="muted">(<?= (int)($pp['bundle_seats'] - $seatUsed) ?> seats left in bundle)</span>
      <?php else: ?>
        <span class="muted">Next seat:</span>
        <strong>+<?= e(fmt_price($nextSeatCost, $cur)) ?></strong>
      <?php endif; ?>
    </div>
  </div>
  <table class="data-table">
    <thead>
      <tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last login</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e(role_label($u['role'])) ?></td>
          <td><?= e($u['department_name'] ?? '—') ?></td>
          <td><?= status_badge($u['status']) ?></td>
          <td><?= e(fmt_dt($u['last_login_at'])) ?: '—' ?></td>
          <td class="actions">
            <a class="btn btn-sm" href="/admin/user_edit.php?id=<?= (int)$u['id'] ?>">Edit</a>
            <?php if ((int)$u['id'] !== (int)$current_user['id']): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm" type="submit">
                  <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php layout_end(); ?>
