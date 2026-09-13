<?php
/**
 * /admin/business_info.php — operator business identity.
 *
 * Consolidates the fields that appear on invoices AND in the T&C /
 * Privacy pages under one form. Historically split between
 * legal.php (T&C-flavoured) and invoicing.php reads (invoice-
 * flavoured) using slightly different key names. This page writes to
 * BOTH sets of keys so both consumers keep working with zero code
 * changes elsewhere.
 *
 * Consumers of these settings:
 *   inc/invoicing.php   — invoice header (from block)
 *   admin/legal.php     — T&C / Privacy page text
 *   terms.php + privacy.php + disclaimer.php — public legal pages
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/platform_settings_helper.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
$db = aiserve_db();

// Field spec. Some keys duplicate historical values — legal.php uses
// `operator_registration_no` and `operator_legal_name` while
// invoicing.php reads `operator_reg_no` and `operator_business_name`.
// We render ONE input per concept and sync-write to both keys on save
// so both consumers see the same value with no schema change.
$fields = [
    'operator_legal_name' => [
        'label' => 'Legal entity name',
        'type'  => 'text',
        'hint'  => "The registered legal name (e.g. \"AiServe Sdn Bhd\"). Shown on invoices + T&C.",
        'max'   => 200,
    ],
    'operator_business_name' => [
        'label' => 'Trading / brand name',
        'type'  => 'text',
        'hint'  => "The name customers know you as (e.g. \"AiServe\"). Falls back to Legal name if blank.",
        'max'   => 200,
        'optional' => true,
    ],
    'operator_registration_no' => [
        'label' => 'Business registration no.',
        'type'  => 'text',
        'hint'  => "SSM number, ROC number, etc. e.g. 202401234567 (1234567-X)",
        'max'   => 100,
        'optional' => true,
    ],
    'operator_tax_id' => [
        'label' => 'Tax ID (SST / GST no.)',
        'type'  => 'text',
        'hint'  => 'Malaysian SST registration number, or equivalent. Shown on invoices.',
        'max'   => 100,
        'optional' => true,
    ],
    'operator_address' => [
        'label' => 'Registered address',
        'type'  => 'textarea',
        'hint'  => 'Full postal address. Shown on invoices and in T&C parties block.',
    ],
    'operator_email' => [
        'label' => 'Contact email',
        'type'  => 'email',
        'hint'  => 'The email customers reply to about billing / support. Shown on invoices.',
        'max'   => 200,
    ],
    'operator_phone' => [
        'label' => 'Contact phone',
        'type'  => 'text',
        'hint'  => 'Best contact number. Shown on invoices.',
        'max'   => 60,
        'optional' => true,
    ],
    'operator_jurisdiction' => [
        'label' => 'Legal jurisdiction',
        'type'  => 'text',
        'hint'  => 'The country / state whose laws govern your T&C (e.g. "Malaysia").',
        'max'   => 120,
        'optional' => true,
    ],
    'operator_courts' => [
        'label' => 'Courts',
        'type'  => 'text',
        'hint'  => 'The courts named in your T&C dispute clause (e.g. "Courts of Kuala Lumpur").',
        'max'   => 200,
        'optional' => true,
    ],
];

$msg = '';
$err = '';
if (is_post()) {
    csrf_check();
    $r = platform_settings_save($db, $fields, 'business_info_updated',
        (int)$current_user['company_id'], (int)$current_user['id']);
    $msg = $r['msg'];
    $err = $r['err'];

    // Sync-write historical duplicate keys so old consumers still find
    // their expected key names. Best-effort — never blocks the save.
    if ($msg) {
        try {
            $mirror = [
                'operator_reg_no'         => trim((string)($_POST['operator_registration_no'] ?? '')),
                'operator_contact_email'  => trim((string)($_POST['operator_email']           ?? '')),
            ];
            $up = $db->prepare(
                'INSERT INTO platform_settings (`key`,`value`) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
            foreach ($mirror as $k => $v) $up->execute([$k, $v]);
        } catch (Throwable $e) { /* ok */ }
    }
}

$load = platform_settings_load($db);
$cur  = $load['rows'];
if ($load['err']) $err = $err ?: $load['err'];

// If the trading name is blank, mirror in the legal name as the
// default placeholder text so the operator sees what invoices will use.
if (empty($cur['operator_business_name']) && !empty($cur['operator_legal_name'])) {
    $cur['operator_business_name'] = '';   // keep the field empty (optional), but hint shows the fallback
}

layout_start($current_user, 'Business info', 'business_info');
?>
<div class="card" style="max-width:720px;">
    <h2>🏢 Business info</h2>
    <p class="muted small">
        Your business identity. These values appear on <strong>invoices</strong> your customers
        receive, in the public <strong>Terms &amp; Privacy</strong> pages, and in <strong>legal
        notices</strong> across the app. Change once here — every consumer refreshes on the
        next page load.
    </p>

    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?>

        <h3 style="margin: 4px 0 -4px;">Identity</h3>
        <?php foreach (['operator_legal_name','operator_business_name','operator_registration_no','operator_tax_id'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <h3 style="margin: 12px 0 -4px;">Contact</h3>
        <?php foreach (['operator_address','operator_email','operator_phone'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <h3 style="margin: 12px 0 -4px;">Legal <small class="muted">(T&amp;C dispute clause)</small></h3>
        <?php foreach (['operator_jurisdiction','operator_courts'] as $k):
            render_platform_setting_field($k, $fields[$k], (string)($cur[$k] ?? ''));
        endforeach; ?>

        <div>
            <button class="btn btn-primary" type="submit">Save business info</button>
            <a class="btn" href="/admin/legal.php">Advanced legal text (T&amp;C) →</a>
        </div>
    </form>
</div>
<?php layout_end(); ?>
