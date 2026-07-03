<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();

    // Settings is now workspace-level ONLY: company identity, brand,
    // timezone, default department, operational alerts. All WhatsApp
    // connection config (provider choice, tokens, URLs, webhook verify
    // token) lives on /admin/channels.php per channel - the AiServe
    // real-world setup uses one Bearer token PER number, so a single
    // "primary" config here would be misleading.
    $name           = trim((string)($_POST['name']           ?? ''));
    $brandColor     = trim((string)($_POST['brand_color']    ?? '#25D366'));
    $timezone       = trim((string)($_POST['timezone']       ?? APP_TIMEZONE));
    $defaultDeptId  = $_POST['default_department_id'] ?? '';
    $defaultDeptId  = ($defaultDeptId === '' || $defaultDeptId === '0') ? null : (int)$defaultDeptId;

    $alertEnabled   = !empty($_POST['alert_failed_sends_enabled']) ? 1 : 0;
    $alertThreshold = max(1, (int)($_POST['alert_failed_sends_threshold'] ?? 5));
    $alertEmail     = trim((string)($_POST['alert_email'] ?? ''));

    if ($name === '') {
        $err = 'Company name is required.';
    } else {
        if ($defaultDeptId !== null) {
            $check = $db->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
            $check->execute([$defaultDeptId, $companyId]);
            if (!$check->fetchColumn()) $defaultDeptId = null;
        }
        $db->prepare(
            'UPDATE companies SET
                name = ?, brand_color = ?, timezone = ?, default_department_id = ?,
                alert_failed_sends_enabled = ?, alert_failed_sends_threshold = ?, alert_email = ?
             WHERE id = ?'
        )->execute([
            $name,
            $brandColor ?: '#25D366',
            $timezone   ?: APP_TIMEZONE,
            $defaultDeptId,
            $alertEnabled, $alertThreshold, $alertEmail ?: null,
            $companyId,
        ]);
        log_activity($companyId, (int)$current_user['id'], 'settings_updated', 'company', $companyId, 'Workspace settings updated');
        $msg = 'Settings saved.';
    }
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch() ?: [];

$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

layout_start($current_user, 'Workspace settings', 'settings', $company['brand_color'] ?? '#25D366');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <h2>Company</h2>
    <div class="alert alert-info" style="margin-bottom:8px;">
      Workspace identifier: <code><?= e($company['slug'] ?? '') ?></code> · Plan: <strong><?= e(ucfirst((string)($company['plan'] ?? 'starter'))) ?></strong>
      (<?= (int)company_user_count($companyId) ?> / <?= (int)plan_seat_limit((string)($company['plan'] ?? 'starter')) ?> seats used).
      The slug is used in your webhook URLs and cannot be changed once issued.
    </div>
    <label>Company name
      <input type="text" name="name" value="<?= e($company['name'] ?? '') ?>" required>
    </label>
    <label>Brand color
      <input type="color" name="brand_color" value="<?= e($company['brand_color'] ?? '#25D366') ?>">
    </label>
    <label>Default timezone
      <input type="text" name="timezone" value="<?= e($company['timezone'] ?? APP_TIMEZONE) ?>" placeholder="Asia/Kuala_Lumpur">
    </label>
    <label>Default department for new conversations
      <select name="default_department_id">
        <option value="0">— None (leave unrouted) —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= ((int)($company['default_department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="muted">New customer messages land here when no <a href="/admin/routing.php">routing rule</a> matches.</small>
    </label>

    <div class="alert alert-info">
      <strong>WhatsApp connection:</strong>
      each WhatsApp number has its own provider config and Bearer token, so
      the connection lives per-channel — not here. Manage numbers at
      <a href="/admin/channels.php"><strong>Admin → Channels</strong></a>.
      Test send + webhook URLs are also on that page, per channel.
    </div>

    <h2>Operational alerts</h2>
    <p class="muted small">
      Get an email when outbound sends start failing in bulk — usually means
      a wrong Bearer token, an expired gateway, or partner-side downtime.
    </p>
    <label class="check-row">
      <input type="checkbox" name="alert_failed_sends_enabled" value="1"
             <?= !empty($company['alert_failed_sends_enabled']) ? 'checked' : '' ?>>
      <span><strong>Email me when outbound sends fail in bulk</strong></span>
    </label>
    <label>Threshold <small class="muted">(N failures in 10 min triggers one alert; min 1)</small>
      <input type="number" name="alert_failed_sends_threshold" min="1" max="999"
             value="<?= (int)($company['alert_failed_sends_threshold'] ?? 5) ?>">
    </label>
    <label>Recipient email <small class="muted">(leave blank to send to all Workspace Admins)</small>
      <input type="email" name="alert_email"
             value="<?= e((string)($company['alert_email'] ?? '')) ?>"
             placeholder="ops@yourcompany.com">
    </label>
    <div class="alert alert-info">
      Alerts run via cron every 5 minutes. Same workspace gets at most one
      alert per 30 minutes (cooldown) so a sustained outage doesn't spam you.
      See <a href="/docs/CRON.md" target="_blank">CRON setup</a> if you
      haven't installed the cron job yet.
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </form>
</div>
<?php layout_end(); ?>
