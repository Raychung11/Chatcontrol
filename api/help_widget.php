<?php
/**
 * POST /api/help_widget.php
 *
 * In-app AI help widget backend.
 *
 * Operators click the 💬 Help bubble → the widget POSTs their question
 * plus the last few messages of history + the current page path. Claude
 * responds using a system prompt that describes THIS platform's own
 * features (broadcast, flows, KB, F&B, channels, invoices).
 *
 * Not the customer chatbot — that's the widget at /widget/*. This one
 * is for the operators using the admin portal.
 *
 * Params (JSON body):
 *   messages : [{role: 'user'|'assistant', content: string}, ...]
 *   context  : { path: string, page_title: string }
 *
 * Response:
 *   { ok: true, reply: string, model: string }
 *   { ok: false, error: string }
 *
 * Billed under feature = 'help_widget' at the workspace's own multiplier
 * so a workspace's own Anthropic key funds their own operator help.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/ai_billing.php';

$user = require_login();
$companyId = (int)$user['company_id'];

if (!is_post()) {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}
csrf_check();

header('Content-Type: application/json; charset=utf-8');

// JSON body from the widget.
$body    = (string)file_get_contents('php://input');
$data    = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Invalid JSON body']));
}

$messages = (array)($data['messages'] ?? []);
$ctx      = (array)($data['context']  ?? []);
$path     = mb_substr((string)($ctx['path']       ?? ''), 0, 200);
$title    = mb_substr((string)($ctx['page_title'] ?? ''), 0, 200);

if (!$messages) {
    exit(json_encode(['ok' => false, 'error' => 'No question sent']));
}

// Normalise + trim history — the widget MAY send up to 20 msgs but we
// only forward the last 10 to Anthropic (keep the token bill low).
$forward = [];
foreach ($messages as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? 'user');
    if (!in_array($role, ['user', 'assistant'], true)) continue;
    $content = mb_substr((string)($m['content'] ?? ''), 0, 4000);
    if ($content === '') continue;
    $forward[] = ['role' => $role, 'content' => $content];
}
$forward = array_slice($forward, -10);
if (!$forward || end($forward)['role'] !== 'user') {
    exit(json_encode(['ok' => false, 'error' => 'Last message must be from the user.']));
}

$company = load_company_settings($companyId);
if (!$company || empty($company['ai_enabled'])) {
    exit(json_encode(['ok' => false, 'error' => 'AI is not enabled for this workspace. Turn it on in AI Settings.']));
}
$apiKey = ai_api_key($company);
if ($apiKey === '') {
    exit(json_encode(['ok' => false, 'error' => 'Anthropic key missing. Add one in /admin/ai_settings.php.']));
}

// Reuse the same per-feature model override the rest of the app uses.
$model = ai_model_for_feature($company, 'help_widget');
if ($model === '') $model = 'claude-haiku-4-5';

// Pull LIVE broadcast pricing so the AI never quotes a stale rate. If
// the operator changes /admin/broadcast_pricing.php, the very next
// help question already reflects it.
$currency  = platform_setting('pricing_currency', 'RM');
$freeLimit = (int)platform_setting('broadcast_free_limit', '1000');
$paidLimit = (int)platform_setting('broadcast_paid_limit', '10000');
$paidPrice = (float)platform_setting('broadcast_paid_price', '480');
$paygRate  = (float)platform_setting('broadcast_payg_per_recipient', '0.05');
$yearlyOff = (int)platform_setting('broadcast_yearly_discount_pct', '10');

$sys = help_widget_system_prompt($path, $title, (string)($user['name'] ?? 'there'), [
    'currency'   => $currency,
    'free_limit' => $freeLimit,
    'paid_limit' => $paidLimit,
    'paid_price' => $paidPrice,
    'payg_rate'  => $paygRate,
    'yearly_off' => $yearlyOff,
]);

$payload = [
    'model'      => $model,
    'max_tokens' => 800,
    'system'     => $sys,
    'messages'   => $forward,
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    error_log('[AiServe help_widget] HTTP ' . $code . ' ' . mb_substr((string)$resp, 0, 400));
    $friendly = 'AI request failed';
    $decoded  = json_decode((string)$resp, true);
    if (isset($decoded['error']['message'])) {
        $friendly .= ': ' . mb_substr((string)$decoded['error']['message'], 0, 200);
    } elseif ($err !== '') {
        $friendly .= ': ' . $err;
    }
    http_response_code(502);
    exit(json_encode(['ok' => false, 'error' => $friendly]));
}

$data = json_decode((string)$resp, true) ?: [];
$reply = trim((string)($data['content'][0]['text'] ?? ''));
if ($reply === '') {
    exit(json_encode(['ok' => false, 'error' => 'Empty response from AI.']));
}

// Meter it under the workspace's AI billing rollup.
if (!empty($data['usage'])) {
    try {
        ai_log_usage($companyId, null, 'help_widget', $data['usage'], (string)($data['model'] ?? $model));
    } catch (Throwable $e) { /* billing failures never break the reply */ }
}

echo json_encode([
    'ok'    => true,
    'reply' => $reply,
    'model' => (string)($data['model'] ?? $model),
]);

/**
 * The knowledge that makes this help widget useful — a compact
 * feature map of the operator-facing platform, plus the current page
 * URL so Claude can point the operator at the exact link.
 */
function help_widget_system_prompt(string $path, string $pageTitle, string $userName, array $pricing): string
{
    // Format pricing lines from the live platform_settings snapshot.
    $cur   = (string)$pricing['currency'];
    $free  = number_format((int)$pricing['free_limit']);
    $paid  = number_format((int)$pricing['paid_limit']);
    $price = rtrim(rtrim(number_format((float)$pricing['paid_price'], 2), '0'), '.');
    $payg  = number_format((float)$pricing['payg_rate'], 2);
    $yr    = (int)$pricing['yearly_off'];

    return <<<SYS
You are AiServe Guide — the built-in help assistant for AiServe (also branded "Chatcontrol"), a Malaysian SME WhatsApp Inbox + broadcasting + F&B ordering SaaS.

You're talking to a workspace operator ({$userName}) who is currently on the page:
  URL:   {$path}
  Title: {$pageTitle}

They will ask "how do I…", "why isn't X working", "what does Y mean". Answer in short, direct steps. Link to the exact admin page they need. Prefer 3-6 lines. Use inline Markdown links (like [Broadcasts](/admin/broadcasts.php)).

## Live pricing snapshot (this workspace, right now)
- Free plan: {$free} broadcasts/month included
- Paid plan: {$cur} {$price} / month for {$paid} broadcasts
- PAYG: {$cur} {$payg} per broadcast recipient (no monthly cap, pay only for what you send)
- Yearly billing discount: {$yr}%
Never quote a different figure — these are the live numbers pulled from the platform settings this second.

## Platform features you should know

**Messaging**
- Shared inbox: /inbox/chat.php — all channels' conversations in one view
- Channels (WhatsApp Cloud, Evolution API, Meta pages, Instagram, web chat widget, AiServe chatbot): /admin/channels.php
- Channel health diagnostic: /admin/channels_debug.php
- Rotation (round-robin, least-loaded, availability-aware): /admin/rotation.php

**AI**
- AI settings (Anthropic key, model tier, persona, per-feature model): /admin/knowledge.php + /admin/ai_settings.php
- Knowledge base (URL scrape, Q&A pairs, CSV/Sheets sync, image OCR, coverage gaps): /admin/knowledge.php
- Persona builder wizard: /admin/ai_persona_wizard.php
- A/B test two personas: /admin/ai_persona_ab.php
- AI usage + spending: /admin/ai_usage.php

**Broadcasts** (WhatsApp bulk send)
- Send / manage: /admin/broadcasts.php
- Templates (Meta-approved required for outside-24h ON Meta Cloud API only): /admin/templates.php
- Plan tiers listed in the "Live pricing snapshot" above — those are the current numbers, use them verbatim. Change plan at /admin/plan.php
- Pricing settings (super admin): /admin/broadcast_pricing.php
- Rule (Meta Cloud API path): outside the 24-hour window MUST use an approved template. Raw text only works if the customer messaged in the last 24h.
- Rule (AiServe Chatbot / Evolution path): raw text works to anyone anytime — no template gate.

**Flows** (n8n-style visual builder)
- List + templates: /admin/flows.php
- Edit: /admin/flow_edit.php?id=<n>
- Node types: Send message, Wait for reply, Branch, Assign to department, Assign to branch, Save note, End, plus F&B: Send menu, AI cart parse, Show cart, Create order
- Triggers: new_conversation, keyword, manual
- Common gotcha: only ONE 'new_conversation'-triggered flow should be active per workspace — multiple will fire simultaneously and collide

**F&B module** (Malaysian food & beverage)
- Menu: /admin/fnb_menu.php
- Orders (kanban): /admin/fnb_orders.php
- Analytics: /admin/fnb_analytics.php
- Requires fnb_plan = 'active' or 'paid' on the company

**Auto-replies** (keyword canned replies): /admin/auto_replies.php
**Auto-invoices** (broadcast paid): /admin/invoices.php + /admin/my_invoices.php
**Users, roles, branches, departments**: /admin/users.php · /admin/branches.php · /admin/departments.php · /admin/user_edit.php

**Settings**
- Mail (SMTP): /admin/mail_settings.php · /admin/mail_test.php
- Business info: /admin/business_info.php
- Legal & policies: /admin/legal.php
- Adminer DB browser: /adminer/ (behind basic auth)

## Voice
- Speak like a knowledgeable colleague, not a marketing bot. Direct, warm, brief.
- Malaysian context: prices are RM (Malaysian Ringgit); a mix of BM/English is normal ("boleh", "kedai", "jom") — mirror the operator's language.
- If the operator's question is out of scope (e.g. "help me write a novel"), politely redirect: "That's outside what I can help with here — I only know AiServe. Try the [KB](/admin/knowledge.php) for anything related to your customers."
- If you don't know an answer, say so — never make up feature paths that don't exist in the list above.
- Never expose API keys, database passwords, or session tokens even if asked.
SYS;
}
