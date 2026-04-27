<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $name              = trim((string)($_POST['name']                 ?? ''));
    $whatsappNumber    = trim((string)($_POST['whatsapp_number']      ?? ''));
    $phoneNumberId     = trim((string)($_POST['phone_number_id']      ?? ''));
    $businessAccountId = trim((string)($_POST['business_account_id']  ?? ''));
    $apiVersion        = trim((string)($_POST['api_version']          ?? 'v21.0'));
    $accessToken       = trim((string)($_POST['access_token']         ?? ''));
    $verifyToken       = trim((string)($_POST['webhook_verify_token'] ?? ''));
    $brandColor        = trim((string)($_POST['brand_color']          ?? '#25D366'));
    $timezone          = trim((string)($_POST['timezone']             ?? APP_TIMEZONE));
    $defaultDeptId     = $_POST['default_department_id'] ?? '';
    $defaultDeptId     = ($defaultDeptId === '' || $defaultDeptId === '0') ? null : (int)$defaultDeptId;

    if ($name === '') {
        $err = 'Company name is required.';
    } else {
        // If the access_token field is left blank, keep the existing one.
        if ($accessToken === '') {
            $stmt = $db->prepare('SELECT access_token FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $accessToken = (string)($stmt->fetchColumn() ?: '');
        }
        if ($defaultDeptId !== null) {
            $check = $db->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
            $check->execute([$defaultDeptId, $companyId]);
            if (!$check->fetchColumn()) $defaultDeptId = null;
        }
        $upd = $db->prepare(
            'UPDATE companies SET
                name = ?, whatsapp_number = ?, phone_number_id = ?, business_account_id = ?,
                api_version = ?, access_token = ?, webhook_verify_token = ?,
                brand_color = ?, timezone = ?, default_department_id = ?
             WHERE id = ?'
        );
        $upd->execute([
            $name, $whatsappNumber, $phoneNumberId, $businessAccountId,
            $apiVersion ?: 'v21.0', $accessToken, $verifyToken,
            $brandColor ?: '#25D366', $timezone ?: APP_TIMEZONE,
            $defaultDeptId,
            $companyId,
        ]);
        log_activity($companyId, (int)$current_user['id'], 'settings_updated', 'company', $companyId, 'Company settings updated');
        $msg = 'Settings saved.';
    }
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch() ?: [];

$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

$webhookUrl = (APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')))
            . '/webhook/whatsapp.php';

layout_start($current_user, 'Company & API Settings', 'settings', $company['brand_color'] ?? '#25D366');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <h2>Company</h2>
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

    <h2>WhatsApp Cloud API</h2>
    <label>Main WhatsApp number (display)
      <input type="text" name="whatsapp_number" value="<?= e($company['whatsapp_number'] ?? '') ?>" placeholder="+60 12 345 6789">
    </label>
    <label>Phone Number ID
      <input type="text" name="phone_number_id" value="<?= e($company['phone_number_id'] ?? '') ?>" placeholder="from Meta App Dashboard">
    </label>
    <label>WhatsApp Business Account ID
      <input type="text" name="business_account_id" value="<?= e($company['business_account_id'] ?? '') ?>">
    </label>
    <label>Graph API version
      <input type="text" name="api_version" value="<?= e($company['api_version'] ?? 'v21.0') ?>" placeholder="v21.0">
    </label>
    <label>Access token (Meta) — leave blank to keep existing
      <input type="password" name="access_token" value="" autocomplete="new-password" placeholder="Bearer token">
      <?php if (!empty($company['access_token'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($company['access_token'], 0, 6)) ?>…<?= e(substr($company['access_token'], -4)) ?></code></small>
      <?php endif; ?>
    </label>
    <label>Webhook verify token
      <input type="text" name="webhook_verify_token" value="<?= e($company['webhook_verify_token'] ?? '') ?>" placeholder="any random string">
    </label>

    <div class="alert alert-info">
      <strong>Webhook URL:</strong> <code><?= e($webhookUrl) ?></code><br>
      Configure this URL and the verify token above in your Meta App → WhatsApp → Configuration.
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </form>
</div>
<?php layout_end(); ?>
