<?php
require_once __DIR__ . '/inc/legal_layout.php';
legal_page_start('Privacy Policy', platform_setting('legal_privacy_updated', '27 June 2026'));
$app   = e(APP_NAME);
$op    = operator_legal_info();
$opEnt = $op['legal_name'] !== '' ? e($op['legal_name']) : $app;
$opReg = $op['registration_no'] !== '' ? ' (' . e($op['registration_no']) . ')' : '';
$opAdd = $op['address'] !== '' ? e($op['address']) : '';
$opMail= $op['email'] !== '' ? e($op['email']) : '';
?>
<p>
  This Privacy Policy describes how <?= $opEnt ?><?= $opReg ?> ("we", "us")
  collects, uses, and shares personal data when you and your team use <?= $app ?>
  ("Service"). It is designed to comply with the principles of the Malaysian
  Personal Data Protection Act 2010 (PDPA) and similar data-protection
  frameworks worldwide.
</p>

<h2>1. Who is the data controller</h2>
<p>
  For data about <strong>your customers</strong> (the people sending you
  WhatsApp messages), you (the Workspace) are the data controller. We act as a
  data processor on your behalf.
</p>
<p>
  For data about <strong>your team members</strong> (the users you invite to
  the portal) and Workspace administrators, we are the data controller.
</p>

<h2>2. What we collect</h2>
<ul>
  <li><strong>Account &amp; Workspace data</strong>: company name, workspace
      slug, billing details, your name, email, hashed password, role, and
      timestamps.</li>
  <li><strong>Conversation content</strong>: incoming and outgoing WhatsApp
      messages, message metadata (timestamps, delivery status, sender),
      attached media files, internal notes, and tags.</li>
  <li><strong>Contact records</strong>: customer WhatsApp ID, phone number,
      profile name as supplied by WhatsApp, and any contact-level metadata
      your team adds.</li>
  <li><strong>Activity logs</strong>: logins, assignments, message sends,
      template uses, AI-suggestion requests, and other actions taken in the
      portal.</li>
  <li><strong>Webhook events</strong>: the raw payloads delivered by your
      configured messaging provider, retained for diagnostics.</li>
  <li><strong>Knowledge-base uploads</strong>: documents, PDFs, or text you
      upload for AI grounding, plus the extracted plain text.</li>
  <li><strong>Technical data</strong>: IP address, browser user agent,
      and session cookies necessary to keep you logged in.</li>
</ul>

<h2>3. How we use it</h2>
<p>We process the data above only to:</p>
<ul>
  <li>provide and maintain the Service;</li>
  <li>route inbound WhatsApp messages to the right Workspace and agent;</li>
  <li>generate AI reply drafts, when AI is enabled by the Workspace admin;</li>
  <li>produce activity logs and reports requested by Workspace admins;</li>
  <li>diagnose problems (the webhook log page exposes recent payloads to
      Workspace admins);</li>
  <li>comply with legal obligations and enforce our Terms.</li>
</ul>

<h2>4. Third-party processors</h2>
<p>
  Depending on the providers and AI features your Workspace enables, we may
  share data with the following processors. Their own privacy notices apply
  to data they receive:
</p>
<ul>
  <li><strong>Meta Platforms, Inc.</strong> — when you use the WhatsApp
      Business Cloud API to send/receive messages. Messages traverse Meta's
      servers.</li>
  <li><strong>Your Evolution API host</strong> or <strong>partner gateway</strong>
      — when you use those providers, messages pass through their
      infrastructure on the way to and from WhatsApp.</li>
  <li><strong>Anthropic, PBC</strong> — when AI reply suggestions are enabled,
      the last 20 messages of context, your system prompt, and any active
      knowledge-base articles are sent to Anthropic's Messages API for
      processing. Anthropic does not train models on API traffic by default
      (see Anthropic's commercial-terms statements). Data is processed in
      the United States.</li>
  <li><strong>Hostinger</strong> (or your own VPS provider) — hosts the portal
      application and database.</li>
</ul>

<h2>5. Where data is stored</h2>
<p>
  Workspace data is stored on the server you (or your operator) deployed the
  portal on. If you use third-party processors above, copies of relevant data
  may also be transmitted to and stored in their jurisdictions, which may
  include the United States and European Union.
</p>

<h2>6. Retention</h2>
<p>
  We retain conversation content, contacts, and activity logs for as long as
  your Workspace remains active, unless you delete them sooner via the admin
  interface. Webhook diagnostic logs are retained at the discretion of the
  Workspace admin. When a Workspace is closed, related data is deleted within
  90 days unless retention is required by law.
</p>

<h2>7. Your rights</h2>
<p>Subject to applicable law, you have the right to:</p>
<ul>
  <li>access the personal data we hold about you;</li>
  <li>correct inaccurate data via your profile or by contacting the Workspace
      admin;</li>
  <li>delete your account and associated data;</li>
  <li>export Workspace data (contacts, conversations) on request;</li>
  <li>object to or restrict certain processing;</li>
  <li>withdraw consent for optional features such as AI suggestions at any
      time.</li>
</ul>
<p>
  Requests should be directed to your Workspace administrator. If you are a
  customer messaging a business that uses the Service, please contact that
  business directly — they are the controller of the conversation.
</p>

<h2>8. Security</h2>
<p>
  We protect data with industry-standard measures: HTTPS for all browser
  traffic, hashed passwords (bcrypt), per-session CSRF tokens, role-based
  access control, audit logging, and HMAC-signed media URLs for outbound
  attachments. No system is 100% secure; please report suspected
  vulnerabilities to the Workspace administrator.
</p>

<h2>9. Cookies &amp; sessions</h2>
<p>
  We set a single first-party session cookie (HttpOnly, SameSite=Lax, Secure
  over HTTPS) to keep you logged in. We do not use third-party analytics or
  advertising cookies.
</p>

<h2>10. Children's privacy</h2>
<p>
  The Service is not intended for use by individuals under 18. We do not
  knowingly collect personal data from children.
</p>

<h2>11. Changes to this Policy</h2>
<p>
  We may update this Policy from time to time. Material changes will be
  notified by email to Workspace administrators or by an in-app notice. The
  "Last updated" date at the top of this page reflects the most recent
  revision.
</p>

<h2>12. Contact</h2>
<p>
  Questions about this Policy or to exercise your rights, contact your
  Workspace administrator first. For Workspace-administrator-level inquiries
  or data-subject access requests under PDPA s.30, contact us directly:
</p>
<ul>
  <li><strong><?= $opEnt ?></strong><?= $opReg ?></li>
  <?php if ($opAdd !== ''): ?><li><?= nl2br($opAdd) ?></li><?php endif; ?>
  <?php if ($opMail !== ''): ?><li>Email: <a href="mailto:<?= $opMail ?>"><?= $opMail ?></a></li><?php endif; ?>
</ul>
<p>
  We aim to respond to verified data-subject requests within 21 days, which is
  the period set by PDPA s.30(3).
</p>

<?php legal_page_end();
