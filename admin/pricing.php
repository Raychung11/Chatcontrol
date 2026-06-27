<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Forbidden — platform administrator only.');
}

$db  = aiserve_db();
$msg = '';
$err = '';

// Editable keys + their human label + input type. Keep this list aligned with
// the seeded rows in sql/migration_phase14.sql.
$fields = [
    'pricing_currency' => [
        'label' => 'Currency symbol',
        'type'  => 'text',
        'hint'  => 'Shown before every amount, e.g. "RM", "$", "SGD".',
        'max'   => 8,
    ],
    'pricing_period_label' => [
        'label' => 'Billing period label',
        'type'  => 'text',
        'hint'  => 'Shown after every amount, e.g. "/ month", "/ year".',
        'max'   => 32,
    ],
    'pricing_per_seat' => [
        'label' => 'Per-seat price',
        'type'  => 'number',
        'hint'  => 'Standard rate per individual seat. Drives Starter total + Enterprise per-extra-seat default.',
        'step'  => '0.01',
    ],
    'pricing_starter_seats' => [
        'label' => 'Starter plan: included seats',
        'type'  => 'number',
        'hint'  => 'Starter plan covers up to this many seats.',
        'step'  => '1',
    ],
    'pricing_bundle_seats' => [
        'label' => 'Growth bundle: included seats',
        'type'  => 'number',
        'hint'  => 'Growth bundle covers up to this many seats. Enterprise starts here too.',
        'step'  => '1',
    ],
    'pricing_bundle_price' => [
        'label' => 'Growth bundle: flat price',
        'type'  => 'number',
        'hint'  => 'Flat price for the Growth bundle. Also the Enterprise base price.',
        'step'  => '0.01',
    ],
    'pricing_extra_seat_price' => [
        'label' => 'Enterprise: extra seat price',
        'type'  => 'number',
        'hint'  => 'Price per seat above the Growth bundle on the Enterprise plan.',
        'step'  => '0.01',
    ],
    'pricing_payment_methods' => [
        'label' => 'Payment methods (FAQ)',
        'type'  => 'textarea',
        'hint'  => 'Shown in the public pricing FAQ under "How do I pay?".',
    ],
    'pricing_footer_note' => [
        'label' => 'Plan change note (FAQ)',
        'type'  => 'textarea',
        'hint'  => 'Shown in the public pricing FAQ under "Can I change plans later?".',
    ],
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
    $values = [];
    foreach ($fields as $key => $meta) {
        $raw = trim((string)($_POST[$key] ?? ''));
        // Numeric fields: keep as a string but validate it parses as a non-negative number.
        if ($meta['type'] === 'number') {
            if ($raw === '' || !is_numeric($raw) || (float)$raw < 0) {
                $err = $meta['label'] . ' must be a non-negative number.';
                break;
            }
            // Drop trailing zeros so "12.00" gets stored as "12".
            $f = (float)$raw;
            $raw = (abs($f - round($f)) < 0.005) ? (string)(int)round($f) : (string)$f;
        }
        if ($raw === '' && in_array($meta['type'], ['text','number'], true) && empty($meta['optional'])) {
            $err = $meta['label'] . ' is required.';
            break;
        }
        $values[$key] = $raw;
    }

    if (!$err) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
            $db->beginTransaction();
            foreach ($values as $k => $v) {
                $stmt->execute([$k, $v]);
            }
            $db->commit();
            log_activity((int)$current_user['company_id'], (int)$current_user['id'],
                'platform_pricing_updated', 'platform', 0, '');
            $msg = 'Pricing saved. Public pricing page, signup, and landing page now reflect the new values.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AiServe] pricing save failed: ' . $e->getMessage());
            $err = 'Could not save pricing — check the migration is in (sql/migration_phase14.sql).';
        }
    }
}

// Re-read after any save so the preview reflects the new values.
$current = [];
try {
    $rows = $db->query('SELECT `key`, `value` FROM platform_settings')->fetchAll();
    foreach ($rows as $r) $current[$r['key']] = (string)$r['value'];
} catch (Throwable $e) {
    $err = $err ?: 'platform_settings table not found — run sql/migration_phase14.sql first.';
}

// Force pricing_get() to recompute by busting its static cache via a fresh require.
// In practice we just call it again - the static cache lives in the helper, so to
// preview live we read from $current directly below.

layout_start($current_user, 'Platform pricing', 'pricing');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <p class="muted small">
    These values drive <a href="/pricing.php" target="_blank">/pricing.php</a>,
    the plan radios on <a href="/register.php" target="_blank">/register.php</a>,
    and the plans block on the landing page. Changes are live immediately —
    no deploy needed.
  </p>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>

    <?php foreach ($fields as $key => $meta):
      $val = $current[$key] ?? '';
    ?>
      <?php $req = empty($meta['optional']) ? 'required' : ''; ?>
      <label>
        <?= e($meta['label']) ?>
        <?php if (!empty($meta['optional'])): ?><small class="muted">(optional)</small><?php endif; ?>
        <?php if ($meta['type'] === 'textarea'): ?>
          <textarea name="<?= e($key) ?>" rows="3"><?= e($val) ?></textarea>
        <?php elseif ($meta['type'] === 'number'): ?>
          <input type="number" name="<?= e($key) ?>" value="<?= e($val) ?>"
                 step="<?= e($meta['step'] ?? '1') ?>" min="0" <?= $req ?>>
        <?php else: ?>
          <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>"
                 <?= isset($meta['max']) ? 'maxlength="' . (int)$meta['max'] . '"' : '' ?> <?= $req ?>>
        <?php endif; ?>
        <small class="muted"><?= e($meta['hint']) ?></small>
      </label>
    <?php endforeach; ?>

    <button class="btn btn-primary" type="submit">Save pricing</button>
  </form>
</div>

<?php
// Live preview using the currently-saved values (not the just-edited form
// state - the preview reflects what customers see right now).
$currency = $current['pricing_currency']         ?? 'RM';
$period   = $current['pricing_period_label']     ?? '/ month';
$perSeat  = (float)($current['pricing_per_seat']         ?? 12);
$stSeats  = (int)  ($current['pricing_starter_seats']    ?? 3);
$bdSeats  = (int)  ($current['pricing_bundle_seats']     ?? 10);
$bdPrice  = (float)($current['pricing_bundle_price']     ?? 60);
$extra    = (float)($current['pricing_extra_seat_price'] ?? 12);

$starterPrice  = $perSeat * $stSeats;
$effPerSeatG   = $bdSeats > 0 ? $bdPrice / $bdSeats : 0;
$badge         = ($perSeat > 0 && $bdPrice < ($perSeat * $bdSeats))
                  ? 'save ' . (int)round((1 - ($bdPrice / max(0.01, $perSeat * $bdSeats))) * 100) . '%'
                  : '';

function fmt_p(float $a, string $c): string {
    $r = round($a, 2);
    return $c . ' ' . (abs($r - round($r)) < 0.005 ? number_format($r, 0) : number_format($r, 2));
}
?>
<div class="card">
  <h2>Live preview</h2>
  <p class="muted small">This is what the public /pricing.php cards will show with the values above.</p>
  <div class="landing-grid plans" style="margin-top: 12px;">
    <div class="plan-card">
      <h3>Starter</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e(fmt_p($starterPrice, $currency)) ?></span>
        <span class="plan-price-unit"><?= e($period) ?></span>
      </div>
      <p class="plan-price-sub muted small"><?= (int)$stSeats ?> seats · <?= e(fmt_p($perSeat, $currency)) ?> per seat</p>
    </div>
    <div class="plan-card highlight">
      <?php if ($badge): ?><div class="plan-badge"><?= e($badge) ?></div><?php endif; ?>
      <h3>Growth</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e(fmt_p($bdPrice, $currency)) ?></span>
        <span class="plan-price-unit"><?= e($period) ?></span>
      </div>
      <p class="plan-price-sub muted small">
        <?= (int)$bdSeats ?> seats · effectively <?= e(fmt_p($effPerSeatG, $currency)) ?> per seat
      </p>
    </div>
    <div class="plan-card">
      <h3>Enterprise</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e(fmt_p($bdPrice, $currency)) ?></span>
        <span class="plan-price-unit">+ <?= e(fmt_p($extra, $currency)) ?> / extra seat</span>
      </div>
      <p class="plan-price-sub muted small">
        Starts at <?= (int)$bdSeats ?> seats, scales seat-by-seat
      </p>
    </div>
  </div>
</div>
<?php layout_end(); ?>
