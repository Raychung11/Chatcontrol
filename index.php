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
  <title><?= e(APP_NAME) ?> · One WhatsApp number, your whole team</title>
  <meta name="description" content="AiServe Shared WhatsApp Inbox Portal — let 10+ staff manage one official WhatsApp Business number from one secure dashboard.">
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
    <a href="#how">How it works</a>
    <a href="#plans">Plans</a>
    <a class="btn btn-primary btn-sm" href="/login.php">Sign in</a>
  </nav>
</header>

<section class="landing-hero">
  <div class="landing-hero-inner">
    <span class="landing-eyebrow">Shared WhatsApp Inbox · for AiServe / SLV Group</span>
    <h1>One WhatsApp number.<br>Your entire team.</h1>
    <p class="landing-lede">
      Let 10+ agents reply, assign and track conversations from the same official
      WhatsApp Business number — without sharing a phone, without logging out, without missing a message.
    </p>
    <div class="landing-cta">
      <a class="btn btn-primary btn-lg" href="/login.php">Sign in to portal</a>
      <a class="btn btn-lg" href="#features">See how it works</a>
    </div>
    <ul class="landing-trust">
      <li>WhatsApp Cloud API</li>
      <li>Role-based access</li>
      <li>24-hour window tracking</li>
      <li>Activity logs</li>
    </ul>
  </div>

  <div class="landing-hero-mock" aria-hidden="true">
    <div class="mock-window">
      <div class="mock-titlebar">
        <span class="mock-dot dot-r"></span>
        <span class="mock-dot dot-y"></span>
        <span class="mock-dot dot-g"></span>
        <span class="mock-url">inbox.aiserve.example.com</span>
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
            <div class="mock-list-text"><b>Nadia Lim</b><span>Hi, is the package still available?</span></div>
            <span class="badge badge-open">Open</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a2">A</div>
            <div class="mock-list-text"><b>+60 12 345 6789</b><span>Sent payment, please confirm</span></div>
            <span class="badge badge-pending">Pending</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a3">R</div>
            <div class="mock-list-text"><b>Raj Subramaniam</b><span>Need to reschedule appointment</span></div>
            <span class="badge badge-escalated">Esc.</span>
          </div>
          <div class="mock-list-row">
            <div class="mock-avatar a4">L</div>
            <div class="mock-list-text"><b>Lina (SLV)</b><span>Thanks, all sorted!</span></div>
            <span class="badge badge-closed">Closed</span>
          </div>
        </div>
        <div class="mock-chat">
          <div class="mock-msg in"><span>Hi, is the package still available?</span></div>
          <div class="mock-msg out"><span>Yes Nadia! Sending you the latest details now.</span></div>
          <div class="mock-msg in"><span>Great, can I pay tomorrow?</span></div>
          <div class="mock-msg out"><span>Of course — I'll hold it until 5pm tomorrow.</span></div>
          <div class="mock-composer">Type a reply…</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="features" class="landing-section">
  <h2 class="landing-h2">Built for customer service teams that share one number</h2>
  <p class="landing-sub">Everything you need to run WhatsApp like a real support desk.</p>
  <div class="landing-grid">
    <div class="feature-card">
      <h3>Shared inbox</h3>
      <p>Multiple agents see the same conversations in real time. Filter by Mine, Unassigned, Open, Pending, Closed, Escalated.</p>
    </div>
    <div class="feature-card">
      <h3>Role-based access</h3>
      <p>Super Admin, Manager, and Agent roles. Managers can monitor and assign; agents only see what's relevant to them.</p>
    </div>
    <div class="feature-card">
      <h3>Smart assignment</h3>
      <p>One agent owns each conversation, while managers can intervene. Reassign, change department, escalate in one click.</p>
    </div>
    <div class="feature-card">
      <h3>24-hour window tracking</h3>
      <p>We track Meta's customer service window per conversation and block free-text replies after expiry, prompting you to use an approved template.</p>
    </div>
    <div class="feature-card">
      <h3>Internal notes</h3>
      <p>Leave private context for teammates that the customer never sees — perfect for handovers and follow-ups.</p>
    </div>
    <div class="feature-card">
      <h3>Templates</h3>
      <p>Manage approved WhatsApp templates for follow-ups, reminders, payment nudges, and re-engagement.</p>
    </div>
    <div class="feature-card">
      <h3>Reports</h3>
      <p>Total conversations, average first response, per-agent reply count, daily and monthly volume — all in one page.</p>
    </div>
    <div class="feature-card">
      <h3>Activity logs</h3>
      <p>Every login, assignment, status change, and reply is logged for auditing and team coaching.</p>
    </div>
    <div class="feature-card">
      <h3>AI ready</h3>
      <p>Reply suggestions, auto summaries, and FAQ answers are scaffolded — never auto-sent, always agent-approved.</p>
    </div>
  </div>
</section>

<section id="how" class="landing-section landing-alt">
  <h2 class="landing-h2">How it works</h2>
  <div class="landing-steps">
    <div class="step">
      <div class="step-num">1</div>
      <h3>Connect your WhatsApp number</h3>
      <p>Plug in your Meta Cloud API credentials in Settings — Phone Number ID, Business Account ID, access token, webhook verify token.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h3>Invite your team</h3>
      <p>Create departments, add agents and managers with role-based permissions. No per-agent WhatsApp licence needed — Meta charges per conversation, not per seat.</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h3>Reply faster, together</h3>
      <p>Customers message your one official number; the team picks up assignments from the shared inbox, replies in seconds, and managers watch the queue.</p>
    </div>
  </div>
</section>

<section id="plans" class="landing-section">
  <h2 class="landing-h2">Plans designed for resale</h2>
  <p class="landing-sub">Database is plan-aware from day one — start small, upgrade without migrations.</p>
  <div class="landing-grid plans">
    <div class="plan-card">
      <h3>Starter</h3>
      <ul>
        <li>3 agents</li>
        <li>1 WhatsApp number</li>
        <li>Basic shared inbox</li>
        <li>Internal notes</li>
      </ul>
      <p class="muted small">For small teams getting started.</p>
    </div>
    <div class="plan-card highlight">
      <div class="plan-badge">Most popular</div>
      <h3>Growth</h3>
      <ul>
        <li>10 agents</li>
        <li>1 WhatsApp number</li>
        <li>Reports &amp; analytics</li>
        <li>Assignment &amp; routing</li>
        <li>Approved templates</li>
      </ul>
      <p class="muted small">Built for SLV Group's typical customer service team.</p>
    </div>
    <div class="plan-card">
      <h3>Enterprise</h3>
      <ul>
        <li>Unlimited agents</li>
        <li>Multiple numbers</li>
        <li>AI reply assistant</li>
        <li>API integrations &amp; CRM</li>
        <li>Priority support</li>
      </ul>
      <p class="muted small">For multi-brand operators and resellers.</p>
    </div>
  </div>
</section>

<section class="landing-cta-band">
  <h2>Ready to take your WhatsApp inbox seriously?</h2>
  <p>Sign in to the portal or contact AiServe to set up a new tenant.</p>
  <a class="btn btn-primary btn-lg" href="/login.php">Sign in to portal</a>
</section>

<footer class="landing-footer">
  <div>&copy; <?= e((string)$year) ?> AiServe / SLV Group · Shared WhatsApp Inbox Portal</div>
  <div>
    <a href="/login.php">Sign in</a>
    <span class="dot">·</span>
    <a href="#features">Features</a>
    <span class="dot">·</span>
    <a href="#plans">Plans</a>
  </div>
</footer>

</body>
</html>
