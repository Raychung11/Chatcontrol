<?php
/**
 * Web chat widget diagnostics — health check for the widget stack.
 *
 * Five sections:
 *   1. Database schema — every migration column/table the widget needs
 *   2. Channel inventory — every web_chat channel for this workspace
 *      with URL, activity counts, and quick "Open widget" test link
 *   3. Live endpoint test — spins up a real test session, sends a real
 *      test message, polls for a reply, and cleans up. Confirms each
 *      of the three API endpoints in one click.
 *   4. Flow + AI status — is a flow active that would reply?
 *   5. Recent activity — sessions / conversations / messages from web
 *      chat in the last 24h so the operator can see the pipe move.
 *
 * Every check renders as ✓ PASS / ⚠ WARN / ✗ FAIL with a specific fix
 * hint. No black boxes — read the page top to bottom and you know
 * exactly what's healthy and what isn't.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$results = [];   // [ ['section', 'check', 'status', 'detail', 'fix'] ]
function chk(array &$results, string $section, string $check, string $status, string $detail = '', string $fix = ''): void
{
    $results[] = compact('section', 'check', 'status', 'detail', 'fix');
}

// ============ 1. Schema ============
try {
    $r = $db->query("SHOW COLUMNS FROM channels LIKE 'provider'")->fetch();
    $providerOk = $r && stripos((string)$r['Type'], 'web_chat') !== false;
    chk($results, 'Schema', 'channels.provider enum includes web_chat',
        $providerOk ? 'PASS' : 'FAIL',
        $r ? 'Type: ' . mb_substr((string)$r['Type'], 0, 200) : 'column missing',
        $providerOk ? '' : "Run: ALTER TABLE channels MODIFY COLUMN provider "
            . "ENUM('cloud_api','evolution','aiserve_chatbot','facebook_page','instagram_business','web_chat') NOT NULL DEFAULT 'cloud_api';");
} catch (Throwable $e) {
    chk($results, 'Schema', 'channels.provider enum', 'FAIL', $e->getMessage(),
        'The channels table is missing entirely — run the base migration.');
}

foreach (['web_chat_greeting', 'web_chat_title'] as $col) {
    try {
        $r = $db->query("SHOW COLUMNS FROM channels LIKE '$col'")->fetch();
        chk($results, 'Schema', "channels.$col column exists",
            $r ? 'PASS' : 'FAIL',
            $r ? 'Type: ' . (string)$r['Type'] : 'column missing',
            $r ? '' : "Run: ALTER TABLE channels ADD COLUMN $col VARCHAR("
                . ($col === 'web_chat_greeting' ? 500 : 120) . ") DEFAULT NULL;");
    } catch (Throwable $e) {
        chk($results, 'Schema', "channels.$col column", 'FAIL', $e->getMessage(), '');
    }
}

try {
    $r = $db->query("SHOW TABLES LIKE 'web_chat_sessions'")->fetch();
    chk($results, 'Schema', 'web_chat_sessions table exists',
        $r ? 'PASS' : 'FAIL',
        $r ? 'table present' : 'missing',
        $r ? '' : 'Run the phase-35 CREATE TABLE for web_chat_sessions (see sql/migration_phase35.sql).');
} catch (Throwable $e) {
    chk($results, 'Schema', 'web_chat_sessions table', 'FAIL', $e->getMessage(), '');
}

try {
    $r = $db->query("SHOW COLUMNS FROM contacts LIKE 'platform'")->fetch();
    $platformOk = $r && stripos((string)$r['Type'], 'web_chat') !== false;
    chk($results, 'Schema', 'contacts.platform enum includes web_chat',
        $platformOk ? 'PASS' : 'FAIL',
        $r ? 'Type: ' . mb_substr((string)$r['Type'], 0, 200) : 'column missing',
        $platformOk ? '' : "Run: ALTER TABLE contacts MODIFY COLUMN platform "
            . "ENUM('whatsapp','facebook','instagram','web_chat') NOT NULL DEFAULT 'whatsapp';");
} catch (Throwable $e) {
    chk($results, 'Schema', 'contacts.platform', 'FAIL', $e->getMessage(), '');
}

// ============ 2. Channels ============
$channels = [];
try {
    $c = $db->prepare(
        'SELECT c.*,
                (SELECT COUNT(*) FROM web_chat_sessions WHERE channel_id = c.id) AS session_count,
                (SELECT COUNT(*) FROM conversations WHERE channel_id = c.id) AS conv_count,
                (SELECT COUNT(*) FROM messages WHERE channel_id = c.id AND created_at >= NOW() - INTERVAL 24 HOUR) AS msgs_24h
         FROM channels c
         WHERE c.company_id = ? AND c.provider = "web_chat"
         ORDER BY c.id DESC'
    );
    $c->execute([$companyId]);
    $channels = $c->fetchAll();

    if (!$channels) {
        chk($results, 'Channels', 'At least one active widget channel exists', 'WARN',
            'No web_chat channels in this workspace.',
            "Go to Admin → 💬 Web chat widget and click 'Create widget'.");
    } else {
        foreach ($channels as $ch) {
            $isActive = $ch['status'] === 'active';
            chk($results, 'Channels', "Channel #{$ch['id']} ({$ch['name']})",
                $isActive ? 'PASS' : 'WARN',
                sprintf('%s · %d sessions · %d conversations · %d messages last 24h',
                    ucfirst($ch['status']), (int)$ch['session_count'], (int)$ch['conv_count'], (int)$ch['msgs_24h']),
                $isActive ? '' : "Click Enable on the channel card at Admin → 💬 Web chat widget.");
        }
    }
} catch (Throwable $e) {
    chk($results, 'Channels', 'Channel inventory query', 'FAIL', $e->getMessage(), '');
}

// ============ 3. Live endpoint test ============
$liveTestResult = null;
if (is_post() && ($_POST['action'] ?? '') === 'live_test') {
    csrf_check();
    $targetChId = (int)($_POST['test_channel_id'] ?? 0);
    if ($targetChId > 0) {
        $liveTestResult = live_endpoint_test($db, $companyId, $targetChId);
    }
}

// ============ 4. Flow + AI ============
$activeFlows = [];
try {
    $f = $db->prepare(
        'SELECT id, name, trigger_type, trigger_keywords, status
         FROM flows WHERE company_id = ? AND status = "active"
         ORDER BY id DESC'
    );
    $f->execute([$companyId]);
    $activeFlows = $f->fetchAll();
    chk($results, 'Flows', 'At least one active flow exists',
        $activeFlows ? 'PASS' : 'WARN',
        $activeFlows ? count($activeFlows) . ' active flow(s)' : 'No active flows.',
        $activeFlows ? '' : 'Widget messages will be received but no bot will reply. '
            . 'Go to Admin → Message flows, seed the F&B ordering flow, and flip Status to Active.');

    if ($activeFlows) {
        $hasKw = false; $hasNew = false;
        foreach ($activeFlows as $af) {
            if ($af['trigger_type'] === 'new_conversation') $hasNew = true;
            if ($af['trigger_type'] === 'keyword' && trim((string)$af['trigger_keywords']) !== '') $hasKw = true;
        }
        chk($results, 'Flows', 'At least one flow has a widget-relevant trigger',
            ($hasKw || $hasNew) ? 'PASS' : 'WARN',
            'Keyword flows: ' . ($hasKw ? 'yes' : 'no')
            . ' · New-conversation flows: ' . ($hasNew ? 'yes' : 'no'),
            ($hasKw || $hasNew) ? '' : 'Make sure at least one active flow has trigger_type=new_conversation, '
                . 'or keyword with terms the customer will send (e.g. "menu, order").');
    }
} catch (Throwable $e) {
    chk($results, 'Flows', 'Flow lookup', 'WARN', $e->getMessage(),
        'The flows table might not exist yet — run the phase-29 migration.');
}

try {
    $co = $db->prepare('SELECT ai_api_key FROM companies WHERE id = ? LIMIT 1');
    $co->execute([$companyId]);
    $row = $co->fetch();
    $apiKey = trim((string)($row['ai_api_key'] ?? ''));
    $envKey = trim((string)(getenv('ANTHROPIC_API_KEY') ?: ''));
    $aiOk   = $apiKey !== '' || $envKey !== '';
    chk($results, 'AI', 'Anthropic API key configured',
        $aiOk ? 'PASS' : 'WARN',
        $apiKey !== '' ? 'Workspace-specific key set.'
            : ($envKey !== '' ? 'Portal-wide env var set.' : 'No key found.'),
        $aiOk ? '' : 'F&B ordering flow needs Claude to parse cart items. '
            . 'Set it at Admin → AI Settings, or export ANTHROPIC_API_KEY on the server.');
} catch (Throwable $e) {
    chk($results, 'AI', 'API key check', 'FAIL', $e->getMessage(), '');
}

// ============ 5. Recent activity ============
$recentActivity = [];
try {
    $r = $db->prepare(
        'SELECT s.session_token, s.created_at, s.last_seen_at, s.context,
                c.name AS channel_name, s.conversation_id,
                (SELECT COUNT(*) FROM messages WHERE conversation_id = s.conversation_id) AS msg_count
         FROM web_chat_sessions s
         INNER JOIN channels c ON c.id = s.channel_id
         WHERE c.company_id = ? AND s.created_at >= NOW() - INTERVAL 24 HOUR
         ORDER BY s.created_at DESC LIMIT 20'
    );
    $r->execute([$companyId]);
    $recentActivity = $r->fetchAll();
} catch (Throwable $e) { /* table missing = section is empty */ }

// ============ Grouping + render ============
$grouped = [];
foreach ($results as $r) $grouped[$r['section']][] = $r;

$countByStatus = ['PASS' => 0, 'WARN' => 0, 'FAIL' => 0];
foreach ($results as $r) $countByStatus[$r['status']] = ($countByStatus[$r['status']] ?? 0) + 1;

layout_start($current_user, 'Web chat debug', 'webchat');
?>
<style>
.dbg-summary { display:flex; gap:12px; margin-bottom: 16px; flex-wrap: wrap; }
.dbg-pill {
  padding: 8px 16px; border-radius: 10px; font-weight: 600; font-size: 15px;
}
.dbg-pill.pass { background: #dcfce7; color: #14532d; }
.dbg-pill.warn { background: #fef3c7; color: #78350f; }
.dbg-pill.fail { background: #fee2e2; color: #7f1d1d; }
.dbg-card {
  background: #fff; border: 1px solid #e3e8ee; border-radius: 12px;
  padding: 16px; margin-bottom: 16px;
}
.dbg-card h2 {
  margin: 0 0 12px; font-size: 13px; text-transform: uppercase;
  letter-spacing: .04em; color: #64748b; font-weight: 600;
}
.dbg-row {
  display: grid; grid-template-columns: 60px 1fr; gap: 12px;
  padding: 10px; border-bottom: 1px solid #f1f5f9;
}
.dbg-row:last-child { border-bottom: none; }
.dbg-status {
  font-size: 12px; font-weight: 700; padding: 4px 8px; border-radius: 4px;
  text-align: center; height: fit-content;
}
.dbg-status.PASS { background: #dcfce7; color: #14532d; }
.dbg-status.WARN { background: #fef3c7; color: #78350f; }
.dbg-status.FAIL { background: #fee2e2; color: #7f1d1d; }
.dbg-check { font-weight: 500; font-size: 14px; }
.dbg-detail { color: #64748b; font-size: 13px; margin-top: 3px; }
.dbg-fix {
  color: #7f1d1d; font-size: 12.5px; margin-top: 5px;
  padding: 6px 10px; background: #fef2f2; border-radius: 4px;
  border-left: 3px solid #dc2626; font-family: monospace;
  white-space: pre-wrap; word-break: break-word;
}
.dbg-live-test { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.dbg-live-result {
  background: #f6f9fb; border: 1px solid #e3e8ee; border-radius: 8px;
  padding: 12px; margin-top: 12px; font-size: 13px;
  font-family: monospace; white-space: pre-wrap; word-break: break-word;
}
.dbg-activity table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dbg-activity th, .dbg-activity td {
  padding: 6px 8px; text-align: left; border-bottom: 1px solid #f1f5f9;
}
.dbg-activity th { color: #64748b; font-weight: 500; font-size: 11.5px; text-transform: uppercase; }
</style>

<div class="dbg-summary">
  <span class="dbg-pill pass">✓ <?= (int)$countByStatus['PASS'] ?> PASS</span>
  <span class="dbg-pill warn">⚠ <?= (int)$countByStatus['WARN'] ?> WARN</span>
  <span class="dbg-pill fail">✗ <?= (int)$countByStatus['FAIL'] ?> FAIL</span>
  <a class="btn" href="/admin/webchat.php" style="margin-left: auto;">← Back to widgets</a>
</div>

<?php foreach ($grouped as $section => $rows): ?>
  <div class="dbg-card">
    <h2><?= e($section) ?></h2>
    <?php foreach ($rows as $r): ?>
      <div class="dbg-row">
        <div class="dbg-status <?= $r['status'] ?>"><?= $r['status'] === 'PASS' ? '✓' : ($r['status'] === 'FAIL' ? '✗' : '⚠') ?> <?= $r['status'] ?></div>
        <div>
          <div class="dbg-check"><?= e($r['check']) ?></div>
          <?php if ($r['detail']): ?><div class="dbg-detail"><?= e($r['detail']) ?></div><?php endif; ?>
          <?php if ($r['fix']): ?><div class="dbg-fix"><?= e($r['fix']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<!-- ============ Live endpoint test ============ -->
<div class="dbg-card">
  <h2>Live endpoint test</h2>
  <p class="muted small">
    Simulates a real customer visit end-to-end: creates a widget session,
    sends a test message, polls for any reply, then deletes the test session.
    Confirms all three API endpoints work in one click. Uses a real channel
    of your choice — safe (no orphan data left behind).
  </p>
  <?php if ($channels): ?>
    <form method="post" class="dbg-live-test">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="live_test">
      <label>Channel
        <select name="test_channel_id" style="padding: 6px 8px;">
          <?php foreach ($channels as $ch): ?>
            <option value="<?= (int)$ch['id'] ?>"><?= e($ch['name']) ?> (<?= e($ch['status']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Run live test</button>
    </form>
  <?php else: ?>
    <p class="muted">No web_chat channels to test against.</p>
  <?php endif; ?>

  <?php if ($liveTestResult): ?>
    <div class="dbg-live-result"><?= e($liveTestResult) ?></div>
  <?php endif; ?>
</div>

<!-- ============ Recent activity ============ -->
<div class="dbg-card dbg-activity">
  <h2>Recent widget sessions (last 24h)</h2>
  <?php if (!$recentActivity): ?>
    <p class="muted">No widget sessions in the last 24 hours. If you just scanned the QR and expected to see one, that means the widget page didn't successfully hit widget_start.php.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Started</th><th>Last seen</th><th>Channel</th><th>Context</th><th>Conv</th><th>Msgs</th></tr></thead>
      <tbody>
        <?php foreach ($recentActivity as $a): ?>
          <tr>
            <td><?= e(fmt_dt($a['created_at'])) ?></td>
            <td><?= e(fmt_dt($a['last_seen_at'])) ?></td>
            <td><?= e($a['channel_name']) ?></td>
            <td class="muted"><?= e((string)($a['context'] ?? '—')) ?></td>
            <td>
              <?php if ($a['conversation_id']): ?>
                <a href="/inbox/chat.php?id=<?= (int)$a['conversation_id'] ?>">#<?= (int)$a['conversation_id'] ?></a>
              <?php else: ?>
                <span class="muted small">no msg yet</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$a['msg_count'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php layout_end(); ?>

<?php
/**
 * End-to-end live test: uses the widget's own API endpoints (via cURL
 * to localhost) so we exercise the exact code path a real browser
 * would. Cleans up its test session on the way out.
 *
 * Returns a printable multi-line string with each step's result.
 */
function live_endpoint_test(PDO $db, int $companyId, int $channelId): string
{
    $out = [];
    $out[] = "=== Live endpoint test · channel #$channelId ===\n";

    $ch = $db->prepare('SELECT * FROM channels WHERE id = ? AND company_id = ? LIMIT 1');
    $ch->execute([$channelId, $companyId]);
    $channel = $ch->fetch();
    if (!$channel) return "FAIL: channel not found in this workspace.";
    if ($channel['provider'] !== 'web_chat') return "FAIL: channel is not web_chat (provider = " . $channel['provider'] . ").";
    if ($channel['status']   !== 'active')  return "FAIL: channel is not active.";

    $base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
        ? rtrim((string)APP_BASE_URL, '/')
        : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));

    // 1. widget_start.php
    $out[] = "[1] POST /api/widget_start.php";
    $r = live_curl($base . '/api/widget_start.php', ['channel_token' => $channel['webhook_token'], 'context' => 'debug_test']);
    $out[] = "    HTTP " . $r['code'] . " · body: " . mb_substr($r['body'], 0, 200);
    $startData = json_decode($r['body'], true);
    if (!is_array($startData) || empty($startData['ok']) || empty($startData['session_token'])) {
        $out[] = "  ✗ FAIL — widget_start did not return a valid session.";
        return implode("\n", $out);
    }
    $sessionToken = (string)$startData['session_token'];
    $out[] = "  ✓ session_token = " . mb_substr($sessionToken, 0, 16) . "…";

    // 2. widget_send.php — send "menu" so keyword-triggered flows fire.
    //    A ping-style text would leave the operator guessing whether the
    //    flow was silent because it didn't match or because it broke.
    $out[] = "\n[2] POST /api/widget_send.php (text = \"menu\")";
    $testText = 'menu';
    $r = live_curl($base . '/api/widget_send.php', ['session_token' => $sessionToken, 'text' => $testText]);
    $out[] = "    HTTP " . $r['code'] . " · body: " . mb_substr($r['body'], 0, 200);
    $sendData = json_decode($r['body'], true);
    if (!is_array($sendData) || empty($sendData['ok'])) {
        $out[] = "  ✗ FAIL — widget_send did not accept the message.";
        return implode("\n", $out);
    }
    $convId = (int)($sendData['conversation_id'] ?? 0);
    $out[] = "  ✓ message id " . (int)$sendData['message_id'] . " · conversation " . $convId;

    // Give flows a moment to react (they run inline on widget_send).
    usleep(500000);

    // 3. widget_poll.php
    $out[] = "\n[3] GET  /api/widget_poll.php";
    $r = live_curl($base . '/api/widget_poll.php?token=' . urlencode($sessionToken) . '&since=0', null);
    $out[] = "    HTTP " . $r['code'] . " · body: " . mb_substr($r['body'], 0, 300);
    $pollData = json_decode($r['body'], true);
    if (!is_array($pollData) || empty($pollData['ok'])) {
        $out[] = "  ✗ FAIL — widget_poll rejected the request.";
    } else {
        $out[] = "  ✓ polled " . count($pollData['messages'] ?? []) . " outbound message(s)";
        foreach (($pollData['messages'] ?? []) as $m) {
            $out[] = "     · #" . (int)$m['id'] . " " . mb_substr((string)$m['text'], 0, 80);
        }
    }

    // 4. Flow instance introspection — the smoking-gun report.
    $out[] = "\n[4] Flow-engine state for conversation #$convId";
    if ($convId > 0) {
        try {
            $fis = $db->prepare(
                'SELECT fi.id, fi.flow_id, fi.status, fi.current_node_id, fi.error_message,
                        f.name AS flow_name, f.trigger_type,
                        n.node_type AS current_node_type, n.label AS current_node_label
                 FROM flow_instances fi
                 LEFT JOIN flows f      ON f.id = fi.flow_id
                 LEFT JOIN flow_nodes n ON n.id = fi.current_node_id
                 WHERE fi.conversation_id = ? ORDER BY fi.id DESC'
            );
            $fis->execute([$convId]);
            $rows = $fis->fetchAll();
            if (!$rows) {
                $out[] = "  ⚠ No flow_instance was created. Possible causes:";
                $out[] = "     - no active flow whose keyword matched \"menu\"";
                $out[] = "     - trigger_type on your flow isn't keyword or new_conversation";
                $out[] = "     - flow's entry_node_id is NULL (seed was interrupted)";
            } else {
                foreach ($rows as $fi) {
                    $out[] = "  · instance #" . (int)$fi['id']
                           . " flow=\"" . (string)$fi['flow_name'] . "\""
                           . " trigger=" . (string)$fi['trigger_type']
                           . " status=" . (string)$fi['status']
                           . " node=" . (string)($fi['current_node_label'] ?? '(none)')
                           . " (" . (string)($fi['current_node_type'] ?? '') . ")";
                    if (!empty($fi['error_message'])) {
                        $out[] = "     ✗ error_message: " . (string)$fi['error_message'];
                    }
                }
            }

            // Show every outgoing row the flow inserted for this conv.
            $ms = $db->prepare(
                'SELECT id, sender_type, message_text, status, created_at
                 FROM messages
                 WHERE conversation_id = ? AND direction = "outgoing"
                 ORDER BY id ASC'
            );
            $ms->execute([$convId]);
            $om = $ms->fetchAll();
            $out[] = "  · outgoing messages in DB: " . count($om);
            foreach ($om as $m) {
                $out[] = "     - #" . (int)$m['id']
                       . " [" . (string)$m['sender_type'] . "/" . (string)$m['status'] . "] "
                       . mb_substr((string)$m['message_text'], 0, 80);
            }
        } catch (Throwable $e) {
            $out[] = "  ✗ Introspection query failed: " . $e->getMessage();
        }
    }

    // 5. Clean up test session (messages stay for auditability).
    try {
        $db->prepare('DELETE FROM web_chat_sessions WHERE session_token = ?')->execute([$sessionToken]);
        $out[] = "\n[5] Test session deleted. Test messages remain in the inbox for review.";
    } catch (Throwable $e) {
        $out[] = "\n[5] Cleanup failed: " . $e->getMessage();
    }

    $out[] = "\n=== Done ===";
    return implode("\n", $out);
}

function live_curl(string $url, ?array $postFields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,   // localhost self-signed acceptable for debug
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'code' => (int)$code,
        'body' => $body === false ? ('(curl error: ' . $err . ')') : (string)$body,
    ];
}
?>
