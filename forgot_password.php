<?php
/**
 * /forgot_password.php — request a password reset email.
 *
 * Always shows the same "if that email exists, you'll receive a link
 * shortly" success message — never confirms or denies whether the
 * email is registered. Prevents account enumeration.
 *
 * Rate limits: max 5 requests per email + IP in 1h. Tokens are
 * one-shot with 1h expiry (see reset_password.php).
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/email.php';

aiserve_start_session();
if (current_user()) redirect('/dashboard.php');

const PWR_TOKEN_TTL_S    = 3600;      // 1 hour
const PWR_MAX_PER_HOUR   = 5;
const PWR_FROM_NAME_FMT  = '%s Support'; // "AiServe Support"

$submitted = false;
$err       = '';

if (is_post()) {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } else {
        $submitted = true;   // ALWAYS mark as submitted regardless of outcome
        pwr_request($email);
    }
}

function pwr_request(string $email): void
{
    $db = aiserve_db();
    // 1. Rate limit — same email + same IP: max 5 requests per hour.
    try {
        $ip = client_ip();
        $rl = $db->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE u.email = ? AND prt.ip_address = ? AND prt.created_at > NOW() - INTERVAL 1 HOUR'
        );
        $rl->execute([$email, $ip]);
        if ((int)$rl->fetchColumn() >= PWR_MAX_PER_HOUR) return;   // silent no-op
    } catch (Throwable $e) { return; }

    // 2. Look up the user. Never leak whether they exist.
    try {
        $s = $db->prepare('SELECT id, name, email FROM users WHERE email = ? AND status = "active" LIMIT 1');
        $s->execute([$email]);
        $user = $s->fetch();
        if (!$user) return;
    } catch (Throwable $e) { return; }

    // 3. Mint + persist token.
    $raw     = bin2hex(random_bytes(32));   // 64 hex chars
    $hash    = hash('sha256', $raw);
    $expires = date('Y-m-d H:i:s', time() + PWR_TOKEN_TTL_S);
    try {
        $db->prepare(
            'INSERT INTO password_reset_tokens
                (user_id, token_hash, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int)$user['id'], $hash,
            mb_substr((string)client_ip(), 0, 64),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $expires,
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe pwr_request] persist: ' . $e->getMessage());
        return;
    }

    // 4. Email the link (best-effort — mail() failure is silent to caller).
    $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
    $link    = $base . '/reset_password.php?token=' . urlencode($raw);
    $subject = 'Reset your ' . APP_NAME . ' password';
    $ipShow  = htmlspecialchars((string)client_ip());
    $safeName= htmlspecialchars((string)($user['name'] ?? 'there'));

    $html = <<<HTML
<!doctype html><html><body style="font-family:-apple-system,system-ui,sans-serif;color:#0f172a;padding:24px;">
<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #e3e8ee;border-radius:10px;padding:28px 30px;">
    <h2 style="margin-top:0;color:#25D366;">🔑 Password reset</h2>
    <p>Hi $safeName,</p>
    <p>We got a request to reset the password for your account. Click the button below to choose a new one — the link is good for <strong>1 hour</strong>.</p>
    <p style="text-align:center;margin:26px 0;">
        <a href="$link"
           style="display:inline-block;background:#25D366;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600;">
            Reset my password
        </a>
    </p>
    <p style="font-size:12.5px;color:#64748b;">Or paste this link into your browser:<br>
       <code style="background:#f6f9fb;padding:6px 10px;border-radius:4px;display:inline-block;margin-top:4px;font-size:11.5px;word-break:break-all;">$link</code>
    </p>
    <hr style="border:none;border-top:1px solid #eef2f7;margin:26px 0;">
    <p style="font-size:12px;color:#94a3b8;line-height:1.5;">
        If you didn't request this, you can safely ignore this email — your password stays the same.
        <br>Request came from IP <code>$ipShow</code>.
    </p>
</div>
</body></html>
HTML;

    send_email_html((string)$user['email'], $subject, $html,
                    sprintf(PWR_FROM_NAME_FMT, APP_NAME));
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <span class="brand-dot" style="background:#25D366"></span>
        <span class="brand-text"><?= e(APP_NAME) ?></span>
    </div>
    <h1>Forgot password</h1>
    <?php if ($submitted): ?>
        <div class="alert alert-success" style="margin-top:12px;">
            ✅ If that email is on our system, you'll receive a reset link within a minute.
            <br><small>Check your spam folder if it doesn't show up.</small>
        </div>
        <p class="muted small" style="text-align:center; margin-top:16px;">
            <a href="/login.php">← Back to sign in</a>
        </p>
    <?php else: ?>
        <p class="muted">Enter the email you sign in with — we'll send a link to set a new password.</p>
        <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   value="<?= e((string)($_POST['email'] ?? '')) ?>">
            <button type="submit" class="btn btn-primary btn-block">Send reset link</button>
        </form>
        <p class="muted small" style="text-align:center; margin-top:12px;">
            Remembered it? <a href="/login.php">Sign in →</a>
        </p>
    <?php endif; ?>
</div>
</body>
</html>
