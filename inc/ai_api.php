<?php
/**
 * AI reply suggestion client.
 *
 * Calls Anthropic's Messages API to produce a draft reply the agent can edit
 * before sending. Configuration lives on companies.* (per-tenant), so each
 * SaaS workspace brings its own API key. A portal-wide ANTHROPIC_API_KEY env
 * var is used as a fallback when the tenant hasn't configured their own.
 *
 * The static company system prompt is sent with cache_control:ephemeral so
 * repeated requests in the same window reuse the cached prefix - cuts
 * latency by ~40% and cost on system tokens by 90%.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/knowledge_base.php';
require_once __DIR__ . '/ai_billing.php';

const AI_DEFAULT_MODEL  = 'claude-haiku-4-5';
const AI_API_VERSION    = '2023-06-01';
const AI_MAX_HISTORY    = 20;       // last N messages of context
const AI_MAX_OUT_TOKENS = 512;

/**
 * Curated persona presets — one-click starting points for
 * /admin/knowledge.php. Operators can apply then edit.
 */
const AI_PERSONA_PRESETS = [
    'formal_en' => [
        'label' => '👔 Formal English',
        'text'  => 'A polished, professional English-language customer-service voice. Complete sentences, courteous tone, no slang or emojis. Suitable for banking, legal, insurance, or B2B contexts.',
    ],
    'warm_ms' => [
        'label' => '🌸 Warm Malay (BM + English)',
        'text'  => "Speak casual Malaysian English mixed with the occasional Bahasa Melayu word (lah, boleh, jom, ok tak, sila). Warm, friendly, patient. Address the customer as \"kak\" or \"bang\" when appropriate. Perfect for family businesses and F&B.",
    ],
    'playful_bi' => [
        'label' => '✨ Bilingual playful',
        'text'  => 'Switch fluidly between English and Bahasa Malaysia depending on how the customer wrote. Light, upbeat, playful — use 1 emoji per reply max. Great for e-commerce, cafes, and beauty brands.',
    ],
    'concise_pro' => [
        'label' => '⚡ Concise professional',
        'text'  => 'Short, direct, no fluff. 1-2 sentences per reply. Never says "sure!" or "no problem" — just answers. Good for high-volume support where speed matters more than warmth.',
    ],
    'fnb_host' => [
        'label' => '🍜 F&B host',
        'text'  => "Warm, hospitable F&B host voice. Refer to menu items by name. Suggest the day's special if the customer asks for recommendations. Confirm orders back before creating them. Use food emojis sparingly (🍽️ 🍜) — never more than one per reply.",
    ],
    'retail_assist' => [
        'label' => '🛍 Retail assistant',
        'text'  => 'Product-focused retail assistant. Ask sizing/color/quantity when relevant. Mention delivery timeline (2–3 working days) if the customer asks about shipping. Suggest similar or complementary products when helpful, never pushy.',
    ],
    'clinic_reception' => [
        'label' => '🩺 Clinic reception',
        'text'  => 'Calm, empathetic clinic receptionist. Use polite pronouns. Never diagnose or give medical advice — always defer to the doctor. Confirm appointment slot + IC/passport before booking. Follow up with a reminder message the day before if the platform supports it.',
    ],
    'salon_stylist' => [
        'label' => '💇 Salon / beauty',
        'text'  => 'Beauty & salon voice — chatty, encouraging, style-savvy. Ask for a reference photo when a customer describes a haircut or nail style. Confirm stylist name + slot + service before booking. Suggest add-ons (hair treatment, add-on manicure) once, never twice.',
    ],
];

/**
 * Feature → model resolver. Reads companies.ai_model_by_feature JSON
 * and falls back to companies.ai_model, then AI_DEFAULT_MODEL. Callers
 * pass a known feature key: 'first_touch', 'always_on', 'suggest_reply',
 * 'fnb_cart_parse', 'kb_distill', 'summary', 'topics', 'auto_reply_suggest'.
 */
function ai_model_for_feature(array $company, string $feature): string
{
    $raw = trim((string)($company['ai_model_by_feature'] ?? ''));
    if ($raw !== '') {
        $map = json_decode($raw, true);
        if (is_array($map) && !empty($map[$feature]) && is_string($map[$feature])) {
            return (string)$map[$feature];
        }
    }
    return (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;
}

function ai_is_configured(array $company): bool
{
    return !empty($company['ai_enabled'])
        && (!empty($company['ai_api_key']) || getenv('ANTHROPIC_API_KEY'));
}

function ai_api_key(array $company): string
{
    if (!empty($company['ai_api_key'])) return (string)$company['ai_api_key'];
    return (string)(getenv('ANTHROPIC_API_KEY') ?: '');
}

function ai_default_system_prompt(array $company): string
{
    $brand = $company['name'] ?? 'this business';
    $prompt = "You are a WhatsApp customer service agent for {$brand}. ";

    // Persona injection — the short "voice / personality" field the
    // operator sets on /admin/knowledge.php. Adds character to EVERY
    // reply without them having to author a full system prompt.
    $persona = trim((string)($company['ai_persona'] ?? ''));
    if ($persona !== '') {
        $prompt .= "Your persona: " . $persona . " ";
    }

    return $prompt
         . "Write a single, concise, friendly reply to the customer's last message. "
         . "Match the customer's language. Keep it under 3 short sentences. "
         . "If the customer asks something you cannot answer with the information given, "
         . "say so politely and offer to connect them with a human agent. "
         . "Output only the reply text - no quotes, no preamble, no labels.";
}

/**
 * @param array $company      Full companies row.
 * @param array $conversation Full conversations row (used for contact name).
 * @param array $messages     Recent message rows in chronological order
 *                            (incoming = customer, outgoing/agent = us).
 * @return array{ok:bool, suggestion?:string, error?:string, model?:string, usage?:array}
 */
function ai_suggest_reply(array $company, array $conversation, array $messages): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled. Configure it in Admin → AI Settings.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }

    $model        = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;
    $systemPrompt = trim((string)($company['ai_system_prompt'] ?? '')) ?: ai_default_system_prompt($company);

    // Load the knowledge base (if any active articles) so the model can
    // ground its reply in company-specific facts.
    $kb = kb_load_for_company((int)$company['id']);

    // Map portal messages -> Anthropic messages. "incoming" = customer (user
    // role), everything outgoing (agent, ai bot, system) = assistant role
    // since they were previously sent to the customer on our behalf.
    $tail = array_slice($messages, -AI_MAX_HISTORY);
    $apiMessages = [];
    foreach ($tail as $m) {
        $text = trim((string)($m['message_text'] ?? ''));
        if ($text === '') continue;
        $role = $m['direction'] === 'incoming' ? 'user' : 'assistant';
        // Merge consecutive same-role messages so the conversation alternates.
        if ($apiMessages && end($apiMessages)['role'] === $role) {
            $apiMessages[count($apiMessages) - 1]['content'] .= "\n" . $text;
        } else {
            $apiMessages[] = ['role' => $role, 'content' => $text];
        }
    }

    // Anthropic requires the conversation to start with a user message.
    while ($apiMessages && $apiMessages[0]['role'] !== 'user') {
        array_shift($apiMessages);
    }
    // ...and to end with a user message (otherwise we have nothing to reply to).
    if (!$apiMessages || end($apiMessages)['role'] !== 'user') {
        return ['ok' => false, 'error' => 'No customer message to reply to yet.'];
    }

    // Anthropic supports multiple system blocks. Persona instructions go
    // first; the KB goes second so it can be cached independently (KB usually
    // changes far less often than the persona prompt, both benefit from
    // cache_control:ephemeral).
    $systemBlocks = [
        ['type' => 'text', 'text' => $systemPrompt,
         'cache_control' => ['type' => 'ephemeral']],
    ];
    if ($kb && !empty($kb['text'])) {
        $kbBlock = "You have access to the following company knowledge base. "
                 . "Use it to answer customer questions accurately. If the answer "
                 . "is not covered, say you'll check and get back to them.\n\n"
                 . "===== KNOWLEDGE BASE =====\n"
                 . $kb['text']
                 . "\n===== END KNOWLEDGE BASE =====";
        $systemBlocks[] = [
            'type' => 'text', 'text' => $kbBlock,
            'cache_control' => ['type' => 'ephemeral'],
        ];
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => AI_MAX_OUT_TOKENS,
        'system'     => $systemBlocks,
        'messages'   => $apiMessages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error: ' . $err];
    }
    $data = json_decode((string)$resp, true);

    if ($code !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        return ['ok' => false, 'error' => (string)$msg, 'model' => $model];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'Empty response from model.'];
    }

    return [
        'ok'         => true,
        'suggestion' => $text,
        'model'      => $model,
        'usage'      => $data['usage'] ?? null,
        'kb_titles'  => $kb['titles'] ?? [],
        'kb_chars'   => $kb['char_count'] ?? 0,
    ];
}

/**
 * Generate a structured handover summary of a conversation.
 *
 * @param array $messages Chronological message rows. Each row should include
 *                        message_text, direction, sender_type, sender_name.
 *
 * @return array{ok:bool, summary?:string, error?:string, model?:string, usage?:array}
 */
function ai_summarize_conversation(array $company, array $messages): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled. Configure it in Admin → AI Settings.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    if (!$messages) {
        return ['ok' => false, 'error' => 'No conversation messages to summarize yet.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;

    // Build a readable transcript so the model has clear turn boundaries.
    $transcript = '';
    foreach ($messages as $m) {
        $text = trim((string)($m['message_text'] ?? ''));
        if ($text === '') continue;
        $who = match ((string)($m['sender_type'] ?? '')) {
            'customer' => 'Customer',
            'agent'    => 'Agent (' . trim((string)($m['sender_name'] ?? 'us')) . ')',
            'ai'       => 'AI bot',
            default    => 'System',
        };
        $transcript .= $who . ': ' . $text . "\n";
    }
    $transcript = trim($transcript);
    if ($transcript === '') {
        return ['ok' => false, 'error' => 'Conversation has no text messages.'];
    }

    $systemPrompt =
        "You produce concise WhatsApp customer-service handover summaries for a new "
      . "agent who is about to take over the conversation. Output exactly these "
      . "sections, in plain text (no markdown, no headings styling):\n\n"
      . "Customer issue: [one or two sentences]\n"
      . "Discussed so far: [two or three sentences]\n"
      . "Open / next action: [one or two short bullet points, each starting with - ]\n"
      . "Customer mood: [one short phrase, e.g. patient, frustrated, eager]\n\n"
      . "Keep the whole summary under 180 words. Be specific and factual. Do not "
      . "restate the transcript verbatim. If something is unclear from the transcript, "
      . "say so explicitly instead of inventing details.";

    $payload = [
        'model'      => $model,
        'max_tokens' => 600,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role' => 'user',
             'content' => "Conversation transcript (chronological):\n\n" . $transcript],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error: ' . $err];
    }
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return [
            'ok' => false,
            'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code)),
            'model' => $model,
        ];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'Empty summary from model.'];
    }

    return [
        'ok'      => true,
        'summary' => $text,
        'model'   => $model,
        'usage'   => $data['usage'] ?? null,
    ];
}

/**
 * Analyze recent customer messages and identify the most common discussion
 * topics. Returns a structured list the analytics page can render directly,
 * AND a list of conversation ids that the model believes belong to each
 * topic - used by the "Apply as tag" flow to bulk-tag conversations.
 *
 * @param array $company        Full companies row.
 * @param array $messageSamples Array of {conversation_id => int, text => string}
 *                              entries to analyze.
 * @param int   $periodDays     Just passed through into the response for
 *                              the UI; doesn't affect the model call.
 *
 * @return array{ok:bool, topics?:array, model?:string, usage?:array, error?:string}
 */
function ai_analyze_topics(array $company, array $messageSamples, int $periodDays): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    if (!$messageSamples) {
        return ['ok' => false, 'error' => 'No conversation messages in this period.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;

    // Cap total input - 200K chars keeps the bill predictable and fits
    // comfortably inside Haiku's context window. Each line prefixed with
    // [Conv N] so the model can return conversation ids per topic.
    $combined = '';
    foreach ($messageSamples as $msg) {
        $cid  = (int)($msg['conversation_id'] ?? 0);
        $text = trim((string)($msg['text'] ?? ''));
        if ($cid <= 0 || $text === '') continue;
        $line = '[Conv ' . $cid . '] ' . $text . "\n";
        if (mb_strlen($combined) + mb_strlen($line) > 180000) break;
        $combined .= $line;
    }

    $systemPrompt =
        "You analyze customer-service conversations and identify the most common "
      . "discussion topics. Each input line is prefixed with [Conv N] where N is "
      . "the conversation id.\n\n"
      . "Return a JSON object with this exact structure:\n\n"
      . "{\n"
      . "  \"topics\": [\n"
      . "    {\n"
      . "      \"topic\": \"Short name (2-5 words, capitalized, e.g. 'Shipping & delivery')\",\n"
      . "      \"count\": <number of unique conversations on this topic>,\n"
      . "      \"summary\": \"One-sentence description of what customers ask\",\n"
      . "      \"examples\": [\"example customer message 1\", \"example customer message 2\"],\n"
      . "      \"conversation_ids\": [123, 145, 187]\n"
      . "    }\n"
      . "  ]\n"
      . "}\n\n"
      . "Rules:\n"
      . "- 5 to 12 topics, sorted by count descending\n"
      . "- Merge similar topics (shipping cost + delivery time + tracking = 'Shipping & delivery')\n"
      . "- Use the customer's language for topic names (English unless most messages are in another language)\n"
      . "- conversation_ids must be the UNIQUE list of [Conv N] ids whose messages belong to that topic\n"
      . "- count must equal the length of conversation_ids\n"
      . "- A conversation can belong to multiple topics if it asks about multiple things\n"
      . "- Examples must be ACTUAL customer messages from the input, copied verbatim (without the [Conv N] prefix)\n"
      . "- Output ONLY the JSON object, no markdown fences, no preamble";

    $payload = [
        // 6000 leaves headroom for workspaces with hundreds of conversations -
        // the JSON output grows with conversation_ids arrays + example strings,
        // and 2000 truncated mid-array on busy accounts.
        'model'      => $model,
        'max_tokens' => 6000,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role'    => 'user',
             'content' => "Customer messages from the last {$periodDays} days "
                        . "(one per line, format: 'N. message'):\n\n" . $combined],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error: ' . $err];
    }
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return [
            'ok' => false,
            'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code)),
        ];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    $stopReason = (string)($data['stop_reason'] ?? '');
    // Defensive: strip code fences if the model included them anyway.
    if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $parsed = json_decode($text, true);
    // Fallback 1: model added preamble like "Here are the topics: {...}".
    // Pull out the first {...} block and try again.
    if (!is_array($parsed)) {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $parsed = json_decode($candidate, true);
        }
    }
    if (!is_array($parsed) || !isset($parsed['topics']) || !is_array($parsed['topics'])) {
        $err = 'Model returned malformed JSON.';
        if ($stopReason === 'max_tokens') {
            $err .= ' Response was truncated by max_tokens - try a shorter period.';
        }
        // Log truncated head of raw output to PHP error log so we can see what
        // Claude actually produced when a workspace keeps hitting this.
        error_log('[AiServe] topic analysis JSON parse failed (stop=' . $stopReason
            . ', len=' . strlen($text) . '): ' . substr($text, 0, 500));
        return [
            'ok'          => false,
            'error'       => $err,
            'raw'         => $text,
            'stop_reason' => $stopReason,
        ];
    }

    return [
        'ok'     => true,
        'topics' => $parsed['topics'],
        'model'  => $model,
        'usage'  => $data['usage'] ?? null,
    ];
}

// =============================================================================
// Routing rule builder
// =============================================================================

/**
 * Turn a plain-English instruction ("send pricing questions to Sales") into a
 * structured routing rule the admin can review and save. The admin always sees
 * the suggestion in the form before it gets committed - we never auto-create.
 *
 * @param array  $company     Full companies row (for ai_api_key + model).
 * @param string $description Operator's natural-language ask.
 * @param array  $departments [{id, name}, ...] active departments for the workspace.
 * @param array  $users       [{id, name, role}, ...] optional agents for assignment.
 * @return array{ok:bool, suggestion?:array, error?:string, model?:string, usage?:array}
 */
function ai_suggest_routing_rule(array $company, string $description, array $departments, array $users = []): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    $description = trim($description);
    if ($description === '') {
        return ['ok' => false, 'error' => 'Please describe what you want to route.'];
    }
    if (!$departments) {
        return ['ok' => false, 'error' => 'No active departments exist yet - add one first.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;

    $deptLines = [];
    foreach ($departments as $d) {
        $deptLines[] = '- ' . (string)$d['name'];
    }
    $userLines = [];
    foreach ($users as $u) {
        $userLines[] = '- ' . (string)$u['name'];
    }

    $systemPrompt =
        "You convert a customer-service manager's plain-English routing intent "
      . "into a structured rule for a WhatsApp inbox. Each rule matches text in "
      . "the FIRST incoming customer message of a new conversation, then sends "
      . "the conversation to a department (and optionally a default agent).\n\n"
      . "Return a single JSON object with this exact structure:\n\n"
      . "{\n"
      . "  \"match_type\": \"contains\" | \"starts_with\" | \"equals\" | \"regex\",\n"
      . "  \"match_value\": \"the text or regex to match\",\n"
      . "  \"department_name\": \"exact name from the department list\",\n"
      . "  \"assigned_user_name\": \"exact name from the user list, or null\",\n"
      . "  \"priority\": 1-9999 (lower runs first; default 100),\n"
      . "  \"explanation\": \"one short sentence explaining why this rule fits\"\n"
      . "}\n\n"
      . "Rules:\n"
      . "- Pick the SIMPLEST match_type that works. Prefer 'contains' for keyword intents.\n"
      . "- Use 'starts_with' only when the operator clearly says 'starts with' or describes a code/prefix.\n"
      . "- Use 'equals' only when the operator clearly says 'exact match' or 'equals'.\n"
      . "- Use 'regex' only when keyword variants need alternation (e.g. 'refund|return|money back'). Write valid PHP PCRE without surrounding /slashes/.\n"
      . "- match_value must be lowercase unless the source clearly demands case-sensitive matching.\n"
      . "- department_name MUST be an exact match from the provided list. If no department clearly fits, set it to the closest one and explain.\n"
      . "- assigned_user_name MUST be an exact match from the user list or null. Default to null unless the operator names a person.\n"
      . "- Output ONLY the JSON object. No markdown fences, no preamble.";

    $userPrompt =
        "Available departments:\n" . implode("\n", $deptLines) . "\n\n"
      . ($userLines ? "Available agents:\n" . implode("\n", $userLines) . "\n\n" : '')
      . "Operator's instruction:\n" . $description;

    $payload = [
        'model'      => $model,
        'max_tokens' => 500,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error: ' . $err];
    }
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return [
            'ok'    => false,
            'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code)),
        ];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
        }
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Model returned malformed JSON.', 'raw' => $text];
    }

    $matchType = strtolower(trim((string)($parsed['match_type'] ?? 'contains')));
    if (!in_array($matchType, ['contains', 'starts_with', 'equals', 'regex'], true)) {
        $matchType = 'contains';
    }
    $matchValue = trim((string)($parsed['match_value'] ?? ''));
    if ($matchValue === '') {
        return ['ok' => false, 'error' => 'Model did not produce a match value.', 'raw' => $text];
    }
    if ($matchType === 'regex'
        && @preg_match('/' . str_replace('/', '\\/', $matchValue) . '/iu', '') === false) {
        return ['ok' => false, 'error' => 'Model produced an invalid regex pattern.', 'raw' => $text];
    }

    // Resolve department name -> id from the workspace's actual list.
    $deptId   = 0;
    $deptName = trim((string)($parsed['department_name'] ?? ''));
    foreach ($departments as $d) {
        if (strcasecmp((string)$d['name'], $deptName) === 0) {
            $deptId   = (int)$d['id'];
            $deptName = (string)$d['name'];
            break;
        }
    }
    if ($deptId <= 0) {
        return [
            'ok'    => false,
            'error' => 'Model picked department "' . $deptName . '" which does not exist. Try rephrasing or add the department first.',
            'raw'   => $text,
        ];
    }

    // Optional agent.
    $userId   = null;
    $userName = null;
    $rawUser  = $parsed['assigned_user_name'] ?? null;
    if (is_string($rawUser) && trim($rawUser) !== '' && strtolower(trim($rawUser)) !== 'null') {
        foreach ($users as $u) {
            if (strcasecmp((string)$u['name'], trim($rawUser)) === 0) {
                $userId   = (int)$u['id'];
                $userName = (string)$u['name'];
                break;
            }
        }
    }

    $priority = (int)($parsed['priority'] ?? 100);
    if ($priority < 1)    $priority = 100;
    if ($priority > 9999) $priority = 9999;

    return [
        'ok'         => true,
        'suggestion' => [
            'match_type'         => $matchType,
            'match_value'        => $matchValue,
            'department_id'      => $deptId,
            'department_name'    => $deptName,
            'assigned_user_id'   => $userId,
            'assigned_user_name' => $userName,
            'priority'           => $priority,
            'explanation'        => trim((string)($parsed['explanation'] ?? '')),
        ],
        'model'      => $model,
        'usage'      => $data['usage'] ?? null,
    ];
}

// =============================================================================
// First-touch auto-reply
// =============================================================================

/**
 * Default escalation phrases - if any of these appear in the customer's first
 * message, we skip auto-reply and let a human handle it. Workspaces can
 * override the list in Settings.
 */
const AI_DEFAULT_ESCALATION_PHRASES =
    'human,agent,manager,representative,refund,complaint,cancel,broken,scam,'
  . 'angry,disappointed,lawsuit,legal,sue,emergency,urgent,asap';

/**
 * Decide whether to auto-reply to a customer's first message and (if yes)
 * generate the reply. Does NOT send - the caller (webhook) does that.
 *
 * Guards (any failing one short-circuits with a "skipped" reason):
 *   1. ai_enabled + ai_first_touch both ON for this workspace
 *   2. AI is properly configured (API key present)
 *   3. Conversation has no assigned agent yet
 *   4. Customer has sent exactly one message in this conversation
 *      (so we don't re-fire on the 2nd/3rd inbound)
 *   5. Customer's message doesn't contain any escalation phrase
 *   6. Workspace's daily AI-send cap not reached
 *
 * @return array{ok:bool, reply_text?:string, skipped?:string, model?:string, usage?:array, error?:string}
 */
function ai_first_touch_decide(array $company, array $conversation, string $customerMessage): array
{
    if (empty($company['ai_enabled']) || empty($company['ai_first_touch'])) {
        return ['ok' => false, 'skipped' => 'disabled'];
    }
    if (ai_api_key($company) === '') {
        return ['ok' => false, 'skipped' => 'no_api_key'];
    }
    if (!empty($conversation['assigned_user_id'])) {
        return ['ok' => false, 'skipped' => 'assigned'];
    }

    $db = aiserve_db();

    // Guard 4 - this must be the customer's FIRST inbound on this conversation.
    $check = $db->prepare(
        'SELECT COUNT(*) FROM messages
         WHERE conversation_id = ? AND direction = "incoming"'
    );
    $check->execute([(int)$conversation['id']]);
    if ((int)$check->fetchColumn() !== 1) {
        return ['ok' => false, 'skipped' => 'not_first_touch'];
    }

    // Guard 5 - escalation phrases.
    $rawPhrases = trim((string)($company['ai_escalation_phrases'] ?? '')) ?: AI_DEFAULT_ESCALATION_PHRASES;
    $phrases = array_filter(array_map(fn($p) => trim(mb_strtolower($p)), explode(',', $rawPhrases)));
    $msgLower = mb_strtolower($customerMessage);
    foreach ($phrases as $phrase) {
        if ($phrase !== '' && str_contains($msgLower, $phrase)) {
            return ['ok' => false, 'skipped' => 'escalation_phrase:' . $phrase];
        }
    }

    // Guard 6 - daily cap.
    $cap = (int)($company['ai_daily_cap'] ?? 200);
    if ($cap > 0) {
        $cnt = $db->prepare(
            'SELECT COUNT(*) FROM messages
             WHERE company_id = ? AND sender_type = "ai"
               AND created_at >= CURDATE()'
        );
        $cnt->execute([(int)$company['id']]);
        if ((int)$cnt->fetchColumn() >= $cap) {
            return ['ok' => false, 'skipped' => 'daily_cap_' . $cap];
        }
    }

    // Generate the reply via the same path the AI suggest button uses (so KB
    // grounding + system prompt + prompt caching all apply).
    $messages = [
        ['direction' => 'incoming', 'message_text' => $customerMessage],
    ];
    $r = ai_suggest_reply($company, $conversation, $messages);
    if (!$r['ok']) {
        return ['ok' => false, 'skipped' => 'ai_error', 'error' => $r['error'] ?? 'unknown'];
    }

    return [
        'ok'         => true,
        'reply_text' => (string)$r['suggestion'],
        'model'      => $r['model']  ?? null,
        'usage'      => $r['usage']  ?? null,
        'kb_titles'  => $r['kb_titles'] ?? [],
    ];
}

/**
 * Find or create the workspace's "AI replied" tag and attach it to a
 * conversation. Used by ai_first_touch_handle so managers can filter the
 * inbox to just AI-handled threads at a glance.
 */
function ai_first_touch_tag_conversation(int $companyId, int $conversationId): void
{
    $db = aiserve_db();
    try {
        $stmt = $db->prepare('SELECT id FROM conversation_tags WHERE company_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$companyId, 'AI replied']);
        $tagId = (int)($stmt->fetchColumn() ?: 0);
        if ($tagId === 0) {
            $db->prepare('INSERT INTO conversation_tags (company_id, name, color) VALUES (?, ?, ?)')
               ->execute([$companyId, 'AI replied', '#6f42c1']);
            $tagId = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT IGNORE INTO conversation_tag_map (conversation_id, tag_id) VALUES (?, ?)')
           ->execute([$conversationId, $tagId]);
    } catch (Throwable $e) {
        error_log('[ai_first_touch_tag] ' . $e->getMessage());
    }
}

/**
 * Full first-touch flow:
 *   - Reload company + conversation + last customer message from DB
 *   - ai_first_touch_decide(); if skipped, log activity and return
 *   - Send via the provider dispatch (Cloud / Evolution / Chatbot gateway)
 *   - Persist the outgoing message row with sender_type='ai'
 *   - Update conversation last_message_* + first_response_at
 *   - Tag the conversation "AI replied"
 *   - log_activity for every outcome
 *
 * Intended to be called from the webhook AFTER the customer's message has
 * been written and AFTER fastcgi_finish_request() so the gateway isn't held
 * waiting for the AI round-trip.
 */
function ai_first_touch_handle(array $company, int $conversationId): void
{
    require_once __DIR__ . '/provider.php';
    require_once __DIR__ . '/channels.php';

    try {
        $db = aiserve_db();
        $stmt = $db->prepare(
            'SELECT c.*, ct.wa_id, ct.display_name, ct.profile_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch();
        if (!$conv) return;

        $stmt = $db->prepare(
            'SELECT message_text FROM messages
             WHERE conversation_id = ? AND direction = "incoming"
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        $customerMessage = (string)($stmt->fetchColumn() ?: '');
        if ($customerMessage === '') return;

        // Billing quota gate — 'paid' tier workspaces that hit the
        // monthly cap don't get a first-touch reply. 'payg' and 'none'
        // pass unconditionally.
        if (!ai_can_spend((int)$company['id'])) {
            log_activity((int)$company['id'], null, 'ai_first_touch_skipped',
                'conversation', $conversationId, 'reason=quota_cap_hit');
            return;
        }

        // Per-feature model override — resolver falls back to
        // companies.ai_model then AI_DEFAULT_MODEL when unset.
        $company['ai_model'] = ai_model_for_feature($company, 'first_touch');
        $decision = ai_first_touch_decide($company, $conv, $customerMessage);
        if (!$decision['ok']) {
            log_activity((int)$company['id'], null, 'ai_first_touch_skipped',
                'conversation', $conversationId,
                'reason=' . ($decision['skipped'] ?? '?')
              . ($decision['error'] ?? '' ? ' err=' . mb_substr($decision['error'], 0, 100) : ''));
            return;
        }

        $reply = $decision['reply_text'];
        $channel = channel_for_conversation($conv);
        if (!$channel) {
            log_activity((int)$company['id'], null, 'ai_first_touch_failed',
                'conversation', $conversationId, 'no_channel');
            return;
        }
        $send  = provider_send_text($channel, (string)$conv['wa_id'], $reply);

        if (!$send['ok']) {
            log_activity((int)$company['id'], null, 'ai_first_touch_failed',
                'conversation', $conversationId,
                'send_error=' . mb_substr((string)($send['error'] ?? ''), 0, 200));
            return;
        }

        // Persist + update conversation.
        $db->prepare(
            'INSERT INTO messages
                (company_id, channel_id, conversation_id, contact_id, sender_type,
                 wa_message_id, direction, message_type, message_text,
                 status, sent_at, created_at)
             VALUES (?, ?, ?, ?, "ai", ?, "outgoing", "text", ?, "sent", NOW(), NOW())'
        )->execute([
            (int)$company['id'], (int)$channel['id'], $conversationId, (int)$conv['contact_id'],
            $send['wa_message_id'], $reply,
        ]);

        $db->prepare(
            'UPDATE conversations
             SET last_message_text  = ?,
                 last_message_at    = NOW(),
                 first_response_at  = COALESCE(first_response_at, NOW())
             WHERE id = ?'
        )->execute([mb_substr($reply, 0, 500), $conversationId]);

        ai_first_touch_tag_conversation((int)$company['id'], $conversationId);

        // Billing: capture tokens so this client is charged for the call.
        ai_log_usage((int)$company['id'], $conversationId, 'first_touch',
            $decision['usage'] ?? null, $decision['model'] ?? null);

        log_activity((int)$company['id'], null, 'ai_first_touch_sent',
            'conversation', $conversationId,
            'model=' . ($decision['model'] ?? '?')
          . ' tokens=' . json_encode($decision['usage'] ?? [])
          . ' kb=' . count($decision['kb_titles'] ?? []));
    } catch (Throwable $e) {
        error_log('[ai_first_touch_handle] ' . $e->getMessage());
    }
}

// =============================================================================
// Always-on AI auto-reply + business hours mode (Phase 12)
// =============================================================================

/**
 * Default schedule used when business hours are enabled but the workspace
 * hasn't customised the JSON yet. Mon-Fri 9-18, weekend closed.
 */
const BUSINESS_HOURS_DEFAULT = [
    'mon' => ['09:00', '18:00'],
    'tue' => ['09:00', '18:00'],
    'wed' => ['09:00', '18:00'],
    'thu' => ['09:00', '18:00'],
    'fri' => ['09:00', '18:00'],
    'sat' => null,
    'sun' => null,
];

const OFF_HOURS_DEFAULT_MESSAGE =
    "Thanks for reaching out! We're currently outside our service hours. "
  . "Our team will reply as soon as we're back. For urgent matters, please "
  . "include the word \"urgent\" in your message and a human will be paged.";

function business_hours_schedule(array $company): array
{
    $raw = trim((string)($company['business_hours_schedule'] ?? ''));
    if ($raw === '') return BUSINESS_HOURS_DEFAULT;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return BUSINESS_HOURS_DEFAULT;
    // Fill missing days with null so day lookup never KeyErrors.
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
        if (!array_key_exists($d, $decoded)) $decoded[$d] = null;
    }
    return $decoded;
}

/**
 * Are we currently inside the workspace's business hours?
 */
function is_inside_business_hours(array $company, ?int $now = null): bool
{
    if (empty($company['business_hours_enabled'])) return true;  // not enforced = always "open"
    $tz   = (string)($company['business_hours_timezone'] ?? '') ?: ((string)($company['timezone'] ?? 'Asia/Kuala_Lumpur'));
    try {
        $dt  = new DateTime('@' . ($now ?? time()));
        $dt->setTimezone(new DateTimeZone($tz));
    } catch (Throwable $e) {
        return true;  // bad TZ -> treat as open rather than blocking everyone
    }
    $dayKey = strtolower($dt->format('D'));  // Mon, Tue...
    $dayKey = substr($dayKey, 0, 3);
    $sched  = business_hours_schedule($company);
    $window = $sched[$dayKey] ?? null;
    if (!is_array($window) || count($window) !== 2) return false;
    [$openStr, $closeStr] = $window;
    $hm = $dt->format('H:i');
    return ($hm >= $openStr) && ($hm < $closeStr);
}

/**
 * Has the workspace already sent its off-hours notice on this conversation
 * within the last 12 hours? Used so we don't ping the customer repeatedly
 * if they message multiple times overnight.
 */
function off_hours_message_already_sent(int $conversationId): bool
{
    $stmt = aiserve_db()->prepare(
        'SELECT COUNT(*) FROM messages
         WHERE conversation_id = ?
           AND sender_type = "system"
           AND created_at > NOW() - INTERVAL 12 HOUR'
    );
    $stmt->execute([$conversationId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Find-or-create the tag we slap on conversations that received an
 * off-hours reply.
 */
function off_hours_tag_conversation(int $companyId, int $conversationId): void
{
    $db = aiserve_db();
    try {
        $stmt = $db->prepare('SELECT id FROM conversation_tags WHERE company_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$companyId, 'Out of hours']);
        $tagId = (int)($stmt->fetchColumn() ?: 0);
        if ($tagId === 0) {
            $db->prepare('INSERT INTO conversation_tags (company_id, name, color) VALUES (?, ?, ?)')
               ->execute([$companyId, 'Out of hours', '#f0ad4e']);
            $tagId = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT IGNORE INTO conversation_tag_map (conversation_id, tag_id) VALUES (?, ?)')
           ->execute([$conversationId, $tagId]);
    } catch (Throwable $e) {
        error_log('[off_hours_tag] ' . $e->getMessage());
    }
}

/**
 * Send the workspace's off-hours templated message via the conversation's
 * channel. Persists as sender_type='system' so it never counts toward the
 * AI daily cap or shows the 🤖 bubble.
 */
function send_off_hours_message(array $company, int $conversationId): void
{
    require_once __DIR__ . '/provider.php';
    require_once __DIR__ . '/channels.php';

    try {
        $db = aiserve_db();
        $stmt = $db->prepare(
            'SELECT c.*, ct.wa_id
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch();
        if (!$conv) return;

        $channel = channel_for_conversation($conv);
        if (!$channel) return;

        $msg = trim((string)($company['off_hours_message'] ?? '')) ?: OFF_HOURS_DEFAULT_MESSAGE;

        $send = provider_send_text($channel, (string)$conv['wa_id'], $msg);
        if (!$send['ok']) {
            log_activity((int)$company['id'], null, 'off_hours_failed',
                'conversation', $conversationId,
                'send_error=' . mb_substr((string)($send['error'] ?? ''), 0, 200));
            return;
        }

        $db->prepare(
            'INSERT INTO messages
                (company_id, channel_id, conversation_id, contact_id, sender_type,
                 wa_message_id, direction, message_type, message_text,
                 status, sent_at, created_at)
             VALUES (?, ?, ?, ?, "system", ?, "outgoing", "text", ?, "sent", NOW(), NOW())'
        )->execute([
            (int)$company['id'], (int)$channel['id'], $conversationId, (int)$conv['contact_id'],
            $send['wa_message_id'], $msg,
        ]);

        // Park as pending so agents see "waiting for office hours" at a glance.
        $db->prepare(
            'UPDATE conversations
             SET status = IF(status = "open", "pending", status),
                 last_message_text = ?, last_message_at = NOW()
             WHERE id = ?'
        )->execute([mb_substr($msg, 0, 500), $conversationId]);

        off_hours_tag_conversation((int)$company['id'], $conversationId);

        log_activity((int)$company['id'], null, 'off_hours_sent',
            'conversation', $conversationId, 'len=' . mb_strlen($msg));
    } catch (Throwable $e) {
        error_log('[send_off_hours_message] ' . $e->getMessage());
    }
}

/**
 * Always-on AI: handle ANY customer message (not just the first), as long as
 * no human has taken the conversation. Reuses the first-touch decision
 * helper but skips the "exactly 1 inbound" guard.
 */
function ai_always_on_handle(array $company, int $conversationId): void
{
    require_once __DIR__ . '/provider.php';
    require_once __DIR__ . '/channels.php';

    try {
        $db = aiserve_db();
        $stmt = $db->prepare(
            'SELECT c.*, ct.wa_id, ct.display_name, ct.profile_name
             FROM conversations c
             INNER JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch();
        if (!$conv) return;

        // Pull recent message context (last 20) just like ai_suggest does.
        $mstmt = $db->prepare(
            'SELECT direction, sender_type, message_text, message_type, created_at
             FROM messages
             WHERE conversation_id = ?
               AND message_text IS NOT NULL AND message_text <> ""
             ORDER BY id DESC LIMIT 20'
        );
        $mstmt->execute([$conversationId]);
        $history = array_reverse($mstmt->fetchAll());
        if (!$history) return;

        $lastInbound = '';
        foreach (array_reverse($history) as $m) {
            if ($m['direction'] === 'incoming') { $lastInbound = (string)$m['message_text']; break; }
        }
        if ($lastInbound === '') return;

        // Re-use the first-touch guards EXCEPT "exactly one inbound" - that's the whole point of always-on.
        if (empty($company['ai_enabled']) || empty($company['ai_always_on'])) return;
        if (ai_api_key($company) === '') return;
        if (!empty($conv['assigned_user_id'])) {
            log_activity((int)$company['id'], null, 'ai_always_on_skipped',
                'conversation', $conversationId, 'reason=assigned');
            return;
        }

        // Escalation phrases
        $raw = trim((string)($company['ai_escalation_phrases'] ?? '')) ?: AI_DEFAULT_ESCALATION_PHRASES;
        foreach (array_filter(array_map(fn($p) => trim(mb_strtolower($p)), explode(',', $raw))) as $phrase) {
            if ($phrase !== '' && str_contains(mb_strtolower($lastInbound), $phrase)) {
                log_activity((int)$company['id'], null, 'ai_always_on_skipped',
                    'conversation', $conversationId, 'reason=escalation:' . $phrase);
                return;
            }
        }

        // Daily cap
        $cap = (int)($company['ai_daily_cap'] ?? 200);
        if ($cap > 0) {
            $cnt = $db->prepare(
                'SELECT COUNT(*) FROM messages WHERE company_id = ? AND sender_type = "ai" AND created_at >= CURDATE()'
            );
            $cnt->execute([(int)$company['id']]);
            if ((int)$cnt->fetchColumn() >= $cap) {
                log_activity((int)$company['id'], null, 'ai_always_on_skipped',
                    'conversation', $conversationId, 'reason=daily_cap_' . $cap);
                return;
            }
        }

        // Monthly billing cap (paid tier). 'payg' + 'none' pass through.
        if (!ai_can_spend((int)$company['id'])) {
            log_activity((int)$company['id'], null, 'ai_always_on_skipped',
                'conversation', $conversationId, 'reason=quota_cap_hit');
            return;
        }

        // Per-feature model override for always-on chatbot.
        $company['ai_model'] = ai_model_for_feature($company, 'always_on');

        // Call Claude with the full conversation history (so it has context).
        $r = ai_suggest_reply($company, $conv, $history);
        if (!$r['ok']) {
            log_activity((int)$company['id'], null, 'ai_always_on_skipped',
                'conversation', $conversationId, 'reason=ai_error:' . mb_substr((string)($r['error'] ?? ''), 0, 100));
            return;
        }

        $channel = channel_for_conversation($conv);
        if (!$channel) return;

        $reply = (string)$r['suggestion'];
        $send  = provider_send_text($channel, (string)$conv['wa_id'], $reply);
        if (!$send['ok']) {
            log_activity((int)$company['id'], null, 'ai_always_on_failed',
                'conversation', $conversationId,
                'send_error=' . mb_substr((string)($send['error'] ?? ''), 0, 200));
            return;
        }

        $db->prepare(
            'INSERT INTO messages
                (company_id, channel_id, conversation_id, contact_id, sender_type,
                 wa_message_id, direction, message_type, message_text,
                 status, sent_at, created_at)
             VALUES (?, ?, ?, ?, "ai", ?, "outgoing", "text", ?, "sent", NOW(), NOW())'
        )->execute([
            (int)$company['id'], (int)$channel['id'], $conversationId, (int)$conv['contact_id'],
            $send['wa_message_id'], $reply,
        ]);

        $db->prepare(
            'UPDATE conversations
             SET last_message_text  = ?,
                 last_message_at    = NOW(),
                 first_response_at  = COALESCE(first_response_at, NOW())
             WHERE id = ?'
        )->execute([mb_substr($reply, 0, 500), $conversationId]);

        ai_first_touch_tag_conversation((int)$company['id'], $conversationId);

        ai_log_usage((int)$company['id'], $conversationId, 'always_on',
            $r['usage'] ?? null, $r['model'] ?? null);

        log_activity((int)$company['id'], null, 'ai_always_on_sent',
            'conversation', $conversationId,
            'model=' . ($r['model'] ?? '?') . ' tokens=' . json_encode($r['usage'] ?? []));
    } catch (Throwable $e) {
        error_log('[ai_always_on_handle] ' . $e->getMessage());
    }
}

/**
 * Top-level inbound automation entry point. Called from both webhook
 * receivers after they've finished writing the customer's message. Decides
 * what (if anything) to do back to the customer:
 *
 *   1. If business hours are enforced AND we're outside them: send the
 *      templated off-hours message (once per 12h per conversation).
 *      No further automation runs - human takes over in the morning.
 *
 *   2. Else if AI always-on is enabled: AI handles every message until
 *      a human takes the conversation.
 *
 *   3. Else if AI first-touch is enabled: AI handles only the FIRST
 *      inbound on a fresh conversation (existing behavior).
 */
function inbound_automation_handle(array $company, int $conversationId): void
{
    try {
        // 1. Business hours
        if (!empty($company['business_hours_enabled']) && !is_inside_business_hours($company)) {
            if (!off_hours_message_already_sent($conversationId)) {
                send_off_hours_message($company, $conversationId);
            }
            return;
        }

        // 2. AI always-on
        if (!empty($company['ai_enabled']) && !empty($company['ai_always_on'])) {
            ai_always_on_handle($company, $conversationId);
            return;
        }

        // 3. AI first-touch (legacy/single-fire)
        if (!empty($company['ai_enabled']) && !empty($company['ai_first_touch'])) {
            ai_first_touch_handle($company, $conversationId);
        }
    } catch (Throwable $e) {
        error_log('[inbound_automation_handle] ' . $e->getMessage());
    }
}

/**
 * Draft a Meta-compliant message template from a plain-English brief.
 * Follows the same "review-before-save" pattern as ai_suggest_routing_rule -
 * the admin always sees the suggestion in the edit form and clicks Save
 * before it lands in the DB.
 *
 * @return array{ok:bool, suggestion?:array, error?:string, model?:string, usage?:array}
 */
function ai_suggest_template(array $company, string $description, ?string $language = null): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    $description = trim($description);
    if ($description === '') {
        return ['ok' => false, 'error' => 'Please describe what the template is for.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;
    $brand = $company['name'] ?? 'this business';
    $lang  = trim((string)($language ?? 'en'));
    if ($lang === '') $lang = 'en';

    $systemPrompt =
        "You draft WhatsApp Business message templates for {$brand}. These templates get "
      . "submitted to Meta for approval before use, so they must follow Meta's rules:\n\n"
      . "- No promotional or misleading language.\n"
      . "- No requests for sensitive info (passwords, card numbers, etc.).\n"
      . "- Variables use {{1}}, {{2}}, {{3}} format - never named placeholders in the body.\n"
      . "- Do NOT put a variable at the very start or very end of the body (Meta rejects those).\n"
      . "- Keep the body under 1024 chars.\n"
      . "- Category MUST be one of: MARKETING, UTILITY, AUTHENTICATION.\n"
      . "  * MARKETING = promotions, offers, catalogs, review requests.\n"
      . "  * UTILITY   = order/payment/booking/shipping updates, account notices, general info.\n"
      . "  * AUTHENTICATION = OTP / login codes only.\n"
      . "- template_name uses lowercase letters, numbers, underscores only. 3-120 chars.\n"
      . "- Language code = 2-letter ISO (en, ms, zh, ta, etc.). Match the requested language.\n\n"
      . "Return a single JSON object with this exact structure:\n"
      . "{\n"
      . "  \"template_name\": \"short_lowercase_name\",\n"
      . "  \"category\": \"UTILITY\" | \"MARKETING\" | \"AUTHENTICATION\",\n"
      . "  \"language\": \"en\" | \"ms\" | ...,\n"
      . "  \"body_text\": \"Hi {{1}}, ... \",\n"
      . "  \"variables_json\": \"{\\\"1\\\":\\\"customer_name\\\",\\\"2\\\":\\\"order_id\\\"}\",\n"
      . "  \"explanation\": \"one sentence why this template fits\"\n"
      . "}\n\n"
      . "variables_json is the string form of a JSON object mapping variable number to a "
      . "friendly slot name (customer_name, order_id, amount, date, etc.). If the body has no "
      . "variables, return \"{}\".\n"
      . "Output ONLY the JSON object. No markdown fences, no preamble.";

    $userPrompt = "Language: " . $lang . "\n\nOperator's brief:\n" . $description;

    $payload = [
        'model'      => $model,
        'max_tokens' => 700,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error: ' . $err];
    }
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return ['ok' => false, 'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code))];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) $text = trim($m[1]);
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        $s = strpos($text, '{'); $eIdx = strrpos($text, '}');
        if ($s !== false && $eIdx !== false && $eIdx > $s) {
            $parsed = json_decode(substr($text, $s, $eIdx - $s + 1), true);
        }
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Model returned malformed JSON.', 'raw' => $text];
    }

    // Normalize + validate against portal / Meta rules.
    $name = strtolower(preg_replace('/[^a-z0-9_]+/', '_',
        trim((string)($parsed['template_name'] ?? ''))));
    if (!preg_match('/^[a-z0-9_]{3,120}$/', $name)) {
        $name = 'template_' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $category = strtoupper(trim((string)($parsed['category'] ?? 'UTILITY')));
    if (!in_array($category, ['MARKETING','UTILITY','AUTHENTICATION'], true)) $category = 'UTILITY';
    $bodyText = trim((string)($parsed['body_text'] ?? ''));
    if ($bodyText === '') {
        return ['ok' => false, 'error' => 'Model did not produce a body.', 'raw' => $text];
    }
    if (mb_strlen($bodyText) > 1024) {
        $bodyText = mb_substr($bodyText, 0, 1024);
    }
    $outLang = strtolower(trim((string)($parsed['language'] ?? $lang)));
    if (!preg_match('/^[a-z]{2}(_[a-z]{2})?$/', $outLang)) $outLang = 'en';

    $varsRaw = $parsed['variables_json'] ?? '{}';
    if (is_array($varsRaw)) $varsRaw = json_encode($varsRaw, JSON_UNESCAPED_UNICODE);
    // Sanity check that variables_json parses.
    $varsCheck = json_decode((string)$varsRaw, true);
    if (!is_array($varsCheck)) $varsRaw = '{}';

    return [
        'ok'         => true,
        'suggestion' => [
            'template_name'  => $name,
            'category'       => $category,
            'language'       => $outLang,
            'body_text'      => $bodyText,
            'variables_json' => (string)$varsRaw,
            'explanation'    => trim((string)($parsed['explanation'] ?? '')),
        ],
        'model' => $model,
        'usage' => $data['usage'] ?? null,
    ];
}

/**
 * Generate a full "starter pack" of message templates + keyword auto-reply
 * rules from a one-sentence business description. Used by the Quick Setup
 * Wizard so operators who don't know how to write templates from scratch
 * can bootstrap a working WhatsApp CS workflow in one click.
 *
 * Returns a suggestion object with both lists; the admin page inserts them
 * as drafts on Apply so the operator retains review-before-save.
 *
 * @return array{ok:bool, suggestion?:array, error?:string, model?:string, usage?:array}
 */
function ai_generate_setup_pack(array $company, string $businessDescription, string $language = 'en'): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    $businessDescription = trim($businessDescription);
    if ($businessDescription === '') {
        return ['ok' => false, 'error' => 'Please describe the business in one sentence.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;
    $brand = $company['name'] ?? 'this business';
    $lang  = $language !== '' ? $language : 'en';

    $systemPrompt =
        "You are onboarding {$brand} onto a shared WhatsApp customer-service inbox. "
      . "Given a one-sentence business description, produce a starter pack:\n\n"
      . "1) 5 message TEMPLATES the business will submit to Meta for approval. Each must:\n"
      . "   - use lowercase_snake_case template_name (3-120 chars)\n"
      . "   - pick category: MARKETING / UTILITY / AUTHENTICATION\n"
      . "   - use {{1}}, {{2}} etc. for variables (NOT at start or end of body)\n"
      . "   - body_text under 1024 chars\n"
      . "   - variables_json mapping the number to a friendly slot name\n\n"
      . "2) 5 keyword AUTO-REPLY rules the business can turn on immediately. Each must:\n"
      . "   - use a short name in Title Case\n"
      . "   - pick a match_type: contains / starts_with / equals / regex\n"
      . "   - pick 1-3 keywords the average customer would type\n"
      . "   - write a reply_text that answers instantly (mention the business by name)\n"
      . "   - suggest a media_hint: 'menu.pdf', 'price_list.pdf', 'location_map.jpg', or 'none'\n"
      . "   - assign priority 10-100 (lower runs first)\n\n"
      . "Return ONE JSON object exactly:\n"
      . "{\n"
      . "  \"templates\": [\n"
      . "    { \"template_name\": \"...\", \"category\": \"UTILITY\", \"language\": \"" . $lang . "\",\n"
      . "      \"body_text\": \"...\", \"variables_json\": \"{\\\"1\\\":\\\"...\\\"}\" }, ...\n"
      . "  ],\n"
      . "  \"auto_replies\": [\n"
      . "    { \"name\": \"...\", \"match_type\": \"contains\", \"match_value\": \"menu\",\n"
      . "      \"reply_text\": \"...\", \"media_hint\": \"menu.pdf\", \"priority\": 10 }, ...\n"
      . "  ],\n"
      . "  \"summary\": \"one-sentence recap of what was generated\"\n"
      . "}\n\n"
      . "Language: use \"" . $lang . "\" ISO code for template language, and write reply_text "
      . "and body_text in that language. Output ONLY the JSON object, no markdown fences.";

    $userPrompt = "Business description:\n" . $businessDescription;

    $payload = [
        'model'      => $model,
        'max_tokens' => 4000,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return ['ok' => false, 'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code))];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) $text = trim($m[1]);
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        $s = strpos($text, '{'); $eIdx = strrpos($text, '}');
        if ($s !== false && $eIdx !== false && $eIdx > $s) {
            $parsed = json_decode(substr($text, $s, $eIdx - $s + 1), true);
        }
    }
    if (!is_array($parsed) || !isset($parsed['templates']) || !isset($parsed['auto_replies'])) {
        return ['ok' => false, 'error' => 'Model returned malformed JSON.', 'raw' => $text];
    }

    // Normalize templates the same way ai_suggest_template does.
    $templates = [];
    foreach ((array)$parsed['templates'] as $t) {
        $n = strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim((string)($t['template_name'] ?? ''))));
        if (!preg_match('/^[a-z0-9_]{3,120}$/', $n)) $n = 'template_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $cat = strtoupper(trim((string)($t['category'] ?? 'UTILITY')));
        if (!in_array($cat, ['MARKETING','UTILITY','AUTHENTICATION'], true)) $cat = 'UTILITY';
        $body = trim((string)($t['body_text'] ?? ''));
        if ($body === '') continue;
        if (mb_strlen($body) > 1024) $body = mb_substr($body, 0, 1024);
        $tLang = strtolower(trim((string)($t['language'] ?? $lang)));
        if (!preg_match('/^[a-z]{2}(_[a-z]{2})?$/', $tLang)) $tLang = 'en';
        $vars = $t['variables_json'] ?? '{}';
        if (is_array($vars)) $vars = json_encode($vars, JSON_UNESCAPED_UNICODE);
        if (!is_array(json_decode((string)$vars, true))) $vars = '{}';
        $templates[] = compact('n','cat','body','tLang','vars') + ['template_name' => $n, 'category' => $cat, 'language' => $tLang, 'body_text' => $body, 'variables_json' => (string)$vars];
    }

    // Normalize auto-replies.
    $autoReplies = [];
    foreach ((array)$parsed['auto_replies'] as $r) {
        $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 150);
        if ($name === '') continue;
        $mt = strtolower(trim((string)($r['match_type'] ?? 'contains')));
        if (!in_array($mt, ['contains','starts_with','equals','regex'], true)) $mt = 'contains';
        $mv = trim((string)($r['match_value'] ?? ''));
        $reply = trim((string)($r['reply_text'] ?? ''));
        if ($mv === '' || $reply === '') continue;
        if ($mt === 'regex' && @preg_match('/' . str_replace('/', '\\/', $mv) . '/iu', '') === false) {
            $mt = 'contains';
        }
        $prio = max(1, min(9999, (int)($r['priority'] ?? 100)));
        $mediaHint = trim((string)($r['media_hint'] ?? 'none'));
        $autoReplies[] = [
            'name'         => $name,
            'match_type'   => $mt,
            'match_value'  => $mv,
            'reply_text'   => $reply,
            'priority'     => $prio,
            'media_hint'   => $mediaHint,
        ];
    }

    if (!$templates && !$autoReplies) {
        return ['ok' => false, 'error' => 'Model returned an empty pack.', 'raw' => $text];
    }

    return [
        'ok'         => true,
        'suggestion' => [
            'templates'    => $templates,
            'auto_replies' => $autoReplies,
            'summary'      => trim((string)($parsed['summary'] ?? '')),
        ],
        'model' => $model,
        'usage' => $data['usage'] ?? null,
    ];
}

/**
 * Turn a plain-English brief into ONE keyword auto-reply rule the
 * operator can review and save. Mirrors ai_suggest_routing_rule and
 * ai_suggest_template - review-before-save flow, never auto-creates.
 *
 * @return array{ok:bool, suggestion?:array, error?:string, model?:string, usage?:array}
 */
function ai_suggest_auto_reply(array $company, string $description, string $language = 'en'): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    $description = trim($description);
    if ($description === '') {
        return ['ok' => false, 'error' => 'Please describe what this auto-reply should do.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;
    $brand = $company['name'] ?? 'this business';
    $lang  = $language !== '' ? $language : 'en';

    $systemPrompt =
        "You draft ONE keyword auto-reply rule for {$brand}, a WhatsApp customer-service inbox. "
      . "Given a plain-English brief from the operator, produce a single rule that fires when a "
      . "customer's message matches the keyword.\n\n"
      . "Return a single JSON object with this exact structure:\n"
      . "{\n"
      . "  \"name\": \"Short Title Case name (2-4 words)\",\n"
      . "  \"match_type\": \"contains\" | \"starts_with\" | \"equals\" | \"regex\",\n"
      . "  \"match_value\": \"keyword or phrase (or regex without slashes)\",\n"
      . "  \"reply_text\": \"the message customers get back — mention " . $brand . " by name\",\n"
      . "  \"priority\": 1-9999 (lower = higher priority; default 100),\n"
      . "  \"cooldown_min\": 0-1440 (per-conversation minutes between fires; default 60),\n"
      . "  \"media_hint\": \"menu.pdf\" | \"price_list.pdf\" | \"location_map.jpg\" | \"none\",\n"
      . "  \"explanation\": \"one sentence why this rule fits\"\n"
      . "}\n\n"
      . "Rules:\n"
      . "- Prefer 'contains' unless the brief clearly says 'starts with' or 'exact match'.\n"
      . "- match_value must be lowercase unless case matters.\n"
      . "- reply_text must be in the language '" . $lang . "' (write it in that language).\n"
      . "- reply_text should NOT reference a specific media file the operator hasn't uploaded yet.\n"
      . "  Instead, phrase it so the media attachment (if operator adds one) becomes a natural\n"
      . "  supplement, e.g. \"Here's our latest menu 👇\" - so the reply reads fine even without media.\n"
      . "- Output ONLY the JSON object. No markdown fences, no preamble.";

    $payload = [
        'model'      => $model,
        'max_tokens' => 500,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role' => 'user', 'content' => "Language: " . $lang . "\n\nOperator's brief:\n" . $description],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return ['ok' => false, 'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code))];
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) $text = trim($m[1]);
    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        $s = strpos($text, '{'); $eIdx = strrpos($text, '}');
        if ($s !== false && $eIdx !== false && $eIdx > $s) {
            $parsed = json_decode(substr($text, $s, $eIdx - $s + 1), true);
        }
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Model returned malformed JSON.', 'raw' => $text];
    }

    $name = mb_substr(trim((string)($parsed['name'] ?? '')), 0, 150);
    if ($name === '') $name = 'Auto reply ' . substr(bin2hex(random_bytes(2)), 0, 4);
    $mt = strtolower(trim((string)($parsed['match_type'] ?? 'contains')));
    if (!in_array($mt, ['contains','starts_with','equals','regex'], true)) $mt = 'contains';
    $mv = trim((string)($parsed['match_value'] ?? ''));
    if ($mv === '') {
        return ['ok' => false, 'error' => 'Model did not produce a keyword.', 'raw' => $text];
    }
    if ($mt === 'regex' && @preg_match('/' . str_replace('/', '\\/', $mv) . '/iu', '') === false) {
        return ['ok' => false, 'error' => 'Model produced an invalid regex pattern.', 'raw' => $text];
    }
    $reply = trim((string)($parsed['reply_text'] ?? ''));
    if ($reply === '') {
        return ['ok' => false, 'error' => 'Model did not produce a reply text.', 'raw' => $text];
    }
    $prio     = max(1, min(9999, (int)($parsed['priority']     ?? 100)));
    $cooldown = max(0, min(1440, (int)($parsed['cooldown_min'] ?? 60)));
    $mediaHint = trim((string)($parsed['media_hint'] ?? 'none'));

    return [
        'ok' => true,
        'suggestion' => [
            'name'         => $name,
            'match_type'   => $mt,
            'match_value'  => $mv,
            'reply_text'   => $reply,
            'priority'     => $prio,
            'cooldown_min' => $cooldown,
            'media_hint'   => $mediaHint,
            'explanation'  => trim((string)($parsed['explanation'] ?? '')),
        ],
        'model' => $model,
        'usage' => $data['usage'] ?? null,
    ];
}

/**
 * Distill an array of historical customer-question / agent-reply pairs
 * into a structured markdown knowledge document. The result is saved
 * as an auto-generated knowledge_base article by cron/learn_from_history
 * and read at draft-time by ai_suggest_reply via the KB.
 *
 * @param array<int, array{q:string, a:string}> $qaPairs
 * @return array{ok:bool, content?:string, error?:string, model?:string, usage?:array}
 */
function ai_distill_conversation_history(array $company, array $qaPairs): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    if (!$qaPairs) {
        return ['ok' => false, 'error' => 'No historical Q&A pairs to distill.'];
    }

    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;
    $brand = $company['name'] ?? 'this business';

    // Cap the input so a busy workspace doesn't blow the context window.
    // ~180K chars stays comfortably under Haiku's context.
    $combined = '';
    $used = 0;
    foreach ($qaPairs as $i => $qa) {
        $q = mb_substr(trim((string)($qa['q'] ?? '')), 0, 400);
        $a = mb_substr(trim((string)($qa['a'] ?? '')), 0, 400);
        if ($q === '' || $a === '') continue;
        $line = "Q" . ($i + 1) . ": " . $q . "\nA" . ($i + 1) . ": " . $a . "\n\n";
        if ($used + mb_strlen($line) > 180000) break;
        $combined .= $line;
        $used += mb_strlen($line);
    }
    if ($combined === '') {
        return ['ok' => false, 'error' => 'All Q&A pairs were empty after trimming.'];
    }

    $systemPrompt =
        "You are compiling a knowledge document for {$brand}'s WhatsApp "
      . "customer-service team. Below are real (customer question, team reply) "
      . "pairs from the last few weeks.\n\n"
      . "Produce a well-organized markdown document that captures HOW THIS TEAM "
      . "ACTUALLY ANSWERS customers. This will be given to Claude as a knowledge "
      . "source when it drafts future replies, so it must be:\n\n"
      . "- Grouped by topic (Pricing, Bookings, Delivery, Hours, Refunds, etc.)\n"
      . "- Written in the team's own voice (match tone, formality, common phrases)\n"
      . "- Concrete: include specific answers, numbers, names, URLs the team used\n"
      . "- Deduplicated: don't repeat variants of the same question\n"
      . "- Honest: if the team often says 'I'll check and get back to you' for a "
      . "  topic, note it as 'defer to human' - don't paper over uncertainty\n\n"
      . "Format:\n"
      . "# " . $brand . " — team response guide\n"
      . "_(auto-generated from recent conversations)_\n\n"
      . "## Topic\n"
      . "- **Q**: <the customer question shape>\n"
      . "  **A**: <how the team answers>\n\n"
      . "Keep the whole document under 4000 words. Skip topics that appeared "
      . "fewer than 2 times. Output ONLY the markdown - no preamble.";

    $payload = [
        'model'      => $model,
        'max_tokens' => 6000,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [
            ['role' => 'user', 'content' => "Real conversation history:\n\n" . $combined],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return ['ok' => false, 'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code))];
    }
    $content = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $content .= $block['text'];
    }
    $content = trim($content);
    if ($content === '') return ['ok' => false, 'error' => 'Model returned empty document.'];

    return [
        'ok'      => true,
        'content' => $content,
        'model'   => $model,
        'usage'   => $data['usage'] ?? null,
    ];
}

/**
 * Translate one message body to a target language. Cheap Haiku call
 * (~0.3 sen per message). Preserves emoji, URLs, phone numbers.
 *
 * @return array{ok:bool, text?:string, error?:string, model?:string, usage?:array}
 */
function ai_translate_message(array $company, string $text, string $targetLang = 'en'): array
{
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'Nothing to translate.'];
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') return ['ok' => false, 'error' => 'No Anthropic API key configured.'];

    $targetLang = strtolower(trim($targetLang)) ?: 'en';
    if (!preg_match('/^[a-z]{2}(_[a-z]{2})?$/', $targetLang)) $targetLang = 'en';

    static $names = [
        'en' => 'English',    'ms' => 'Bahasa Malaysia', 'zh' => 'Chinese (Simplified)',
        'ta' => 'Tamil',      'id' => 'Bahasa Indonesia', 'th' => 'Thai',
        'vi' => 'Vietnamese', 'ja' => 'Japanese',        'ko' => 'Korean',
        'ar' => 'Arabic',     'hi' => 'Hindi',           'fr' => 'French',
        'es' => 'Spanish',    'de' => 'German',          'pt' => 'Portuguese',
    ];
    $targetName = $names[$targetLang] ?? $targetLang;
    $model = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;

    $systemPrompt =
        "You translate WhatsApp customer-service messages into {$targetName}. Rules:\n"
      . "- If the source is already in {$targetName}, output it unchanged.\n"
      . "- Preserve emoji, URLs, phone numbers, order numbers, and prices verbatim.\n"
      . "- Keep it natural and conversational - match how a customer would text.\n"
      . "- Do NOT add greetings, disclaimers, or notes. Do NOT explain your translation.\n"
      . "- Output ONLY the translated text. No quotes, no preamble, no source-language label.";

    $payload = [
        'model'      => $model,
        'max_tokens' => 800,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
        'messages'   => [['role' => 'user', 'content' => $text]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return ['ok' => false, 'error' => 'Network error: ' . $err];
    $data = json_decode((string)$resp, true);
    if ($code !== 200) {
        return ['ok' => false, 'error' => (string)($data['error']['message'] ?? ('HTTP ' . $code))];
    }
    $out = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $out .= $block['text'];
    }
    $out = trim($out);
    if ($out === '') return ['ok' => false, 'error' => 'Model returned empty translation.'];

    return [
        'ok'    => true,
        'text'  => $out,
        'model' => $model,
        'usage' => $data['usage'] ?? null,
    ];
}

/**
 * Generate a persona from a 5-question wizard's answers. Powers the
 * client-facing persona builder at /admin/ai_persona_wizard.php.
 *
 * @param array $answers Keys:
 *                       - business_type      (string)
 *                       - tone               (string)
 *                       - language           (string)
 *                       - signature_thing    (string, optional)
 *                       - avoid              (string, optional)
 * @return array{ok:bool, persona?:string, error?:string, model?:string, usage?:array}
 */
function ai_generate_persona(array $company, array $answers): array
{
    if (!ai_is_configured($company)) {
        return ['ok' => false, 'error' => 'AI is not enabled for this workspace.'];
    }
    $apiKey = ai_api_key($company);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'No Anthropic API key configured.'];
    }
    $brand = trim((string)($company['name'] ?? 'this business'));
    $businessType    = trim((string)($answers['business_type']   ?? ''));
    $tone            = trim((string)($answers['tone']            ?? ''));
    $language        = trim((string)($answers['language']        ?? ''));
    $signatureThing  = trim((string)($answers['signature_thing'] ?? ''));
    $avoid           = trim((string)($answers['avoid']           ?? ''));

    if ($businessType === '' || $tone === '' || $language === '') {
        return ['ok' => false, 'error' => 'Business type, tone, and language are required.'];
    }

    // Use the workspace's chosen model for persona generation, but let
    // it fall back to a mid-tier default so a Haiku default doesn't
    // produce a thin persona. We pass the workspace default though so
    // the operator still controls cost when they've picked Opus.
    $model = ai_model_for_feature($company, 'persona_wizard');

    $systemPrompt =
        "You write persona descriptions for AI WhatsApp customer-service bots. "
      . "Given a short brief from the business owner, produce ONE persona description that:\n"
      . "- Is 3-6 sentences long, 200-600 characters total.\n"
      . "- Reads as a direct instruction to the AI (\"You are…\", \"You speak…\", \"Recommend…\").\n"
      . "- Captures voice, tone, and language usage vividly — a reader should picture a specific type of person.\n"
      . "- Includes any signature phrase / house special / thing to mention if the owner mentioned one.\n"
      . "- Includes any explicit 'never do X' rules from the owner.\n"
      . "- Never uses corporate filler ('leverage', 'engage', 'delight'). Never mentions AI, chatbot, model, or Claude.\n\n"
      . "Return ONLY the persona text. No markdown, no preamble, no quotes.";

    $userPrompt =
        "Business name: {$brand}\n"
      . "Business type: {$businessType}\n"
      . "Desired tone: {$tone}\n"
      . "Language usage: {$language}\n"
      . ($signatureThing !== '' ? "Signature thing to always mention when relevant: {$signatureThing}\n" : '')
      . ($avoid          !== '' ? "Must NEVER do / say: {$avoid}\n" : '')
      . "\nWrite the persona now.";

    $payload = [
        'model'      => $model,
        'max_tokens' => 600,
        'system'     => [['type' => 'text', 'text' => $systemPrompt, 'cache_control' => ['type' => 'ephemeral']]],
        'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . AI_API_VERSION,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => 'Network error: ' . $err];

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['content'][0]['text'])) {
        return ['ok' => false, 'error' => 'AI returned an unexpected shape (HTTP ' . $code . ')'];
    }
    $persona = trim((string)$data['content'][0]['text']);
    if ($persona === '') return ['ok' => false, 'error' => 'Model returned an empty persona.'];
    // Trim to the DB column size so it saves cleanly.
    if (mb_strlen($persona) > 1000) $persona = mb_substr($persona, 0, 1000);

    return [
        'ok'      => true,
        'persona' => $persona,
        'model'   => $model,
        'usage'   => $data['usage'] ?? null,
    ];
}
