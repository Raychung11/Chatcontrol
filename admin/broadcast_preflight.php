<?php
/**
 * /admin/broadcast_preflight.php
 *
 * System-health check for the broadcast pipeline. Runs a series of quick
 * checks against the current workspace's configuration and the surrounding
 * server environment, then renders one green/red/amber card per check with
 * a "Fix →" link where relevant. Intended as the first thing a super-admin
 * opens before their first send, and as a triage page when a blast stalls.
 *
 * Read-only: no DB writes, no migrations, no side effects.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/broadcasts.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

/**
 * Local helper: build one check result row. Status = 'pass' | 'fail' | 'info'.
 * $fixHref / $fixLabel drive the "Fix →" link on failed cards. $note is a
 * plain-text status line. $extraHtml renders any post-note markup (copy
 * blocks, per-channel lists, etc.).
 */
$mkCheck = static function (
    string $title,
    string $status,
    string $note,
    ?string $fixHref = null,
    ?string $fixLabel = null,
    ?string $extraHtml = null
): array {
    return [
        'title'      => $title,
        'status'     => $status,
        'note'       => $note,
        'fix_href'   => $fixHref,
        'fix_label'  => $fixLabel,
        'extra_html' => $extraHtml,
    ];
};

$checks = [];

// ---- 1. Workspace webhook_verify_token ----
try {
    $s = $db->prepare('SELECT LENGTH(webhook_verify_token) FROM companies WHERE id = ?');
    $s->execute([$companyId]);
    $len = (int)($s->fetchColumn() ?: 0);
    if ($len > 0) {
        $checks[] = $mkCheck(
            'Webhook verify token',
            'pass',
            'Set (' . $len . ' chars).'
        );
    } else {
        $checks[] = $mkCheck(
            'Webhook verify token',
            'fail',
            'Not set. WhatsApp cannot verify inbound webhook callbacks.',
            '/admin/settings.php#security-tokens',
            'Fix in Settings'
        );
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'Webhook verify token',
        'fail',
        'Error reading companies row: ' . $e->getMessage()
    );
}

// ---- 2. APP_BASE_URL defined ----
try {
    $hasBase = defined('APP_BASE_URL') && APP_BASE_URL !== '';
    if ($hasBase) {
        $checks[] = $mkCheck(
            'APP_BASE_URL configured',
            'pass',
            'APP_BASE_URL = ' . APP_BASE_URL
        );
    } else {
        $checks[] = $mkCheck(
            'APP_BASE_URL configured',
            'fail',
            "Ops must add define('APP_BASE_URL', 'https://...') to config/db_config.local.php. Contact your platform admin."
        );
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'APP_BASE_URL configured',
        'fail',
        'Error checking constant: ' . $e->getMessage()
    );
}

// ---- 3. At least one active channel exists ----
try {
    $s = $db->prepare('SELECT COUNT(*) FROM channels WHERE company_id = ? AND status = "active"');
    $s->execute([$companyId]);
    $activeCount = (int)$s->fetchColumn();
    if ($activeCount > 0) {
        $checks[] = $mkCheck(
            'Active channel available',
            'pass',
            $activeCount . ' active channel' . ($activeCount === 1 ? '' : 's') . ' in this workspace.'
        );
    } else {
        $checks[] = $mkCheck(
            'Active channel available',
            'fail',
            'No active channels — a broadcast has nothing to send through.',
            '/admin/channels.php',
            'Open channels'
        );
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'Active channel available',
        'fail',
        'Error reading channels: ' . $e->getMessage()
    );
}

// ---- 4. aiserve_chatbot channels have base_url + bearer token ----
try {
    $s = $db->prepare(
        'SELECT id, name, chatbot_base_url, chatbot_bearer_token
         FROM channels
         WHERE company_id = ? AND provider = "aiserve_chatbot" AND status = "active"
         ORDER BY name'
    );
    $s->execute([$companyId]);
    $cbChannels = $s->fetchAll();

    if (!$cbChannels) {
        $checks[] = $mkCheck(
            'aiserve_chatbot channels configured',
            'info',
            'No active aiserve_chatbot channels in this workspace — nothing to check.'
        );
    } else {
        $badChannels = [];
        $rowsHtml    = '<ul style="margin:6px 0 0 18px; padding:0; font-size:13px;">';
        foreach ($cbChannels as $ch) {
            $hasUrl   = trim((string)($ch['chatbot_base_url']     ?? '')) !== '';
            $hasToken = trim((string)($ch['chatbot_bearer_token'] ?? '')) !== '';
            $ok       = $hasUrl && $hasToken;
            if (!$ok) $badChannels[] = $ch;

            $missing = [];
            if (!$hasUrl)   $missing[] = 'base URL';
            if (!$hasToken) $missing[] = 'bearer token';
            $missingStr = $missing ? ' — missing: ' . implode(', ', $missing) : '';

            $rowsHtml .= '<li style="margin:4px 0;">'
                . ($ok ? '<span style="color:#1f7a3f;">&#10003;</span> ' : '<span style="color:#b3261e;">&#10007;</span> ')
                . '<strong>' . e((string)$ch['name']) . '</strong>'
                . e($missingStr)
                . ' &middot; <a href="/admin/channel_edit.php?id=' . (int)$ch['id'] . '">Edit</a>'
                . '</li>';
        }
        $rowsHtml .= '</ul>';

        if (!$badChannels) {
            $checks[] = $mkCheck(
                'aiserve_chatbot channels configured',
                'pass',
                'All ' . count($cbChannels) . ' aiserve_chatbot channel(s) have a base URL and bearer token.',
                null, null,
                $rowsHtml
            );
        } else {
            $first = $badChannels[0];
            $checks[] = $mkCheck(
                'aiserve_chatbot channels configured',
                'fail',
                count($badChannels) . ' of ' . count($cbChannels) . ' aiserve_chatbot channel(s) are missing credentials.',
                '/admin/channel_edit.php?id=' . (int)$first['id'],
                'Fix first channel',
                $rowsHtml
            );
        }
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'aiserve_chatbot channels configured',
        'fail',
        'Error reading channels: ' . $e->getMessage()
    );
}

// ---- 5. AI enabled + valid Anthropic key (optional) ----
try {
    $s = $db->prepare('SELECT ai_enabled, LENGTH(ai_api_key) FROM companies WHERE id = ?');
    $s->execute([$companyId]);
    $row = $s->fetch(PDO::FETCH_NUM);
    $aiOn  = (int)($row[0] ?? 0) === 1;
    $keyLen = (int)($row[1] ?? 0);
    if ($aiOn && $keyLen >= 20) {
        $checks[] = $mkCheck(
            'AI enabled + Anthropic key (optional)',
            'pass',
            'AI on, key length ' . $keyLen . ' chars. Broadcasts don\'t strictly need AI, but personalisation/replies do.'
        );
    } else {
        $why = !$aiOn ? 'AI is disabled' : 'API key too short (' . $keyLen . ' chars)';
        $checks[] = $mkCheck(
            'AI enabled + Anthropic key (optional)',
            'info',
            'Optional — broadcasts work without AI. ' . $why . '.',
            '/admin/ai_settings.php',
            'AI settings'
        );
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'AI enabled + Anthropic key (optional)',
        'fail',
        'Error reading companies row: ' . $e->getMessage()
    );
}

// ---- 6. Broadcast cron installed ----
try {
    $cronPath  = '/var/www/aiserve/cron/process_broadcasts.php';
    $fileOk    = @is_file($cronPath);
    $recent    = null;
    try {
        $s = $db->prepare(
            "SELECT MAX(created_at) FROM activity_logs
             WHERE company_id = ?
               AND action_type IN ('broadcast_batch_processed', 'broadcast_send')
               AND created_at >= NOW() - INTERVAL 30 MINUTE"
        );
        $s->execute([$companyId]);
        $recent = $s->fetchColumn();
    } catch (Throwable $inner) {
        // activity_logs read is best-effort; don't fail the check on this.
        $recent = null;
    }

    $cmd = '* * * * * php ' . $cronPath . ' >/dev/null 2>&1';
    $cmdBlock = '<div style="margin-top:8px;">'
        . '<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">'
        . '<code id="preflight-cron-cmd" style="display:inline-block; padding:6px 10px; background:#f6f9fb; border:1px solid #e2e6ec; border-radius:4px; font-size:12px; word-break:break-all;">'
        . e($cmd) . '</code>'
        . '<button type="button" class="btn btn-sm"'
        . ' onclick="navigator.clipboard&amp;&amp;navigator.clipboard.writeText(document.getElementById(\'preflight-cron-cmd\').innerText).then(function(){this.innerText=\'Copied\'}.bind(this))">'
        . 'Copy</button>'
        . '</div>'
        . '<div class="muted small" style="margin-top:4px;">Install as root with <code>crontab -e</code>. Runs every minute.</div>'
        . '</div>';

    if ($recent) {
        $checks[] = $mkCheck(
            'Broadcast cron running',
            'pass',
            'Cron activity seen in the last 30 min (last: ' . e((string)$recent) . ').',
            null, null,
            $cmdBlock
        );
    } elseif ($fileOk) {
        $checks[] = $mkCheck(
            'Broadcast cron installed',
            'info',
            'Worker file exists at ' . $cronPath . ' but no recent activity in activity_logs. Cron must be installed on the server.',
            null, null,
            $cmdBlock
        );
    } else {
        $checks[] = $mkCheck(
            'Broadcast cron installed',
            'fail',
            'Worker file NOT found at ' . $cronPath . '. Confirm the deploy path and install the cron entry.',
            null, null,
            $cmdBlock
        );
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'Broadcast cron installed',
        'fail',
        'Error checking cron: ' . $e->getMessage()
    );
}

// ---- 7. Contact count (info-only) ----
try {
    $s = $db->prepare('SELECT COUNT(*) FROM contacts WHERE company_id = ?');
    $s->execute([$companyId]);
    $contactCount = (int)$s->fetchColumn();
    $checks[] = $mkCheck(
        'Contacts on file',
        'info',
        'You have ' . number_format($contactCount) . ' contact' . ($contactCount === 1 ? '' : 's') . '. Import more at /contact_import.php.',
        '/contact_import.php',
        'Import contacts'
    );
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'Contacts on file',
        'fail',
        'Error counting contacts: ' . $e->getMessage()
    );
}

// ---- 8. Broadcast plan / quota (info-only) ----
try {
    $quotaFn = function_exists('broadcasts_quota_for_company')
        ? 'broadcasts_quota_for_company'
        : (function_exists('broadcast_quota_for_workspace') ? 'broadcast_quota_for_workspace' : null);
    if ($quotaFn === null) {
        $checks[] = $mkCheck(
            'Broadcast plan',
            'info',
            'Quota helper function not available in this build.'
        );
    } else {
        $q = $quotaFn($companyId);
        $planLabel = (string)($q['plan'] ?? 'unknown');
        $used      = (int)($q['used'] ?? 0);
        $limit     = (int)($q['limit'] ?? 0);
        $unlimited = !empty($q['unlimited']);
        if ($unlimited) {
            $note = 'Plan: ' . $planLabel . ' · ' . number_format($used) . ' recipient(s) sent this month · unlimited.';
        } else {
            $note = 'Plan: ' . $planLabel . ' · used ' . number_format($used) . ' / ' . number_format($limit) . ' this month.';
        }
        $checks[] = $mkCheck(
            'Broadcast plan',
            'info',
            $note,
            '/admin/plan.php',
            'Manage plan'
        );
    }
} catch (Throwable $e) {
    $checks[] = $mkCheck(
        'Broadcast plan',
        'fail',
        'Error reading quota: ' . $e->getMessage()
    );
}

// ---- Overall banner tally ----
$failCount = 0;
$passCount = 0;
$infoCount = 0;
foreach ($checks as $c) {
    if ($c['status'] === 'fail')  $failCount++;
    if ($c['status'] === 'pass')  $passCount++;
    if ($c['status'] === 'info')  $infoCount++;
}
$allPass = ($failCount === 0);

layout_start($current_user, 'Broadcast preflight', 'broadcast_preflight');
?>

<div style="margin-bottom:12px;">
  <a href="/admin/broadcasts.php" class="muted small">&larr; Back to Broadcasts</a>
</div>

<?php if ($allPass): ?>
  <div class="alert alert-success" style="font-size:14px;">
    <strong>Ready to broadcast &#9989;</strong>
    &middot; <?= (int)$passCount ?> check(s) passed, <?= (int)$infoCount ?> info-only.
  </div>
<?php else: ?>
  <div class="alert alert-error" style="font-size:14px;">
    <strong><?= (int)$failCount ?> issue<?= $failCount === 1 ? '' : 's' ?> to fix</strong>
    &middot; <?= (int)$passCount ?> passed, <?= (int)$infoCount ?> info-only.
    Fix each red card below before starting a broadcast.
  </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:12px;">
  <?php foreach ($checks as $c):
      $s = $c['status'];
      $bg     = $s === 'pass' ? '#e8f7ee' : ($s === 'fail' ? '#fdecea' : '#fff7e6');
      $border = $s === 'pass' ? '#b8e3c5' : ($s === 'fail' ? '#f5c6c2' : '#f4d68a');
      $color  = $s === 'pass' ? '#1f7a3f' : ($s === 'fail' ? '#b3261e' : '#8a5a00');
      $icon   = $s === 'pass' ? '&#10003;' : ($s === 'fail' ? '&#10007;' : '&#9432;');
      $label  = $s === 'pass' ? 'Pass' : ($s === 'fail' ? 'Fail' : 'Info');
  ?>
    <div class="card" style="background:<?= $bg ?>; border:1px solid <?= $border ?>; margin:0;">
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="font-size:18px; color:<?= $color ?>; line-height:1;"><?= $icon ?></span>
        <strong style="color:<?= $color ?>;"><?= e($c['title']) ?></strong>
        <span style="margin-left:auto; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; padding:2px 8px; border-radius:999px; background:rgba(255,255,255,0.6); color:<?= $color ?>;">
          <?= e($label) ?>
        </span>
      </div>
      <div style="margin-top:8px; font-size:13px; color:#333;">
        <?= e($c['note']) ?>
      </div>
      <?php if (!empty($c['extra_html'])): ?>
        <?= $c['extra_html'] /* trusted HTML built above */ ?>
      <?php endif; ?>
      <?php if ($c['status'] === 'fail' && !empty($c['fix_href'])): ?>
        <div style="margin-top:10px;">
          <a class="btn btn-sm btn-primary" href="<?= e($c['fix_href']) ?>">
            <?= e($c['fix_label'] ?: 'Fix') ?> &rarr;
          </a>
        </div>
      <?php elseif ($c['status'] === 'info' && !empty($c['fix_href'])): ?>
        <div style="margin-top:10px;">
          <a class="btn btn-sm" href="<?= e($c['fix_href']) ?>">
            <?= e($c['fix_label'] ?: 'Open') ?> &rarr;
          </a>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<p class="muted small" style="margin-top:16px;">
  This page is read-only. Nothing here changes settings — it just reports what the
  broadcast pipeline needs and where to fix it.
</p>

<?php layout_end(); ?>
