<?php
require_once __DIR__ . '/inc/auth.php';

aiserve_start_session();

// Already logged in -> bounce to dashboard
if (current_user()) {
    redirect('/dashboard.php');
}

$error = '';
$email = '';
$next  = $_GET['next'] ?? '/dashboard.php';
if (!is_string($next) || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/dashboard.php';
}

if (is_post()) {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $ip    = client_ip();

    if ($email === '' || $pass === '') {
        $error = 'Please enter your email and password.';
    } elseif (login_is_rate_limited($email, $ip)) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $stmt = aiserve_db()->prepare(
            'SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            login_record_attempt($email, $ip, true);
            login_user($user);
            redirect($next);
        }

        login_record_attempt($email, $ip, false);
        $error = 'Invalid email or password.';
    }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
  <div class="login-card">
    <div class="login-brand">
      <span class="brand-dot" style="background:#25D366"></span>
      <span class="brand-text">AiServe Inbox</span>
    </div>
    <h1>Sign in</h1>
    <p class="muted">Shared WhatsApp Inbox Portal</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required>

      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>

      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="muted small">
      New here? <a href="/register.php">Create a workspace</a>.
    </p>
    <p class="muted small" style="text-align:center; margin-top: 8px;">
      <a href="/terms.php">Terms</a> ·
      <a href="/privacy.php">Privacy</a> ·
      <a href="/disclaimer.php">Disclaimer</a>
    </p>
  </div>
</body>
</html>
