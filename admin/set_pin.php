<?php
/**
 * /admin/set_pin.php — user self-serve PIN management.
 *
 * Set new PIN → confirms with password
 * Change PIN  → asks current PIN + new PIN twice
 * Remove PIN  → asks password
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_login();
$db = aiserve_db();

$msg = '';
$err = '';
$hasPin = pin_user_has_pin((int)$current_user['id']);

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // For every action, verify current password.
    $pass = (string)($_POST['password'] ?? '');
    $s = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $s->execute([(int)$current_user['id']]);
    $hash = (string)($s->fetchColumn() ?: '');
    if (!password_verify($pass, $hash)) {
        $err = 'Password is wrong.';
    } elseif ($action === 'set') {
        $pin  = trim((string)($_POST['pin']         ?? ''));
        $conf = trim((string)($_POST['pin_confirm'] ?? ''));
        if (!pin_is_valid_shape($pin))           $err = 'PIN must be exactly 6 digits.';
        elseif ($pin !== $conf)                  $err = 'PIN and confirmation do not match.';
        elseif (in_array($pin, ['000000','111111','123456','654321','121212'], true))
                                                 $err = 'Pick a less obvious PIN.';
        else {
            if (pin_user_set((int)$current_user['id'], $pin)) {
                $msg = $hasPin ? 'PIN updated.' : 'PIN set. Next time you re-open the app, you\'ll be asked for it.';
                $hasPin = true;
                pin_mark_verified();
                log_activity((int)$current_user['company_id'], (int)$current_user['id'],
                    'pin_set', 'user', (int)$current_user['id']);
            } else {
                $err = 'Could not save PIN.';
            }
        }
    } elseif ($action === 'remove') {
        pin_user_clear((int)$current_user['id']);
        $hasPin = false;
        $msg = 'PIN removed. You\'ll be signed straight in on remembered devices from now on.';
        log_activity((int)$current_user['company_id'], (int)$current_user['id'],
            'pin_removed', 'user', (int)$current_user['id']);
    }
}

layout_start($current_user, 'PIN quick unlock', 'set_pin');
?>
<div class="card" style="max-width:520px;">
  <h2>🔒 PIN quick unlock</h2>
  <p class="muted small">
    Set a 6-digit PIN and next time you open the app on this device you'll just enter the
    PIN instead of your full password. Great for phones — like WhatsApp's screen lock.
    You still need your password on new devices and after 5 wrong PIN attempts.
  </p>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <?php if ($hasPin): ?>
    <div class="alert alert-info" style="margin-bottom:14px;">
      ✅ A PIN is currently set for your account.
      To change it, fill the fields below. To remove it entirely, use the button at the bottom.
    </div>
  <?php endif; ?>

  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="set">
    <label>Your password <small class="muted">(to confirm)</small>
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <label>New PIN <small class="muted">(6 digits)</small>
      <input type="password" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6"
             minlength="6" required autocomplete="new-password">
    </label>
    <label>Confirm new PIN
      <input type="password" name="pin_confirm" inputmode="numeric" pattern="\d{6}" maxlength="6"
             minlength="6" required autocomplete="new-password">
    </label>
    <div>
      <button class="btn btn-primary" type="submit"><?= $hasPin ? 'Change PIN' : 'Set PIN' ?></button>
    </div>
  </form>

  <?php if ($hasPin): ?>
    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e3e8ee;">
    <form method="post" onsubmit="return confirm('Remove your PIN? You will be signed straight in on remembered devices without any PIN prompt.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove">
      <label>Password <small class="muted">(to confirm removal)</small>
        <input type="password" name="password" autocomplete="current-password" required
               style="max-width:260px;">
      </label>
      <button class="btn btn-danger btn-sm" type="submit" style="margin-top:8px;">
        Remove PIN
      </button>
    </form>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
