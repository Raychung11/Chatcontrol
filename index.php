<?php
require_once __DIR__ . '/inc/auth.php';

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
  <title><?= e(APP_NAME) ?> · WhatsApp customer service with AI assist</title>
  <meta name="description" content="Shared WhatsApp inbox for customer service teams. AI drafts replies grounded in your knowledge base. Three provider options: Meta Cloud API, Evolution, or any partner gateway.">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="landing-body">

<header class="landing-nav">
  <a class="landing-brand" href="/">
    <span class="brand-dot" style="background:#25D366"></span>
    <span class="brand-text"><?= e(APP_NAME) ?></span>
  </a>
  <nav class="landing-nav-links">
    <a href="#features">Features</a>
    <a href="#providers">Providers</a>
    <a href="#how">How it works</a>
    <a href="#plans">Plans</a>
    <a class="btn btn-primary btn-sm" href="/login.php">Sign in</a>
  </nav>
</header>

<section class="landing-hero">
  <div class="landing-hero-inner">
    <span class="landing-eyebrow">Shared WhatsApp Inbox · AI-assisted</span>
    <h1>One WhatsApp number.<br>Your team. With AI.</h1>
    <p class="landing-lede">
      Run customer service on WhatsApp the way Intercom runs email. Multiple agents,
      one shared inbox, live refresh, role-based access — and Claude drafts a reply
      for every customer message, grounded in your own knowledge base.
    </p>
    <div class="landing-cta">
      <a class="btn btn-primary btn-lg" href="/register.php">Create workspace · free</a>
      <a class="btn btn-lg" href="/login.php">Sign in</a>
    </div>
    <ul class="landing-trust">
      <li>3 WhatsApp providers supported</li>
      <li>AI drafts grounded in your docs</li>
      <li>Live inbox refresh</li>
      <li>Up to 10 agents on Growth</li>
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

<section id="features" class="landing-section">
  <h2 class="landing-h2">Built for customer service teams that share one number</h2>
  <p class="landing-sub">Everything you need to run WhatsApp like a real support desk — plus AI.</p>
  <div class="landing-grid">
    <div class="feature-card feature-ai">
      <h3>🤖 AI reply suggestions</h3>
      <p>Claude drafts a reply for every customer message. Agents review, edit, send. AI never sends on its own. Per-workspace API key.</p>
    </div>
    <div class="feature-card feature-ai">
      <h3>📚 Knowledge base</h3>
      <p>Upload PDFs, Word docs, or paste your FAQs. The AI grounds answers in your own content — pricing, policies, hours. Citations shown on every draft.</p>
    </div>
    <div class="feature-card">
      <h3>Live shared inbox</h3>
      <p>Auto-refreshes every 5 seconds. New messages appear without reload, delivery ticks update in place, sound alerts on new inbound.</p>
    </div>
    <div class="feature-card">
      <h3>Role-based access</h3>
      <p>Super Admin, Manager, and Agent roles. Managers monitor and assign; agents only see what's relevant.</p>
    </div>
    <div class="feature-card">
      <h3>Smart assignment &amp; routing</h3>
      <p>Auto-route new conversations by keyword to the right department. Reassign, escalate, change owner in one click.</p>
    </div>
    <div class="feature-card">
      <h3>Templates &amp; 24h window</h3>
      <p>For Cloud API tenants, we track Meta's customer service window per conversation and prompt template use after expiry.</p>
    </div>
    <div class="feature-card">
      <h3>Internal notes &amp; tags</h3>
      <p>Leave private context for teammates. Color-coded tags for filtering. Customers never see either.</p>
    </div>
    <div class="feature-card">
      <h3>Reports with date range</h3>
      <p>Conversation totals, avg first response, avg resolution time, per-agent stats, daily volume, top tags. All filterable by date.</p>
    </div>
    <div class="feature-card">
      <h3>Activity logs</h3>
      <p>Every login, assignment, status change, AI suggestion, and reply is logged for auditing and coaching.</p>
    </div>
  </div>
</section>

<section id="providers" class="landing-section landing-alt">
  <h2 class="landing-h2">Pick your WhatsApp provider</h2>
  <p class="landing-sub">Each workspace chooses its own. Switch anytime in Settings — the inbox stays exactly the same.</p>
  <div class="landing-grid">
    <div class="feature-card provider-card">
      <h3>Meta Cloud API</h3>
      <p class="muted small">Official, ban-safe, paid per conversation</p>
      <ul class="provider-bullets">
        <li>Stable &amp; supported by Meta</li>
        <li>Requires Phone Number ID + access token</li>
        <li>Templates required outside 24h window</li>
      </ul>
    </div>
    <div class="feature-card provider-card">
      <h3>Evolution API</h3>
      <p class="muted small">Self-hosted, unofficial, free messaging</p>
      <ul class="provider-bullets">
        <li>QR pairing — no number migration</li>
        <li>Docker compose included in repo</li>
        <li>Risk: Meta may ban the number</li>
      </ul>
    </div>
    <div class="feature-card provider-card">
      <h3>Partner gateway</h3>
      <p class="muted small">Any Bearer-token HTTP gateway</p>
      <ul class="provider-bullets">
        <li>Plug in your reseller's API</li>
        <li>One-click test send from Settings</li>
        <li>Outbound &amp; inbound both wired</li>
      </ul>
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

<section id="plans" class="landing-section landing-alt">
  <h2 class="landing-h2">Plans</h2>
  <p class="landing-sub">All plans include AI suggestions and the knowledge base. Bring your own Anthropic API key — you pay for AI at cost.</p>
  <div class="landing-grid plans">
    <div class="plan-card">
      <h3>Starter</h3>
      <ul>
        <li>3 agents</li>
        <li>1 WhatsApp number</li>
        <li>AI suggestions &amp; knowledge base</li>
        <li>Shared inbox + notes + tags</li>
      </ul>
      <p class="muted small">For small teams getting started.</p>
    </div>
    <div class="plan-card highlight">
      <div class="plan-badge">Most popular</div>
      <h3>Growth</h3>
      <ul>
        <li>10 agents</li>
        <li>1 WhatsApp number</li>
        <li>Everything in Starter</li>
        <li>Reports, routing rules, templates</li>
        <li>All 3 provider options</li>
      </ul>
      <p class="muted small">Built for typical customer-service teams.</p>
    </div>
    <div class="plan-card">
      <h3>Enterprise</h3>
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
</section>

<section class="landing-cta-band">
  <h2>Ready to take your WhatsApp inbox seriously?</h2>
  <p>Create your workspace in 30 seconds, then invite your team.</p>
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
    <a href="#features">Features</a>
    <span class="dot">·</span>
    <a href="#plans">Plans</a>
    <span class="dot">·</span>
    <a href="/terms.php">Terms</a>
    <span class="dot">·</span>
    <a href="/privacy.php">Privacy</a>
    <span class="dot">·</span>
    <a href="/disclaimer.php">Disclaimer</a>
  </div>
</footer>

</body>
</html>
