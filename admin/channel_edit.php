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

<?php if ($row): ?>

<!-- ============================================================
     TEST SEND panel - verifies OUTBOUND works for this channel
     ============================================================ -->
<div class="card">
  <h2>Test outbound — this channel</h2>
  <p class="muted small">
    Sends one real WhatsApp message via this channel's provider to verify
    base URL + Bearer token + connectivity. Enter your OWN number for the
    first try. This does NOT test inbound (webhook) — for that, ask the
    partner to send a message to this channel's WhatsApp number and check
    the "Recent inbound activity" panel below.
  </p>
  <div class="inline-form" style="display:flex; gap:8px; flex-wrap:wrap;">
    <input type="text" id="ch-test-to"
           placeholder="60123456789 (digits with country code, no +)"
           style="flex:1; min-width:220px;">
    <button type="button" class="btn" id="ch-test-btn">Send test message</button>
  </div>
  <p class="small" id="ch-test-status" style="margin-top:8px;"></p>
</div>

<!-- ============================================================
     RECENT INBOUND ACTIVITY - verifies webhook wiring
     ============================================================ -->
<?php
  $inboundEvents = $db->prepare(
    'SELECT id, http_status, message_count, status_count, error_text, ip_address, created_at
     FROM webhook_events
     WHERE company_id = ?
     ORDER BY id DESC
     LIMIT 15'
  );
  $inboundEvents->execute([$companyId]);
  $inboundEvents = $inboundEvents->fetchAll();

  // Recent messages for THIS channel (proves the webhook actually landed
  // rows against this specific channel_id, not a sibling channel).
  $chanMsgs = $db->prepare(
    'SELECT m.id, m.direction, m.message_type, m.status, m.created_at,
            SUBSTRING(m.message_text, 1, 60) AS preview,
            ct.wa_id, ct.display_name
     FROM messages m
     LEFT JOIN contacts ct ON ct.id = m.contact_id
     WHERE m.company_id = ? AND m.channel_id = ?
     ORDER BY m.id DESC
     LIMIT 10'
  );
  $chanMsgs->execute([$companyId, (int)$row['id']]);
  $chanMsgs = $chanMsgs->fetchAll();
?>
<div class="card">
  <h2>Recent inbound activity — this channel</h2>

  <h3 style="font-size:14px; margin-top:12px;">Last 10 messages tied to this channel</h3>
  <?php if (!$chanMsgs): ?>
    <p class="muted small">
      This channel has <strong>0 messages</strong> in the database yet.
      Ask the partner to send a WhatsApp message to
      <?= $row['display_phone'] ? '<strong>' . e($row['display_phone']) . '</strong>' : 'this channel\'s number' ?>
      then reload this page. If nothing appears after that, check:
      <ol style="margin:6px 0 0 20px;">
        <li>Did you paste the correct webhook URL into your partner's dashboard? It's shown above under "Webhook URLs for this channel".</li>
        <li>Is the partner actually sending events (check with them)?</li>
        <li>Look at the "Recent webhook events" table below — is anything landing on the server?</li>
      </ol>
    </p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>When</th><th>Dir</th><th>From/To</th><th>Preview</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($chanMsgs as $m): ?>
          <tr>
            <td><?= e(fmt_dt($m['created_at'])) ?></td>
            <td><?= $m['direction'] === 'incoming' ? '⬇︎ in' : '⬆︎ out' ?></td>
            <td><?= e($m['display_name'] ?: $m['wa_id'] ?: '—') ?></td>
            <td class="muted small"><?= e($m['preview'] ?? '') ?></td>
            <td><?= status_badge($m['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3 style="font-size:14px; margin-top:20px;">Last 15 webhook events for this workspace</h3>
  <p class="muted small">Every POST from your provider lands here, regardless of channel. If this is empty and the partner claims they are sending, the URL in their dashboard is wrong.</p>
  <?php if (!$inboundEvents): ?>
    <p class="muted small"><em>No webhook events recorded yet.</em></p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>When</th><th>Status</th><th>Msgs</th><th>Statuses</th><th>Error</th><th>From IP</th></tr></thead>
      <tbody>
        <?php foreach ($inboundEvents as $ev): ?>
          <tr>
            <td><?= e(fmt_dt($ev['created_at'])) ?></td>
            <td><?= (int)$ev['http_status'] === 200
                    ? '<span class="badge badge-open">200</span>'
                    : '<span class="badge badge-failed">' . (int)$ev['http_status'] . '</span>' ?></td>
            <td><?= (int)$ev['message_count'] ?></td>
            <td><?= (int)$ev['status_count'] ?></td>
            <td class="muted small"><?= e($ev['error_text'] ?? '') ?></td>
            <td class="muted small"><code><?= e($ev['ip_address'] ?? '') ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const btn  = document.getElementById('ch-test-btn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const to = (document.getElementById('ch-test-to').value || '').trim();
    const out = document.getElementById('ch-test-status');
    if (!to) { out.textContent = 'Enter a recipient phone first.'; out.style.color = '#b3261e'; return; }
    btn.disabled = true; out.textContent = 'Sending…'; out.style.color = '';
    try {
      const fd = new FormData();
      fd.append('to', to);
      fd.append('channel_id', '<?= (int)$row['id'] ?>');
      fd.append('_csrf', csrf);
      const res  = await fetch('/api/test_chatbot.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (data.ok) {
        out.textContent = '✓ Sent via this channel. wa_message_id=' + (data.wa_message_id || '?') + ' — check WhatsApp on ' + to + '.';
        out.style.color = '#1f7a3f';
      } else {
        out.textContent = '✗ ' + (data.error || ('HTTP ' + (data.http_code || res.status)));
        out.style.color = '#b3261e';
      }
    } catch (e) {
      out.textContent = '✗ Network error: ' + e.message;
      out.style.color = '#b3261e';
    } finally { btn.disabled = false; }
  });
})();
</script>

<?php endif; ?>
<?php layout_end(); ?>
