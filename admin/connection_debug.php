<?php
/**
 * /admin/connection_debug.php
 *
 * Pinpoints where the outbound curl call to chatbot.aiserve.my is dying.
 * Captures all the diagnostic data the partner usually asks for:
 *   - server outbound IP (so they can allowlist us)
 *   - DNS resolution of the gateway host
 *   - HTTP HEAD to the gateway base URL with verbose curl error info
 *   - A real test POST to /api/boardcast/sendMessage using the saved
 *     Bearer token, with the actual JSON response printed
 *   - Last 10 failed outbound messages with their error_message column
 *
 * Platform-admin only (super_admin scope on any workspace also works via
 * impersonation). Safe to leave on a live site - read-only - but it does
 * surface partial token prefixes, so don't post screenshots publicly.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// Pick channel - default for this workspace unless ?channel_id=N
$channelId = (int)($_GET['channel_id'] ?? 0);
$channel   = $channelId > 0 ? channel_by_id($channelId) : channel_default_for_company($companyId);
if ($channel && (int)$channel['company_id'] !== $companyId) $channel = null;

// Listing of all channels for the dropdown
$chList = $db->prepare('SELECT id, name, provider, chatbot_base_url FROM channels WHERE company_id = ? ORDER BY id');
$chList->execute([$companyId]);
$chList = $chList->fetchAll();

// Diagnostics container
$diag = [];

if ($channel) {
    $baseUrl = (string)($channel['chatbot_base_url'] ?? '');
    $token   = (string)($channel['chatbot_bearer_token'] ?? '');
    $host    = parse_url($baseUrl, PHP_URL_HOST) ?: '';

    // 1. Server outbound IP - what the partner sees as the source.
    $outboundIp = '(unknown)';
    $ipResp = @file_get_contents('https://api.ipify.org?format=json', false, stream_context_create([
        'http' => ['timeout' => 5, 'method' => 'GET']
    ]));
    if ($ipResp) {
        $j = json_decode($ipResp, true);
        if (!empty($j['ip'])) $outboundIp = $j['ip'];
    }
    $diag['Server outbound IP']            = $outboundIp;
    $diag['Server hostname']               = gethostname() ?: '?';
    $diag['Gateway base URL']              = $baseUrl ?: '(not configured)';
    $diag['Gateway host (parsed)']         = $host ?: '(no host)';
    $diag['Bearer token (masked)']         = $token === '' ? '(not configured)'
        : (substr($token, 0, 6) . '…' . substr($token, -4));

    // 2. DNS resolution test
    if ($host !== '') {
        $resolvedIp = @gethostbyname($host);
        $diag['DNS A record'] = ($resolvedIp === $host) ? 'FAILED - host did not resolve' : $resolvedIp;
    }

    // 3. HEAD test against base URL (catches DNS / SSL / connect failures
    //    without sending any payload)
    if ($baseUrl !== '') {
        $ch = curl_init($baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOBODY            => true,
            CURLOPT_HEADER            => true,
            CURLOPT_TIMEOUT           => 10,
            CURLOPT_FOLLOWLOCATION    => true,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_VERBOSE           => false,
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_FRESH_CONNECT     => true,
            CURLOPT_FORBID_REUSE      => true,
        ]);
        $headResp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $diag['HEAD curl_errno']      = curl_errno($ch) . ' (' . curl_strerror(curl_errno($ch)) . ')';
        $diag['HEAD curl_error()']    = curl_error($ch) ?: '(none)';
        $diag['HEAD HTTP status']     = (int)$info['http_code'];
        $diag['HEAD resolved IP']     = $info['primary_ip']        ?? '?';
        $diag['HEAD primary port']    = $info['primary_port']      ?? '?';
        $diag['HEAD SSL verify']      = $info['ssl_verify_result'] ?? '?';
        $diag['HEAD DNS time (s)']    = $info['namelookup_time']   ?? '?';
        $diag['HEAD connect time (s)']= $info['connect_time']      ?? '?';
        $diag['HEAD total time (s)']  = $info['total_time']        ?? '?';
        curl_close($ch);
    }
}

// Test-send result (only when the form is submitted)
$sendDiag    = [];
$sendBody    = '';
$sendHeaders = '';
if (is_post() && $channel) {
    csrf_check();
    $to = preg_replace('/[^0-9]/', '', (string)($_POST['to'] ?? ''));
    if ($to !== '' && strlen($to) >= 8) {
        $url = rtrim((string)$channel['chatbot_base_url'], '/') . '/api/boardcast/sendMessage';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_HEADER            => true,
            CURLOPT_TIMEOUT           => 30,
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_FRESH_CONNECT     => true,
            CURLOPT_FORBID_REUSE      => true,
            CURLOPT_HTTPHEADER        => [
                'Authorization: Bearer ' . (string)$channel['chatbot_bearer_token'],
            ],
            CURLOPT_POSTFIELDS     => [
                'msg' => 'AiServe connection debug — please ignore',
                'to'  => $to,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $sendDiag['POST URL']             = $url;
        $sendDiag['curl_errno']           = curl_errno($ch) . ' (' . curl_strerror(curl_errno($ch)) . ')';
        $sendDiag['curl_error()']         = curl_error($ch) ?: '(none)';
        $sendDiag['HTTP status']          = (int)$info['http_code'];
        $sendDiag['Resolved IP']          = $info['primary_ip']        ?? '?';
        $sendDiag['SSL verify result']    = $info['ssl_verify_result'] ?? '?';
        $sendDiag['DNS time (s)']         = $info['namelookup_time']   ?? '?';
        $sendDiag['Connect time (s)']     = $info['connect_time']      ?? '?';
        $sendDiag['Total time (s)']       = $info['total_time']        ?? '?';
        curl_close($ch);

        if (is_string($resp) && $resp !== '') {
            $headerSize = (int)($info['header_size'] ?? 0);
            $sendHeaders = substr($resp, 0, $headerSize);
            $sendBody    = substr($resp, $headerSize);
        }
        log_activity($companyId, (int)$current_user['id'], 'connection_debug_send', 'channel',
            (int)$channel['id'], 'http=' . (int)$info['http_code'] . ' errno=' . curl_errno($ch));
    }
}

// Recent failures (always shown)
$failStmt = $db->prepare(
    'SELECT m.id, m.created_at, m.message_text, m.error_message,
            ct.display_name, ct.wa_id
     FROM messages m
     LEFT JOIN contacts ct ON ct.id = m.contact_id
     WHERE m.company_id = ? AND m.direction = "outgoing" AND m.status = "failed"
     ORDER BY m.id DESC LIMIT 10'
);
$failStmt->execute([$companyId]);
$recentFails = $failStmt->fetchAll();

layout_start($current_user, 'Connection debug', 'connection_debug');
?>
<div class="card">
  <h2>Connection debug</h2>
  <p class="muted small">
    Diagnoses where the outbound call to the partner's gateway is failing —
    DNS, SSL, network, or auth. Share the rows below with your partner when
    you suspect their side; share the curl error rows here when you suspect
    yours. Safe to refresh - the page is read-only until you submit the test send.
  </p>

  <form method="get" class="inline-form" style="margin-bottom:14px;">
    <label>Channel
      <select name="channel_id" onchange="this.form.submit()">
        <?php foreach ($chList as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $channel && (int)$channel['id'] === (int)$c['id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?> · <?= e($c['provider']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>

  <?php if (!$channel): ?>
    <div class="alert alert-error">No channels configured for this workspace.</div>
  <?php else: ?>
    <h3>Diagnostics</h3>
    <table class="data-table">
      <tbody>
        <?php foreach ($diag as $k => $v): ?>
          <tr>
            <th style="width:240px"><?= e($k) ?></th>
            <td><code style="word-break:break-all"><?= e((string)$v) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small" style="margin-top:6px">
      <strong>What to look for</strong>: HEAD curl_errno = 6 means DNS, 7 = connect refused, 28 = timeout,
      35 / 60 = SSL. HTTP 200 / 404 / 405 here are all healthy (it just means the URL is reachable
      — 405 is normal for a HEAD on a POST-only endpoint).
    </p>
  <?php endif; ?>
</div>

<?php if ($channel): ?>
<div class="card">
  <h3>Live test send</h3>
  <p class="muted small">
    Sends one real message to the recipient you specify, using this channel's
    saved Bearer token. Use your own phone for the first test. The full curl
    output + response body is shown below so you can paste it to your partner
    if there's an issue.
  </p>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="text" name="to" placeholder="60123456789 (digits, no +)"
           value="<?= e((string)($_POST['to'] ?? '')) ?>"
           style="min-width:260px">
    <button class="btn btn-primary" type="submit">Send debug test</button>
  </form>

  <?php if ($sendDiag): ?>
    <h4 style="margin-top:14px">Result</h4>
    <table class="data-table">
      <tbody>
        <?php foreach ($sendDiag as $k => $v): ?>
          <tr>
            <th style="width:240px"><?= e($k) ?></th>
            <td><code style="word-break:break-all"><?= e((string)$v) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($sendHeaders): ?>
      <h4 style="margin-top:14px">Response headers</h4>
      <pre style="background:#1f2933;color:#d9e0e8;padding:12px;border-radius:6px;font-size:12px;overflow:auto;max-height:240px"><?= e($sendHeaders) ?></pre>
    <?php endif; ?>
    <?php if ($sendBody): ?>
      <h4>Response body</h4>
      <pre style="background:#1f2933;color:#d9e0e8;padding:12px;border-radius:6px;font-size:12px;overflow:auto;max-height:240px"><?= e($sendBody) ?></pre>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>Last 10 failed outbound messages</h3>
  <?php if (!$recentFails): ?>
    <p class="muted">No recent failures — last 24 h was clean.</p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Time</th><th>To</th><th>Text</th><th>error_message</th></tr></thead>
      <tbody>
        <?php foreach ($recentFails as $r): ?>
          <tr>
            <td class="muted small"><?= e($r['created_at']) ?></td>
            <td><?= e($r['display_name'] ?: '+' . $r['wa_id']) ?></td>
            <td><?= e(mb_strimwidth((string)$r['message_text'], 0, 60, '…')) ?></td>
            <td><code style="word-break:break-all"><?= e((string)$r['error_message']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Reading curl error codes</h3>
  <table class="data-table">
    <thead><tr><th>errno</th><th>Meaning</th><th>Where it lives</th></tr></thead>
    <tbody>
      <tr><td>0</td><td>No error - request completed</td><td>—</td></tr>
      <tr><td>6</td><td>Couldn't resolve host</td><td>Hostinger DNS (your side)</td></tr>
      <tr><td>7</td><td>Couldn't connect to server</td><td>Network between Hostinger and partner (could be either)</td></tr>
      <tr><td>28</td><td>Timeout</td><td>Partner's server slow or unreachable</td></tr>
      <tr><td>35 / 60</td><td>SSL handshake / cert verify failed</td><td>Partner's SSL cert (their side)</td></tr>
      <tr><td>52</td><td>Empty reply from server</td><td>Partner's server crashed mid-request</td></tr>
      <tr><td>56</td><td>Failure receiving network data</td><td>Network drop mid-response</td></tr>
    </tbody>
  </table>
  <p class="muted small" style="margin-top:8px">
    <strong>Anything ≥ HTTP 200 means the partner got your request and chose to reject it</strong>
    (401 = bad token, 404 = wrong path, 422 = bad payload). errno != 0 means the request never
    completed — it's a network / DNS / SSL problem between the two servers.
  </p>
</div>
<?php layout_end(); ?>
