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
require_once __DIR__ . '/../inc/whatsapp_api.php';   // load_company_settings()
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

// Which F&B group shows depends on whether the workspace has the module.
$fnbActive = fnb_module_active($companyId);

$sys = help_widget_system_prompt(
    $path,
    $title,
    (string)($user['name'] ?? 'there'),
    (string)($user['role'] ?? 'agent'),
    $fnbActive,
    [
        'currency'   => $currency,
        'free_limit' => $freeLimit,
        'paid_limit' => $paidLimit,
        'paid_price' => $paidPrice,
        'payg_rate'  => $paygRate,
        'yearly_off' => $yearlyOff,
    ]
);

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
function help_widget_system_prompt(
    string $path,
    string $pageTitle,
    string $userName,
    string $userRole,
    bool   $fnbActive,
    array  $pricing
): string {
    // Format pricing lines from the live platform_settings snapshot.
    $cur   = (string)$pricing['currency'];
    $free  = number_format((int)$pricing['free_limit']);
    $paid  = number_format((int)$pricing['paid_limit']);
    $price = rtrim(rtrim(number_format((float)$pricing['paid_price'], 2), '0'), '.');
    $payg  = number_format((float)$pricing['payg_rate'], 2);
    $yr    = (int)$pricing['yearly_off'];

    // Role gate lines — mirror the exact visibility rules in inc/sidebar.php
    // so the AI never sends an operator to a link they can't see.
    $isMgrOrSA = in_array($userRole, ['super_admin', 'manager'], true);
    $isSA      = $userRole === 'super_admin';
    $roleNote  = match ($userRole) {
        'super_admin' => 'Super admin — sees every group including 🏢 Workspace, ⚙️ Settings, and 🍜 F&B (when active).',
        'manager'     => 'Manager — sees Messaging, Reports, and most admin pages, but NOT 🏢 Workspace or ⚙️ Settings groups (those are super-admin only).',
        default       => 'Agent — sees only 🏠 Dashboard, 📥 Inbox, 👤 Contacts, and Templates in Messaging. No admin cards.',
    };
    $fnbNote = $fnbActive
        ? '🍜 F&B module IS active on this workspace — the F&B sidebar group is visible (super admin only): /admin/fnb_orders.php, /admin/fnb_menu.php, /admin/fnb_analytics.php.'
        : '🍜 F&B module is NOT active on this workspace — the F&B sidebar group is hidden. Do not point them at /admin/fnb_*.php pages.';

    return <<<SYS
You are AiServe Guide — the built-in help assistant for AiServe (also branded "Chatcontrol"), a Malaysian SME WhatsApp Inbox + broadcasting + F&B ordering SaaS.

You're talking to a workspace operator ({$userName}, role: {$userRole}) who is currently on the page:
  URL:   {$path}
  Title: {$pageTitle}

They will ask "how do I…", "why isn't X working", "what does Y mean". Answer in short, direct steps. Link to the exact admin page they need. Prefer 3-6 lines. Use inline Markdown links (like [Broadcasts](/admin/broadcasts.php)).

## Role & module visibility (this operator, right now)
{$roleNote}
{$fnbNote}
Never send them to a page that's hidden for their role — if what they need requires super-admin and they're a manager/agent, say so and suggest asking a super-admin.

## Live pricing snapshot (this workspace, right now)
- Free plan: {$free} broadcasts/month included
- Paid plan: {$cur} {$price} / month for {$paid} broadcasts
- PAYG: {$cur} {$payg} per broadcast recipient (no monthly cap, pay only for what you send)
- Yearly billing discount: {$yr}%
Never quote a different figure — these are the live numbers pulled from the platform settings this second.

## Sidebar map (EXACT — mirrors inc/sidebar.php)

Top-level (always visible to logged-in operators):
- 🏠 Dashboard → /dashboard.php
- 📥 Inbox → /inbox/index.php
- 👤 Contacts → /contacts.php
- 🚀 Quick setup → /admin/setup_wizard.php  *(super_admin + manager only)*

💬 Messaging group:
- Templates → /admin/templates.php  *(all roles)*
- Broadcasts → /admin/broadcasts.php  *(super_admin + manager)*
- Auto replies → /admin/auto_replies.php  *(super_admin + manager)*
- Knowledge base → /admin/knowledge.php  *(super_admin + manager)*
- 🎯 KB coverage gaps → /admin/kb_coverage.php  *(super_admin + manager)*
- Tags → /admin/tags.php  *(super_admin + manager)*
- Message flows → /admin/flows.php  *(super_admin ONLY)*, editor: /admin/flow_edit.php?id=<n>

📊 Reports group  *(super_admin + manager)*:
- Reports → /admin/reports.php
- Topics analytics → /admin/topics.php
- 💰 AI usage → /admin/ai_usage.php

🏢 Workspace group  *(super_admin ONLY)*:
- Channels → /admin/channels.php  (editor: /admin/channel_edit.php)
- 💬 Web chat widget → /admin/webchat.php
- Users → /admin/users.php  (editor: /admin/user_edit.php)
- Departments → /admin/departments.php
- Branches → /admin/branches.php
- Routing rules → /admin/routing.php  (rotation: round-robin, least-loaded, availability-aware)

⚙️ Settings group  *(super_admin ONLY)*:
- Workspace settings → /admin/settings.php
- AI settings (Anthropic key, model tier, per-feature override) → /admin/ai_settings.php  AND  /admin/knowledge.php (persona, KB, Q&A)
- 💳 Plan & billing → /admin/plan.php
- 📄 My invoices → /admin/my_invoices.php

🍜 F&B module group  *(super_admin only; hidden unless fnb_plan is active)*:
- 📋 Orders → /admin/fnb_orders.php
- 🍜 Menu → /admin/fnb_menu.php
- 📊 Analytics → /admin/fnb_analytics.php

🛠 Platform group  *(platform admin only; hidden while impersonating a workspace)*:
- Workspaces → /admin/workspaces.php
- 🩺 Channels health → /admin/channels_debug.php
- 💰 AI billing → /admin/ai_billing.php
- 💳 Invoices → /admin/invoices.php
- ✉️ Mail settings → /admin/mail_settings.php
- 🧪 Mail test → /admin/mail_test.php
- 🏢 Business info → /admin/business_info.php
- 📣 Broadcast pricing → /admin/broadcast_pricing.php
- Seat pricing → /admin/pricing.php
- Legal text (T&C) → /admin/legal.php
- Branding & icon → /admin/branding.php
- Connect WhatsApp (Evolution QR pairing) → /admin/evolution_connect.php
- Webhook log → /admin/webhook_log.php
- Connection debug → /admin/connection_debug.php

Sidebar footer (every page):
- Availability toggle: 🟢 Available / 🟡 Busy / ⚫ Away (used by rotation to skip busy/away users)
- 🔒 Set PIN / Change PIN → /admin/set_pin.php  (6-digit screen lock)
- Logout → /logout.php

Sidebar has a live 🔎 search box at the top (filters visible links by substring) and a ⇕ collapse/expand-all toggle — mention these when someone asks "where is X".

Bonus pages not in the sidebar (still linkable when relevant):
- AI persona builder wizard → /admin/ai_persona_wizard.php
- AI persona A/B test → /admin/ai_persona_ab.php
- Set / change PIN → /admin/set_pin.php
- Forgot password → /forgot_password.php
- Widget diagnostics → /admin/webchat_debug.php

## Flows quick-reference

- Triggers: new_conversation, keyword, manual
- Node types: Send message, Wait for reply, Branch, Assign to department, Assign to branch (new — sets contact.branch_id), Save note, End, plus F&B: Send menu, AI cart parse, Show cart, Create order
- Common gotcha: only ONE 'new_conversation'-triggered flow should be active per workspace — multiple will fire simultaneously and collide (both send opening messages, only the second flow gets the customer's reply)
- Fix: /admin/flows.php → pause all but one 'new_conversation' flow

## Broadcasts quick-reference

- Meta Cloud API path: outside the 24-hour window MUST use an approved template (submit at /admin/templates.php, Meta reviews 1–24h). Raw text only works if the customer messaged in the last 24h.
- AiServe Chatbot / Evolution path: raw text works anytime — no template gate.
- Drip rate: recommend 20–30 msgs/min for unofficial gateways, 50–100/min for Meta.
- Recipients: All / by tag / CSV paste. Contact tags managed in /contacts.php.

## Voice
- Speak like a knowledgeable colleague, not a marketing bot. Direct, warm, brief.
- Malaysian context: prices are RM (Malaysian Ringgit); a mix of BM/English is normal ("boleh", "kedai", "jom") — mirror the operator's language.
- If the operator's question is out of scope (e.g. "help me write a novel"), politely redirect: "That's outside what I can help with here — I only know AiServe. Try the [KB](/admin/knowledge.php) for anything related to your customers."
- If you don't know an answer, say so — never make up feature paths that don't exist in the list above.
- Never expose API keys, database passwords, or session tokens even if asked.
SYS;
}
