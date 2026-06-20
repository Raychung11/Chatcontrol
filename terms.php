<?php
require_once __DIR__ . '/inc/legal_layout.php';
legal_page_start('Terms of Service');
$app = e(APP_NAME);
?>
<p>
  These Terms of Service ("Terms") govern your access to and use of <?= $app ?>
  ("Service", "we", "us"). By creating an account, accessing the portal, or
  otherwise using the Service, you agree to be bound by these Terms. If you do not
  agree, do not use the Service.
</p>

<h2>1. Eligibility &amp; account</h2>
<p>
  You must be at least 18 years old and authorized to bind the organization
  (the "Workspace") you register on behalf of. You are responsible for the security
  of your credentials and all activity that occurs under your account, including
  the activity of teammates you invite.
</p>

<h2>2. Description of the Service</h2>
<p>
  The Service is a shared inbox for managing WhatsApp customer-service conversations.
  It includes integrations with third-party messaging providers (Meta WhatsApp
  Business Cloud API, the Evolution API, and partner gateways) and optional
  AI-assisted reply suggestions. Specific features available to your Workspace
  depend on the plan you choose and the providers you connect.
</p>

<h2>3. Acceptable use</h2>
<p>You agree not to use the Service to:</p>
<ul>
  <li>send spam, bulk unsolicited messages, or any content that violates the
      WhatsApp Business Solution Terms or any applicable Meta policies;</li>
  <li>transmit content that is illegal, defamatory, infringing, harassing,
      or that violates the privacy or intellectual-property rights of others;</li>
  <li>impersonate any person or entity, or misrepresent your affiliation;</li>
  <li>reverse-engineer, decompile, or attempt to circumvent any technical
      protection mechanism of the Service;</li>
  <li>use the Service to provide regulated professional advice (legal, medical,
      financial) unless you are appropriately licensed and assume full
      responsibility for that advice.</li>
</ul>

<h2>4. Plans, seats, and fees</h2>
<p>
  Each Workspace selects a plan that limits the number of active user seats and
  available features. We may update plans and pricing on reasonable notice.
  Where the Service is offered on a paid subscription, fees are charged in
  advance for each billing cycle and are non-refundable except where required
  by applicable law.
</p>
<p>
  You are responsible for fees charged by third parties you connect to your
  Workspace (Meta, Anthropic for AI suggestions, your partner gateway, your
  Evolution server hosting, etc.). The Service does not pay those fees on
  your behalf.
</p>

<h2>5. Customer data &amp; messages</h2>
<p>
  You retain all rights to the customer conversations, contacts, and uploads
  ("Customer Data") that you process through the Service. You grant us a limited
  licence to host, transmit, and process Customer Data solely for the purpose of
  providing the Service to you. Our processing of personal data is described
  separately in the <a href="/privacy.php">Privacy Policy</a>.
</p>

<h2>6. AI features</h2>
<p>
  When AI suggestions are enabled, message context and any knowledge-base articles
  you upload are sent to Anthropic, PBC for processing. AI suggestions are
  drafts only; they are not sent until a human agent reviews and confirms.
  You are responsible for the accuracy, suitability, and lawfulness of any reply
  you send, including replies that originated as AI drafts.
</p>

<h2>7. Third-party providers</h2>
<p>
  The Service connects to messaging providers (e.g. Meta, Evolution, partner
  gateways) at your direction. We do not control those providers, do not
  guarantee their availability or pricing, and disclaim all liability for
  outages, account suspensions, or bans imposed by them. Using an unofficial
  provider such as Evolution may violate Meta's Terms of Service and may
  result in your number being permanently banned by Meta — use it at your
  own risk.
</p>

<h2>8. Suspension &amp; termination</h2>
<p>
  We may suspend or terminate a Workspace immediately if we reasonably believe
  it has violated these Terms or is being used to abuse, harass, defraud, or
  otherwise harm others. You may close your Workspace at any time from the
  admin settings.
</p>

<h2>9. Intellectual property</h2>
<p>
  The Service, including the software, documentation, branding, and design,
  is owned by <?= $app ?> and protected by intellectual-property laws.
  We grant you a limited, non-exclusive, non-transferable licence to use the
  Service in accordance with these Terms.
</p>

<h2>10. Disclaimers</h2>
<p>
  THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE". TO THE MAXIMUM EXTENT
  PERMITTED BY APPLICABLE LAW, WE DISCLAIM ALL WARRANTIES, EXPRESS OR IMPLIED,
  INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND
  NON-INFRINGEMENT. See the <a href="/disclaimer.php">Disclaimer</a> for
  additional notices, including those that apply to AI-generated content.
</p>

<h2>11. Limitation of liability</h2>
<p>
  TO THE MAXIMUM EXTENT PERMITTED BY APPLICABLE LAW, OUR AGGREGATE LIABILITY
  ARISING OUT OF OR RELATING TO THESE TERMS OR THE SERVICE WILL NOT EXCEED
  THE GREATER OF (A) THE FEES YOU PAID US FOR THE SERVICE IN THE 12 MONTHS
  PRECEDING THE EVENT GIVING RISE TO THE CLAIM, OR (B) ONE HUNDRED
  US DOLLARS (USD 100). IN NO EVENT WILL WE BE LIABLE FOR INDIRECT,
  INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES.
</p>

<h2>12. Indemnity</h2>
<p>
  You will defend, indemnify, and hold us harmless from claims arising from
  your Customer Data, your use of the Service in breach of these Terms, or
  your violation of any law or third-party right.
</p>

<h2>13. Governing law</h2>
<p>
  These Terms are governed by the laws of Malaysia, without regard to its
  conflict-of-laws principles. The courts of Kuala Lumpur, Malaysia have
  exclusive jurisdiction over any dispute arising out of or relating to these
  Terms or the Service.
</p>

<h2>14. Changes</h2>
<p>
  We may update these Terms from time to time. Material changes will be
  notified by email to Workspace administrators or by an in-app notice.
  Continued use of the Service after a change takes effect constitutes
  acceptance of the updated Terms.
</p>

<h2>15. Contact</h2>
<p>Questions about these Terms can be sent to your Workspace administrator,
or to the operator of the Service at the support address listed in your
Workspace settings.</p>

<?php legal_page_end();
