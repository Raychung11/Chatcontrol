<?php
require_once __DIR__ . '/inc/legal_layout.php';
legal_page_start('Refund & Cancellation Policy', platform_setting('legal_refund_updated', '27 June 2026'));
$app    = e(APP_NAME);
$op     = operator_legal_info();
$opEnt  = $op['legal_name'] !== '' ? e($op['legal_name']) : $app;
$opReg  = $op['registration_no'] !== '' ? ' (' . e($op['registration_no']) . ')' : '';
$opMail = $op['email'] !== '' ? e($op['email']) : '';
$days   = (int)platform_setting('refund_window_days', '7');
$extra  = trim(platform_setting('refund_policy_extra', ''));
$p      = pricing_get();
?>

<p>
  This Refund &amp; Cancellation Policy applies to paid subscriptions to <?= $app ?>
  ("Service"), provided by <strong><?= $opEnt ?><?= $opReg ?></strong> ("we", "us").
  It supplements section 4 of our <a href="/terms.php">Terms of Service</a>.
</p>

<h2>1. Cooling-off period</h2>
<p>
  You may request a full refund of your <strong>first paid invoice</strong> within
  <strong><?= (int)$days ?> days</strong> of payment if all of the following are true:
</p>
<ul>
  <li>you have not yet connected a live WhatsApp number to your Workspace;</li>
  <li>your Workspace has not sent any messages to real customers through the Service;</li>
  <li>you have not used the AI suggestion feature on customer conversations beyond
      trial / setup activity.</li>
</ul>
<p>
  Refunds during the cooling-off period are made to the original payment method
  within 14 working days of the request being verified.
</p>

<h2>2. After the cooling-off period</h2>
<p>
  Fees are charged in advance for each billing cycle and are non-refundable after
  the cooling-off period above, except where required by applicable consumer-protection
  law. You can cancel at any time and the Service will continue until the end of
  the cycle you have already paid for.
</p>

<h2>3. Cancellation</h2>
<p>
  To cancel, the Workspace administrator can either close the workspace from the
  admin area or email us at
  <?php if ($opMail !== ''): ?>
    <a href="mailto:<?= $opMail ?>"><?= $opMail ?></a>
  <?php else: ?>
    the support address listed in your Workspace settings
  <?php endif; ?>.
  Cancellation takes effect at the end of the current paid billing cycle. We do
  not pro-rate refunds for partial months.
</p>

<h2>4. Plan changes (upgrades and downgrades)</h2>
<p>
  You may move between plans (<a href="/pricing.php">Starter, Growth, Enterprise</a>)
  at any time. When you upgrade mid-cycle we charge the prorated difference for
  the remainder of the cycle. When you downgrade mid-cycle the change takes
  effect at the start of the next cycle and we do not refund the difference for
  the current cycle.
</p>
<p>
  Adding extra seats on the Enterprise plan is billed at
  <?= e(fmt_price($p['extra_seat_price'], $p['currency'])) ?> per seat
  <?= e($p['period_label']) ?> and is prorated for the current cycle.
</p>

<h2>5. Failed payments</h2>
<p>
  If a recurring payment fails we will email the Workspace administrator and give
  you 7 days to update the payment method before the Workspace is suspended.
  Suspended Workspaces stop receiving and sending messages but data is retained
  for 30 days so you can recover after settling the invoice.
</p>

<h2>6. Third-party charges</h2>
<p>
  Refunds from us do not cover charges made by third parties you connect to your
  Workspace, including Meta (WhatsApp Cloud API conversation fees), Anthropic
  (AI suggestion tokens), Evolution server hosting, or partner gateways. Refund
  requests for those should be directed to the respective provider.
</p>

<h2>7. Chargebacks &amp; disputes</h2>
<p>
  Please email us before initiating a card or bank chargeback so we have a
  chance to resolve the issue directly. Initiating a chargeback after our refund
  policy has been applied may result in your Workspace being suspended pending
  the dispute outcome.
</p>

<?php if ($extra !== ''): ?>
<h2>8. Additional terms</h2>
<p><?= nl2br(e($extra)) ?></p>
<?php endif; ?>

<h2>How to request a refund</h2>
<p>
  Email your Workspace administrator first. If that does not resolve it, email
  us at
  <?php if ($opMail !== ''): ?>
    <a href="mailto:<?= $opMail ?>"><?= $opMail ?></a>
  <?php else: ?>
    the support address listed in your Workspace settings
  <?php endif; ?>
  with your Workspace slug, the invoice number, and a brief reason. We will
  respond within 7 working days.
</p>

<?php legal_page_end();
