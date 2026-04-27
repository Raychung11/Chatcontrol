<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/evolution_api.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch() ?: [];

// Lightweight JSON action handler (used by polling JS)
if (is_post() && !empty($_POST['action'])) {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)$_POST['action'];

    switch ($action) {
        case 'create':
            $r = evolution_create_instance($company);
            // Auto-set webhook to our endpoint so messages flow back without manual config
            $webhookUrl = (APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '')))
                        . '/webhook/evolution.php?token=' . urlencode((string)($company['webhook_verify_token'] ?? ''));
            evolution_set_webhook($company, $webhookUrl);
            echo json_encode($r);
            exit;

        case 'qr':
            $r = evolution_get_qr($company);
            echo json_encode($r);
            exit;

        case 'state':
            $r = evolution_connection_state($company);
            // Persist the state in companies row so Settings reflects it.
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
            echo json_encode($r);
            exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$configured = evolution_is_configured($company);

layout_start($current_user, 'Pair WhatsApp (Evolution)', 'whatsapp_pair');
?>
<div class="card">
  <h2>Pair WhatsApp via Evolution</h2>
  <?php if (($company['provider'] ?? 'cloud_api') !== 'evolution'): ?>
    <div class="alert alert-info">
      Provider is currently set to <strong><?= e($company['provider'] ?? 'cloud_api') ?></strong>.
      Switch to <strong>Evolution</strong> in <a href="/admin/settings.php">Settings</a> first.
    </div>
  <?php elseif (!$configured): ?>
    <div class="alert alert-error">
      Evolution server URL, API key, and instance name must be set before pairing.
      Configure them in <a href="/admin/settings.php">Settings</a>.
    </div>
  <?php else: ?>
    <p class="muted">
      1. Click <strong>Start pairing</strong>. We'll create the instance on your Evolution server (if it doesn't already exist) and fetch a QR code.<br>
      2. Open WhatsApp on the phone with +<?= e($company['whatsapp_number'] ?? 'your business number') ?> →
      <strong>Settings → Linked devices → Link a device</strong> → scan the QR.<br>
      3. Once "Connected" shows below, customer messages will flow into the inbox.
    </p>

    <div class="pair-actions">
      <button id="btn-start" class="btn btn-primary">Start pairing</button>
      <button id="btn-refresh" class="btn">Refresh QR</button>
      <button id="btn-logout" class="btn btn-danger">Logout / disconnect</button>
      <span id="pair-state-pill" class="badge badge-default">unknown</span>
    </div>

    <div id="qr-wrap" class="qr-wrap hidden">
      <img id="qr-image" alt="WhatsApp pairing QR" />
      <p id="pair-code" class="muted small"></p>
    </div>
  <?php endif; ?>
</div>

<style>
.pair-actions { display: flex; gap: 10px; align-items: center; margin: 16px 0; }
.qr-wrap { text-align: center; padding: 20px; background: #fff; border: 1px solid var(--c-border); border-radius: 8px; }
.qr-wrap img { max-width: 280px; height: auto; border: 1px solid var(--c-border); border-radius: 6px; }
.evo-state-connected   { background: #e8f7ee; color: #1f7a3f; }
.evo-state-connecting  { background: #fff3e0; color: #b25c00; }
.evo-state-disconnected{ background: #fdecea; color: #b3261e; }
</style>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  const btnStart   = document.getElementById('btn-start');
  const btnRefresh = document.getElementById('btn-refresh');
  const btnLogout  = document.getElementById('btn-logout');
  const qrWrap     = document.getElementById('qr-wrap');
  const qrImage    = document.getElementById('qr-image');
  const pairCode   = document.getElementById('pair-code');
  const statePill  = document.getElementById('pair-state-pill');
  if (!btnStart) return;

  let pollTimer = null;

  function call(action) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_csrf', csrf);
    return fetch('/admin/whatsapp_pair.php', { method: 'POST', body: fd })
      .then(r => r.json());
  }

  async function refreshState() {
    const r = await call('state');
    const s = (r && r.state) || 'unknown';
    statePill.textContent = s;
    statePill.className = 'badge evo-state-' + s;
    if (s === 'connected') {
      qrWrap.classList.add('hidden');
      stopPolling();
    }
  }

  async function fetchQr() {
    const r = await call('qr');
    if (r.ok && r.qr) {
      qrImage.src = 'data:image/png;base64,' + r.qr;
      qrWrap.classList.remove('hidden');
      pairCode.textContent = r.pairing ? ('Or use pairing code: ' + r.pairing) : '';
    } else if (r.ok && !r.qr) {
      // No QR returned — likely already connected
      qrWrap.classList.add('hidden');
    }
  }

  function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => { refreshState(); }, 3000);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  btnStart.addEventListener('click', async () => {
    btnStart.disabled = true;
    btnStart.textContent = 'Starting…';
    const c = await call('create');
    btnStart.disabled = false;
    btnStart.textContent = 'Start pairing';
    if (!c.ok) { alert('Could not create instance: ' + (c.error || 'unknown')); return; }
    await fetchQr();
    refreshState();
    startPolling();
  });

  btnRefresh.addEventListener('click', fetchQr);

  btnLogout.addEventListener('click', async () => {
    if (!confirm('Disconnect this WhatsApp number from the Evolution server?')) return;
    await call('logout');
    refreshState();
  });

  // Initial state on page load
  refreshState();
})();
</script>
<?php layout_end(); ?>
