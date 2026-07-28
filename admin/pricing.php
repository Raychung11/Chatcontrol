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

    // --- Broadcast metering (phase 30) --------------------------------
    'broadcast_free_limit' => [
        'label' => 'Broadcast free tier: monthly recipient limit',
        'type'  => 'number',
        'hint'  => 'Every workspace on the FREE broadcast plan can send this many recipients per calendar month at no extra charge. One recipient = one billable send. Default 1000.',
        'step'  => '1',
    ],
    'broadcast_paid_limit' => [
        'label' => 'Broadcast paid tier: monthly recipient limit',
        'type'  => 'number',
        'hint'  => 'Workspaces on the PAID broadcast plan can send up to this many recipients per calendar month. Default 10000.',
        'step'  => '1',
    ],
    'broadcast_paid_price' => [
        'label' => 'Broadcast paid tier: monthly price',
        'type'  => 'number',
        'hint'  => 'Flat monthly price for the paid broadcast plan. In the same currency as the seat pricing above.',
        'step'  => '0.01',
    ],
    'broadcast_yearly_discount_pct' => [
        'label' => 'Yearly billing discount (%)',
        'type'  => 'number',
        'hint'  => 'Discount applied when a workspace picks yearly billing. e.g. 20 means 12 months for the price of 9.6. Display-only — actual billing happens out-of-band.',
        'step'  => '1',
    ],
    'broadcast_payg_per_recipient' => [
        'label' => 'Pay-as-you-go: price per recipient',
        'type'  => 'number',
        'hint'  => 'Rate charged per recipient send on the PAYG plan. No monthly cap. Displayed in the same currency as above.',
        'step'  => '0.01',
    ],
];

if (is_post()) {
    csrf_check();
    $r = platform_settings_save(
        $db, $fields, 'platform_pricing_updated',
        (int)$current_user['company_id'], (int)$current_user['id']
    );
    $msg = $r['msg'];
    $err = $r['err'];
}

$loaded  = platform_settings_load($db);
$current = $loaded['rows'];
if ($loaded['err'] && !$err) $err = $loaded['err'];

layout_start($current_user, 'Platform pricing', 'pricing');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <p class="muted small">
    These values drive <a href="/pricing.php" target="_blank">/pricing.php</a>,
    the plan radios on <a href="/register.php" target="_blank">/register.php</a>,
    and the plans block on the landing page. Changes are live immediately —
    no deploy needed. Looking for operator details, refund policy, or legal
    page dates? They moved to <a href="/admin/legal.php">Legal &amp; operator</a>.
  </p>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <?php foreach ($fields as $key => $meta) {
        render_platform_setting_field($key, $meta, $current[$key] ?? '');
    } ?>
    <button class="btn btn-primary" type="submit">Save pricing</button>
  </form>
</div>

<?php
// Live preview using the currently-saved values so you can confirm before
// reloading the public page.
$currency = $current['pricing_currency']         ?? 'RM';
$period   = $current['pricing_period_label']     ?? '/ month';
$perSeat  = (float)($current['pricing_per_seat']         ?? 12);
$stSeats  = (int)  ($current['pricing_starter_seats']    ?? 3);
$bdSeats  = (int)  ($current['pricing_bundle_seats']     ?? 10);
$bdPrice  = (float)($current['pricing_bundle_price']     ?? 60);
$extra    = (float)($current['pricing_extra_seat_price'] ?? 12);

$starterPrice = $perSeat * $stSeats;
$effPerSeatG  = $bdSeats > 0 ? $bdPrice / $bdSeats : 0;
$badge        = ($perSeat > 0 && $bdPrice < ($perSeat * $bdSeats))
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
