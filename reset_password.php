<?php
/**
 * /reset_password.php?token=<raw>
 *
 * Verify the token → show password form → set new password →
 * mark token used → revoke all remember-me tokens (a compromised
 * account should boot every stored device) → redirect to login.
 */

require_once __DIR__ . '/inc/auth.php';

aiserve_start_session();
if (current_user()) redirect('/dashboard.php');

$raw = trim((string)($_GET['token'] ?? ''));
if ($raw === '' && is_post()) $raw = trim((string)($_POST['token'] ?? ''));

$tokenErr = '';
$saveErr  = '';
$saved    = false;
$user     = null;

if ($raw !== '') {
    $hash = hash('sha256', $raw);
    try {
        $db = aiserve_db();
        $s = $db->prepare(
            'SELECT prt.id AS token_id, prt.used_at, prt.expires_at, u.id, u.email, u.name
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = ? LIMIT 1'
        );
        $s->execute([$hash]);
        $row = $s->fetch();
        if (!$row) {
            $tokenErr = 'This link is invalid.';
        } elseif ($row['used_at']) {
            $tokenErr = 'This link has already been used. Request a new one.';
        } elseif (strtotime((string)$row['expires_at']) < time()) {
            $tokenErr = 'This link has expired. Request a new one.';
        } else {
            $user = $row;
        }
    } catch (Throwable $e) {
        $tokenErr = 'Server error checking your link — try again in a moment.';
    }
}

if ($user && is_post() && $tokenErr === '') {
    csrf_check();
    $p1 = (string)($_POST['password']         ?? '');
    $p2 = (string)($_POST['password_confirm'] ?? '');
    if (strlen($p1) < 8)          $saveErr = 'Password must be at least 8 characters.';
    elseif ($p1 !== $p2)          $saveErr = 'Passwords do not match.';
    else {
        try {
            $db->beginTransaction();
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([password_hash($p1, PASSWORD_BCRYPT), (int)$user['id']]);
            $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
               ->execute([(int)$user['token_id']]);
            // Optional but recommended: invalidate every remember-me
            // cookie the user has ever issued — if the reset was because
            // the account was compromised, all stored device sessions
            // should die. WebAuthn credentials are LEFT ALONE — the
            // legit owner may still want Face ID to work; they can
            // manually revoke in /admin/set_pin.php.
            remember_me_revoke_all((int)$user['id']);
            // Also clear any PIN lockout on this account.
            $db->prepare(
                'UPDATE users SET pin_failed_attempts = 0, pin_locked_until = NULL
                 WHERE id = ?'
            )->execute([(int)$user['id']]);
            $db->commit();
            log_activity((int)($user['company_id'] ?? 0), (int)$user['id'],
                'password_reset_completed', 'user', (int)$user['id']);
            $saved = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $saveErr = 'Could not save new password: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <span class="brand-dot" style="background:#25D366"></span>
        <span class="brand-text"><?= e(APP_NAME) ?></span>
    </div>

    <?php if ($saved): ?>
        <h1>✅ Password updated</h1>
        <div class="alert alert-success">
            Your new password is set. You've been signed out of every remembered device — sign in
            again from any of them.
        </div>
        <a class="btn btn-primary btn-block" href="/login.php">Sign in</a>
    <?php elseif ($tokenErr): ?>
        <h1>Reset link problem</h1>
        <div class="alert alert-error"><?= e($tokenErr) ?></div>
        <a class="btn btn-primary btn-block" href="/forgot_password.php">Request a new link</a>
    <?php elseif ($user): ?>
        <h1>Set a new password</h1>
        <p class="muted">For <strong><?= e((string)$user['email']) ?></strong></p>
        <?php if ($saveErr): ?><div class="alert alert-error"><?= e($saveErr) ?></div><?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($raw) ?>">
            <label for="password">New password <small class="muted">(min 8 chars)</small></label>
            <input type="password" id="password" name="password" required minlength="8"
                   autocomplete="new-password">
            <label for="password_confirm">Confirm new password</label>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
                   autocomplete="new-password">
            <button type="submit" class="btn btn-primary btn-block">Update password</button>
        </form>
    <?php else: ?>
        <h1>Reset password</h1>
        <div class="alert alert-error">Missing or invalid link. Request a new one:</div>
        <a class="btn btn-primary btn-block" href="/forgot_password.php">Send reset link</a>
    <?php endif; ?>
</div>
</body>
</html>
