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

    $alertEnabled      = !empty($_POST['alert_failed_sends_enabled']) ? 1 : 0;
    $alertThreshold    = max(1, (int)($_POST['alert_failed_sends_threshold'] ?? 5));
    $alertEmail        = trim((string)($_POST['alert_email'] ?? ''));

    $provider          = (string)($_POST['provider'] ?? 'cloud_api');
    if (!in_array($provider, ['cloud_api', 'evolution', 'aiserve_chatbot'], true)) $provider = 'cloud_api';
    $evoBaseUrl        = trim((string)($_POST['evolution_base_url'] ?? ''));
    $evoApiKey         = trim((string)($_POST['evolution_api_key']  ?? ''));
    $evoInstance       = trim((string)($_POST['evolution_instance'] ?? ''));
    $chatbotUrl        = trim((string)($_POST['chatbot_base_url']     ?? ''));
    $chatbotToken      = trim((string)($_POST['chatbot_bearer_token'] ?? ''));

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
        // Same for chatbot_bearer_token — keep existing if blank
        if ($chatbotToken === '') {
            $stmt = $db->prepare('SELECT chatbot_bearer_token FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $chatbotToken = (string)($stmt->fetchColumn() ?: '');
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
                provider = ?, evolution_base_url = ?, evolution_api_key = ?, evolution_instance = ?,
                chatbot_base_url = ?, chatbot_bearer_token = ?,
                alert_failed_sends_enabled = ?, alert_failed_sends_threshold = ?, alert_email = ?
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
            rtrim($chatbotUrl, '/') ?: null,
            $chatbotToken ?: null,
            $alertEnabled, $alertThreshold, $alertEmail ?: null,
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

$webhookUrl = webhook_url_for($company, '/webhook/whatsapp.php');

layout_start($current_user, 'Company & API Settings', 'settings', $company['brand_color'] ?? '#25D366');
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
      <label class="provider-radio">
        <input type="radio" name="provider" value="aiserve_chatbot" <?= $currentProvider === 'aiserve_chatbot' ? 'checked' : '' ?>>
        <span>
          <strong>AiServe Chatbot Gateway</strong> <small class="muted">— partner-hosted Bearer-token gateway (e.g. chatbot.aiserve.my). You only need a URL + token; the partner handles WhatsApp pairing.</small>
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
    <?php $evoBaseUrl = webhook_url_for($company, '/webhook/evolution.php'); ?>
    <div class="alert alert-info">
      <strong>Evolution webhook URL:</strong> <code><?= e($evoBaseUrl) ?></code><br>
      Set this in your Evolution instance webhook config. Pair the WhatsApp number at
      <a href="/admin/whatsapp_pair.php">Admin → Pair WhatsApp</a>.
      <br>Connection status:
      <strong class="<?= 'evo-status-' . e((string)($company['evolution_status'] ?? 'disconnected')) ?>">
        <?= e(ucfirst((string)($company['evolution_status'] ?? 'disconnected'))) ?>
      </strong>
    </div>

    <h2>AiServe Chatbot Gateway settings</h2>
    <p class="muted small">Used when provider is set to AiServe Chatbot Gateway. Ask your partner for the base URL and the Bearer token (from their merchant detail page).</p>
    <label>Gateway base URL
      <input type="url" name="chatbot_base_url" value="<?= e($company['chatbot_base_url'] ?? '') ?>" placeholder="https://chatbot.aiserve.my">
    </label>
    <label>Bearer token — leave blank to keep existing
      <input type="password" name="chatbot_bearer_token" value="" autocomplete="new-password" placeholder="token from merchant detail page">
      <?php if (!empty($company['chatbot_bearer_token'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($company['chatbot_bearer_token'], 0, 6)) ?>…<?= e(substr($company['chatbot_bearer_token'], -4)) ?></code></small>
      <?php endif; ?>
    </label>
    <?php $chatbotInboundUrl = webhook_url_for($company, '/webhook/evolution.php'); ?>
    <div class="alert alert-info">
      <strong>Inbound webhook URL (give this to your partner):</strong> <code><?= e($chatbotInboundUrl) ?></code><br>
      The gateway should POST incoming WhatsApp messages to this URL using the
      standard Evolution event shape (<code>messages.upsert</code>). If your
      partner's payload is different, paste a sample and we'll add an adapter.
    </div>

    <div class="card chatbot-test">
      <h3>Test connection</h3>
      <p class="muted small">Sends one real WhatsApp message via the gateway to verify base URL + Bearer token. Use your own number for the first try.</p>
      <div class="inline-form">
        <input type="text" id="chatbot-test-to" placeholder="60123456789 (digits with country code, no +)" style="flex:1; min-width: 220px;">
        <button type="button" class="btn" id="chatbot-test-btn">Send test message</button>
      </div>
      <p class="muted small" id="chatbot-test-status" style="margin-top:8px;"></p>
    </div>

    <script>
    (function () {
      const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      const btn  = document.getElementById('chatbot-test-btn');
      if (!btn) return;
      btn.addEventListener('click', async () => {
        const to = (document.getElementById('chatbot-test-to').value || '').trim();
        const out = document.getElementById('chatbot-test-status');
        if (!to) { out.textContent = 'Enter a recipient phone number first.'; out.style.color = '#b3261e'; return; }
        btn.disabled = true; out.textContent = 'Sending…'; out.style.color = '';
        try {
          const fd = new FormData();
          fd.append('to', to);
          fd.append('_csrf', csrf);
          const res  = await fetch('/api/test_chatbot.php', { method: 'POST', body: fd });
          const data = await res.json().catch(() => ({}));
          if (data.ok) {
            out.textContent = '✓ Sent. wa_message_id=' + (data.wa_message_id || '?') + ' — check WhatsApp.';
            out.style.color = '#1f7a3f';
          } else {
            out.textContent = '✗ ' + (data.error || ('HTTP ' + (data.http_code || res.status)));
            out.style.color = '#b3261e';
          }
        } catch (e) {
          out.textContent = '✗ Network error: ' + e.message;
          out.style.color = '#b3261e';
        } finally {
          btn.disabled = false;
        }
      });
    })();
    </script>

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
