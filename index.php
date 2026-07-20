<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/cookie_notice.php';

// Logged-in users skip the landing page.
if (current_user()) {
    redirect('/dashboard.php');
}

$year = date('Y');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(APP_NAME) ?> · Stop losing customers in WhatsApp</title>
  <meta name="description" content="Turn one WhatsApp number into a team inbox. Every message replied to, nothing missed, boss can see everything, AI drafts every reply. Built for shops, clinics, agencies and service businesses.">
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
    <a href="#pain">Why</a>
    <a href="#features">Features</a>
    <a href="#how">How it works</a>
    <a href="/pricing.php">Pricing</a>
    <a class="btn btn-primary btn-sm" href="/login.php">Sign in</a>
  </nav>
</header>

<section class="landing-hero">
  <div class="landing-hero-inner">
    <span class="landing-eyebrow">Shared WhatsApp Inbox · AI-assisted</span>
    <h1>Stop losing customers<br>in WhatsApp.</h1>
    <p class="landing-lede">
      Right now, all your customer chats live on <strong>one staff member's phone</strong>.
      Messages get missed. Two people reply to the same customer. The boss has no idea what's
      being promised. New hires start from zero.
      <br><br>
      Give your team a real inbox — shared, live, with AI drafting every reply from your own FAQs.
      One WhatsApp number, your whole team, everything logged.
    </p>
    <div class="landing-cta">
      <a class="btn btn-primary btn-lg" href="/register.php">Start free · 30 seconds</a>
      <a class="btn btn-lg" href="/login.php">Sign in</a>
    </div>
    <ul class="landing-trust">
      <li>No app to install — works in your browser</li>
      <li>Keep your existing WhatsApp number</li>
      <li>AI drafts, humans send</li>
      <li>Cancel anytime</li>
    </ul>
  </div>

  <div class="landing-hero-mock" aria-hidden="true">
    <div class="mock-window">
      <div class="mock-titlebar">
        <span class="mock-dot dot-r"></span>
        <span class="mock-dot dot-y"></span>
        <span class="mock-dot dot-g"></span>
        <span class="mock-url">your-workspace.example.com</span>
      </div>
      <div class="mock-body">
        <div class="mock-side">
          <div class="mock-side-row active"><span>Mine</span><b>14</b></div>
          <div class="mock-side-row"><span>Unassigned</span><b>3</b></div>
          <div class="mock-side-row"><span>Open</span><b>22</b></div>
          <div class="mock-side-row"><span>Closed</span><b>108</b></div>
        </div>
        <div class="mock-list">
          <div class="mock-list-row">
            <div class="mock-avatar a1">N</div>
            <div class="mock-list-text"><b>Nadia Lim</b><span>Hi, do you ship to Penang?</span></div>
            <span class="badge badge-open">Open</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a2">A</div>
            <div class="mock-list-text"><b>+60 12 345 6789</b><span>Sent payment, please confirm</span></div>
            <span class="badge badge-pending">Pending</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a3">R</div>
            <div class="mock-list-text"><b>Raj Subramaniam</b><span>Need to reschedule</span></div>
            <span class="badge badge-escalated">Esc.</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a4">L</div>
            <div class="mock-list-text"><b>Lina (SLV)</b><span>Thanks, all sorted!</span></div>
            <span class="badge badge-closed">Closed</span>
          </div>
        </div>
        <div class="mock-chat">
          <div class="mock-msg in"><span>Hi, do you ship to Penang?</span></div>
          <div class="mock-msg out"><span>Hi Nadia! Yes we do — flat RM 8 within Peninsular Malaysia.</span></div>
          <div class="mock-msg in"><span>Great, and what's your return policy?</span></div>
          <div class="mock-ai-draft">
            <div class="mock-ai-head">🤖 AI suggested reply <span class="mock-ai-meta">📚 Shipping &amp; returns FAQ</span></div>
            <div class="mock-ai-body">We accept returns within 14 days of delivery as long as the item is unused and in its original packaging. Just reply here with your order number and we'll arrange the pickup.</div>
            <div class="mock-ai-actions"><span class="mock-mini-btn">Use this</span><span class="mock-mini-btn">Regenerate</span></div>
          </div>
          <div class="mock-composer">Type a reply…</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="pain" class="landing-section landing-alt">
  <h2 class="landing-h2">Sound familiar?</h2>
  <p class="landing-sub">If any of these hit close, you're not alone. Every growing business on WhatsApp runs into the same wall.</p>
  <div class="landing-grid">
    <div class="feature-card">
      <h3>📱 One phone, one person</h3>
      <p>Your WhatsApp lives on <em>one</em> staff member's device. When they're on leave, sick, or asleep, replies stop. When they quit, the chat history walks out with them.</p>
    </div>
    <div class="feature-card">
      <h3>💤 Messages slip through</h3>
      <p>Customer sends at 9pm. Nobody sees it until morning. By then they've messaged your competitor. You never even knew you lost them.</p>
    </div>
    <div class="feature-card">
      <h3>🤝 Double replies</h3>
      <p>Two staff open WhatsApp Web on the same number and reply to the same customer — with different answers. Awkward, and it kills trust.</p>
    </div>
    <div class="feature-card">
      <h3>👀 No visibility for the boss</h3>
      <p>You have zero idea what your team is promising customers. No response-time numbers. No way to spot the customer who's been waiting 2 days.</p>
    </div>
    <div class="feature-card">
      <h3>🆕 New hires start from zero</h3>
      <p>New agent joins. They have no history, no context, no idea how you usually answer FAQs. They learn on real customers, mistakes included.</p>
    </div>
    <div class="feature-card">
      <h3>✍️ Typing the same reply, again</h3>
      <p>Same 20 questions every day. "Do you ship to Sabah?" "What are your hours?" "How much is delivery?" Your team's whole day is copy-paste.</p>
    </div>
  </div>
</section>

<section id="features" class="landing-section">
  <h2 class="landing-h2">Here's how <?= e(APP_NAME) ?> fixes it</h2>
  <p class="landing-sub">Everything a growing team needs to run WhatsApp like a real desk.</p>
  <div class="landing-grid">
    <div class="feature-card">
      <h3>📥 Shared inbox</h3>
      <p>Every agent sees every chat, from any browser.</p>
    </div>
    <div class="feature-card">
      <h3>🔔 Live refresh</h3>
      <p>New messages appear in seconds. Sound alerts. No reload.</p>
    </div>
    <div class="feature-card">
      <h3>🎯 One owner per chat</h3>
      <p>Assigned agent per conversation. No double replies.</p>
    </div>
    <div class="feature-card feature-ai">
      <h3>🤖 AI drafts replies</h3>
      <p>Claude drafts, agent sends. Never auto-sent.</p>
    </div>
    <div class="feature-card feature-ai">
      <h3>📚 Your knowledge base</h3>
      <p>Upload FAQs. AI answers in your voice, with citations.</p>
    </div>
    <div class="feature-card">
      <h3>👀 Manager reports</h3>
      <p>Response time, workload, top topics — at a glance.</p>
    </div>
    <div class="feature-card">
      <h3>🧑‍💼 Role-based access</h3>
      <p>Admin, Manager, Agent. Everyone sees what they should.</p>
    </div>
    <div class="feature-card">
      <h3>📖 Full history + notes</h3>
      <p>Searchable past chats. Private notes for teammates.</p>
    </div>
    <div class="feature-card feature-ai">
      <h3>📊 Topic analytics</h3>
      <p>AI groups chats by theme. See what customers ask most.</p>
    </div>
    <div class="feature-card">
      <h3>🔀 Smart routing</h3>
      <p>Auto-send refund chats to Billing, delivery to Ops.</p>
    </div>
    <div class="feature-card">
      <h3>🔌 Keep your number</h3>
      <p>Meta Cloud API, Evolution, or any partner gateway.</p>
    </div>
    <div class="feature-card">
      <h3>🔒 Full audit log</h3>
      <p>Every login, assignment, reply — recorded.</p>
    </div>
  </div>
</section>

<section id="how" class="landing-section">
  <h2 class="landing-h2">Up and running in 5 minutes</h2>
  <div class="landing-steps">
    <div class="step">
      <div class="step-num">1</div>
      <h3>Create your workspace</h3>
      <p>Sign up with company name, your email, password. Choose a plan. You're auto-logged in as the workspace admin in 30 seconds.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h3>Connect WhatsApp + AI</h3>
      <p>Pick a provider in Settings (Cloud API, Evolution, or partner gateway). Drop in your Anthropic API key and upload your FAQ docs.</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h3>Invite your team &amp; reply</h3>
      <p>Add up to 10 teammates on Growth. Customers message; AI drafts; agents send. Manager watches the queue, reports flag bottlenecks.</p>
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
    <?= e($lpPerSeat) ?> per seat, or grab the <?= (int)$lp['bundle_seats'] ?>-seat bundle for <?= e($lpBdPrice) ?> <?= e($lpPer) ?>.
    Every plan includes AI suggestions and the knowledge base.
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
        <li>Up to <?= (int)$lp['starter_seats'] ?> agents</li>
        <li>1 WhatsApp number</li>
        <li>AI suggestions &amp; knowledge base</li>
        <li>Shared inbox + notes + tags</li>
      </ul>
      <p class="muted small">For small teams getting started.</p>
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
        <li>Up to <?= (int)$lp['bundle_seats'] ?> agents</li>
        <li>1 WhatsApp number</li>
        <li>Everything in Starter</li>
        <li>Reports, routing rules, templates</li>
        <li>All 3 provider options</li>
      </ul>
      <p class="muted small">Built for typical customer-service teams.</p>
    </div>
    <div class="plan-card">
      <h3>Enterprise</h3>
      <div class="plan-price">
        <span class="plan-price-amount"><?= e($lpBdPrice) ?></span>
        <span class="plan-price-unit">+ <?= e($lpExtra) ?> / extra seat</span>
      </div>
      <p class="plan-price-sub muted small"><?= (int)$lp['bundle_seats'] ?>+ seats · scale seat by seat</p>
      <ul>
        <li>Unlimited agents</li>
        <li>Multiple numbers</li>
        <li>Everything in Growth</li>
        <li>Priority support</li>
        <li>CRM integrations (Odoo coming)</li>
      </ul>
      <p class="muted small">For multi-brand operators and resellers.</p>
    </div>
  </div>
  <p style="text-align:center; margin-top: 18px;">
    <a class="btn" href="/pricing.php">See full pricing &amp; FAQ →</a>
  </p>
</section>

<section class="landing-cta-band">
  <h2>Every day you wait, more customers slip through.</h2>
  <p>Set up your workspace in 30 seconds. Invite your team. Stop losing chats — tonight.</p>
  <a class="btn btn-primary btn-lg" href="/register.php">Start free · 30 seconds</a>
  <a class="btn btn-lg" href="/login.php" style="margin-left:8px;">Sign in</a>
</section>

<footer class="landing-footer">
  <div>&copy; <?= e((string)$year) ?> <?= e(APP_NAME) ?></div>
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
