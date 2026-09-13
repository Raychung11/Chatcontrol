<?php
/**
 * Channels Health — per-channel diagnostic page for the platform admin.
 *
 * Table of every channel with:
 *   - Health dot (🟢 <24h · 🟡 <7d · 🔴 dark · ⚫ disabled · · never)
 *   - Provider chip, last incoming timestamp, 24h count, conv count
 *   - Correct webhook URL to paste into the provider config, with copy
 *   - "🧪 Test" button — live-curls the webhook endpoint from the VPS
 *     to prove it accepts POSTs and resolves the channel token
 *
 * The test endpoint fires a synthetic Evolution/Meta POST to the local
 * webhook via HTTP loopback. It intentionally uses a shape that any
 * handler will reject cleanly (empty payload / verification only) so
 * we don't inject phantom messages into the inbox.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_login();
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}

$db = aiserve_db();

// -------------------- Test-endpoint action --------------------
$testResult = null;
if (is_post() && ($_POST['action'] ?? '') === 'test') {
    csrf_check();
    $cid = (int)($_POST['channel_id'] ?? 0);
    $s = $db->prepare('SELECT * FROM channels WHERE id = ? LIMIT 1');
    $s->execute([$cid]);
    $ch = $s->fetch();
    if ($ch) {
        $provider = (string)$ch['provider'];
        // Per-provider probe: match how the real webhook receives traffic.
        //  - evolution / aiserve_chatbot: POST empty JSON. The handler
        //    parses it, finds no messages/statuses, returns 200 with
        //    zero counts. Auth passes because ?ch=<token> is Case 1
        //    (cryptographic token) — no side effects.
        //  - cloud_api (Meta): GET with hub_challenge=… the standard
        //    Meta verification handshake — returns 200 echoing the
        //    challenge if the workspace's verify_token matches.
        //  - web_chat is a widget, not a webhook — skipped upstream by
        //    the UI, so we don't reach here in practice.
        $endpoint = match ($provider) {
            'evolution', 'aiserve_chatbot' => '/webhook/evolution.php',
            'web_chat'                     => '/chat.php',
            default                        => '/webhook/whatsapp.php',
        };

        $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
            ? rtrim((string)APP_BASE_URL, '/')
            : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $url  = $base . $endpoint . '?ch=' . urlencode((string)$ch['webhook_token']);

        // Fire the right probe for this provider via cURL — supports
        // both POST bodies and GET, plus proper HTTPS on localhost.
        $curl = curl_init();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $probeMode = 'GET';
        if (in_array($provider, ['evolution', 'aiserve_chatbot'], true)) {
            // Empty-JSON POST — safe no-op.
            $opts[CURLOPT_URL]        = $url;
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = '{}';
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $probeMode = 'POST';
        } else {
            // Meta verification GET.
            $opts[CURLOPT_URL] = $url . '&hub_mode=subscribe&hub_verify_token=aiserve-health&hub_challenge=hc';
        }
        curl_setopt_array($curl, $opts);
        $t0     = microtime(true);
        $body   = curl_exec($curl);
        $dur    = (int)((microtime(true) - $t0) * 1000);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlErr= curl_error($curl);
        curl_close($curl);

        $testResult = [
            'channel_id' => $cid,
            'url'        => $url,
            'mode'       => $probeMode,
            'status'     => $status,
            'body'       => mb_substr((string)($body ?: ''), 0, 300),
            'duration'   => $dur,
            'error'      => $body === false ? ($curlErr ?: 'request failed') : null,
        ];
    }
}

// -------------------- Fetch channels + stats --------------------
$rows = $db->query(
    "SELECT
        c.*,
        co.name AS company_name, co.slug AS company_slug,
        (SELECT MAX(m.created_at)
          FROM messages m
          WHERE m.channel_id = c.id AND m.direction = 'incoming') AS last_incoming,
        (SELECT COUNT(*) FROM messages m
          WHERE m.channel_id = c.id AND m.direction = 'incoming'
            AND m.created_at > NOW() - INTERVAL 24 HOUR)          AS msgs_24h,
        (SELECT COUNT(*) FROM messages m
          WHERE m.channel_id = c.id AND m.direction = 'incoming'
            AND m.created_at > NOW() - INTERVAL 7 DAY)            AS msgs_7d,
        (SELECT COUNT(*) FROM conversations
          WHERE channel_id = c.id)                                AS convs
     FROM channels c
     INNER JOIN companies co ON co.id = c.company_id AND co.status = 'active'
     ORDER BY last_incoming DESC, c.id DESC"
)->fetchAll();

// Enrich each row with a health status.
$now = time();
foreach ($rows as &$r) {
    if ($r['status'] !== 'active') { $r['health'] = 'disabled'; continue; }
    if (!$r['last_incoming'])       { $r['health'] = 'never';    continue; }
    $lastMs = strtotime((string)$r['last_incoming']);
    if     ($lastMs > $now -   86400) $r['health'] = 'green';
    elseif ($lastMs > $now - 7*86400) $r['health'] = 'amber';
    else                              $r['health'] = 'red';
}
unset($r);

// Filter
$fHealth = (string)($_GET['health'] ?? 'all');
$fProv   = (string)($_GET['provider'] ?? 'all');
if (in_array($fHealth, ['green','amber','red','disabled','never'], true)) {
    $rows = array_values(array_filter($rows, fn($x) => $x['health'] === $fHealth));
}
if ($fProv !== 'all') {
    $rows = array_values(array_filter($rows, fn($x) => (string)$x['provider'] === $fProv));
}

// KPI totals across the UNFILTERED set (we re-fetch counts).
$k = ['total' => 0, 'green' => 0, 'amber' => 0, 'red' => 0, 'disabled' => 0, 'never' => 0];
$allRows = $db->query(
    "SELECT c.status,
            (SELECT MAX(m.created_at) FROM messages m
              WHERE m.channel_id = c.id AND m.direction='incoming') AS last_incoming
     FROM channels c
     INNER JOIN companies co ON co.id=c.company_id AND co.status='active'"
)->fetchAll();
foreach ($allRows as $r) {
    $k['total']++;
    if ($r['status'] !== 'active') { $k['disabled']++; continue; }
    if (!$r['last_incoming'])       { $k['never']++;    continue; }
    $ms = strtotime((string)$r['last_incoming']);
    if     ($ms > $now -   86400) $k['green']++;
    elseif ($ms > $now - 7*86400) $k['amber']++;
    else                          $k['red']++;
}

// Distinct providers for filter dropdown
$providers = [];
foreach ($allRows as $_) {}   // (kept for parity; real providers below)
$providers = array_values(array_unique(array_map(fn($x) => (string)$x['provider'], $rows)));

// Base URL for building webhook URLs.
$base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));

layout_start($current_user, 'Channels · Health', 'channels_debug');
?>
<style>
.ch-kpi-row { display:grid; gap:10px; margin-bottom:14px; grid-template-columns:repeat(5,1fr); }
@media (max-width: 900px) { .ch-kpi-row { grid-template-columns: repeat(2, 1fr); } }
.ch-kpi { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px 14px; text-align:center; }
.ch-kpi .val { font-size:22px; font-weight:700; }
.ch-kpi .lbl { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.ch-kpi.green .val    { color:#16A34A; }
.ch-kpi.amber .val    { color:#F59E0B; }
.ch-kpi.red .val      { color:#DC2626; }
.ch-kpi.disabled .val { color:#94A3B8; }
.ch-kpi.never .val    { color:#0072B2; }

.ch-toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:12px; }
.ch-toolbar select, .ch-toolbar a.btn { font-size:12.5px; }

.ch-health {
  display:inline-block; width:10px; height:10px; border-radius:50%;
  vertical-align:middle; margin-right:6px;
}
.ch-green    { background:#16A34A; }
.ch-amber    { background:#F59E0B; }
.ch-red      { background:#DC2626; }
.ch-disabled { background:#94A3B8; }
.ch-never    { background:#0072B2; }

.ch-provider-chip {
  display:inline-block; padding:2px 8px; border-radius:999px;
  font-size:11px; font-weight:600;
  background:#eef2ff; color:#3730a3;
}
.ch-url {
  background:#f6f9fb; border:1px solid #e3e8ee; border-radius:5px;
  padding:4px 8px; font-family:monospace; font-size:11.5px;
  overflow-x:auto; white-space:nowrap; max-width:340px;
  display:inline-block; vertical-align:middle;
}
.ch-copy {
  display:inline-block; margin-left:4px;
  border:1px solid #d0d7de; background:#fff; padding:2px 6px;
  border-radius:4px; cursor:pointer; font-size:11px;
}
.ch-copy:hover { border-color:#0072B2; color:#0072B2; }

.ch-test-result {
  margin-top:12px; padding:12px 14px; border-radius:8px;
  font-size:12.5px; background:#f6f9fb; border:1px solid #e3e8ee;
}
.ch-test-result.ok    { background:#f0fdf4; border-color:#bbf7d0; }
.ch-test-result.warn  { background:#fefce8; border-color:#fef08a; }
.ch-test-result.fail  { background:#fef2f2; border-color:#fecaca; }
.ch-test-result pre {
  background:#fff; border:1px solid #e3e8ee; border-radius:4px;
  padding:6px 8px; margin-top:6px; overflow-x:auto; font-size:11.5px;
}
</style>

<div class="card">
  <h2>Channels · Health</h2>
  <p class="muted small">
    Every channel across every active workspace. A channel goes <strong>🔴 dark</strong> when it hasn't
    received a customer message in 7+ days despite being active — usually a provider-side webhook config
    problem (URL pointing at the wrong host, or IP-hardcoded to an old server). Click <em>Test</em> on any
    row to live-fire the local webhook endpoint from the VPS and see what response comes back.
  </p>

  <div class="ch-kpi-row">
    <div class="ch-kpi"><div class="lbl">Total</div><div class="val"><?= (int)$k['total'] ?></div></div>
    <div class="ch-kpi green"><div class="lbl">🟢 Healthy (24h)</div><div class="val"><?= (int)$k['green'] ?></div></div>
    <div class="ch-kpi amber"><div class="lbl">🟡 Quiet (7d)</div><div class="val"><?= (int)$k['amber'] ?></div></div>
    <div class="ch-kpi red"><div class="lbl">🔴 Dark (7d+)</div><div class="val"><?= (int)$k['red'] ?></div></div>
    <div class="ch-kpi never"><div class="lbl">· Never used</div><div class="val"><?= (int)$k['never'] ?></div></div>
  </div>

  <form class="ch-toolbar" method="get">
    <select name="health">
      <option value="all"      <?= $fHealth === 'all'      ? 'selected' : '' ?>>All health</option>
      <option value="green"    <?= $fHealth === 'green'    ? 'selected' : '' ?>>🟢 Healthy (24h)</option>
      <option value="amber"    <?= $fHealth === 'amber'    ? 'selected' : '' ?>>🟡 Quiet (7d)</option>
      <option value="red"      <?= $fHealth === 'red'      ? 'selected' : '' ?>>🔴 Dark (7d+)</option>
      <option value="disabled" <?= $fHealth === 'disabled' ? 'selected' : '' ?>>⚫ Disabled</option>
      <option value="never"    <?= $fHealth === 'never'    ? 'selected' : '' ?>>· Never used</option>
    </select>
    <select name="provider">
      <option value="all">All providers</option>
      <?php foreach ($providers as $p): ?>
        <option value="<?= e($p) ?>" <?= $fProv === $p ? 'selected' : '' ?>><?= e($p) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Filter</button>
    <?php if ($fHealth !== 'all' || $fProv !== 'all'): ?>
      <a class="btn btn-sm" href="/admin/channels_debug.php">✕ clear</a>
    <?php endif; ?>
    <span class="muted small" style="margin-left:auto;"><?= count($rows) ?> shown</span>
  </form>

  <?php if ($testResult): ?>
    <?php
      $isOk  = $testResult['status'] >= 200 && $testResult['status'] < 400;
      $isErr = $testResult['error'] || $testResult['status'] === 0 || $testResult['status'] >= 500;
      $cls   = $isErr ? 'fail' : ($isOk ? 'ok' : 'warn');
    ?>
    <div class="ch-test-result <?= $cls ?>">
      <strong>🧪 Test result</strong> — <?= e((string)($testResult['mode'] ?? 'GET')) ?>
      · HTTP <?= (int)$testResult['status'] ?> · <?= (int)$testResult['duration'] ?> ms
      <div class="muted small" style="margin-top:4px;">URL: <code><?= e($testResult['url']) ?></code></div>
      <?php if ($testResult['error']): ?>
        <div style="color:#DC2626; margin-top:6px;"><strong>Error:</strong> <?= e($testResult['error']) ?></div>
      <?php endif; ?>
      <?php if ($testResult['body'] !== ''): ?>
        <pre><?= e($testResult['body']) ?></pre>
      <?php endif; ?>
      <div class="muted small" style="margin-top:6px;">
        <?php if ($cls === 'ok'): ?>
          ✅ Endpoint reachable and channel token resolved. If real webhooks still aren't arriving, the problem is on the provider side (URL wrong, IP hardcoded, or the provider itself is down).
        <?php elseif ($cls === 'warn'): ?>
          ⚠ Endpoint reachable but the handler responded with a non-2xx. Check the URL vs the provider's registered webhook.
        <?php else: ?>
          ❌ Could not reach the endpoint from the VPS. Check nginx + PHP-FPM + DNS + SSL first.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="padding:0;">
  <table class="data-table" style="margin:0;">
    <thead>
      <tr>
        <th>Channel</th>
        <th>Workspace</th>
        <th>Provider</th>
        <th>Last incoming</th>
        <th style="text-align:right;">24h</th>
        <th style="text-align:right;">Convs</th>
        <th>Webhook URL (paste into provider)</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted" style="text-align:center; padding:24px;">
          No channels match this filter.
        </td></tr>
      <?php endif; ?>

      <?php foreach ($rows as $r):
        // Correct webhook URL per provider — matches how each handler
        // resolves ?ch=<token> in webhook/*.php.
        $endpoint = match ((string)$r['provider']) {
            'evolution', 'aiserve_chatbot' => '/webhook/evolution.php',
            'web_chat'                     => '/chat.php',           // widgets — not a webhook
            default                        => '/webhook/whatsapp.php',
        };
        $webhookUrl = $r['provider'] === 'web_chat'
            ? $base . '/chat.php?c=' . urlencode((string)$r['webhook_token'])
            : $base . $endpoint . '?ch=' . urlencode((string)$r['webhook_token']);
      ?>
        <tr>
          <td>
            <span class="ch-health ch-<?= e($r['health']) ?>" title="<?= e($r['health']) ?>"></span>
            <strong><?= e((string)$r['name']) ?></strong>
            <?php if ($r['status'] !== 'active'): ?>
              <span class="muted small">· <?= e((string)$r['status']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?= e((string)$r['company_name']) ?>
            <div class="muted small"><code><?= e((string)$r['company_slug']) ?></code></div>
          </td>
          <td><span class="ch-provider-chip"><?= e((string)$r['provider']) ?></span></td>
          <td class="muted small">
            <?= $r['last_incoming'] ? e(fmt_dt($r['last_incoming'])) : '—' ?>
          </td>
          <td style="text-align:right;"><?= number_format((int)$r['msgs_24h']) ?></td>
          <td style="text-align:right;"><?= number_format((int)$r['convs']) ?></td>
          <td>
            <?php if ($r['provider'] === 'web_chat'): ?>
              <span class="muted small">(no webhook — widget URL is <code><?= e($webhookUrl) ?></code>)</span>
            <?php else: ?>
              <span class="ch-url" id="url-<?= (int)$r['id'] ?>"><?= e($webhookUrl) ?></span>
              <button type="button" class="ch-copy" onclick="chCopy('url-<?= (int)$r['id'] ?>', this)">Copy</button>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['provider'] !== 'web_chat'): ?>
              <form method="post" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test">
                <input type="hidden" name="channel_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-sm">🧪 Test</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function chCopy(elId, btn) {
  const el = document.getElementById(elId);
  const text = el.textContent.trim();
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.textContent;
    btn.textContent = 'Copied ✓';
    setTimeout(() => { btn.textContent = orig; }, 1500);
  });
}
</script>

<?php layout_end(); ?>
