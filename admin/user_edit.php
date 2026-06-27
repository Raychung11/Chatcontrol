<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$userId = (int)($_GET['id'] ?? 0);
$user   = null;
if ($userId > 0) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$userId, $companyId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        exit('User not found.');
    }
}

$err = '';
$msg = '';

if (is_post()) {
    csrf_check();
    $name     = trim((string)($_POST['name']           ?? ''));
    $email    = trim((string)($_POST['email']          ?? ''));
    $phone    = trim((string)($_POST['phone']          ?? ''));
    $role     = (string)($_POST['role']                ?? 'agent');
    $deptId   = $_POST['department_id'] ?? '';
    $deptId   = ($deptId === '' || $deptId === '0') ? null : (int)$deptId;
    $status   = ((string)($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
    $password = (string)($_POST['password'] ?? '');

    if (!in_array($role, ['super_admin', 'manager', 'agent'], true)) {
        $err = 'Invalid role.';
    } elseif ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Name and a valid email are required.';
    } elseif (!$user && $password === '') {
        $err = 'Password is required for new users.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $err = 'Password must be at least 8 characters.';
    } else {
        // Seat-limit guard - block adding a new active user beyond the plan.
        $isNewActive  = !$user && $status === 'active';
        $reactivating = $user && $user['status'] !== 'active' && $status === 'active';
        if ($isNewActive || $reactivating) {
            $cstmt = $db->prepare('SELECT plan FROM companies WHERE id = ?');
            $cstmt->execute([$companyId]);
            $plan  = (string)($cstmt->fetchColumn() ?: 'starter');
            $limit = plan_seat_limit($plan);
            $used  = company_user_count($companyId);
            if ($used >= $limit) {
                $pp = pricing_get();
                $wantedSeats = $used + 1;
                if ($plan === 'starter' && $wantedSeats <= $pp['bundle_seats']) {
                    $upgradeTo   = 'Growth';
                    $upgradeCost = $pp['bundle_price'];
                } else {
                    $upgradeTo   = 'Enterprise';
                    $extras      = max(0, $wantedSeats - $pp['bundle_seats']);
                    $upgradeCost = $pp['bundle_price'] + ($extras * $pp['extra_seat_price']);
                }
                $err = 'Seat limit reached (' . $used . ' / ' . $limit . ' on ' . ucfirst($plan)
                     . '). Deactivate someone, or upgrade to ' . $upgradeTo
                     . ' for ' . fmt_price($upgradeCost, $pp['currency']) . ' ' . $pp['period_label']
                     . ' (covers ' . $wantedSeats . ' seats).';
            }
        }
    }
    if ($err === '') {
        try {
            if ($user) {
                $sql = 'UPDATE users SET name=?, email=?, phone=?, role=?, department_id=?, status=?'
                     . ($password !== '' ? ', password_hash=?' : '')
                     . ' WHERE id=? AND company_id=?';
                $params = [$name, $email, $phone, $role, $deptId, $status];
                if ($password !== '') {
                    $params[] = password_hash($password, PASSWORD_BCRYPT);
                }
                $params[] = (int)$user['id'];
                $params[] = $companyId;
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                log_activity($companyId, (int)$current_user['id'], 'user_updated', 'user', (int)$user['id']);
                $msg = 'User updated.';
                // refresh
                $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([(int)$user['id']]);
                $user = $stmt->fetch();
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO users (company_id, department_id, name, email, phone, password_hash, role, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $companyId, $deptId, $name, $email, $phone,
                    password_hash($password, PASSWORD_BCRYPT), $role, $status,
                ]);
                $newId = (int)$db->lastInsertId();
                log_activity($companyId, (int)$current_user['id'], 'user_created', 'user', $newId);
                redirect('/admin/user_edit.php?id=' . $newId);
            }
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                $err = 'A user with that email already exists.';
            } else {
                error_log($e->getMessage());
                $err = 'Could not save user.';
            }
        }
    }
}

$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

layout_start($current_user, $user ? 'Edit user' : 'New user', 'users');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <label>Name<input type="text" name="name" required value="<?= e($user['name'] ?? ($_POST['name'] ?? '')) ?>"></label>
    <label>Email<input type="email" name="email" required value="<?= e($user['email'] ?? ($_POST['email'] ?? '')) ?>"></label>
    <label>Phone<input type="text" name="phone" value="<?= e($user['phone'] ?? ($_POST['phone'] ?? '')) ?>"></label>
    <label>Role
      <select name="role">
        <?php foreach (['super_admin','manager','agent'] as $r): ?>
          <option value="<?= $r ?>" <?= ($user['role'] ?? 'agent') === $r ? 'selected' : '' ?>>
            <?= e(role_label($r)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Department
      <select name="department_id">
        <option value="0">— None —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= ((int)($user['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="active"   <?= ($user['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= ($user['status'] ?? '')       === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </label>
    <label><?= $user ? 'New password (leave blank to keep current)' : 'Password' ?>
      <input type="password" name="password" autocomplete="new-password" minlength="8" <?= $user ? '' : 'required' ?>>
    </label>
    <div>
      <button class="btn btn-primary" type="submit"><?= $user ? 'Save changes' : 'Create user' ?></button>
      <a class="btn" href="/admin/users.php">Cancel</a>
    </div>
  </form>
</div>
<?php layout_end(); ?>
