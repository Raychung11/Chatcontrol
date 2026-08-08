<?php
/**
 * /admin/mail_test.php — send a test email via whatever backend is
 * currently configured (SMTP relay or PHP mail()).
 *
 * Shows every relevant platform_settings value with the SMTP password
 * masked, so operators can see at a glance what's configured. Any
 * failure is surfaced with the last log line for fast triage.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/email.php';

$current_user = require_login();
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}

$sent = false;
$sentTo = '';
$err = '';

if (is_post()) {
    csrf_check();
    $sentTo = trim((string)($_POST['to'] ?? ''));
    if (!filter_var($sentTo, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid email address.';
    } else {
        $subject = 'AiServe mail test · ' . date('H:i:s');
        $html    = '<h2 style="color:#25D366;">✅ It works</h2>'
                 . '<p>This is a test message from your AiServe install.</p>'
                 . '<p>Sent at <strong>' . date('r') . '</strong> from <code>'
                 . htmlspecialchars(($_SERVER['HTTP_HOST'] ?? 'localhost')) . '</code>.</p>';
        $sent = send_email_html($sentTo, $subject, $html);
        if (!$sent) {
            $err = 'Send failed. Check /var/log/nginx/aiserve.error.log for the SMTP handshake output.';
        }
    }
}

$cfg = [
    'mail_from_address' => platform_setting('mail_from_address', '(unset)'),
    'mail_from_name'    => platform_setting('mail_from_name',    '(unset)'),
    'smtp_host'         => platform_setting('smtp_host',         '(unset — using PHP mail())'),
    'smtp_port'         => platform_setting('smtp_port',         '(unset)'),
    'smtp_secure'       => platform_setting('smtp_secure',       '(unset)'),
    'smtp_user'         => platform_setting('smtp_user',         '(unset)'),
    'smtp_pass'         => platform_setting('smtp_pass',         '')
                            ? str_repeat('•', 8) . ' (' . strlen((string)platform_setting('smtp_pass', '')) . ' chars)'
                            : '(unset)',
];
$backend = mail_smtp_configured() ? 'SMTP relay' : 'PHP mail() (local Postfix)';

layout_start($current_user, 'Mail test', 'mail_test');
?>
<div class="card" style="max-width:640px;">
    <h2>✉️ Mail test</h2>
    <p class="muted small">
        Sends a test HTML email through whichever backend is configured. Uses the same
        <code>send_email_html()</code> as password reset, invoices, and F&amp;B notifications.
    </p>

    <?php if ($sent): ?>
        <div class="alert alert-success">
            ✅ Sent to <strong><?= e($sentTo) ?></strong> via <strong><?= e($backend) ?></strong>.
            <br><small>Check the inbox (and spam) — should arrive within 30 seconds.</small>
        </div>
    <?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <label>Send test email to
            <input type="email" name="to" required autocomplete="email"
                   placeholder="you@example.com"
                   value="<?= e($sentTo ?: (string)($current_user['email'] ?? '')) ?>">
        </label>
        <button class="btn btn-primary" type="submit">Send test</button>
    </form>
</div>

<div class="card" style="max-width:640px;">
    <h3 style="margin-top:0;">Current mail configuration</h3>
    <p class="muted small">Backend in use: <strong><?= e($backend) ?></strong></p>
    <table class="data-table" style="font-size:13px;">
        <?php foreach ($cfg as $k => $v): ?>
            <tr><th style="width:40%;"><code><?= e($k) ?></code></th><td><?= e((string)$v) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <p class="muted small" style="margin-top:12px;">
        Edit any of these in Adminer → <code>platform_settings</code> table, or via SQL.
    </p>
</div>
<?php layout_end(); ?>
