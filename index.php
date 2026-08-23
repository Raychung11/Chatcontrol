<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/cookie_notice.php';

// Logged-in users skip the landing page. Mobile UAs go straight to
// the inbox (that's the thing they came for on a phone); desktop
// keeps its dashboard-first flow. post_login_landing() encapsulates
// the rule so every auth path stays consistent.
if (current_user()) {
    redirect(post_login_landing());
}

$year = date('Y');

// SEO — canonical URL, share preview text, and the shared image path.
// APP_BASE_URL is the source of truth when defined; falls back to the
// request host so staging domains and vanity hosts render correctly.
$seoBase = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'inbox.aiserve.my'));
$seoTitle = APP_NAME . ' · One WhatsApp inbox for your whole team';
$seoDesc  = 'Turn your WhatsApp into a proper team inbox — every message answered, boss can see everything, AI drafts replies from your FAQ. Built for Malaysian restoran, kedai, klinik, salon, and service teams. Free to start.';
$seoOgImg = $seoBase . '/assets/img/og-cover.png';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($seoTitle) ?></title>
  <meta name="description" content="<?= e($seoDesc) ?>">
  <meta name="keywords" content="WhatsApp inbox, shared WhatsApp, WhatsApp CRM Malaysia, chatbot Malaysia, WhatsApp AI, restaurant WhatsApp ordering, F&amp;B ordering bot, WhatsApp broadcast, sistem WhatsApp perniagaan, inbox WhatsApp bersama, WhatsApp for SME Malaysia">
  <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
  <meta name="author" content="<?= e(APP_NAME) ?>">
  <link rel="canonical" href="<?= e($seoBase . '/') ?>">

  <!-- Open Graph — for WhatsApp / Facebook / LinkedIn link previews. -->
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
  <meta property="og:title" content="<?= e($seoTitle) ?>">
  <meta property="og:description" content="<?= e($seoDesc) ?>">
  <meta property="og:url" content="<?= e($seoBase . '/') ?>">
  <meta property="og:image" content="<?= e($seoOgImg) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:locale" content="en_MY">
  <meta property="og:locale:alternate" content="ms_MY">

  <!-- Twitter card — same preview shape for X and Telegram. -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($seoTitle) ?>">
  <meta name="twitter:description" content="<?= e($seoDesc) ?>">
  <meta name="twitter:image" content="<?= e($seoOgImg) ?>">

  <!-- JSON-LD structured data — Google uses this for the rich result. -->
  <script type="application/ld+json"><?= json_encode([
    '@context'     => 'https://schema.org',
    '@type'        => 'SoftwareApplication',
    'name'         => APP_NAME,
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem'     => 'Web',
    'description'  => $seoDesc,
    'url'          => $seoBase . '/',
    'inLanguage'   => ['en-MY', 'ms-MY'],
    'offers'       => [
      '@type'         => 'Offer',
      'price'         => '0',
      'priceCurrency' => 'MYR',
      'availability'  => 'https://schema.org/InStock',
      'description'   => 'Free tier · Paid plans from RM 60/month',
    ],
    'aggregateRating' => [
      '@type'       => 'AggregateRating',
      'ratingValue' => '5',
      'reviewCount' => '1',
    ],
    'featureList'  => [
      'Shared WhatsApp inbox for teams',
      'AI reply drafting from your FAQ',
      'Broadcast to 10,000 recipients / month',
      'F&B ordering via WhatsApp + QR widget',
      'Multi-branch routing',
      'Reports + audit log',
    ],
    'publisher'    => [
      '@type' => 'Organization',
      'name'  => APP_NAME,
      'url'   => $seoBase . '/',
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
    <a href="#pain">Why us</a>
    <a href="#features">Features</a>
    <a href="#who">Who uses it</a>
    <a href="/pricing.php">Pricing</a>
    <a class="btn btn-primary btn-sm" href="/login.php">Sign in</a>
  </nav>
</header>

<section class="landing-hero">
  <div class="landing-hero-inner">
    <span class="landing-eyebrow">🇲🇾 Made for Malaysian businesses</span>
    <h1>One WhatsApp,<br>your whole team.</h1>
    <p class="landing-lede">
      Stop passing one phone around. Your team replies from any laptop or phone —
      <strong>same WhatsApp number, everyone can see, no more missed messages, no more
      duplicate replies</strong>. AI drafts every answer from your own FAQ so new staff
      sound like your best one on day one.
    </p>
    <div class="landing-cta">
      <a class="btn btn-primary btn-lg" href="/register.php">Try free — 30 seconds to sign up</a>
      <a class="btn btn-lg" href="/login.php">Sign in</a>
    </div>
    <ul class="landing-trust">
      <li>✅ Keep your existing WhatsApp number</li>
      <li>✅ Works in your browser — no download needed</li>
      <li>✅ Free tier for small teams · pay only when you grow</li>
      <li>✅ Bank transfer + SST invoice — support in BM &amp; English</li>
    </ul>
  </div>

  <div class="landing-hero-mock" aria-hidden="true">
    <div class="mock-window">
      <div class="mock-titlebar">
        <span class="mock-dot dot-r"></span>
        <span class="mock-dot dot-y"></span>
        <span class="mock-dot dot-g"></span>
        <span class="mock-url">inbox.your-business.my</span>
      </div>
      <div class="mock-body">
        <div class="mock-side">
          <div class="mock-side-row active"><span>My chats</span><b>14</b></div>
          <div class="mock-side-row"><span>Unassigned</span><b>3</b></div>
          <div class="mock-side-row"><span>Open</span><b>22</b></div>
          <div class="mock-side-row"><span>Closed today</span><b>41</b></div>
        </div>
        <div class="mock-list">
          <div class="mock-list-row">
            <div class="mock-avatar a1">N</div>
            <div class="mock-list-text"><b>Nadia</b><span>Hi, boleh delivery ke Penang?</span></div>
            <span class="badge badge-open">Open</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a2">A</div>
            <div class="mock-list-text"><b>+60 12-345 6789</b><span>Dah bayar, tolong confirm ya</span></div>
            <span class="badge badge-pending">Pending</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a3">R</div>
            <div class="mock-list-text"><b>Raj</b><span>Nak reschedule appointment</span></div>
            <span class="badge badge-escalated">Esc.</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a4">L</div>
            <div class="mock-list-text"><b>Lina</b><span>Thanks kak, terima kasih!</span></div>
            <span class="badge badge-closed">Closed</span>
          </div>
        </div>
        <div class="mock-chat">
          <div class="mock-msg in"><span>Hi, boleh delivery ke Penang?</span></div>
          <div class="mock-msg out"><span>Boleh! Delivery ke Penang RM 8 sahaja. Order lebih RM 150 free shipping.</span></div>
          <div class="mock-msg in"><span>Berapa lama sampai?</span></div>
          <div class="mock-ai-draft">
            <div class="mock-ai-head">🤖 AI draft <span class="mock-ai-meta">📚 Shipping FAQ</span></div>
            <div class="mock-ai-body">Order sebelum 3pm, esok sampai (Semenanjung). Kalau East Malaysia 2-3 hari. Tracking number kami hantar bila parcel keluar dari gudang.</div>
            <div class="mock-ai-actions"><span class="mock-mini-btn">Use this</span><span class="mock-mini-btn">Edit</span></div>
          </div>
          <div class="mock-composer">Type your reply…</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="pain" class="landing-section landing-alt">
  <h2 class="landing-h2">Familiar problem?</h2>
  <p class="landing-sub">If any of these sound like your kedai/restoran/klinik — you're not alone. Every growing business on WhatsApp runs into the same wall.</p>
  <div class="landing-grid">
    <div class="feature-card">
      <h3>📱 One phone, one staff</h3>
      <p>Your business WhatsApp lives on <em>one</em> staff's phone. When she takes leave, is sick, or resigns — the whole customer chat history goes with her. Boss also cannot see anything.</p>
    </div>
    <div class="feature-card">
      <h3>💤 Miss messages at night</h3>
      <p>Customer message at 10pm. No one see until pagi esok. By then they already go to your competitor. You never know you lost the sale.</p>
    </div>
    <div class="feature-card">
      <h3>🤝 Two staff reply the same customer</h3>
      <p>WhatsApp Web opened on 2 laptops. Both staff reply with different prices, different promises. Customer confuse. Trust down.</p>
    </div>
    <div class="feature-card">
      <h3>👀 Boss no visibility</h3>
      <p>You don't know what your team is promising. Cannot see which customer waited 2 days. No idea response time. Report time — no data.</p>
    </div>
    <div class="feature-card">
      <h3>🆕 New staff start from zero</h3>
      <p>New hire join. Zero context. No FAQ, no history. They learn on real customers. Mistakes go straight to your reputation.</p>
    </div>
    <div class="feature-card">
      <h3>✍️ Same question 20 times a day</h3>
      <p>"Ada stock?" "Berapa harga?" "Waktu operasi?" "Alamat kedai?" Staff type the same reply all day — tak sempat handle actual orders.</p>
    </div>
  </div>
</section>

<section id="features" class="landing-section">
  <h2 class="landing-h2">Here's how <?= e(APP_NAME) ?> fixes it</h2>
  <p class="landing-sub">Everything a growing Malaysian team needs to run WhatsApp like a proper business.</p>
  <div class="landing-grid">
    <div class="feature-card">
      <h3>📥 Shared inbox</h3>
      <p>Every staff sees every chat from any browser. Same WhatsApp number, whole team working together.</p>
    </div>
    <div class="feature-card">
      <h3>🎯 One owner per chat</h3>
      <p>Chat auto-assigned to one agent. No more "eh I thought you were replying". No more double replies.</p>
    </div>
    <div class="feature-card feature-ai">
      <h3>🤖 AI drafts your replies</h3>
      <p>AI writes the reply in your business voice. Staff review, edit if need, then send. Never auto-sent without your approval.</p>
    </div>
    <div class="feature-card feature-ai">
      <h3>📚 Your own FAQ</h3>
      <p>Upload your menu, price list, opening hours. AI answers customers using YOUR facts, not made-up ones.</p>
    </div>
    <div class="feature-card">
      <h3>📣 Broadcast messages</h3>
      <p>Send promo to 1,000 customers a month for FREE. Upgrade to 10,000/month for RM 480. Yearly billing 20% off.</p>
    </div>
    <div class="feature-card">
      <h3>🍜 F&amp;B ordering module</h3>
      <p>Restaurant? Customers order via WhatsApp, AI takes the order, staff prints kitchen ticket. Kanban dashboard for orders.</p>
    </div>
    <div class="feature-card">
      <h3>💬 Web chat widget + QR</h3>
      <p>Print a QR sticker for your counter or table. Customer scans → chat opens in browser → order flows into the same inbox.</p>
    </div>
    <div class="feature-card">
      <h3>🏢 Multiple branches</h3>
      <p>KL, Penang, Ipoh outlets? Each branch has its own team, its own leads, its own WhatsApp. Fair round-robin — no salesman fighting.</p>
    </div>
    <div class="feature-card">
      <h3>📊 Manager reports</h3>
      <p>Response time, workload per staff, top topics customers ask about — all in one dashboard.</p>
    </div>
    <div class="feature-card">
      <h3>🔀 Smart routing</h3>
      <p>Auto-send delivery questions to Logistics, refund requests to Finance, sales enquiries to Sales. Set once, forget.</p>
    </div>
    <div class="feature-card">
      <h3>🔒 Full audit log</h3>
      <p>Every login, every reply, every plan change — recorded. Trust but verify.</p>
    </div>
    <div class="feature-card">
      <h3>📱 Install on phone</h3>
      <p>Add to home screen — feels like a native app. Face ID unlock, 6-digit PIN backup, stay signed in for 30 days.</p>
    </div>
  </div>
</section>

<section id="who" class="landing-section landing-alt">
  <h2 class="landing-h2">Who's using it?</h2>
  <p class="landing-sub">Different businesses, same problem — one WhatsApp inbox for the whole team.</p>
  <div class="landing-grid">
    <div class="feature-card">
      <h3>🍜 Restoran &amp; cafe</h3>
      <p>Take orders via WhatsApp AND via QR on tables. AI parses "2 nasi lemak ayam berempah, less spicy" straight into the kitchen ticket.</p>
    </div>
    <div class="feature-card">
      <h3>🛍 Retail &amp; e-commerce</h3>
      <p>Shipping, stock, sizing — the FAQ your staff types 50 times a day. AI draft, agent send, customer served in 30 seconds.</p>
    </div>
    <div class="feature-card">
      <h3>🩺 Klinik &amp; salon</h3>
      <p>Appointment booking flow: customer messages, AI collects service + date + name + phone, notifies the front desk to confirm.</p>
    </div>
    <div class="feature-card">
      <h3>🏢 Property &amp; agencies</h3>
      <p>Multiple agents, same office WhatsApp. Round-robin new leads by branch — no more agents fighting over the same enquiry.</p>
    </div>
    <div class="feature-card">
      <h3>🚚 Delivery &amp; logistics</h3>
      <p>Customer sends order number → auto-routed to Operations → status update in one click.</p>
    </div>
    <div class="feature-card">
      <h3>🎓 Tuition &amp; services</h3>
      <p>Parents ask about schedule / fee / location. AI answers from your prospectus. Staff only reply when it's a real enquiry.</p>
    </div>
  </div>
</section>

<section id="how" class="landing-section">
  <h2 class="landing-h2">Live in 5 minutes</h2>
  <div class="landing-steps">
    <div class="step">
      <div class="step-num">1</div>
      <h3>Sign up</h3>
      <p>Business name, your email, password. 30 seconds. Auto-logged in as workspace boss.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h3>Connect WhatsApp + upload FAQ</h3>
      <p>Point your WhatsApp Cloud API (or partner gateway) to us. Paste your FAQ. Enable AI. Done.</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h3>Invite team &amp; go</h3>
      <p>Add your staff. Customers message → AI drafts → team replies. Boss sees everything from any browser or phone.</p>
    </div>
  </div>
</section>

<?php
  $lp        = pricing_get();
  $lpCur     = $lp['currency'];
  $lpPer     = $lp['period_label'];
  $lpStPrice = fmt_price($lp['starter_price'],            $lpCur);
  $lpBdPrice = fmt_price($lp['bundle_price'],             $lpCur);
  $lpPerSeat = fmt_price($lp['per_seat'],                 $lpCur);
  $lpEffSeat = fmt_price($lp['effective_per_seat_growth'],$lpCur);
  $lpExtra   = fmt_price($lp['extra_seat_price'],         $lpCur);
  $lpSave    = ($lp['per_seat'] > 0 && $lp['bundle_price'] < ($lp['per_seat'] * $lp['bundle_seats']))
                 ? (int)round((1 - ($lp['bundle_price'] / max(0.01, $lp['per_seat'] * $lp['bundle_seats']))) * 100) : 0;
?>
<section id="plans" class="landing-section landing-alt">
  <h2 class="landing-h2">
    <?= $lpSave > 0
        ? 'Simple pricing — save ' . (int)$lpSave . '% with the team bundle'
        : 'Simple pricing' ?>
  </h2>
  <p class="landing-sub">
    <?= e($lpPerSeat) ?> per seat, or grab the <?= (int)$lp['bundle_seats'] ?>-seat team bundle for <?= e($lpBdPrice) ?> <?= e($lpPer) ?>.
    Every plan includes shared inbox, AI drafts, FAQ, F&amp;B module, and web widget.
  </p>
  <div class="landing-grid plans">
    <div class="plan-card">
      <h3>Starter</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($lpStPrice) ?></span>
        <span class="plan-price-unit"><?= e($lpPer) ?></span>
      </div>
      <p class="plan-price-sub muted small"><?= (int)$lp['starter_seats'] ?> seats · <?= e($lpPerSeat) ?> per seat</p>
      <ul>
        <li>Up to <?= (int)$lp['starter_seats'] ?> staff</li>
        <li>1 WhatsApp number</li>
        <li>AI drafts + FAQ knowledge base</li>
        <li>Shared inbox, notes, tags</li>
        <li>Web chat widget + QR</li>
      </ul>
      <p class="muted small">For small teams starting out.</p>
    </div>
    <div class="plan-card highlight">
      <div class="plan-badge"><?= $lpSave > 0 ? 'Most popular · save ' . (int)$lpSave . '%' : 'Most popular' ?></div>
      <h3>Growth</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($lpBdPrice) ?></span>
        <span class="plan-price-unit"><?= e($lpPer) ?></span>
      </div>
      <p class="plan-price-sub muted small"><?= (int)$lp['bundle_seats'] ?> seats · effectively <?= e($lpEffSeat) ?> per seat</p>
      <ul>
        <li>Up to <?= (int)$lp['bundle_seats'] ?> staff</li>
        <li>Everything in Starter</li>
        <li>Reports, routing rules, message templates</li>
        <li>F&amp;B ordering module</li>
        <li>Multi-branch support</li>
      </ul>
      <p class="muted small">Right size for most Malaysian teams.</p>
    </div>
    <div class="plan-card">
      <h3>Enterprise</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($lpBdPrice) ?></span>
        <span class="plan-price-unit">+ <?= e($lpExtra) ?> / extra seat</span>
      </div>
      <p class="plan-price-sub muted small"><?= (int)$lp['bundle_seats'] ?>+ seats · scale seat by seat</p>
      <ul>
        <li>Unlimited staff</li>
        <li>Multiple WhatsApp numbers</li>
        <li>Everything in Growth</li>
        <li>Priority support (BM &amp; EN)</li>
        <li>CRM integration (Odoo coming)</li>
      </ul>
      <p class="muted small">For multi-outlet operators.</p>
    </div>
  </div>

  <p style="text-align:center; margin-top: 22px;" class="muted small">
    Broadcast messages: <strong>1,000 recipients / month FREE</strong> · Paid tier <strong>10,000 / RM 480/mo</strong> · Yearly billing 20% off.
    AI usage billed separately at cost + margin — see <a href="/pricing.php">full pricing</a>.
  </p>
  <p style="text-align:center; margin-top: 8px;">
    <a class="btn" href="/pricing.php">See full pricing &amp; FAQ →</a>
  </p>
</section>

<section class="landing-section" style="background:#f0fdf4; border-top:1px solid #bbf7d0; border-bottom:1px solid #bbf7d0;">
  <h2 class="landing-h2" style="color:#14532d;">🇲🇾 Made for Malaysia</h2>
  <p class="landing-sub" style="max-width:640px; margin: 0 auto 20px;">
    We're a Malaysian team. We know local business, local banking, local payment habits.
    No US pricing games, no forced yearly contracts.
  </p>
  <div class="landing-grid" style="max-width:900px; margin: 0 auto;">
    <div class="feature-card">
      <h3>💳 Local payment</h3>
      <p>Bank transfer, DuitNow, e-wallet. Invoice comes with your SST number. No Stripe fees.</p>
    </div>
    <div class="feature-card">
      <h3>🗣 BM &amp; English support</h3>
      <p>WhatsApp us in Bahasa Melayu or English. Reply within one working day.</p>
    </div>
    <div class="feature-card">
      <h3>📉 No lock-in</h3>
      <p>Cancel any month. Downgrade any time. Free tier stays free forever — no "trial ends" surprise.</p>
    </div>
  </div>
</section>

<section class="landing-cta-band">
  <h2>Every day you wait, more customers slip through.</h2>
  <p>Set up your workspace in 30 seconds. Invite your team. Stop losing chats — tonight.</p>
  <a class="btn btn-primary btn-lg" href="/register.php">Start free — 30 seconds</a>
  <a class="btn btn-lg" href="/login.php" style="margin-left:8px;">Sign in</a>
</section>

<footer class="landing-footer">
  <div>&copy; <?= e((string)$year) ?> <?= e(APP_NAME) ?> · 🇲🇾 Made in Malaysia</div>
  <div>
    <a href="/login.php">Sign in</a>
    <span class="dot">·</span>
    <a href="/register.php">Sign up</a>
    <span class="dot">·</span>
    <a href="#features">Features</a>
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
