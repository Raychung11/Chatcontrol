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
        'model'      => $model,
        'max_tokens' => 2000,
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
    // Defensive: strip code fences if the model included them anyway.
    if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $text, $m)) {
        $text = trim($m[1]);
    }
    $parsed = json_decode($text, true);
    if (!is_array($parsed) || !isset($parsed['topics']) || !is_array($parsed['topics'])) {
        return ['ok' => false, 'error' => 'Model returned malformed JSON.', 'raw' => $text];
    }

    return [
        'ok'     => true,
        'topics' => $parsed['topics'],
        'model'  => $model,
        'usage'  => $data['usage'] ?? null,
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

        log_activity((int)$company['id'], null, 'ai_first_touch_sent',
            'conversation', $conversationId,
            'model=' . ($decision['model'] ?? '?')
          . ' tokens=' . json_encode($decision['usage'] ?? [])
          . ' kb=' . count($decision['kb_titles'] ?? []));
    } catch (Throwable $e) {
        error_log('[ai_first_touch_handle] ' . $e->getMessage());
    }
}
