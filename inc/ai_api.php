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

    $payload = [
        'model'      => $model,
        'max_tokens' => AI_MAX_OUT_TOKENS,
        'system'     => [
            ['type' => 'text', 'text' => $systemPrompt,
             'cache_control' => ['type' => 'ephemeral']],
        ],
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
    ];
}
