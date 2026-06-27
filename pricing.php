<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/cookie_notice.php';

$year = date('Y');
$p    = pricing_get();
$cur  = $p['currency'];
$per  = $p['period_label'];

$starterPrice = fmt_price($p['starter_price'], $cur);
$bundlePrice  = fmt_price($p['bundle_price'],  $cur);
$perSeatF     = fmt_price($p['per_seat'],      $cur);
$effPerSeatF  = fmt_price($p['effective_per_seat_growth'], $cur);
$extraSeatF   = fmt_price($p['extra_seat_price'], $cur);
$listPriceGrowth = fmt_price($p['per_seat'] * $p['bundle_seats'], $cur);

$savePct = ($p['per_seat'] > 0 && $p['bundle_price'] < ($p['per_seat'] * $p['bundle_seats']))
    ? (int)round((1 - ($p['bundle_price'] / max(0.01, $p['per_seat'] * $p['bundle_seats']))) * 100)
    : 0;

// Example for Enterprise card: bundle_seats + 5 extras.
$exampleSeats  = $p['bundle_seats'] + 5;
$exampleTotal  = $p['bundle_price'] + (5 * $p['extra_seat_price']);
$exampleTotalF = fmt_price($exampleTotal, $cur);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pricing · <?= e(APP_NAME) ?></title>
  <meta name="description" content="Simple pricing: RM 12 per seat, or RM 60 for a 10-seat team (50% off). Every plan includes AI reply suggestions and the knowledge base.">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
  <?= pwa_head_tags() ?>
</head>
<body class="landing-body">

<header class="landing-nav">
  <a class="landing-brand" href="/">
    <span class="brand-dot" style="background:#25D366"></span>
    <span class="brand-text"><?= e(APP_NAME) ?></span>
  </a>
  <nav class="landing-nav-links">
    <a href="/#features">Features</a>
    <a href="/#how">How it works</a>
    <a href="/pricing.php" aria-current="page">Pricing</a>
    <a class="btn btn-primary btn-sm" href="/login.php">Sign in</a>
  </nav>
</header>

<section class="landing-hero" style="padding-bottom: 20px;">
  <div class="landing-hero-inner" style="grid-column: 1 / -1; max-width: 820px; margin: 0 auto; text-align:center;">
    <span class="landing-eyebrow">Simple, transparent pricing</span>
    <h1>One bundle, three sizes.<br>No surprises.</h1>
    <p class="landing-lede">
      Pay <strong><?= e($perSeatF) ?> per seat</strong>, or grab the <strong><?= e((int)$p['bundle_seats']) ?>-seat bundle for <?= e($bundlePrice) ?><?= e($per) ?></strong>
      <?= $savePct > 0 ? 'and save ' . (int)$savePct . '%' : '' ?>. Need more than <?= e((int)$p['bundle_seats']) ?>? Add seats at <?= e($extraSeatF) ?> each.
      <br>Every plan includes AI reply suggestions, the knowledge base, multi-channel inbox, and everything else in the product.
    </p>
  </div>
</section>

<section class="landing-section" style="padding-top: 0;">
  <div class="landing-grid plans">

    <div class="plan-card">
      <h3>Starter</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($starterPrice) ?></span>
        <span class="plan-price-unit"><?= e($per) ?></span>
      </div>
      <p class="plan-price-sub muted small"><?= (int)$p['starter_seats'] ?> seats · <?= e($perSeatF) ?> per seat</p>
      <ul>
        <li>Up to <strong><?= (int)$p['starter_seats'] ?> agents</strong></li>
        <li>1 WhatsApp number</li>
        <li>AI reply suggestions</li>
        <li>Knowledge base (PDFs, Word, FAQs)</li>
        <li>Shared inbox + notes + tags</li>
        <li>Topics analytics</li>
      </ul>
      <a class="btn btn-block" href="/register.php?plan=starter">Start with Starter</a>
      <p class="muted small" style="margin-top:8px;">Best for solo founders and small teams.</p>
    </div>

    <div class="plan-card highlight">
      <div class="plan-badge"><?= $savePct > 0 ? 'Most popular · save ' . (int)$savePct . '%' : 'Most popular' ?></div>
      <h3>Growth</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($bundlePrice) ?></span>
        <span class="plan-price-unit"><?= e($per) ?></span>
      </div>
      <p class="plan-price-sub muted small">
        <?= (int)$p['bundle_seats'] ?> seats bundle · effectively <?= e($effPerSeatF) ?> per seat
        <?php if ($savePct > 0): ?>
          <br><s><?= e($listPriceGrowth) ?></s> at per-seat rate
        <?php endif; ?>
      </p>
      <ul>
        <li>Up to <strong><?= (int)$p['bundle_seats'] ?> agents</strong></li>
        <li>1 WhatsApp number</li>
        <li>Everything in Starter</li>
        <li>Routing rules + AI rule builder</li>
        <li>Reports with date range</li>
        <li>Auto-reply &amp; business hours</li>
        <li>Email alerts on failed sends</li>
      </ul>
      <a class="btn btn-primary btn-block" href="/register.php?plan=growth">Start with Growth</a>
      <p class="muted small" style="margin-top:8px;">Built for typical customer-service teams.</p>
    </div>

    <div class="plan-card">
      <h3>Enterprise</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($bundlePrice) ?></span>
        <span class="plan-price-unit">+ <?= e($extraSeatF) ?> / extra seat</span>
      </div>
      <p class="plan-price-sub muted small">
        Starts at <?= (int)$p['bundle_seats'] ?> seats, scales seat-by-seat
        <br>Example: <?= (int)$exampleSeats ?> seats = <?= e($bundlePrice) ?> + (5 × <?= e($extraSeatF) ?>) = <?= e($exampleTotalF) ?><?= e($per) ?>
      </p>
      <ul>
        <li><strong>Unlimited agents</strong> (pay per seat)</li>
        <li>Multiple WhatsApp numbers</li>
        <li>Everything in Growth</li>
        <li>Priority support</li>
        <li>CRM integrations (Odoo coming)</li>
      </ul>
      <a class="btn btn-block" href="/register.php?plan=enterprise">Start with Enterprise</a>
      <p class="muted small" style="margin-top:8px;">For multi-brand operators and resellers.</p>
    </div>

  </div>
</section>

<section class="landing-section landing-alt">
  <h2 class="landing-h2">Frequently asked</h2>
  <div class="landing-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
    <div class="feature-card">
      <h3>What does "seat" mean?</h3>
      <p>One seat = one team member who can log in, see the inbox, and reply. Customers messaging in over WhatsApp are not seats.</p>
    </div>
    <div class="feature-card">
      <h3>Can I change plans later?</h3>
      <p>Yes. Move from Starter → Growth → Enterprise any time. <?= e($p['footer_note']) ?></p>
    </div>
    <div class="feature-card">
      <h3>What about the WhatsApp side?</h3>
      <p>If you use Meta Cloud API, Meta charges per conversation (you pay them directly). If you use Evolution or a partner gateway, that's separate too. We're the inbox layer.</p>
    </div>
    <div class="feature-card">
      <h3>What about AI cost?</h3>
      <p>Every workspace brings its own Anthropic API key. You pay Anthropic at cost — typically a few sen per AI-drafted reply. We don't mark it up.</p>
    </div>
    <div class="feature-card">
      <h3>Is there a free trial?</h3>
      <p>You can create a workspace and explore the product. We bill you once you connect a live WhatsApp number and start replying to real customers.</p>
    </div>
    <div class="feature-card">
      <h3>How do I pay?</h3>
      <p><?= e($p['payment_methods']) ?></p>
    </div>
  </div>
</section>

<section class="landing-cta-band">
  <h2>Ready to take your WhatsApp inbox seriously?</h2>
  <p>Create your workspace in 30 seconds. Pick a plan when you're ready — we'll only bill once you're sending real replies.</p>
  <a class="btn btn-primary btn-lg" href="/register.php">Create workspace</a>
  <a class="btn btn-lg" href="/login.php" style="margin-left:8px;">Sign in</a>
</section>

<footer class="landing-footer">
  <div>&copy; <?= e((string)$year) ?> <?= e(APP_NAME) ?></div>
  <div>
    <a href="/login.php">Sign in</a>
    <span class="dot">·</span>
    <a href="/register.php">Sign up</a>
    <span class="dot">·</span>
    <a href="/pricing.php">Pricing</a>
    <span class="dot">·</span>
    <a href="/terms.php">Terms</a>
    <span class="dot">·</span>
    <a href="/privacy.php">Privacy</a>
    <span class="dot">·</span>
    <a href="/disclaimer.php">Disclaimer</a>
  </div>
</footer>

<?php cookie_notice(); ?>
<script src="<?= e(asset_url('/assets/js/pwa.js')) ?>" defer></script>
</body>
</html>
