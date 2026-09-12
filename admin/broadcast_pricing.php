<?php
/**
 * /admin/broadcast_pricing.php — broadcast tier pricing controls.
 *
 * Every field here is read by inc/helpers.php::broadcast_quota_for_
 * workspace() so changes take effect immediately across every
 * workspace's Plan page, monthly cost estimate, and the invoice
 * cron that reads unit_price from the same helper.
 *
 * Payment instructions field is the yellow box that appears on both
 * the customer's /admin/plan.php after upgrade AND at the bottom of
 * every invoice (inc/invoicing.php reads the same key).
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/platform_settings_helper.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
$db = aiserve_db();

$fields = [
    'broadcast_free_limit' => [
        'label' => 'Free-tier recipient cap / month',
        'type'  => 'number',
        'hint'  => 'Every workspace on Free can send up to this many recipients per calendar month before the send button locks.',
    ],
    'broadcast_paid_limit' => [
        'label' => 'Paid-tier recipient cap / month',
        'type'  => 'number',
        'hint'  => 'Paid workspaces get this many recipients per calendar month.',
    ],
    'broadcast_paid_price' => [
        'label' => 'Paid-tier monthly price',
        'type'  => 'number',
        'step'  => '0.01',
        'hint'  => 'Monthly price in your platform currency (see /admin/pricing.php).',
    ],
    'broadcast_yearly_discount_pct' => [
        'label' => 'Yearly billing discount %',
        'type'  => 'number',
        'step'  => '1',
        'hint'  => '0-100. Applied to the paid price when the workspace picks yearly billing (e.g. 20 = 20% off).',
    ],
    'broadcast_payg_per_recipient' => [
        'label' => 'PAYG rate per recipient',
        'type'  => 'number',
        'step'  => '0.01',
        'hint'  => 'Charged per recipient sent for PAYG workspaces. Kept per-recipient so quantity is obvious on invoices.',
    ],
    'broadcast_payment_instructions' => [
        'label' => 'Payment instructions',
        'type'  => 'textarea',
        'rows'  => 6,
        'hint'  => 'Shown in the yellow box on the customer\'s Plan page after upgrade AND at the bottom of every invoice. Include your bank details, e-wallet, or "invoice will follow" wording.',
        'optional' => true,
    ],
];

$msg = '';
$err = '';
if (is_post()) {
    csrf_check();
    $r = platform_settings_save($db, $fields, 'broadcast_pricing_updated',
        (int)$current_user['company_id'], (int)$current_user['id']);
    $msg = $r['msg'];
    $err = $r['err'];
}

$load = platform_settings_load($db);
$cur  = $load['rows'];
if ($load['err']) $err = $err ?: $load['err'];

// Sensible defaults on first paint so the operator sees baseline values
// even before any prior config exists.
$defaults = [
    'broadcast_free_limit'          => '1000',
    'broadcast_paid_limit'          => '10000',
    'broadcast_paid_price'          => '480',
    'broadcast_yearly_discount_pct' => '20',
    'broadcast_payg_per_recipient'  => '0.05',
];
foreach ($defaults as $k => $v) if (!isset($cur[$k])) $cur[$k] = $v;

// Live preview of what a customer would see.
$currency  = platform_setting('pricing_currency', 'RM');
$free      = (int)$cur['broadcast_free_limit'];
$paid      = (int)$cur['broadcast_paid_limit'];
$monthly   = (float)$cur['broadcast_paid_price'];
$discount  = (int)$cur['broadcast_yearly_discount_pct'];
$yearly    = $monthly * 12 * (1 - $discount / 100);
$paygRate  = (float)$cur['broadcast_payg_per_recipient'];

layout_start($current_user, 'Broadcast pricing', 'broadcast_pricing');
?>
<div class="card" style="max-width:720px;">
    <h2>📣 Broadcast pricing</h2>
    <p class="muted small">
        Controls the tiers customers see on <a href="/admin/plan.php">/admin/plan.php</a>
        + the amounts on the invoices your cron auto-generates. Currency comes from
        <a href="/admin/pricing.php">Pricing</a> (currently <strong><?= e($currency) ?></strong>).
    </p>

    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?>

        <h3 style="margin: 4px 0 -4px;">Tier limits</h3>
        <?php foreach (['broadcast_free_limit','broadcast_paid_limit'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <h3 style="margin: 12px 0 -4px;">Prices</h3>
        <?php foreach (['broadcast_paid_price','broadcast_yearly_discount_pct','broadcast_payg_per_recipient'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <h3 style="margin: 12px 0 -4px;">Payment instructions</h3>
        <?php render_platform_setting_field('broadcast_payment_instructions', $fields['broadcast_payment_instructions'],
              (string)($cur['broadcast_payment_instructions'] ?? '')); ?>

        <div>
            <button class="btn btn-primary" type="submit">Save broadcast pricing</button>
        </div>
    </form>
</div>

<!-- Live preview of what the customer sees. -->
<div class="card" style="max-width:720px; background:#f0fdf4; border-color:#bbf7d0;">
    <h3 style="margin-top:0;">👀 Customer view preview</h3>
    <p class="muted small" style="margin-bottom:12px;">
        This is roughly what the tier cards look like on the customer's Plan page
        with the current values.
    </p>
    <div style="display:grid; gap:10px; grid-template-columns: repeat(3, 1fr);">
        <div style="background:#fff; border:1px solid #e3e8ee; border-radius:8px; padding:12px;">
            <div style="font-weight:700;">Free</div>
            <div style="font-size:22px; font-weight:700; margin:6px 0;"><?= e($currency) ?> 0<small style="font-size:11px; font-weight:400;">/mo</small></div>
            <div class="muted small"><?= number_format($free) ?> recipients / month</div>
        </div>
        <div style="background:#fff; border:2px solid #0072B2; border-radius:8px; padding:12px;">
            <div style="font-weight:700;">Paid <span style="background:#dbeafe; color:#1e3a8a; padding:1px 6px; border-radius:999px; font-size:10px;">recommended</span></div>
            <div style="font-size:22px; font-weight:700; margin:6px 0;"><?= e($currency) ?> <?= number_format($monthly, 0) ?><small style="font-size:11px; font-weight:400;">/mo</small></div>
            <div class="muted small"><?= number_format($paid) ?> recipients / month<br>
                Yearly: <?= e($currency) ?> <?= number_format($yearly / 12, 0) ?>/mo (<?= (int)$discount ?>% off)
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e3e8ee; border-radius:8px; padding:12px;">
            <div style="font-weight:700;">PAYG</div>
            <div style="font-size:22px; font-weight:700; margin:6px 0;"><?= e($currency) ?> <?= number_format($paygRate, 2) ?><small style="font-size:11px; font-weight:400;">/recipient</small></div>
            <div class="muted small">Unlimited · no monthly cap</div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
