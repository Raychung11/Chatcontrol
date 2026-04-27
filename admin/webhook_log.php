<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// ---- Auto-create webhook_events if it doesn't exist (first-deploy convenience) ----
$createNotice = '';
try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS `webhook_events` (
           `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `company_id` INT UNSIGNED NOT NULL,
           `method` VARCHAR(8) DEFAULT NULL,
           `http_status` INT DEFAULT NULL,
           `message_count` INT NOT NULL DEFAULT 0,
           `status_count` INT NOT NULL DEFAULT 0,
           `error_text` VARCHAR(500) DEFAULT NULL,
           `raw_body` MEDIUMTEXT,
           `ip_address` VARCHAR(64) DEFAULT NULL,
           `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           KEY `idx_webhook_events_company_time` (`company_id`,`created_at`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (Throwable $e) {
    $createNotice = $e->getMessage();
}

// ---- Counts ----
$counts = [
    'events_total'  => 0,
    'events_24h'    => 0,
    'events_with_messages_24h' => 0,
    'events_errors_24h' => 0,
    'messages_total'   => 0,
    'messages_24h'     => 0,
    'conversations'    => 0,
];
try {
    $r = $db->prepare(
        'SELECT
           COUNT(*) AS events_total,
           SUM(created_at > NOW() - INTERVAL 1 DAY) AS events_24h,
           SUM(message_count > 0 AND created_at > NOW() - INTERVAL 1 DAY) AS events_with_messages_24h,
           SUM(error_text IS NOT NULL AND created_at > NOW() - INTERVAL 1 DAY) AS events_errors_24h
         FROM webhook_events WHERE company_id = ?'
    );
    $r->execute([$companyId]);
    $counts = array_merge($counts, $r->fetch() ?: []);
} catch (Throwable $e) { /* table might not exist yet on first paint */ }

$mr = $db->prepare(
    'SELECT
       COUNT(*) AS messages_total,
       SUM(created_at > NOW() - INTERVAL 1 DAY) AS messages_24h
     FROM messages WHERE company_id = ?'
);
$mr->execute([$companyId]);
$mc = $mr->fetch() ?: [];
$counts['messages_total'] = (int)($mc['messages_total'] ?? 0);
$counts['messages_24h']   = (int)($mc['messages_24h']   ?? 0);

$cr = $db->prepare('SELECT COUNT(*) FROM conversations WHERE company_id = ?');
$cr->execute([$companyId]);
$counts['conversations'] = (int)$cr->fetchColumn();

// ---- Recent webhook events ----
$events = [];
try {
    $stmt = $db->prepare(
        'SELECT id, http_status, message_count, status_count, error_text, ip_address, created_at,
                LEFT(raw_body, 4000) AS body_preview, LENGTH(raw_body) AS body_len
         FROM webhook_events WHERE company_id = ?
         ORDER BY id DESC LIMIT 30'
    );
    $stmt->execute([$companyId]);
    $events = $stmt->fetchAll();
} catch (Throwable $e) { /* table missing */ }

// ---- Recent messages ----
$recentMessages = $db->prepare(
    'SELECT m.id, m.direction, m.message_type, m.message_text, m.status, m.error_message, m.created_at,
            ct.wa_id, ct.display_name, ct.profile_name, c.id AS conversation_id
     FROM messages m
     INNER JOIN contacts ct ON ct.id = m.contact_id
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE m.company_id = ?
     ORDER BY m.id DESC LIMIT 20'
);
$recentMessages->execute([$companyId]);
$recentMessages = $recentMessages->fetchAll();

// ---- Recent conversations ----
$recentConvs = $db->prepare(
    'SELECT c.id, c.status, c.last_message_text, c.last_message_at, c.unread_count,
            ct.wa_id, ct.display_name, ct.profile_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.company_id = ?
     ORDER BY c.id DESC LIMIT 10'
);
$recentConvs->execute([$companyId]);
$recentConvs = $recentConvs->fetchAll();

// ---- Pretty-print helper ----
function pretty_json(?string $body): string
{
    if (!$body) return '';
    $obj = json_decode($body, true);
    if ($obj === null) return $body;
    return (string)json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Detect common configuration gotchas
$company = (function () use ($db, $companyId) {
    $s = $db->prepare('SELECT * FROM companies WHERE id = ?');
    $s->execute([$companyId]);
    return $s->fetch() ?: [];
})();

$problems = [];
if (empty($company['webhook_verify_token'])) {
    $problems[] = 'Webhook verify token is empty in Settings. Meta verification will fail.';
}
if (empty($company['phone_number_id'])) {
    $problems[] = 'Phone Number ID is empty in Settings. You can RECEIVE messages but cannot SEND replies.';
}
if (empty($company['access_token'])) {
    $problems[] = 'Meta access token is empty. Replies will fail.';
}
if ((int)$counts['events_24h'] === 0 && (int)$counts['events_total'] === 0) {
    $problems[] = 'No webhook POSTs have been received yet. Check that you subscribed to the "messages" field in Meta App → WhatsApp → Configuration → Webhooks.';
}
if ((int)$counts['events_24h'] > 0 && (int)$counts['events_with_messages_24h'] === 0 && (int)$counts['events_errors_24h'] === 0) {
    $problems[] = 'Webhook is receiving POSTs but none contained customer messages yet. They may all be status updates. Send a real WhatsApp message TO your business number from another phone.';
}
if ((int)$counts['events_errors_24h'] > 0) {
    $problems[] = 'Some webhook POSTs returned errors below. Expand them to see what went wrong.';
}

layout_start($current_user, 'Webhook diagnostics', 'webhook_log');
?>

<?php if ($createNotice): ?>
  <div class="alert alert-error"><strong>webhook_events table:</strong> <?= e($createNotice) ?></div>
<?php endif; ?>

<div class="report-grid">
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['events_total'] ?></div><div>Total webhook POSTs</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['events_24h'] ?></div><div>Webhook POSTs (24h)</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['events_with_messages_24h'] ?></div><div>POSTs with messages (24h)</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['events_errors_24h'] ?></div><div>POSTs with errors (24h)</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['messages_total'] ?></div><div>Messages stored</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['messages_24h'] ?></div><div>Messages (24h)</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$counts['conversations'] ?></div><div>Conversations</div></div>
</div>

<?php if ($problems): ?>
<div class="card">
  <h2>Things to check</h2>
  <ul class="bullet">
    <?php foreach ($problems as $p): ?>
      <li><?= e($p) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card">
  <h2>Last 30 webhook POSTs</h2>
  <p class="muted small">Most recent first. Click a row to see the raw payload Meta sent.</p>
  <?php if (!$events): ?>
    <p class="muted">
      No webhook POSTs have hit <code>/webhook/whatsapp.php</code> yet.
      In Meta App Dashboard → WhatsApp → Configuration → Webhooks, make sure you clicked
      <strong>Subscribe</strong> next to <code>messages</code> (verification alone is not enough).
    </p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Time</th><th>Status</th><th>Messages</th><th>Statuses</th><th>Error</th><th>IP</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($events as $i => $ev): ?>
          <tr>
            <td><?= e(fmt_dt($ev['created_at'], 'Y-m-d H:i:s')) ?></td>
            <td>
              <?php
                $cls = ((int)$ev['http_status'] === 200) ? 'badge-open' : 'badge-failed';
              ?>
              <span class="badge <?= e($cls) ?>"><?= (int)$ev['http_status'] ?></span>
            </td>
            <td><?= (int)$ev['message_count'] ?></td>
            <td><?= (int)$ev['status_count'] ?></td>
            <td><?= e($ev['error_text'] ?? '—') ?></td>
            <td class="muted small"><?= e($ev['ip_address'] ?? '') ?></td>
            <td>
              <button class="btn btn-sm" type="button" onclick="document.getElementById('ev-<?= (int)$ev['id'] ?>').classList.toggle('hidden')">
                Toggle payload
              </button>
            </td>
          </tr>
          <tr id="ev-<?= (int)$ev['id'] ?>" class="hidden">
            <td colspan="7">
              <pre class="webhook-payload"><?= e(pretty_json($ev['body_preview'])) ?></pre>
              <?php if ((int)$ev['body_len'] > strlen((string)$ev['body_preview'])): ?>
                <p class="muted small">(payload truncated to first 4000 chars; total <?= (int)$ev['body_len'] ?>)</p>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="report-row">
  <div class="card">
    <h2>Last 20 messages</h2>
    <?php if (!$recentMessages): ?>
      <p class="muted">No messages stored yet.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Time</th><th>Dir</th><th>Type</th><th>From</th><th>Body</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($recentMessages as $m): ?>
            <tr>
              <td class="muted small"><?= e(fmt_dt($m['created_at'], 'Y-m-d H:i:s')) ?></td>
              <td><?= e($m['direction']) ?></td>
              <td><?= e($m['message_type']) ?></td>
              <td>
                <a href="/inbox/chat.php?id=<?= (int)$m['conversation_id'] ?>">
                  <?= e($m['display_name'] ?: $m['profile_name'] ?: ('+' . $m['wa_id'])) ?>
                </a>
              </td>
              <td><?= e(mb_strimwidth((string)$m['message_text'], 0, 60, '…')) ?></td>
              <td><?= status_badge($m['status']) ?>
                <?php if (!empty($m['error_message'])): ?>
                  <span class="muted small" title="<?= e($m['error_message']) ?>">·err</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Recent conversations</h2>
    <?php if (!$recentConvs): ?>
      <p class="muted">No conversations yet.</p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Customer</th><th>Status</th><th>Unread</th><th>Last message</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach ($recentConvs as $c): ?>
            <tr>
              <td><a href="/inbox/chat.php?id=<?= (int)$c['id'] ?>">
                <?= e($c['display_name'] ?: $c['profile_name'] ?: ('+' . $c['wa_id'])) ?>
              </a></td>
              <td><?= status_badge($c['status']) ?></td>
              <td><?= (int)$c['unread_count'] ?></td>
              <td><?= e(mb_strimwidth((string)$c['last_message_text'], 0, 50, '…')) ?></td>
              <td class="muted small"><?= e(fmt_dt($c['last_message_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<style>
.webhook-payload {
  background: #1f2933; color: #d9e0e8; padding: 12px; border-radius: 6px;
  font-size: 12px; overflow-x: auto; max-height: 360px; white-space: pre;
}
</style>
<?php layout_end(); ?>
