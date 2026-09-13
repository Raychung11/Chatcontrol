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

$seoBase = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'inbox.aiserve.my'));
$seoTitle = 'Pricing · ' . APP_NAME . ' — WhatsApp inbox for Malaysian SMEs';
$seoDesc  = 'Simple pricing for Malaysian teams: per-seat plans, team bundle with a big discount, plus a free tier. Broadcast + F&B ordering + AI drafts all included. No lock-in, cancel any month.';
$seoOgImg = $seoBase . '/assets/img/og-cover.png';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($seoTitle) ?></title>
  <meta name="description" content="<?= e($seoDesc) ?>">
  <meta name="keywords" content="WhatsApp inbox pricing Malaysia, shared WhatsApp cost, sistem WhatsApp harga, WhatsApp broadcast pricing, WhatsApp CRM cost">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
  <link rel="canonical" href="<?= e($seoBase . '/pricing.php') ?>">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
  <meta property="og:title" content="<?= e($seoTitle) ?>">
  <meta property="og:description" content="<?= e($seoDesc) ?>">
  <meta property="og:url" content="<?= e($seoBase . '/pricing.php') ?>">
  <meta property="og:image" content="<?= e($seoOgImg) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:locale" content="en_MY">
  <meta property="og:locale:alternate" content="ms_MY">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($seoTitle) ?>">
  <meta name="twitter:description" content="<?= e($seoDesc) ?>">
  <meta name="twitter:image" content="<?= e($seoOgImg) ?>">

  <script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
      ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',    'item' => $seoBase . '/'],
      ['@type' => 'ListItem', 'position' => 2, 'name' => 'Pricing', 'item' => $seoBase . '/pricing.php'],
    ],
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

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

<?php
  // Broadcast tier settings for the public section below.
  $bcastFreeLimit  = (int)platform_setting('broadcast_free_limit', '1000');
  $bcastPaidLimit  = (int)platform_setting('broadcast_paid_limit', '10000');
  $bcastPaidPrice  = (float)platform_setting('broadcast_paid_price', '480');
  $bcastYearlyPct  = max(0, min(100, (int)platform_setting('broadcast_yearly_discount_pct', '20')));
  $bcastPaygRate   = (float)platform_setting('broadcast_payg_per_recipient', '0.05');
  $bcastPaidYearly = $bcastPaidPrice * 12 * (1 - ($bcastYearlyPct / 100));
  $fmt = fn($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
?>
<section class="landing-section">
  <h2 class="landing-h2">Broadcast pricing</h2>
  <p class="landing-sub">
    Send announcements, promos, and reminders to many customers in one go.
    One send = one recipient. Every workspace starts on the free tier —
    upgrade only when you need more volume.
  </p>

  <div class="landing-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
    <div class="feature-card" style="border: 2px solid var(--c-border, #e3e8ee);">
      <h3>Free</h3>
      <p style="font-size: 28px; font-weight: 700; margin: 4px 0;"><?= e($cur) ?> 0<span class="muted small" style="font-weight: normal;">/month</span></p>
      <p><strong><?= number_format($bcastFreeLimit) ?></strong> recipient sends per month</p>
      <ul class="muted small" style="margin: 8px 0 0 18px; line-height: 1.7;">
        <li>Included with every workspace</li>
        <li>Attach up to 4 images per blast</li>
        <li>Trickle sending at your pace</li>
      </ul>
    </div>

    <div class="feature-card" style="border: 2px solid #25D366; position: relative;">
      <div style="position: absolute; top: -10px; right: 12px; background: #25D366; color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">POPULAR</div>
      <h3>Paid</h3>
      <p style="font-size: 28px; font-weight: 700; margin: 4px 0;">
        <?= e($cur) ?> <?= e($fmt($bcastPaidPrice)) ?><span class="muted small" style="font-weight: normal;">/month</span>
      </p>
      <p><strong><?= number_format($bcastPaidLimit) ?></strong> recipient sends per month</p>
      <ul class="muted small" style="margin: 8px 0 0 18px; line-height: 1.7;">
        <li><?= e($cur) ?> <?= e($fmt($bcastPaidPrice / max($bcastPaidLimit, 1))) ?> per recipient at cap</li>
        <li>All free-tier features included</li>
        <?php if ($bcastYearlyPct > 0): ?>
          <li><strong>Save <?= (int)$bcastYearlyPct ?>%</strong> with yearly billing: <?= e($cur) ?> <?= e($fmt($bcastPaidYearly)) ?>/year</li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="feature-card" style="border: 2px solid #0891B2;">
      <h3>Pay-as-you-go</h3>
      <p style="font-size: 28px; font-weight: 700; margin: 4px 0;">
        <?= e($cur) ?> <?= e($fmt($bcastPaygRate)) ?><span class="muted small" style="font-weight: normal;">/recipient</span>
      </p>
      <p><strong>Unlimited</strong> sends, billed monthly by usage</p>
      <ul class="muted small" style="margin: 8px 0 0 18px; line-height: 1.7;">
        <li>No monthly commitment</li>
        <li>No cap, no auto-suspend</li>
        <li>Ideal for seasonal / burst usage</li>
      </ul>
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
    <span class="dot">·</span>
    <a href="/refund.php">Refunds</a>
  </div>
</footer>

<?php cookie_notice(); ?>
<script src="<?= e(asset_url('/assets/js/pwa.js')) ?>" defer></script>
</body>
</html>
