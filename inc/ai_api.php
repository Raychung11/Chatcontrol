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

const AI_DEFAULT_MODEL  = 'claude-haiku-4-5';
const AI_API_VERSION    = '2023-06-01';
const AI_MAX_HISTORY    = 20;       // last N messages of context
const AI_MAX_OUT_TOKENS = 512;

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
    return "You are a WhatsApp customer service agent for {$brand}. "
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
