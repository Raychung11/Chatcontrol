<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/evolution_api.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

function evo_company(PDO $db, int $id): array
{
    $s = $db->prepare('SELECT * FROM companies WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: [];
}

// ---- JSON action handler (driven by the wizard JS) ----
if (is_post() && !empty($_POST['action'])) {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $action  = (string)$_POST['action'];
    $company = evo_company($db, $companyId);

    switch ($action) {

        case 'save_config':
            $base     = trim((string)($_POST['evolution_base_url'] ?? ''));
            $instance = trim((string)($_POST['evolution_instance'] ?? ''));
            $apiKey   = trim((string)($_POST['evolution_api_key']  ?? ''));
            if ($apiKey === '') {
                $apiKey = (string)($company['evolution_api_key'] ?? '');
            }
            if ($base === '' || $instance === '' || $apiKey === '') {
                echo json_encode(['ok' => false, 'error' => 'Base URL, instance, and API key are all required.']);
                exit;
            }
            $base = rtrim($base, '/');
            $db->prepare(
                'UPDATE companies
                 SET provider = "evolution",
                     evolution_base_url = ?, evolution_instance = ?, evolution_api_key = ?
                 WHERE id = ?'
            )->execute([$base, $instance, $apiKey, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'evolution_config_saved', 'company', $companyId);
            echo json_encode(['ok' => true]);
            exit;

        case 'test':
            // A lightweight reachability check: connectionState returns even
            // when the instance doesn't exist yet (404), which still proves
            // the server + API key are valid.
            $r = evolution_connection_state($company);
            echo json_encode([
                'ok'    => $r['ok'] || ($r['error'] ?? '') === 'HTTP 404',
                'state' => $r['state'] ?? 'unknown',
                'error' => $r['error'] ?? null,
            ]);
            exit;

        case 'create':
            $r = evolution_create_instance($company);
            $webhookUrl = (APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')))
                        . '/webhook/evolution.php?token=' . urlencode((string)($company['webhook_verify_token'] ?? ''));
            $wh = evolution_set_webhook($company, $webhookUrl);
            echo json_encode(['ok' => $r['ok'], 'error' => $r['error'] ?? null,
                              'webhook_ok' => $wh['ok'] ?? false, 'webhook_url' => $webhookUrl]);
            exit;

        case 'qr':
            echo json_encode(evolution_get_qr($company));
            exit;

        case 'state':
            $r = evolution_connection_state($company);
            if ($r['ok']) {
                $db->prepare('UPDATE companies SET evolution_status = ? WHERE id = ?')
                   ->execute([$r['state'], $companyId]);
            }
            echo json_encode($r);
            exit;

        case 'logout':
            $r = evolution_logout_instance($company);
            $db->prepare('UPDATE companies SET evolution_status = "disconnected" WHERE id = ?')
               ->execute([$companyId]);
            log_activity($companyId, (int)$current_user['id'], 'evolution_logout', 'company', $companyId);
            echo json_encode(['ok' => true, 'raw' => $r['raw'] ?? null]);
            exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$company    = evo_company($db, $companyId);
$verifyTok  = (string)($company['webhook_verify_token'] ?? '');
$webhookUrl = (APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')))
            . '/webhook/evolution.php?token=' . urlencode($verifyTok);

layout_start($current_user, 'Connect WhatsApp (Evolution)', 'evolution_connect');
?>
<div class="card">
  <h2>Connect WhatsApp via Evolution</h2>
  <p class="muted">
    Evolution is a self-hosted, unofficial WhatsApp gateway. No number migration needed —
    it pairs like WhatsApp Web. Follow the 4 steps below. Need a server first?
    See the <a href="/docs/EVOLUTION.md" target="_blank">self-host guide</a>.
  </p>
  <?php if (empty($verifyTok)): ?>
    <div class="alert alert-error">
      Set a <strong>Webhook verify token</strong> in <a href="/admin/settings.php">Settings</a> first —
      it secures the Evolution → portal webhook.
    </div>
  <?php endif; ?>
</div>

<div class="card wizard-step" id="step-1">
  <h3>Step 1 — Evolution server details</h3>
  <form id="cfg-form" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_config">
    <label>Evolution server base URL
      <input type="url" name="evolution_base_url" required
             value="<?= e($company['evolution_base_url'] ?? '') ?>"
             placeholder="https://evo.your-server.com">
    </label>
    <label>Instance name
      <input type="text" name="evolution_instance" required
             value="<?= e($company['evolution_instance'] ?? 'aiserve-prod') ?>"
             placeholder="aiserve-prod">
    </label>
    <label>API key <?= !empty($company['evolution_api_key']) ? '(leave blank to keep existing)' : '' ?>
      <input type="password" name="evolution_api_key" autocomplete="new-password"
             placeholder="AUTHENTICATION_API_KEY from your Evolution .env">
      <?php if (!empty($company['evolution_api_key'])): ?>
        <small class="muted">Currently set: <code><?= e(substr($company['evolution_api_key'], 0, 6)) ?>…</code></small>
      <?php endif; ?>
    </label>
    <div>
      <button class="btn btn-primary" type="submit">Save &amp; switch provider to Evolution</button>
      <span id="cfg-status" class="muted small"></span>
    </div>
  </form>
</div>

<div class="card wizard-step" id="step-2">
  <h3>Step 2 — Test connection</h3>
  <p class="muted small">Confirms the portal can reach your Evolution server with the API key.</p>
  <button class="btn" id="btn-test">Test connection</button>
  <span id="test-status" class="muted small"></span>
</div>

<div class="card wizard-step" id="step-3">
  <h3>Step 3 — Create instance &amp; register webhook</h3>
  <p class="muted small">
    Creates the instance on Evolution (if needed) and points its webhook at:<br>
    <code><?= e($webhookUrl) ?></code>
  </p>
  <button class="btn" id="btn-create">Create / prepare instance</button>
  <span id="create-status" class="muted small"></span>
</div>

<div class="card wizard-step" id="step-4">
  <h3>Step 4 — Scan QR to pair</h3>
  <p class="muted small">
    On the phone with your business number:
    <strong>WhatsApp → Settings → Linked devices → Link a device</strong> → scan below.
  </p>
  <div class="pair-actions">
    <button class="btn btn-primary" id="btn-qr">Show / refresh QR</button>
    <button class="btn btn-danger" id="btn-logout">Disconnect</button>
    <span id="pair-pill" class="badge badge-default">unknown</span>
  </div>
  <div id="qr-wrap" class="qr-wrap hidden">
    <img id="qr-image" alt="WhatsApp pairing QR">
    <p id="pair-code" class="muted small"></p>
  </div>
</div>

<style>
.wizard-step h3 { margin: 0 0 8px; }
.pair-actions { display: flex; gap: 10px; align-items: center; margin: 12px 0; }
.qr-wrap { text-align: center; padding: 20px; background: #fff; border: 1px solid var(--c-border); border-radius: 8px; }
.qr-wrap img { max-width: 280px; border: 1px solid var(--c-border); border-radius: 6px; }
.evo-state-connected   { background:#e8f7ee; color:#1f7a3f; }
.evo-state-connecting  { background:#fff3e0; color:#b25c00; }
.evo-state-disconnected{ background:#fdecea; color:#b3261e; }
.ok-text  { color:#1f7a3f; } .err-text { color:#b3261e; }
</style>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  function call(payload) {
    const fd = new FormData();
    Object.keys(payload).forEach(k => fd.append(k, payload[k]));
    fd.append('_csrf', csrf);
    return fetch('/admin/evolution_connect.php', { method: 'POST', body: fd }).then(r => r.json());
  }

  // Step 1
  const cfgForm = document.getElementById('cfg-form');
  cfgForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const s = document.getElementById('cfg-status');
    s.textContent = 'Saving…'; s.className = 'muted small';
    const fd = new FormData(cfgForm);
    const r = await call(Object.fromEntries(fd.entries()));
    if (r.ok) { s.textContent = 'Saved. Provider set to Evolution.'; s.className = 'small ok-text'; }
    else { s.textContent = r.error || 'Failed'; s.className = 'small err-text'; }
  });

  // Step 2
  document.getElementById('btn-test').addEventListener('click', async () => {
    const s = document.getElementById('test-status');
    s.textContent = 'Testing…'; s.className = 'muted small';
    const r = await call({ action: 'test' });
    if (r.ok) { s.textContent = '✓ Server reachable (state: ' + (r.state || '?') + ')'; s.className = 'small ok-text'; }
    else { s.textContent = '✗ ' + (r.error || 'Unreachable'); s.className = 'small err-text'; }
  });

  // Step 3
  document.getElementById('btn-create').addEventListener('click', async () => {
    const s = document.getElementById('create-status');
    s.textContent = 'Working…'; s.className = 'muted small';
    const r = await call({ action: 'create' });
    if (r.ok) {
      s.textContent = '✓ Instance ready' + (r.webhook_ok ? ' · webhook registered' : ' · webhook NOT set (set it manually in Evolution)');
      s.className = 'small ' + (r.webhook_ok ? 'ok-text' : 'err-text');
    } else { s.textContent = '✗ ' + (r.error || 'Failed'); s.className = 'small err-text'; }
  });

  // Step 4
  const qrWrap = document.getElementById('qr-wrap');
  const qrImg  = document.getElementById('qr-image');
  const pill   = document.getElementById('pair-pill');
  let timer = null;

  async function refreshState() {
    const r = await call({ action: 'state' });
    const st = (r && r.state) || 'unknown';
    pill.textContent = st;
    pill.className = 'badge evo-state-' + st;
    if (st === 'connected') { qrWrap.classList.add('hidden'); stop(); }
  }
  async function showQr() {
    const r = await call({ action: 'qr' });
    if (r.ok && r.qr) {
      qrImg.src = 'data:image/png;base64,' + r.qr;
      qrWrap.classList.remove('hidden');
      document.getElementById('pair-code').textContent =
        r.pairing ? ('Or pairing code: ' + r.pairing) : '';
      start();
    } else if (r.ok && !r.qr) {
      qrWrap.classList.add('hidden');
      refreshState();
    } else {
      alert('Could not get QR: ' + (r.error || 'unknown'));
    }
  }
  function start() { stop(); timer = setInterval(refreshState, 3000); }
  function stop()  { if (timer) { clearInterval(timer); timer = null; } }

  document.getElementById('btn-qr').addEventListener('click', showQr);
  document.getElementById('btn-logout').addEventListener('click', async () => {
    if (!confirm('Disconnect this WhatsApp number from Evolution?')) return;
    await call({ action: 'logout' });
    refreshState();
  });

  refreshState();
})();
</script>
<?php layout_end(); ?>
