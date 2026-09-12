<?php
/**
 * PIN quick-unlock screen — shown when remember-me auto-logs a user
 * in and they have a PIN set. Big touch-friendly 6-digit pad.
 *
 * If the user submits the correct PIN, mark session as verified and
 * bounce back to ?next. If they're locked out or don't have a PIN,
 * fall through to the password login.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/webauthn.php';

aiserve_start_session();

$u = current_user();
if (!$u) {
    redirect('/login.php');
}
$hasBio = wa_user_has_credential((int)$u['id']);
$hasPin = !empty($u['pin_hash']);

// Nothing to unlock — pass through.
if (!$hasPin && !$hasBio) {
    pin_mark_verified();
    redirect(post_login_landing($_GET['next'] ?? null));
}
if (pin_is_verified()) {
    redirect(post_login_landing($_GET['next'] ?? null));
}

$next = $_GET['next'] ?? null;
if (is_string($next) && (!str_starts_with($next, '/') || str_starts_with($next, '//'))) {
    $next = null;
}

$errMsg    = '';
$lockedFor = pin_user_locked_until((int)$u['id']);

if (is_post() && $lockedFor === 0) {
    csrf_check();
    $pin = trim((string)($_POST['pin'] ?? ''));
    $r   = pin_verify_and_unlock((int)$u['id'], $pin);
    if ($r['ok']) {
        redirect(post_login_landing($next));
    }
    $errMsg    = (string)$r['message'];
    $lockedFor = (int)$r['locked_s'];
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title>Unlock · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
  <style>
    body.pin-body {
      background: #101820;
      min-height: 100vh; margin: 0;
      display: flex; align-items: center; justify-content: center;
      font-family: -apple-system, system-ui, sans-serif;
    }
    .pin-card {
      background: #1a2431; border-radius: 20px;
      padding: 28px 24px; width: 320px; max-width: 92vw;
      text-align: center; color: #e2e8f0;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    }
    .pin-brand { font-size: 22px; margin-bottom: 4px; }
    .pin-hello { color: #94a3b8; font-size: 13px; margin-bottom: 22px; }
    .pin-dots  { display: flex; justify-content: center; gap: 12px; margin-bottom: 22px; }
    .pin-dot   {
      width: 14px; height: 14px; border-radius: 50%;
      background: #2c3744; border: 1.5px solid #3f4d5f;
      transition: all 0.15s;
    }
    .pin-dot.on { background: #25D366; border-color: #25D366; box-shadow: 0 0 8px rgba(37,211,102,0.6); }
    .pin-pad   { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
    .pin-key   {
      background: #222b36; border: 1px solid #2c3744; color: #e2e8f0;
      border-radius: 14px; font-size: 24px; font-weight: 600;
      padding: 18px 0; cursor: pointer; user-select: none;
      transition: background 0.1s, transform 0.05s;
    }
    .pin-key:active { background: #2c3744; transform: scale(0.96); }
    .pin-key.wide   { grid-column: span 2; }
    .pin-key.icon   { font-size: 18px; }
    .pin-err        { color: #f87171; font-size: 13px; margin: 10px 0; min-height: 18px; }
    .pin-alt {
      margin-top: 18px; padding-top: 14px; border-top: 1px solid #2c3744;
      font-size: 12px; color: #94a3b8;
    }
    .pin-alt a { color: #7dd3fc; text-decoration: none; }
  </style>
</head>
<body class="pin-body">
  <form method="post" class="pin-card" id="pin-form">
    <?= csrf_field() ?>
    <input type="hidden" name="pin" id="pin-value" value="">
    <div class="pin-brand">🔒</div>
    <div class="pin-hello">
      <?= e($u['name'] ?? 'Welcome back') ?><br>
      <small><?= $lockedFor > 0
        ? 'Locked for ' . ceil($lockedFor / 60) . ' min'
        : 'Enter your 6-digit PIN to unlock' ?></small>
    </div>

    <div class="pin-dots" id="pin-dots">
      <?php for ($i = 0; $i < 6; $i++): ?>
        <div class="pin-dot" data-i="<?= $i ?>"></div>
      <?php endfor; ?>
    </div>

    <div class="pin-err" id="pin-err"><?= e($errMsg) ?></div>

    <div class="pin-pad" <?= $lockedFor > 0 ? 'style="opacity:0.35; pointer-events:none;"' : '' ?>>
      <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
        <button type="button" class="pin-key" data-digit="<?= $n ?>"><?= $n ?></button>
      <?php endforeach; ?>
      <button type="button" class="pin-key icon" data-action="clear">Clear</button>
      <button type="button" class="pin-key" data-digit="0">0</button>
      <button type="button" class="pin-key icon" data-action="backspace">⌫</button>
    </div>

    <?php if ($hasBio): ?>
      <button type="button" id="bio-unlock" class="pin-key" style="grid-column: unset; margin-top: 12px; width:100%; font-size: 15px; background:#25D366; color:#fff; border-color:#25D366;">
        👆 Use Face ID / Touch ID
      </button>
    <?php endif; ?>

    <div class="pin-alt">
      <?= $hasPin ? 'Forgot PIN? ' : '' ?><a href="/logout.php?next=/login.php">Sign in with password</a>
    </div>
  </form>

  <?php if ($hasBio): ?>
    <script src="/assets/js/webauthn.js"></script>
    <script>
    (function () {
        var btn = document.getElementById('bio-unlock');
        var err = document.getElementById('pin-err');
        if (!btn) return;
        if (!window.PublicKeyCredential) { btn.style.display = 'none'; return; }
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            btn.textContent = 'Waiting for biometric…';
            var r = await waAuthenticate();
            if (r.ok) {
                // Same mobile-vs-desktop routing as the password path so
                // biometric unlock on a phone drops straight into the
                // inbox rather than the manager dashboard.
                window.location.href = <?= json_encode(post_login_landing($next)) ?>;
            } else {
                err.textContent = r.error || 'Biometric unlock failed';
                btn.disabled = false;
                btn.textContent = '👆 Try biometric again';
            }
        });
        // Auto-prompt on load for the WhatsApp-style "just open the app" feel.
        // Small delay so the page renders first.
        setTimeout(function () { btn.click(); }, 250);
    })();
    </script>
  <?php endif; ?>

<script>
(function () {
  var digits = '';
  var dots = document.querySelectorAll('#pin-dots .pin-dot');
  var input = document.getElementById('pin-value');
  var form  = document.getElementById('pin-form');
  var err   = document.getElementById('pin-err');

  function render() {
    dots.forEach(function (d, i) {
      d.classList.toggle('on', i < digits.length);
    });
    input.value = digits;
  }
  function submitIfFull() {
    if (digits.length === 6) {
      // Small delay so the 6th dot renders before the page navigates.
      setTimeout(function () { form.submit(); }, 80);
    }
  }
  document.querySelectorAll('.pin-key').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.dataset.action === 'clear')     { digits = ''; err.textContent = ''; render(); return; }
      if (btn.dataset.action === 'backspace') { digits = digits.slice(0, -1); err.textContent = ''; render(); return; }
      var d = btn.dataset.digit;
      if (d != null && digits.length < 6) { digits += d; err.textContent = ''; render(); submitIfFull(); }
    });
  });
  // Hardware keyboard support (nice on phone Bluetooth keyboards + desktop).
  document.addEventListener('keydown', function (e) {
    if (e.key >= '0' && e.key <= '9' && digits.length < 6) {
      digits += e.key; err.textContent = ''; render(); submitIfFull();
    } else if (e.key === 'Backspace') {
      digits = digits.slice(0, -1); render(); e.preventDefault();
    } else if (e.key === 'Enter' && digits.length === 6) {
      form.submit();
    }
  });
})();
</script>
</body>
</html>
