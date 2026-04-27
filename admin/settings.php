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

    $provider          = (string)($_POST['provider'] ?? 'cloud_api');
    if (!in_array($provider, ['cloud_api', 'evolution'], true)) $provider = 'cloud_api';
    $evoBaseUrl        = trim((string)($_POST['evolution_base_url'] ?? ''));
    $evoApiKey         = trim((string)($_POST['evolution_api_key']  ?? ''));
    $evoInstance       = trim((string)($_POST['evolution_instance'] ?? ''));

    if ($name === '') {
        $err = 'Company name is required.';
    } else {
        // If the access_token field is left blank, keep the existing one.
        if ($accessToken === '') {
            $stmt = $db->prepare('SELECT access_token FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $accessToken = (string)($stmt->fetchColumn() ?: '');
        }
        // Same for evolution_api_key — keep existing if blank
        if ($evoApiKey === '') {
            $stmt = $db->prepare('SELECT evolution_api_key FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $evoApiKey = (string)($stmt->fetchColumn() ?: '');
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
                brand_color = ?, timezone = ?, default_department_id = ?,
                provider = ?, evolution_base_url = ?, evolution_api_key = ?, evolution_instance = ?
             WHERE id = ?'
        );
        $upd->execute([
            $name, $whatsappNumber, $phoneNumberId, $businessAccountId,
            $apiVersion ?: 'v21.0', $accessToken, $verifyToken,
            $brandColor ?: '#25D366', $timezone ?: APP_TIMEZONE,
            $defaultDeptId,
            $provider,
            $evoBaseUrl ?: null,
            $evoApiKey  ?: null,
            $evoInstance ?: null,
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

    <h2>Messaging provider</h2>
    <?php $currentProvider = $company['provider'] ?? 'cloud_api'; ?>
    <div class="provider-picker">
      <label class="provider-radio">
        <input type="radio" name="provider" value="cloud_api" <?= $currentProvider === 'cloud_api' ? 'checked' : '' ?>>
        <span>
          <strong>Meta Cloud API</strong> <small class="muted">— official, paid per conversation, supports templates &amp; 24-hour window enforced.</small>
        </span>
      </label>
      <label class="provider-radio">
        <input type="radio" name="provider" value="evolution" <?= $currentProvider === 'evolution' ? 'checked' : '' ?>>
        <span>
          <strong>Evolution API</strong> <small class="muted">— self-hosted, unofficial Baileys/WhatsApp Web. No template requirement, but Meta may ban the number. Use at your own risk.</small>
        </span>
      </label>
    </div>

    <h2>Cloud API settings</h2>
    <p class="muted small">Used when provider is set to Meta Cloud API.</p>
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
      <strong>Cloud API webhook URL:</strong> <code><?= e($webhookUrl) ?></code><br>
      Configure this URL and the verify token above in your Meta App → WhatsApp → Configuration.
    </div>

    <h2>Evolution API settings</h2>
    <p class="muted small">Used when provider is set to Evolution. See <a href="/docs/EVOLUTION.md" target="_blank">self-host guide</a> for setup.</p>
    <label>Evolution server base URL
      <input type="url" name="evolution_base_url" value="<?= e($company['evolution_base_url'] ?? '') ?>" placeholder="https://evo.your-server.com">
    </label>
    <label>Evolution API key (instance-level apikey) — leave blank to keep existing
      <input type="password" name="evolution_api_key" value="" autocomplete="new-password" placeholder="apikey value">
      <?php if (!empty($company['evolution_api_key'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($company['evolution_api_key'], 0, 6)) ?>…<?= e(substr($company['evolution_api_key'], -4)) ?></code></small>
      <?php endif; ?>
    </label>
    <label>Instance name
      <input type="text" name="evolution_instance" value="<?= e($company['evolution_instance'] ?? '') ?>" placeholder="aiserve-prod">
    </label>
    <?php
      $evoBase = $_SERVER['HTTPS'] ?? '';
      $evoBaseUrl = (APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')))
                  . '/webhook/evolution.php?token=' . urlencode((string)($company['webhook_verify_token'] ?? ''));
    ?>
    <div class="alert alert-info">
      <strong>Evolution webhook URL:</strong> <code><?= e($evoBaseUrl) ?></code><br>
      Set this in your Evolution instance webhook config. Pair the WhatsApp number at
      <a href="/admin/whatsapp_pair.php">Admin → Pair WhatsApp</a>.
      <br>Connection status:
      <strong class="<?= 'evo-status-' . e((string)($company['evolution_status'] ?? 'disconnected')) ?>">
        <?= e(ucfirst((string)($company['evolution_status'] ?? 'disconnected'))) ?>
      </strong>
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </form>
</div>

<style>
.provider-picker { display: grid; gap: 8px; margin-bottom: 12px; }
.provider-radio  { display: flex; gap: 10px; align-items: flex-start; padding: 10px;
                   border: 1px solid var(--c-border); border-radius: 8px; cursor: pointer; }
.provider-radio:has(input:checked) { border-color: var(--c-primary); background: #f6fff9; }
.provider-radio input { margin-top: 4px; }
.evo-status-connected    { color: #1f7a3f; }
.evo-status-connecting   { color: #b25c00; }
.evo-status-disconnected { color: #b3261e; }
</style>
<?php layout_end(); ?>
