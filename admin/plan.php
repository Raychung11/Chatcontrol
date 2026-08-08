<?php
/**
 * /admin/plan.php — customer-facing broadcast plan + upgrade page.
 *
 * Workspace super_admin can:
 *   - See current plan, monthly quota, usage this month, next billing date
 *   - Upgrade Free → Paid (monthly / yearly with discount)
 *   - Downgrade Paid → Free (immediate, keeps sent count)
 *   - Switch monthly ↔ yearly
 *   - View bank transfer / payment instructions for the invoice
 *
 * Change is applied immediately — no manual approval needed. Every
 * plan change is logged to activity_logs so platform admin sees it in
 * /admin/workspaces.php and can revoke via the same page if payment
 * doesn't arrive.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

// Quota + pricing snapshot for THIS workspace.
$quota   = broadcast_quota_for_workspace($companyId);
$company = $db->prepare('SELECT name, broadcast_plan, broadcast_billing_cycle FROM companies WHERE id = ? LIMIT 1');
$company->execute([$companyId]);
$company = $company->fetch() ?: [];
$curPlan  = (string)($company['broadcast_plan']         ?? 'free');
$curCycle = (string)($company['broadcast_billing_cycle'] ?? 'monthly');

// Platform-editable payment instructions (bank details, e-wallet etc.).
// Falls back to a friendly "reach out" message if the platform admin
// hasn't configured any yet in /admin/pricing.php or /admin/legal.php.
$payInstructions = platform_setting('broadcast_payment_instructions', '');
if ($payInstructions === '') {
    $payInstructions = "After clicking upgrade, our team will email you an invoice within 24 hours.\n"
                     . "Reply to that email once payment is done and we'll issue a receipt.";
}
$operatorEmail = platform_setting('operator_email', platform_setting('operator_contact_email', ''));

// -------------------- Apply change --------------------
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $newPlan  = (string)($_POST['plan']           ?? '');
    $newCycle = (string)($_POST['billing_cycle']  ?? 'monthly');

    if ($action === 'change') {
        if (!in_array($newPlan, ['free', 'paid', 'payg'], true)) {
            $err = 'Invalid plan.';
        } elseif (!in_array($newCycle, ['monthly', 'yearly'], true)) {
            $err = 'Invalid billing cycle.';
        } else {
            $wasPlan  = $curPlan;
            $wasCycle = $curCycle;
            $db->prepare(
                'UPDATE companies
                 SET broadcast_plan = ?, broadcast_billing_cycle = ?
                 WHERE id = ?'
            )->execute([$newPlan, $newCycle, $companyId]);

            $labelBefore = ucfirst($wasPlan) . '/' . $wasCycle;
            $labelAfter  = ucfirst($newPlan) . '/' . $newCycle;
            log_activity($companyId, (int)$current_user['id'], 'broadcast_plan_selfserve_change',
                'company', $companyId, $labelBefore . ' → ' . $labelAfter);

            // Refresh
            $company->execute([$companyId]);
            $company = $company->fetch();
            $curPlan  = (string)($company['broadcast_plan']         ?? 'free');
            $curCycle = (string)($company['broadcast_billing_cycle'] ?? 'monthly');
            $quota    = broadcast_quota_for_workspace($companyId);

            $msg = $newPlan === $wasPlan
                ? 'Billing cycle updated to ' . $newCycle . '.'
                : ($newPlan === 'free'
                    ? 'Downgraded to Free. You can upgrade again anytime.'
                    : 'Upgraded to ' . ucfirst($newPlan) . '! Payment instructions below.');
        }
    }
}

$usedPct = $quota['limit'] !== PHP_INT_MAX && $quota['limit'] > 0
    ? min(100, ($quota['used'] / $quota['limit']) * 100)
    : 0;

layout_start($current_user, 'Plan & billing', 'plan');
?>
<style>
.plan-hero { display:grid; gap:12px; grid-template-columns: 1fr 1fr 1fr; margin-bottom:16px; }
@media (max-width: 900px) { .plan-hero { grid-template-columns: 1fr; } }
.plan-kpi { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:14px 16px; }
.plan-kpi .lbl { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.plan-kpi .val { color:#0f172a; font-size:24px; font-weight:700; margin-top:4px; }
.plan-kpi .sub { color:#94a3b8; font-size:12px; margin-top:4px; }
.plan-bar { height:6px; background:#e3e8ee; border-radius:3px; margin-top:6px; overflow:hidden; }
.plan-bar-fill { height:100%; border-radius:3px; background:#16A34A; }
.plan-bar-fill.warn { background:#F59E0B; }
.plan-bar-fill.full { background:#DC2626; }

.plan-tier-row { display:grid; gap:12px; grid-template-columns: repeat(3, 1fr); margin-bottom:16px; }
@media (max-width: 900px) { .plan-tier-row { grid-template-columns: 1fr; } }
.plan-tier {
    background:#fff; border:2px solid #e3e8ee; border-radius:12px;
    padding:20px; display:flex; flex-direction:column;
}
.plan-tier.current   { border-color:#16A34A; box-shadow:0 0 0 3px rgba(22,163,74,0.08); }
.plan-tier.recommend { border-color:#0072B2; }
.plan-tier .name  { font-size:18px; font-weight:700; margin-bottom:4px; }
.plan-tier .price { font-size:28px; font-weight:700; color:#0f172a; margin:8px 0; }
.plan-tier .price small { font-size:13px; font-weight:400; color:#64748b; }
.plan-tier ul { list-style:none; padding:0; margin:12px 0; flex:1; }
.plan-tier li { padding:4px 0; font-size:13.5px; color:#334155; }
.plan-tier li::before { content:'✓ '; color:#16A34A; font-weight:700; }
.plan-tier .cta { margin-top:12px; }
.plan-tier .badge {
    display:inline-block; padding:2px 8px; border-radius:999px;
    font-size:11px; font-weight:600; background:#dcfce7; color:#14532d;
    margin-left:6px;
}
.plan-tier .badge-blue { background:#dbeafe; color:#1e3a8a; }

.plan-cycle {
    display:flex; gap:8px; align-items:center;
    background:#f6f9fb; border:1px solid #e3e8ee; border-radius:10px;
    padding:10px 14px; margin-bottom:16px;
}
.plan-cycle label { display:flex; gap:6px; align-items:center; font-size:13px; cursor:pointer; margin:0; }
.plan-cycle .save-chip {
    padding:2px 8px; border-radius:999px; background:#dcfce7; color:#14532d;
    font-size:11px; font-weight:600;
}
</style>

<div class="card" style="max-width:1000px;">
  <h2>💳 Plan &amp; billing</h2>
  <p class="muted small">
    Choose your broadcast plan. Changes apply <strong>immediately</strong> — payment
    instructions appear once you upgrade. Downgrade or switch cycle any time; no lock-in.
  </p>
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <!-- Current status -->
  <div class="plan-hero">
    <div class="plan-kpi">
      <div class="lbl">Current plan</div>
      <div class="val" style="text-transform:capitalize;"><?= e($curPlan) ?></div>
      <div class="sub"><?= e($curCycle) ?> billing</div>
    </div>
    <div class="plan-kpi">
      <div class="lbl">This month usage</div>
      <div class="val"><?= number_format($quota['used']) ?></div>
      <div class="sub">
        of
        <?= $quota['unlimited']
            ? '∞ (PAYG)'
            : number_format($quota['limit']) ?>
        recipients
      </div>
      <?php if (!$quota['unlimited'] && $quota['limit'] > 0): ?>
        <div class="plan-bar">
          <div class="plan-bar-fill <?= $usedPct >= 100 ? 'full' : ($usedPct >= 80 ? 'warn' : '') ?>"
               style="width: <?= number_format($usedPct, 1) ?>%;"></div>
        </div>
      <?php endif; ?>
    </div>
    <div class="plan-kpi">
      <div class="lbl">Est. this month cost</div>
      <?php if ($curPlan === 'free'): ?>
        <div class="val">RM 0</div>
        <div class="sub">free tier</div>
      <?php elseif ($curPlan === 'paid'): ?>
        <div class="val">
          <?= e($quota['currency']) ?>
          <?= number_format($curCycle === 'yearly' ? $quota['yearly_price'] / 12 : $quota['price'], 0) ?>
        </div>
        <div class="sub"><?= $curCycle === 'yearly' ? 'yearly billing — ' . (int)$quota['yearly_discount'] . '% off' : 'monthly billing' ?></div>
      <?php else: ?>
        <div class="val">
          <?= e($quota['currency']) ?>
          <?= number_format($quota['payg_accrued'], 2) ?>
        </div>
        <div class="sub">accrued so far · <?= e($quota['currency']) ?> <?= number_format($quota['payg_rate'], 2) ?> per recipient</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Billing cycle switch (only meaningful when picking Paid) -->
  <form method="post" id="plan-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change">
    <input type="hidden" name="plan" id="chosen-plan" value="<?= e($curPlan) ?>">

    <div class="plan-cycle">
      <span class="muted small">Billing cycle for Paid plan:</span>
      <label>
        <input type="radio" name="billing_cycle" value="monthly"
               <?= $curCycle === 'monthly' ? 'checked' : '' ?>>
        Monthly
      </label>
      <label>
        <input type="radio" name="billing_cycle" value="yearly"
               <?= $curCycle === 'yearly' ? 'checked' : '' ?>>
        Yearly
        <span class="save-chip">save <?= (int)$quota['yearly_discount'] ?>%</span>
      </label>
    </div>

    <!-- Three tiers -->
    <div class="plan-tier-row">
      <!-- Free -->
      <div class="plan-tier <?= $curPlan === 'free' ? 'current' : '' ?>">
        <div class="name">Free <?php if ($curPlan === 'free'): ?><span class="badge">current</span><?php endif; ?></div>
        <div class="price">RM 0 <small>/ month</small></div>
        <ul>
          <li><?= number_format($quota['free_limit']) ?> broadcast recipients / month</li>
          <li>Unlimited inbox conversations</li>
          <li>All AI features (billed separately)</li>
          <li>No credit card required</li>
        </ul>
        <div class="cta">
          <?php if ($curPlan === 'free'): ?>
            <button type="button" class="btn" disabled style="width:100%; opacity:.5;">Current plan</button>
          <?php else: ?>
            <button type="submit" class="btn" style="width:100%;"
                    onclick="document.getElementById('chosen-plan').value='free'; return confirm('Downgrade to Free? You keep any recipients already sent this month, but any beyond <?= number_format($quota['free_limit']) ?> in future months would be blocked until you upgrade again.');">
              Downgrade to Free
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Paid -->
      <div class="plan-tier <?= $curPlan === 'paid' ? 'current' : 'recommend' ?>">
        <div class="name">
          Paid
          <?php if ($curPlan === 'paid'): ?>
            <span class="badge">current</span>
          <?php else: ?>
            <span class="badge badge-blue">recommended</span>
          <?php endif; ?>
        </div>
        <div class="price" id="paid-price">
          <?= e($quota['currency']) ?>
          <span id="paid-num"><?= $curCycle === 'yearly' ? number_format($quota['yearly_price'] / 12, 0) : number_format($quota['price'], 0) ?></span>
          <small>/ month</small>
        </div>
        <div class="muted small" id="paid-billed">
          <?= $curCycle === 'yearly'
              ? 'billed ' . e($quota['currency']) . ' ' . number_format($quota['yearly_price'], 2) . ' yearly (save ' . (int)$quota['yearly_discount'] . '%)'
              : 'billed monthly' ?>
        </div>
        <ul>
          <li><strong><?= number_format($quota['paid_limit']) ?> recipients</strong> / month</li>
          <li>Everything in Free</li>
          <li>Priority queue for broadcasts</li>
          <li>Downgrade or cancel anytime</li>
        </ul>
        <div class="cta">
          <?php if ($curPlan === 'paid'): ?>
            <button type="submit" class="btn" style="width:100%;"
                    onclick="document.getElementById('chosen-plan').value='paid';">
              Save cycle change
            </button>
          <?php else: ?>
            <button type="submit" class="btn btn-primary" style="width:100%;"
                    onclick="document.getElementById('chosen-plan').value='paid'; return confirm('Upgrade to Paid? Your plan changes immediately and we\'ll email an invoice within 24 hours.');">
              ✨ Upgrade to Paid
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- PAYG -->
      <div class="plan-tier <?= $curPlan === 'payg' ? 'current' : '' ?>">
        <div class="name">PAYG <?php if ($curPlan === 'payg'): ?><span class="badge">current</span><?php endif; ?></div>
        <div class="price">
          <?= e($quota['currency']) ?> <?= number_format($quota['payg_rate'], 2) ?>
          <small>/ recipient</small>
        </div>
        <ul>
          <li>Unlimited recipients — no monthly cap</li>
          <li>Pay only for what you send</li>
          <li>Ideal for large one-off campaigns</li>
          <li>Invoiced end of month</li>
        </ul>
        <div class="cta">
          <?php if ($curPlan === 'payg'): ?>
            <button type="button" class="btn" disabled style="width:100%; opacity:.5;">Current plan</button>
          <?php else: ?>
            <button type="submit" class="btn" style="width:100%;"
                    onclick="document.getElementById('chosen-plan').value='payg'; return confirm('Switch to PAYG? You\'ll be billed per recipient sent — no monthly cap, no monthly minimum.');">
              Switch to PAYG
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </form>

  <!-- Payment instructions -->
  <?php if ($curPlan !== 'free'): ?>
    <div class="card" style="background:#fefce8; border-color:#fef08a; margin-top: 4px;">
      <h3 style="margin-top:0;">💰 Payment instructions</h3>
      <pre style="white-space:pre-wrap; font-family:inherit; font-size:13.5px; color:#334155; margin:0;"><?= e($payInstructions) ?></pre>
      <?php if ($operatorEmail !== ''): ?>
        <p class="muted small" style="margin-top:10px;">
          Questions? Email <a href="mailto:<?= e($operatorEmail) ?>"><?= e($operatorEmail) ?></a>.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
// Live price swap when the cycle radio changes.
(function () {
    var priceMonthly = <?= json_encode(number_format($quota['price'], 0)) ?>;
    var priceYearly  = <?= json_encode(number_format($quota['yearly_price'] / 12, 0)) ?>;
    var yearlyTotal  = <?= json_encode(number_format($quota['yearly_price'], 2)) ?>;
    var currency     = <?= json_encode($quota['currency']) ?>;
    var discount     = <?= (int)$quota['yearly_discount'] ?>;
    var num = document.getElementById('paid-num');
    var billed = document.getElementById('paid-billed');
    document.querySelectorAll('input[name="billing_cycle"]').forEach(function (r) {
        r.addEventListener('change', function () {
            if (r.checked && r.value === 'yearly') {
                num.textContent = priceYearly;
                billed.textContent = 'billed ' + currency + ' ' + yearlyTotal + ' yearly (save ' + discount + '%)';
            } else if (r.checked) {
                num.textContent = priceMonthly;
                billed.textContent = 'billed monthly';
            }
        });
    });
})();
</script>
<?php layout_end(); ?>
