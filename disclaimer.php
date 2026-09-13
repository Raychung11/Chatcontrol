<?php
require_once __DIR__ . '/inc/legal_layout.php';
legal_page_start('Disclaimer', platform_setting('legal_disclaimer_updated', '27 June 2026'));
$app = e(APP_NAME);
?>
<p>
  This Disclaimer applies to your use of <?= $app ?> ("Service"). It is
  intended to be read alongside our
  <a href="/terms.php">Terms of Service</a> and
  <a href="/privacy.php">Privacy Policy</a>.
</p>

<h2>1. No warranty</h2>
<p>
  The Service is provided "as is" and "as available", with all faults and
  without warranty of any kind. We make no warranty that the Service will be
  uninterrupted, error-free, secure, or that defects will be corrected.
</p>

<h2>2. WhatsApp &amp; Meta</h2>
<p>
  <?= $app ?> is not affiliated with, endorsed, or sponsored by Meta
  Platforms, Inc. or WhatsApp LLC. "WhatsApp" is a trademark of WhatsApp LLC.
  Your use of WhatsApp via the Cloud API is also subject to Meta's
  WhatsApp Business Solution Terms.
</p>

<h2>3. Unofficial providers (Evolution, third-party gateways)</h2>
<p>
  The Service can connect to unofficial WhatsApp gateways including the
  Evolution API and partner-hosted Bearer-token APIs. Such providers operate
  outside Meta's official Cloud API and may violate Meta's Terms of Service.
  Using them may result in your number being temporarily restricted or
  permanently banned by Meta with no recovery path.
</p>
<p>
  <strong>You use unofficial providers at your own risk.</strong> We
  disclaim all liability for outages, message delivery failures, account
  suspensions, data loss, or bans imposed by Meta or any third-party
  provider.
</p>

<h2>4. AI-generated content</h2>
<p>
  When AI reply suggestions are enabled, drafts are produced by large language
  models hosted by Anthropic, PBC. AI output may be:
</p>
<ul>
  <li>inaccurate, incomplete, biased, or out of date;</li>
  <li>inconsistent across runs;</li>
  <li>misaligned with your knowledge base or company policy.</li>
</ul>
<p>
  AI drafts are <strong>not legal, medical, financial, or other professional
  advice</strong> and must be reviewed by a human agent before being sent. You
  are solely responsible for the accuracy, lawfulness, and suitability of any
  reply your team sends, including replies that originated as AI drafts.
</p>

<h2>5. Customer-service messages are not professional advice</h2>
<p>
  Unless the Workspace operator is appropriately licensed and explicitly
  states otherwise, conversations conducted through the Service are general
  customer-service communications. They are not a substitute for professional
  consultation in regulated fields (legal, medical, financial, etc.).
</p>

<h2>6. Service availability</h2>
<p>
  We do not guarantee any specific uptime. The Service depends on third-party
  infrastructure including your hosting provider, Meta WhatsApp, Anthropic,
  your Evolution server, and your partner gateway. Outages at any of these
  third parties may cause the Service to be unavailable or messages to be
  delayed.
</p>

<h2>7. External links</h2>
<p>
  The Service may contain links to third-party websites and documentation.
  We do not control or endorse the content of those sites and accept no
  responsibility for them.
</p>

<h2>8. Limitation of liability</h2>
<p>
  Our liability is limited as described in the
  <a href="/terms.php">Terms of Service</a>. To the maximum extent permitted
  by applicable law, we will not be liable for indirect, incidental, special,
  consequential, or punitive damages arising out of or in connection with your
  use of the Service.
</p>

<h2>9. Changes</h2>
<p>
  We may update this Disclaimer at any time without prior notice. The "Last
  updated" date at the top of this page reflects the most recent revision.
</p>

<?php legal_page_end();
