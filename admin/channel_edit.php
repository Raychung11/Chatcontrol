<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    // Workspace owners can see the channel list but not the connection details.
    redirect('/admin/channels.php');
}
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$id  = (int)($_GET['id'] ?? 0);
$row = null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM channels WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Channel not found.'); }
}

$err = '';

if (is_post()) {
    csrf_check();
    $name         = trim((string)($_POST['name']           ?? ''));
    $displayPhone = trim((string)($_POST['display_phone']  ?? ''));
    $provider     = (string)($_POST['provider']            ?? 'cloud_api');
    if (!in_array($provider, ['cloud_api','evolution','aiserve_chatbot'], true)) $provider = 'cloud_api';

    // Cloud API
    $phoneNumberId     = trim((string)($_POST['phone_number_id']     ?? ''));
    $businessAccountId = trim((string)($_POST['business_account_id'] ?? ''));
    $apiVersion        = trim((string)($_POST['api_version']         ?? 'v21.0')) ?: 'v21.0';
    $accessToken       = trim((string)($_POST['access_token']        ?? ''));

    // Evolution
    $evoBase     = trim((string)($_POST['evolution_base_url'] ?? ''));
    $evoApiKey   = trim((string)($_POST['evolution_api_key']  ?? ''));
    $evoInstance = trim((string)($_POST['evolution_instance'] ?? ''));

    // Chatbot
    $chatbotUrl   = rtrim(trim((string)($_POST['chatbot_base_url']     ?? '')), '/');
    $chatbotToken = trim((string)($_POST['chatbot_bearer_token']  ?? ''));

    // Keep existing secrets if blank
    if ($row) {
        if ($accessToken  === '') $accessToken  = (string)($row['access_token']        ?? '');
        if ($evoApiKey    === '') $evoApiKey    = (string)($row['evolution_api_key']   ?? '');
        if ($chatbotToken === '') $chatbotToken = (string)($row['chatbot_bearer_token'] ?? '');
    }

    if ($name === '') {
        $err = 'Channel name is required.';
    } else {
        if ($row) {
            $upd = $db->prepare(
                'UPDATE channels SET
                    name = ?, display_phone = ?, provider = ?,
                    phone_number_id = ?, business_account_id = ?, api_version = ?, access_token = ?,
                    evolution_base_url = ?, evolution_api_key = ?, evolution_instance = ?,
                    chatbot_base_url = ?, chatbot_bearer_token = ?
                 WHERE id = ? AND company_id = ?'
            );
            $upd->execute([
                $name, $displayPhone ?: null, $provider,
                $phoneNumberId ?: null, $businessAccountId ?: null, $apiVersion, $accessToken ?: null,
                $evoBase ?: null, $evoApiKey ?: null, $evoInstance ?: null,
                $chatbotUrl ?: null, $chatbotToken ?: null,
                $id, $companyId,
            ]);
            log_activity($companyId, (int)$current_user['id'], 'channel_updated', 'channel', $id);
        } else {
            $token = channel_generate_webhook_token();
            $ins = $db->prepare(
                'INSERT INTO channels
                    (company_id, name, display_phone, provider, webhook_token,
                     phone_number_id, business_account_id, api_version, access_token,
                     evolution_base_url, evolution_api_key, evolution_instance,
                     chatbot_base_url, chatbot_bearer_token,
                     is_default, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
            );
            // If this is the first channel, mark it default automatically.
            $countStmt = $db->prepare('SELECT COUNT(*) FROM channels WHERE company_id = ?');
            $countStmt->execute([$companyId]);
            $isDefault = (int)$countStmt->fetchColumn() === 0 ? 1 : 0;
            $ins->execute([
                $companyId, $name, $displayPhone ?: null, $provider, $token,
                $phoneNumberId ?: null, $businessAccountId ?: null, $apiVersion, $accessToken ?: null,
                $evoBase ?: null, $evoApiKey ?: null, $evoInstance ?: null,
                $chatbotUrl ?: null, $chatbotToken ?: null,
                $isDefault,
            ]);
            $newId = (int)$db->lastInsertId();
            log_activity($companyId, (int)$current_user['id'], 'channel_created', 'channel', $newId, $name);
            redirect('/admin/channel_edit.php?id=' . $newId);
        }
        // Re-read after save
        $stmt = $db->prepare('SELECT * FROM channels WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }
}

$webhookUrl = $row ? channel_webhook_url($row, '/webhook/evolution.php') : null;
$cloudHook  = $row ? channel_webhook_url($row, '/webhook/whatsapp.php')  : null;

layout_start($current_user, $row ? ('Channel · ' . $row['name']) : 'New channel', 'channels');
?>
<div class="card">
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <h2>Channel</h2>
    <label>Name <small class="muted">(internal label — e.g. "Sales", "Support", "MY")</small>
      <input type="text" name="name" required maxlength="100" value="<?= e($row['name'] ?? ($_POST['name'] ?? '')) ?>">
    </label>
    <label>Display phone <small class="muted">(for the inbox header — e.g. +60 12 345 6789)</small>
      <input type="text" name="display_phone" value="<?= e($row['display_phone'] ?? ($_POST['display_phone'] ?? '')) ?>">
    </label>

    <h2>Messaging provider</h2>
    <?php $cur = $row['provider'] ?? 'cloud_api'; ?>
    <div class="provider-picker">
      <?php foreach ([
        'cloud_api'      => ['Meta Cloud API', 'official, paid per conversation, supports templates'],
        'evolution'      => ['Evolution API', 'self-hosted Baileys / WhatsApp Web. Free messaging, ban risk'],
        'aiserve_chatbot'=> ['AiServe Chatbot Gateway', 'partner-hosted Bearer-token gateway'],
      ] as $k => [$label, $desc]): ?>
        <label class="provider-radio">
          <input type="radio" name="provider" value="<?= e($k) ?>" <?= $cur === $k ? 'checked' : '' ?>>
          <span><strong><?= e($label) ?></strong> <small class="muted">— <?= e($desc) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>

    <h3>Cloud API fields</h3>
    <label>Phone Number ID
      <input type="text" name="phone_number_id" value="<?= e($row['phone_number_id'] ?? '') ?>">
    </label>
    <label>Business Account ID
      <input type="text" name="business_account_id" value="<?= e($row['business_account_id'] ?? '') ?>">
    </label>
    <label>Graph API version
      <input type="text" name="api_version" value="<?= e($row['api_version'] ?? 'v21.0') ?>">
    </label>
    <label>Access token <small class="muted">(leave blank to keep existing)</small>
      <input type="password" name="access_token" autocomplete="new-password" placeholder="Bearer token">
      <?php if (!empty($row['access_token'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($row['access_token'], 0, 6)) ?>…<?= e(substr($row['access_token'], -4)) ?></code></small>
      <?php endif; ?>
    </label>

    <h3>Evolution API fields</h3>
    <label>Evolution base URL
      <input type="url" name="evolution_base_url" value="<?= e($row['evolution_base_url'] ?? '') ?>" placeholder="https://evo.your-server.com">
    </label>
    <label>Evolution API key <small class="muted">(leave blank to keep existing)</small>
      <input type="password" name="evolution_api_key" autocomplete="new-password">
      <?php if (!empty($row['evolution_api_key'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($row['evolution_api_key'], 0, 6)) ?>…</code></small>
      <?php endif; ?>
    </label>
    <label>Instance name
      <input type="text" name="evolution_instance" value="<?= e($row['evolution_instance'] ?? '') ?>">
    </label>

    <h3>AiServe Chatbot Gateway fields</h3>
    <label>Gateway base URL
      <input type="url" name="chatbot_base_url" value="<?= e($row['chatbot_base_url'] ?? '') ?>" placeholder="https://chatbot.aiserve.my">
    </label>
    <label>Bearer token <small class="muted">(leave blank to keep existing)</small>
      <input type="password" name="chatbot_bearer_token" autocomplete="new-password">
      <?php if (!empty($row['chatbot_bearer_token'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($row['chatbot_bearer_token'], 0, 6)) ?>…<?= e(substr($row['chatbot_bearer_token'], -4)) ?></code></small>
      <?php endif; ?>
    </label>

    <?php if ($row): ?>
    <div class="alert alert-info">
      <strong>Webhook URLs for this channel:</strong><br>
      <strong>Cloud API (Meta):</strong> <code><?= e($cloudHook) ?></code><br>
      <strong>Evolution / AiServe Chatbot Gateway:</strong>
        <code><?= e($webhookUrl) ?></code><br>
      <small class="muted">
        Paste ONE URL into your partner dashboard — do <strong>not</strong>
        append <code>?type=incoming</code> or <code>?type=outgoing</code>.
        Our webhook auto-detects whether the payload is a customer
        message or an AI-reply echo from the JSON shape itself. Older
        <code>?type=</code> URLs still work for back-compat.
      </small>
    </div>
    <?php endif; ?>

    <div>
      <button class="btn btn-primary" type="submit"><?= $row ? 'Save channel' : 'Create channel' ?></button>
      <a class="btn" href="/admin/channels.php">Cancel</a>
    </div>
  </form>
</div>
<?php layout_end(); ?>
