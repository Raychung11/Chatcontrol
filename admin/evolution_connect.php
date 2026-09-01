<?php
/**
 * /admin/evolution_connect.php — self-serve WhatsApp pairing.
 *
 * Workspace super_admin (or manager) picks one of their existing
 * Evolution channel rows, scans a QR from the paired phone, and the
 * portal wires up the webhook back to itself. No platform-admin
 * involvement needed.
 *
 * Backends:
 *   POST action=test       — probes /instance/connectionState to
 *                            verify base URL + API key are correct
 *   POST action=create     — POST /instance/create to spin up a new
 *                            Baileys session inside Evolution, then
 *                            POST /webhook/set to register OUR
 *                            /webhook/evolution.php as the target
 *   POST action=qr         — fetch the pair-code + QR PNG from
 *                            /instance/connect/<name>
 *   POST action=state      — poll /instance/connectionState; caches
 *                            the result on the channel row so the
 *                            channels_health page reflects it too
 *   POST action=logout     — DELETE /instance/logout/<name> to end
 *                            the current WhatsApp session (e.g. before
 *                            re-pairing a different phone)
 *   POST action=webhook    — re-register the webhook (idempotent)
 *
 * Every action is scoped to a channel_id that the current workspace
 * owns — no cross-workspace peek possible.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';
require_once __DIR__ . '/../inc/evolution_api.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

/** Load a channel row, scoped to this workspace, or return null. */
function evo_channel(PDO $db, int $channelId, int $companyId): ?array
{
    if ($channelId <= 0) return null;
    $s = $db->prepare(
        "SELECT * FROM channels
         WHERE id = ? AND company_id = ? AND provider = 'evolution' LIMIT 1"
    );
    $s->execute([$channelId, $companyId]);
    return $s->fetch() ?: null;
}

/** Compute the public webhook URL this portal advertises to Evolution. */
function evo_webhook_url(array $channel): string
{
    $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));
    return $base . '/webhook/evolution.php?ch=' . rawurlencode((string)($channel['webhook_token'] ?? ''));
}

// ==============================================================
// JSON action handler — driven by the wizard JS below
// ==============================================================
if (is_post() && !empty($_POST['action'])) {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');

    $action = (string)$_POST['action'];
    $chId   = (int)($_POST['channel_id'] ?? 0);
    $ch     = evo_channel($db, $chId, $companyId);
    if (!$ch) {
        echo json_encode(['ok' => false, 'error' => 'Channel not found or not owned by this workspace.']);
        exit;
    }

    switch ($action) {

        case 'test':
            // Reachability + auth check. 404 from connectionState still
            // proves the server + key work — it just means the instance
            // doesn't exist yet, which we'll create next.
            $r = evolution_connection_state($ch);
            echo json_encode([
                'ok'    => $r['ok'] || ($r['error'] ?? '') === 'HTTP 404',
                'state' => $r['state'] ?? 'unknown',
                'error' => $r['error'] ?? null,
            ]);
            exit;

        case 'create':
            // Create the Baileys session inside Evolution, then wire our
            // webhook so future MESSAGES_UPSERT events reach us.
            $r  = evolution_create_instance($ch);
            $wh = evolution_set_webhook($ch, evo_webhook_url($ch));
            log_activity($companyId, (int)$current_user['id'], 'evolution_instance_created',
                         'channel', $chId, evolution_instance_name($ch));
            echo json_encode([
                'ok'          => $r['ok'],
                'error'       => $r['error'] ?? null,
                'webhook_ok'  => $wh['ok'] ?? false,
                'webhook_url' => evo_webhook_url($ch),
            ]);
            exit;

        case 'qr':
            // Returns { ok, qrcode_base64, pairing_code, ... } — the JS
            // renders qrcode_base64 as an <img>.
            echo json_encode(evolution_get_qr($ch));
            exit;

        case 'webhook':
            $wh = evolution_set_webhook($ch, evo_webhook_url($ch));
            echo json_encode([
                'ok'          => $wh['ok'] ?? false,
                'webhook_url' => evo_webhook_url($ch),
                'error'       => $wh['error'] ?? null,
            ]);
            exit;

        case 'state':
            // Poll every ~3s while pairing. Also cache the result on
            // the channel row so /admin/channels_health.php reflects
            // the live probe state between health-ping-cron ticks.
            $r = evolution_connection_state($ch);
            if ($r['ok']) {
                $mapToProbe = [
                    'connected'    => 'connected',
                    'connecting'   => 'connecting',
                    'disconnected' => 'disconnected',
                ];
                $probe = $mapToProbe[$r['state']] ?? 'unknown';
                $prev  = (string)($ch['probe_state'] ?? 'unknown');
                if ($prev !== $probe) {
                    $db->prepare(
                        'UPDATE channels
                         SET probe_state = ?, probe_state_since = NOW(), probe_last_at = NOW()
                         WHERE id = ? LIMIT 1'
                    )->execute([$probe, $chId]);
                } else {
                    $db->prepare('UPDATE channels SET probe_last_at = NOW() WHERE id = ? LIMIT 1')
                       ->execute([$chId]);
                }
            }
            echo json_encode($r);
            exit;

        case 'logout':
            $r = evolution_logout_instance($ch);
            log_activity($companyId, (int)$current_user['id'], 'evolution_instance_logout',
                         'channel', $chId);
            echo json_encode($r);
            exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// ==============================================================
// HTML wizard
// ==============================================================
$channels = $db->prepare(
    "SELECT id, name, evolution_base_url, evolution_api_key, evolution_instance,
            probe_state, probe_last_at, display_phone
     FROM channels
     WHERE company_id = ? AND provider = 'evolution'
     ORDER BY id ASC"
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

$selectedId = (int)($_GET['channel_id'] ?? ($channels[0]['id'] ?? 0));
$selected   = null;
foreach ($channels as $c) if ((int)$c['id'] === $selectedId) $selected = $c;

layout_start($current_user, '📱 Pair WhatsApp (Evolution)', 'evolution_connect');
?>
<style>
.ec-shell { max-width: 900px; }
.ec-picker { display:flex; gap:10px; align-items:center; margin-bottom:14px; flex-wrap:wrap; }
.ec-picker select { padding:8px 10px; font-size:14px; border:1px solid #d0d7de; border-radius:6px; min-width:280px; }
.ec-state-row {
  display:flex; gap:12px; align-items:center; padding:12px 14px;
  background:#fff; border:1px solid #e3e8ee; border-radius:10px;
  margin-bottom:12px; flex-wrap:wrap;
}
.ec-dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
.ec-dot.connected  { background:#16A34A; box-shadow:0 0 0 3px rgba(22,163,74,.18); }
.ec-dot.connecting { background:#F59E0B; }
.ec-dot.disconnected { background:#DC2626; box-shadow:0 0 0 3px rgba(220,38,38,.15); }
.ec-dot.unknown    { background:#94a3b8; }
.ec-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.ec-actions button { padding:8px 14px; border-radius:8px; border:1px solid #d0d7de; background:#fff; cursor:pointer; font-size:13.5px; }
.ec-actions button.primary { background:#25D366; color:#fff; border-color:#25D366; }
.ec-actions button.danger  { color:#DC2626; border-color:#fca5a5; background:#fff; }
.ec-actions button:disabled { opacity:.5; cursor:not-allowed; }
.ec-qr {
  background:#fff; border:1px solid #e3e8ee; border-radius:12px;
  padding:20px; text-align:center; margin-bottom:12px;
}
.ec-qr img { width:280px; height:280px; display:block; margin:0 auto; }
.ec-qr .code { margin-top:10px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:18px; letter-spacing:.15em; color:#0f172a; }
.ec-hint { color:#475569; font-size:13px; line-height:1.5; }
.ec-log {
  background:#0f172a; color:#e2e8f0; border-radius:8px; padding:10px 14px;
  font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px;
  margin-top:12px; max-height:180px; overflow-y:auto; white-space:pre-wrap;
}
</style>

<div class="ec-shell">
  <h1>📱 Pair WhatsApp <span class="muted small">(Evolution / Baileys)</span></h1>
  <p class="ec-hint">
    Pair a WhatsApp number to one of your Evolution channels. Requires the channel to already have its
    Base URL, API key, and instance name filled in on <a href="/admin/channels.php">Channels</a>.
  </p>

  <?php if (!$channels): ?>
    <div class="alert alert-info">
      No Evolution channels yet. Go to <a href="/admin/channels.php">Channels → + New channel</a>,
      pick <strong>evolution</strong> as the provider, then come back here.
    </div>
  <?php else: ?>
    <form method="get" class="ec-picker">
      <label>Channel:</label>
      <select name="channel_id" onchange="this.form.submit()">
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $selectedId ? 'selected' : '' ?>>
            #<?= (int)$c['id'] ?> · <?= e((string)$c['name']) ?>
            <?php if (!empty($c['display_phone'])): ?>· <?= e((string)$c['display_phone']) ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if ($selected): ?>
      <div class="ec-state-row">
        <span class="ec-dot <?= e((string)($selected['probe_state'] ?? 'unknown')) ?>" id="ec-dot"></span>
        <strong id="ec-state-label">
          <?= e(ucfirst((string)($selected['probe_state'] ?? 'unknown'))) ?>
        </strong>
        <span class="muted small">·</span>
        <span class="muted small">Instance: <code><?= e((string)$selected['evolution_instance']) ?></code></span>
        <span class="muted small">·</span>
        <span class="muted small">Base: <code><?= e((string)$selected['evolution_base_url']) ?></code></span>
      </div>

      <div class="ec-actions">
        <button type="button" id="ec-test">1. Test connection</button>
        <button type="button" id="ec-create" class="primary">2. Create instance + webhook</button>
        <button type="button" id="ec-qr" class="primary">3. Show QR to pair</button>
        <button type="button" id="ec-webhook">Re-register webhook</button>
        <button type="button" id="ec-logout" class="danger">Log out (end session)</button>
      </div>

      <div id="ec-qr-panel" class="ec-qr" style="display:none;">
        <img id="ec-qr-img" src="" alt="Pair WhatsApp QR">
        <div class="code" id="ec-qr-code"></div>
        <div class="ec-hint" style="margin-top:8px;">
          On the phone: <strong>WhatsApp → Menu → Linked devices → Link a device</strong> → scan this QR.
          <br>QR refreshes every 30 seconds. Once paired, the dot turns 🟢 green above.
        </div>
      </div>

      <div class="ec-log" id="ec-log">Ready. Click <strong>1. Test connection</strong> to start.</div>

      <script>
      (function () {
        const chId = <?= (int)$selected['id'] ?>;
        const csrf = <?= json_encode(csrf_token()) ?>;

        const $ = id => document.getElementById(id);
        const dot   = $('ec-dot'), label = $('ec-state-label'), logEl = $('ec-log');
        const qrPanel = $('ec-qr-panel'), qrImg = $('ec-qr-img'), qrCode = $('ec-qr-code');

        function log(line, kind) {
          const ts = new Date().toLocaleTimeString();
          const color = kind === 'err' ? '#f87171' : kind === 'ok' ? '#4ade80' : '#93c5fd';
          logEl.innerHTML += `\n<span style="color:${color};">[${ts}] ${line}</span>`;
          logEl.scrollTop = logEl.scrollHeight;
        }
        async function call(action, extra = {}) {
          const fd = new FormData();
          fd.append('_csrf', csrf);
          fd.append('action', action);
          fd.append('channel_id', chId);
          for (const [k, v] of Object.entries(extra)) fd.append(k, v);
          const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
          const text = await res.text();
          try { return JSON.parse(text); } catch (e) { return { ok: false, error: 'Bad JSON: ' + text.slice(0, 200) }; }
        }
        function setState(state) {
          dot.className = 'ec-dot ' + (state || 'unknown');
          label.textContent = state ? state[0].toUpperCase() + state.slice(1) : 'Unknown';
          if (state === 'connected') {
            qrPanel.style.display = 'none';
            log('🎉 Paired! You can close this page.', 'ok');
          }
        }

        $('ec-test').onclick = async () => {
          log('Testing base URL + API key…');
          const r = await call('test');
          if (r.ok) log('✓ Reachable. Current state: ' + (r.state || 'unknown'), 'ok');
          else      log('✗ ' + (r.error || 'Unreachable'), 'err');
        };

        $('ec-create').onclick = async () => {
          log('Creating Evolution instance + registering webhook…');
          const r = await call('create');
          if (r.ok || r.error === null) {
            log('✓ Instance created (or already exists)', 'ok');
            log('✓ Webhook registered → ' + (r.webhook_url || '?'), 'ok');
          } else {
            log('✗ Create failed: ' + r.error, 'err');
          }
        };

        $('ec-qr').onclick = async () => {
          log('Fetching QR…');
          const r = await call('qr');
          // Different Evolution versions name the base64 field differently.
          // v2.3.7 returns 'qr', older builds return 'qrcode_base64' or
          // 'base64'. Accept any of them, then add the data:image/png
          // prefix if the value doesn't already have it.
          const b64 = r.qrcode_base64 || r.qr || r.base64 || null;
          const code = r.pairing_code || r.pairingCode || r.code || null;
          if (b64) {
            qrImg.src = b64.startsWith('data:') ? b64 : ('data:image/png;base64,' + b64);
            qrCode.textContent = code || '';
            qrPanel.style.display = 'block';
            log('✓ QR ready — scan from WhatsApp → Linked devices', 'ok');
            startPolling();
          } else if (r.count && r.count > 0) {
            log('Instance is already paired (state should show connected).', 'ok');
          } else {
            log('✗ Could not fetch QR: ' + (r.error || JSON.stringify(r)).slice(0, 200), 'err');
          }
        };

        $('ec-webhook').onclick = async () => {
          log('Re-registering webhook…');
          const r = await call('webhook');
          if (r.ok) log('✓ Webhook set → ' + r.webhook_url, 'ok');
          else      log('✗ ' + (r.error || 'failed'), 'err');
        };

        $('ec-logout').onclick = async () => {
          if (!confirm('Log this WhatsApp number out of Evolution? You\'ll need to re-scan a QR to pair again.')) return;
          log('Logging out…');
          const r = await call('logout');
          if (r.ok) { log('✓ Logged out', 'ok'); setState('disconnected'); }
          else      log('✗ ' + (r.error || 'failed'), 'err');
        };

        // Live poll — cheap, only runs while the tab is visible.
        let pollTimer = null;
        function startPolling() {
          if (pollTimer) return;
          pollTimer = setInterval(async () => {
            if (document.hidden) return;
            const r = await call('state');
            if (r.ok) setState(r.state);
          }, 3000);
        }
        // Kick off one immediate probe on load so the dot reflects reality.
        (async () => {
          const r = await call('state');
          if (r.ok) setState(r.state);
          if (r.state !== 'connected') startPolling();
        })();
      })();
      </script>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
