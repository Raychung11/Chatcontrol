<?php
/**
 * cron/detect_coverage_gaps.php — daily.
 *
 * Scans recent messages for two patterns and inserts an
 * ai_coverage_gaps row per unresolved gap so the operator can turn
 * each one into a KB article from /admin/kb_coverage.php.
 *
 * Patterns (each is IDEMPOTENT via a UNIQUE-ish check on
 * conversation_id + created_at):
 *
 *   'no_answer'
 *     AI reply contains phrases like "I don't know" / "not sure" /
 *     "please connect you with a human" — signal the AI couldn't
 *     find an answer in the KB.
 *
 *   'fast_escalation'
 *     Human agent replied within 30 seconds of an AI reply on the
 *     same conversation — signal the human had to correct the AI.
 *
 * Cron entry:
 *   45 3 * * * php /var/www/aiserve/cron/detect_coverage_gaps.php >/dev/null 2>&1
 */

require_once __DIR__ . '/../inc/helpers.php';

const COV_LOOKBACK_HOURS = 26;                 // one day + slop for cron drift
const COV_NO_ANSWER_REGEX = '/(i (?:don\'t|do not) know|not sure|cannot find|couldn\'t find|couldn\'t answer|connect (?:you |)with (?:a |)(?:human|agent)|our team will (?:reach out|follow up))/i';
const COV_FAST_ESC_SECONDS = 30;

$db = aiserve_db();

// -- Pattern 1: AI reply with "no answer" phrase --
$s = $db->prepare(
    "SELECT id, company_id, conversation_id, message_text, created_at
     FROM messages
     WHERE sender_type = 'ai'
       AND direction = 'outgoing'
       AND created_at > NOW() - INTERVAL " . (int)COV_LOOKBACK_HOURS . " HOUR
       AND message_text IS NOT NULL"
);
$s->execute();
$rows = $s->fetchAll();

$noAnswerRows = 0;
foreach ($rows as $r) {
    if (!preg_match(COV_NO_ANSWER_REGEX, (string)$r['message_text'])) continue;
    // Find the customer message that triggered this AI reply — the
    // most recent incoming BEFORE it.
    $q = $db->prepare(
        "SELECT message_text FROM messages
         WHERE conversation_id = ? AND direction = 'incoming' AND created_at < ?
         ORDER BY id DESC LIMIT 1"
    );
    $q->execute([(int)$r['conversation_id'], (string)$r['created_at']]);
    $custMsg = (string)($q->fetchColumn() ?: '');
    if ($custMsg === '') continue;

    _cov_upsert($db, (int)$r['company_id'], (int)$r['conversation_id'], $custMsg, 'no_answer', (string)$r['created_at']);
    $noAnswerRows++;
}

// -- Pattern 2: human agent replied within COV_FAST_ESC_SECONDS of AI --
$s = $db->prepare(
    "SELECT ai.company_id, ai.conversation_id, ai.created_at AS ai_at
     FROM messages ai
     WHERE ai.sender_type = 'ai'
       AND ai.direction   = 'outgoing'
       AND ai.created_at > NOW() - INTERVAL " . (int)COV_LOOKBACK_HOURS . " HOUR
       AND EXISTS (
         SELECT 1 FROM messages hum
         WHERE hum.conversation_id = ai.conversation_id
           AND hum.direction       = 'outgoing'
           AND hum.sender_type     = 'agent'
           AND hum.created_at BETWEEN ai.created_at AND ai.created_at + INTERVAL " . (int)COV_FAST_ESC_SECONDS . " SECOND
       )"
);
$s->execute();
$fastEsc = $s->fetchAll();

$fastEscRows = 0;
foreach ($fastEsc as $r) {
    $q = $db->prepare(
        "SELECT message_text FROM messages
         WHERE conversation_id = ? AND direction = 'incoming' AND created_at < ?
         ORDER BY id DESC LIMIT 1"
    );
    $q->execute([(int)$r['conversation_id'], (string)$r['ai_at']]);
    $custMsg = (string)($q->fetchColumn() ?: '');
    if ($custMsg === '') continue;
    _cov_upsert($db, (int)$r['company_id'], (int)$r['conversation_id'], $custMsg, 'fast_escalation', (string)$r['ai_at']);
    $fastEscRows++;
}

echo "no-answer: {$noAnswerRows}, fast-escalation: {$fastEscRows}\n";

function _cov_upsert(PDO $db, int $companyId, int $convId, string $custMsg, string $reason, string $atStr): void
{
    try {
        // Dedup: skip if a gap for this (conv, customer message hash) already exists this week.
        $hashKey = mb_substr($custMsg, 0, 200);
        $dup = $db->prepare(
            "SELECT 1 FROM ai_coverage_gaps
             WHERE company_id = ? AND conversation_id = ?
               AND LEFT(customer_message, 200) = ?
               AND created_at > NOW() - INTERVAL 7 DAY
             LIMIT 1"
        );
        $dup->execute([$companyId, $convId, $hashKey]);
        if ($dup->fetchColumn()) return;
        $db->prepare(
            'INSERT INTO ai_coverage_gaps
                (company_id, conversation_id, customer_message, reason)
             VALUES (?, ?, ?, ?)'
        )->execute([$companyId, $convId, mb_substr($custMsg, 0, 2000), $reason]);
    } catch (Throwable $e) {
        error_log('[AiServe cov_upsert] ' . $e->getMessage());
    }
}
