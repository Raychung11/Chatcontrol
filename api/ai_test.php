<?php
/**
 * POST /api/ai_test.php
 *
 * Sends a minimal probe to Anthropic using the workspace's saved key
 * and returns the outcome. Lets the operator verify AI is actually
 * reachable + the key is valid, without having to trigger a real
 * reply-draft flow.
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/provider.php';

$user = require_role(['super_admin']);
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
csrf_check();

$company = load_company_settings((int)$user['company_id']) ?: [];

// Detailed status so the UI can point at exactly which thing is missing.
$status = [
    'ai_enabled_flag' => !empty($company['ai_enabled']),
    'has_api_key'     => !empty($company['ai_api_key']) || (bool)getenv('ANTHROPIC_API_KEY'),
    'model'           => (string)($company['ai_model'] ?? AI_DEFAULT_MODEL),
    'key_source'      => !empty($company['ai_api_key']) ? 'workspace' :
                         (getenv('ANTHROPIC_API_KEY') ? 'env_fallback' : 'none'),
];

if (!$status['ai_enabled_flag']) {
    json_response([
        'ok'     => false,
        'error'  => 'AI is not enabled. Tick the "Enable AI suggestions" checkbox above and save.',
        'status' => $status,
    ], 400);
}
if (!$status['has_api_key']) {
    json_response([
        'ok'     => false,
        'error'  => 'No Anthropic API key configured. Paste a key from console.anthropic.com and save.',
        'status' => $status,
    ], 400);
}

$apiKey = ai_api_key($company);
$model  = (string)($company['ai_model'] ?? AI_DEFAULT_MODEL) ?: AI_DEFAULT_MODEL;

// Minimal probe: ask Claude to reply with exactly "OK". max_tokens=20 keeps
// it under 1 sen. Any 200 response is a pass.
$payload = [
    'model'      => $model,
    'max_tokens' => 20,
    'messages'   => [['role' => 'user', 'content' => 'Reply with exactly the two letters OK.']],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'anthropic-version: ' . AI_API_VERSION,
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    log_activity((int)$user['company_id'], (int)$user['id'], 'ai_test_failed', 'company',
        (int)$user['company_id'], 'network: ' . substr($curlErr, 0, 200));
    json_response([
        'ok'     => false,
        'error'  => 'Network error reaching Anthropic: ' . $curlErr,
        'status' => $status,
    ], 502);
}

$data = json_decode((string)$resp, true);
if ($code !== 200) {
    $errMsg = (string)($data['error']['message'] ?? ('HTTP ' . $code));
    $errType = (string)($data['error']['type'] ?? '');
    $hint = '';
    if ($code === 401 || str_contains(strtolower($errType), 'authentication')) {
        $hint = ' — Your API key is wrong, revoked, or from a different Anthropic org.';
    } elseif ($code === 403 || str_contains(strtolower($errMsg), 'permission')) {
        $hint = ' — Key is valid but has no permission for this model. Try Haiku instead of Opus, or check org settings.';
    } elseif ($code === 429) {
        $hint = ' — Rate-limited by Anthropic. Wait a minute and try again, or check your Anthropic org quota.';
    } elseif ($code === 400 && str_contains(strtolower($errMsg), 'model')) {
        $hint = ' — Model "' . $model . '" is not available on your Anthropic account. Switch to claude-haiku-4-5.';
    }
    log_activity((int)$user['company_id'], (int)$user['id'], 'ai_test_failed', 'company',
        (int)$user['company_id'], 'http ' . $code . ': ' . substr($errMsg, 0, 200));
    json_response([
        'ok'        => false,
        'error'     => $errMsg . $hint,
        'http_code' => $code,
        'status'    => $status,
    ], 502);
}

$text = '';
foreach (($data['content'] ?? []) as $b) {
    if (($b['type'] ?? '') === 'text') $text .= $b['text'];
}

log_activity((int)$user['company_id'], (int)$user['id'], 'ai_test_ok', 'company',
    (int)$user['company_id'], 'model=' . $model);

json_response([
    'ok'      => true,
    'model'   => $model,
    'reply'   => trim($text),
    'usage'   => $data['usage'] ?? null,
    'status'  => $status,
]);
