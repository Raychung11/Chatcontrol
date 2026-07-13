<?php
/**
 * Cron: weekly. For each workspace with learn_from_history_enabled = 1,
 * sample recent (customer question -> agent reply) pairs, ask Claude to
 * distill them into a structured team response guide, save the result
 * as an auto_generated=1 knowledge_base article. The AI reply drafting
 * path already reads from knowledge_base, so the bot starts using the
 * team's own answers automatically on the next inbound message.
 *
 * Hostinger cron-tab line (Sunday 3am):
 *   0 3 * * 0 /usr/bin/php $HOME/domains/inbox.aiserve.my/public_html/cron/learn_from_history.php >> $HOME/cron.log 2>&1
 *
 * Web access is blocked by cron/.htaccess. The script also self-blocks
 * if invoked over HTTP - cron-only.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be invoked from the command line (cron).\n");
}

require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/ai_api.php';
require_once __DIR__ . '/../inc/knowledge_base.php';

$db = aiserve_db();
$AUTO_TITLE      = '[Auto] Learned team responses';
$SAMPLE_CONVS    = 300;  // last N conversations with at least one agent reply
$MAX_PAIRS_CONV  = 3;    // top N Q&A pairs per conversation
$PERIOD_DAYS     = 45;   // only consider conversations from last N days

$companies = $db->query(
    'SELECT c.*
     FROM companies c
     WHERE c.status = "active" AND c.learn_from_history_enabled = 1'
)->fetchAll();

$now = date('Y-m-d H:i:s');
echo "[$now] learn_from_history checking " . count($companies) . " workspace(s)\n";

foreach ($companies as $co) {
    $cid = (int)$co['id'];
    echo "  workspace=$cid slug=" . $co['slug'] . "\n";

    // 1. Sample recent conversations that had at least one agent reply.
    $stmt = $db->prepare(
        'SELECT c.id
         FROM conversations c
         WHERE c.company_id = ?
           AND c.last_message_at > NOW() - INTERVAL ? DAY
           AND EXISTS (
             SELECT 1 FROM messages m
             WHERE m.conversation_id = c.id
               AND m.direction = "outgoing"
               AND m.sender_type IN ("agent", "ai")
           )
         ORDER BY c.last_message_at DESC
         LIMIT ' . (int)$SAMPLE_CONVS
    );
    $stmt->execute([$cid, $PERIOD_DAYS]);
    $convIds = array_map(fn($r) => (int)$r['id'], $stmt->fetchAll());
    if (!$convIds) {
        echo "    no eligible conversations, skipping\n";
        continue;
    }

    // 2. For each conversation, extract up to MAX_PAIRS_CONV
    //    (customer message -> next agent reply) pairs.
    $qaPairs = [];
    $mStmt = $db->prepare(
        'SELECT id, direction, sender_type, message_text, created_at
         FROM messages
         WHERE conversation_id = ?
           AND message_type IN ("text", "")
           AND message_text IS NOT NULL AND message_text <> ""
         ORDER BY id ASC'
    );
    foreach ($convIds as $convId) {
        $mStmt->execute([$convId]);
        $rows = $mStmt->fetchAll();
        $pending = null;
        $pairsHere = 0;
        foreach ($rows as $m) {
            if ($m['direction'] === 'incoming' && $m['sender_type'] === 'customer') {
                $pending = trim((string)$m['message_text']);
            } elseif ($pending !== null
                && $m['direction'] === 'outgoing'
                && in_array($m['sender_type'], ['agent', 'ai'], true)
            ) {
                $qaPairs[] = ['q' => $pending, 'a' => trim((string)$m['message_text'])];
                $pending = null;
                $pairsHere++;
                if ($pairsHere >= $MAX_PAIRS_CONV) break;
            }
        }
    }
    if (!$qaPairs) {
        echo "    no Q&A pairs extracted, skipping\n";
        continue;
    }
    echo "    extracted " . count($qaPairs) . " Q&A pairs from " . count($convIds) . " conversations\n";

    // 3. Distill with Claude.
    try {
        $result = ai_distill_conversation_history($co, $qaPairs);
        if (!$result['ok']) {
            echo "    distill failed: " . ($result['error'] ?? '?') . "\n";
            log_activity($cid, null, 'learn_from_history_failed', 'company', $cid,
                substr((string)($result['error'] ?? ''), 0, 300));
            continue;
        }
    } catch (Throwable $e) {
        echo "    distill exception: " . $e->getMessage() . "\n";
        error_log('[AiServe learn_from_history] ' . $e->getMessage());
        continue;
    }

    // 4. Upsert the auto-generated KB article. Look up by exact title so
    //    re-runs REPLACE the previous distillation instead of piling up
    //    new rows.
    $content = (string)$result['content'];
    $chars   = mb_strlen($content);

    try {
        $find = $db->prepare(
            'SELECT id FROM knowledge_base
             WHERE company_id = ? AND title = ? AND auto_generated = 1 LIMIT 1'
        );
        $find->execute([$cid, $AUTO_TITLE]);
        $existingId = (int)($find->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $db->prepare(
                'UPDATE knowledge_base
                 SET content_text = ?, content_chars = ?, status = "active",
                     last_auto_updated_at = NOW()
                 WHERE id = ?'
            )->execute([$content, $chars, $existingId]);
            echo "    updated KB article id=$existingId chars=$chars\n";
        } else {
            $db->prepare(
                'INSERT INTO knowledge_base
                    (company_id, title, source_filename, mime_type,
                     content_text, content_chars, status,
                     auto_generated, last_auto_updated_at, created_at)
                 VALUES (?, ?, NULL, "text/markdown",
                         ?, ?, "active",
                         1, NOW(), NOW())'
            )->execute([$cid, $AUTO_TITLE, $content, $chars]);
            $existingId = (int)$db->lastInsertId();
            echo "    inserted KB article id=$existingId chars=$chars\n";
        }
        log_activity($cid, null, 'learn_from_history_updated', 'knowledge_base', $existingId,
            'pairs=' . count($qaPairs) . ' chars=' . $chars);
    } catch (Throwable $e) {
        echo "    KB write failed: " . $e->getMessage() . "\n";
        error_log('[AiServe learn_from_history] ' . $e->getMessage());
    }
}

echo "[done]\n";
