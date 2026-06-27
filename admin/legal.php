<?php
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/platform_settings_helper.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Forbidden — platform administrator only.');
}

$db  = aiserve_db();
$msg = '';
$err = '';

$fields = [
    // ---- Operator legal details ----
    'operator_legal_name' => [
        'label' => 'Operator legal name',
        'type'  => 'text',
        'hint'  => 'Your registered company name. Appears in Terms, Privacy, Refund Policy, and footers. Leave blank to use the brand name only.',
        'max'   => 190,
        'optional' => true,
    ],
    'operator_registration_no' => [
        'label' => 'Registration number',
        'type'  => 'text',
        'hint'  => 'SSM company number or similar. Shown after the legal name on the legal pages.',
        'max'   => 64,
        'optional' => true,
    ],
    'operator_address' => [
        'label' => 'Business address',
        'type'  => 'textarea',
        'hint'  => 'Shown in Terms s.15 and Privacy s.12 contact blocks.',
        'optional' => true,
    ],
    'operator_email' => [
        'label' => 'Support email',
        'type'  => 'text',
        'hint'  => 'Used for refund requests and data-subject access requests on the legal pages.',
        'max'   => 190,
        'optional' => true,
    ],
    'operator_jurisdiction' => [
        'label' => 'Governing-law jurisdiction',
        'type'  => 'text',
        'hint'  => 'e.g. "Malaysia". Shown in Terms s.13.',
        'max'   => 64,
    ],
    'operator_courts' => [
        'label' => 'Forum courts',
        'type'  => 'text',
        'hint'  => 'e.g. "the courts of Kuala Lumpur, Malaysia". Shown in Terms s.13.',
        'max'   => 190,
    ],
    // ---- Editable legal-page dates ----
    'legal_terms_updated' => [
        'label' => 'Terms: Last updated date',
        'type'  => 'text',
        'hint'  => 'Free text date shown at the top of /terms.php. Bump when you edit the page so customers see the change.',
        'max'   => 32,
    ],
    'legal_privacy_updated' => [
        'label' => 'Privacy: Last updated date',
        'type'  => 'text',
        'hint'  => 'Free text date shown at the top of /privacy.php.',
        'max'   => 32,
    ],
    'legal_disclaimer_updated' => [
        'label' => 'Disclaimer: Last updated date',
        'type'  => 'text',
        'hint'  => 'Free text date shown at the top of /disclaimer.php.',
        'max'   => 32,
    ],
    'legal_refund_updated' => [
        'label' => 'Refund Policy: Last updated date',
        'type'  => 'text',
        'hint'  => 'Free text date shown at the top of /refund.php.',
        'max'   => 32,
    ],
    // ---- Refund policy ----
    'refund_window_days' => [
        'label' => 'Refund cooling-off window (days)',
        'type'  => 'number',
        'hint'  => 'Number of days a customer can get a full refund on their first paid invoice if they have not connected a live WhatsApp number.',
        'step'  => '1',
    ],
    'refund_policy_extra' => [
        'label' => 'Refund policy: extra terms',
        'type'  => 'textarea',
        'hint'  => 'Optional extra paragraph rendered after section 7 on /refund.php. Leave blank to hide.',
        'optional' => true,
    ],
];

if (is_post()) {
    csrf_check();
    $r = platform_settings_save(
        $db, $fields, 'platform_legal_updated',
        (int)$current_user['company_id'], (int)$current_user['id']
    );
    $msg = $r['msg'];
    $err = $r['err'];
}

$loaded  = platform_settings_load($db);
$current = $loaded['rows'];
if ($loaded['err'] && !$err) $err = $loaded['err'];

layout_start($current_user, 'Legal & operator', 'legal');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <p class="muted small">
    Operator details (legal name, SSM registration, address, email) appear in
    <a href="/terms.php" target="_blank">/terms.php</a>,
    <a href="/privacy.php" target="_blank">/privacy.php</a>,
    <a href="/refund.php" target="_blank">/refund.php</a>, and the legal-page
    footers. Leave operator name blank to keep the brand-only fallback.
    Last-updated dates are shown verbatim — bump them when you change the page
    content so customers see the version they're agreeing to.
  </p>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <h2 style="grid-column:1/-1;">Operator details</h2>
    <?php
      $opKeys = ['operator_legal_name','operator_registration_no','operator_address',
                 'operator_email','operator_jurisdiction','operator_courts'];
      foreach ($opKeys as $k) {
          render_platform_setting_field($k, $fields[$k], $current[$k] ?? '');
      }
    ?>

    <h2 style="grid-column:1/-1;">Last updated dates</h2>
    <?php
      $dateKeys = ['legal_terms_updated','legal_privacy_updated',
                   'legal_disclaimer_updated','legal_refund_updated'];
      foreach ($dateKeys as $k) {
          render_platform_setting_field($k, $fields[$k], $current[$k] ?? '');
      }
    ?>

    <h2 style="grid-column:1/-1;">Refund policy</h2>
    <?php
      $refKeys = ['refund_window_days','refund_policy_extra'];
      foreach ($refKeys as $k) {
          render_platform_setting_field($k, $fields[$k], $current[$k] ?? '');
      }
    ?>

    <button class="btn btn-primary" type="submit">Save legal &amp; operator</button>
  </form>
</div>

<?php
// Mini preview of how the operator block renders on Terms s.15 / Privacy s.12 /
// Refund footer so you can confirm what customers see.
$entity = trim((string)($current['operator_legal_name']      ?? ''));
$reg    = trim((string)($current['operator_registration_no'] ?? ''));
$addr   = trim((string)($current['operator_address']         ?? ''));
$mail   = trim((string)($current['operator_email']           ?? ''));
?>
<div class="card">
  <h2>Live preview — contact block</h2>
  <p class="muted small">This is what appears under "Contact" in Terms and Privacy, and in the Refund Policy.</p>
  <ul style="margin: 4px 0 0 18px;">
    <li><strong><?= e($entity !== '' ? $entity : APP_NAME) ?></strong>
        <?= $reg !== '' ? '(' . e($reg) . ')' : '' ?></li>
    <?php if ($addr !== ''): ?><li><?= nl2br(e($addr)) ?></li><?php endif; ?>
    <?php if ($mail !== ''): ?><li>Email: <a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a></li><?php endif; ?>
  </ul>
  <?php if ($entity === '' && $addr === '' && $mail === ''): ?>
    <p class="muted small" style="margin-top:8px;">
      No operator details filled in — Terms/Privacy/Refund fall back to the brand
      name (<?= e(APP_NAME) ?>) only. Fill at least the legal name and email so
      customers know who to contact for refunds and data-subject requests.
    </p>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
