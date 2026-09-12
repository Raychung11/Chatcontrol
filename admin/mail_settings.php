<?php
/**
 * /admin/mail_settings.php — outbound email configuration.
 *
 * Sender identity (From address + name) — used by every AiServe
 * outbound: password reset, invoices, F&B staff notifications,
 * broadcast quota alerts, mail test.
 *
 * Optional SMTP relay. When smtp_host is set, /inc/email.php routes
 * every send through fsockopen SMTP AUTH LOGIN instead of PHP mail().
 * Empties the SMTP host to fall back to the local Postfix.
 *
 * Uses the shared platform_settings_helper pattern that /admin/legal.php
 * and /admin/pricing.php also use — same save + upsert + activity_log
 * flow.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/platform_settings_helper.php';
require_once __DIR__ . '/../inc/email.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
$db = aiserve_db();

$fields = [
    'mail_from_address' => [
        'label' => 'From email address',
        'type'  => 'email',
        'hint'  => 'Appears in the "From:" header of every outbound email. e.g. noreply@aiserve.my',
        'max'   => 200,
    ],
    'mail_from_name' => [
        'label' => 'From name',
        'type'  => 'text',
        'hint'  => 'The friendly name recipients see before your email address.',
        'max'   => 120,
    ],
    'smtp_host' => [
        'label'    => 'SMTP host',
        'type'     => 'text',
        'hint'     => 'Leave blank to fall back to PHP mail() on the local server. For Hostinger mailboxes: smtp.hostinger.com',
        'max'      => 200,
        'optional' => true,
    ],
    'smtp_port' => [
        'label' => 'SMTP port',
        'type'  => 'number',
        'hint'  => '465 for SSL (recommended), 587 for STARTTLS, 25 for plain (rarely used).',
        'step'  => '1',
        'optional' => true,
    ],
    'smtp_secure' => [
        'label' => 'Encryption',
        'type'  => 'select',
        'hint'  => 'Match this to the port: SSL for 465, TLS for 587, None only in test setups.',
        'options' => ['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None (insecure)'],
        'optional' => true,
    ],
    'smtp_user' => [
        'label' => 'SMTP username',
        'type'  => 'text',
        'hint'  => 'Usually the full mailbox address, e.g. noreply@aiserve.my.',
        'max'   => 200,
        'optional' => true,
    ],
    'smtp_pass' => [
        'label' => 'SMTP password',
        'type'  => 'password',
        'hint'  => 'The mailbox password. Leave blank to keep the current value unchanged.',
        'optional' => true,
    ],
];

$msg = '';
$err = '';
if (is_post()) {
    csrf_check();
    $r = platform_settings_save($db, $fields, 'mail_settings_updated',
        (int)$current_user['company_id'], (int)$current_user['id']);
    $msg = $r['msg'];
    $err = $r['err'];
}

$load = platform_settings_load($db);
$cur  = $load['rows'];
if ($load['err']) $err = $err ?: $load['err'];

// Default port 465 / secure ssl on first paint (helps the operator
// pick sensible values instead of blank).
if (!isset($cur['smtp_port']))   $cur['smtp_port']   = '465';
if (!isset($cur['smtp_secure'])) $cur['smtp_secure'] = 'ssl';

$backend = mail_smtp_configured() ? 'SMTP relay' : 'PHP mail() (local Postfix)';

layout_start($current_user, 'Mail settings', 'mail_settings');
?>
<div class="card" style="max-width:720px;">
    <h2>✉️ Mail settings</h2>
    <p class="muted small">
        These control every outbound email — password reset, invoices, F&amp;B staff notifications,
        broadcast quota alerts, and the mail test. Currently sending via
        <strong><?= e($backend) ?></strong>.
    </p>

    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?>

        <h3 style="margin: 4px 0 -4px;">Sender identity</h3>
        <?php foreach (['mail_from_address','mail_from_name'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <h3 style="margin: 12px 0 -4px;">SMTP relay <small class="muted">(optional — leave blank to use PHP mail())</small></h3>
        <?php foreach (['smtp_host','smtp_port','smtp_secure','smtp_user','smtp_pass'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <div>
            <button class="btn btn-primary" type="submit">Save mail settings</button>
            <a class="btn" href="/admin/mail_test.php">✉️ Send a test email →</a>
        </div>
    </form>
</div>

<div class="card" style="max-width:720px; background:#fefce8; border-color:#fef08a;">
    <h3 style="margin-top:0;">💡 Hostinger quick-start</h3>
    <p class="small" style="margin:0 0 8px;">Set up a mailbox first in <strong>hpanel → Emails</strong>, then paste:</p>
    <table class="data-table" style="font-size:12.5px;">
        <tr><th style="width:35%;">Host</th><td><code>smtp.hostinger.com</code></td></tr>
        <tr><th>Port</th><td><code>465</code> (SSL) — recommended</td></tr>
        <tr><th>Encryption</th><td>SSL</td></tr>
        <tr><th>Username</th><td>full mailbox address, e.g. <code>noreply@aiserve.my</code></td></tr>
        <tr><th>Password</th><td>the mailbox password from hpanel</td></tr>
    </table>
    <p class="small muted" style="margin-top:10px;">
        For deliverability, also add these DNS records in
        <strong>hpanel → Domains → DNS/Nameservers</strong>:
    </p>
    <pre style="font-size:11.5px; background:#fff; padding:8px 10px; border-radius:6px; border:1px solid #fde68a; overflow-x:auto;">TXT  @        v=spf1 include:_spf.mail.hostinger.com ~all
TXT  _dmarc   v=DMARC1; p=none; rua=mailto:you@aiserve.my</pre>
    <p class="small muted" style="margin-top:6px;">
        DKIM is auto-configured by Hostinger once the mailbox is created.
    </p>
</div>
<?php layout_end(); ?>
