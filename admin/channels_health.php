<?php
/**
 * /admin/channels_health.php — workspace-facing channel health.
 *
 * A super_admin or manager of a workspace lands here from the
 * "Channel silence detected" banner (or from the sidebar) and sees
 * per-channel:
 *   - Health dot (🟢 <1h · 🟡 <24h · 🟠 <7d · 🔴 silent ≥7d · ⚫ never)
 *   - Provider chip + display phone
 *   - Last inbound + last outbound timestamps
 *   - 24h + 7d inbound counts
 *   - Copy-paste webhook URL to re-paste into the provider config if
 *     it looks like the webhook got dropped
 *
 * Only reads their OWN company's channels — no cross-workspace peek.
 * The platform-admin channels_debug.php remains the super view.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/channels.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

$db = aiserve_db();

$channels = $db->prepare(
    "SELECT id, name, provider, display_phone, status, webhook_token, is_default,
            probe_state, probe_state_since, probe_last_at, probe_queue_size,
            alert_last_sent_at
     FROM channels
     WHERE company_id = ?
     ORDER BY is_default DESC, id ASC"
);
$channels->execute([$companyId]);
$channels = $channels->fetchAll();

// Per-channel stats: last inbound, last outbound, 24h + 7d inbound counts.
$stats = [];
if ($channels) {
    $ph = implode(',', array_fill(0, count($channels), '?'));
    $ids = array_map(fn($c) => (int)$c['id'], $channels);
    $q = $db->prepare(
        "SELECT channel_id,
                MAX(CASE WHEN direction = 'incoming' THEN created_at END) AS last_in,
                MAX(CASE WHEN direction = 'outgoing' THEN created_at END) AS last_out,
                SUM(direction = 'incoming' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS in_24h,
                SUM(direction = 'incoming' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS in_7d
         FROM messages
         WHERE channel_id IN ($ph)
         GROUP BY channel_id"
    );
    $q->execute($ids);
    foreach ($q->fetchAll() as $r) $stats[(int)$r['channel_id']] = $r;
}

$base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? ''));

layout_start($current_user, '🩺 Channels health', 'channels_health');
?>
<style>
.ch-table th, .ch-table td { padding: 10px 12px; }
.ch-health { display:inline-block; width:12px; height:12px; border-radius:50%; vertical-align:middle; margin-right:6px; }
.ch-h-live  { background:#16A34A; box-shadow:0 0 0 3px rgba(22,163,74,.18); }
.ch-h-day   { background:#84CC16; }
.ch-h-week  { background:#F59E0B; }
.ch-h-dark  { background:#DC2626; box-shadow:0 0 0 3px rgba(220,38,38,.15); }
.ch-h-never { background:#94a3b8; }
.ch-h-off   { background:#64748b; }
.ch-provider {
    display:inline-block; padding:2px 8px; border-radius:999px;
    font-size:11px; font-weight:600; background:#eef2ff; color:#3730a3;
}
.ch-count { font-variant-numeric: tabular-nums; }
.ch-url {
    background:#f6f9fb; border:1px solid #e3e8ee; border-radius:6px;
    padding:8px 10px; font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size:12px; word-break:break-all;
}
</style>

<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
  <div>
    <h1 style="margin:0;">🩺 Channels health</h1>
    <div class="muted small">
      When was each channel last active? Use this to spot a WhatsApp gateway that silently stopped delivering.
    </div>
  </div>
  <a class="btn btn-sm" href="/admin/channels.php">← Manage channels</a>
</div>

<?php
$staleNow = channels_stale_ingestion($companyId, 6);
if ($staleNow):
?>
  <div class="alert alert-error" style="margin-bottom:14px;">
    ⚠ <strong><?= count($staleNow) ?> channel(s) appear silent</strong> —
    check the affected rows below. The most common causes: Meta Cloud API
    webhook URL changed or verify_token mismatched, Evolution session
    disconnected (needs QR re-scan), or aiserve_chatbot bearer token
    revoked.
  </div>
<?php endif; ?>

<div class="card" style="padding:0;">
  <table class="data-table ch-table" style="margin:0;">
    <thead>
      <tr>
        <th>Channel</th>
        <th>Provider</th>
        <th>Session state</th>
        <th>Last inbound</th>
        <th>Last outbound</th>
        <th style="text-align:right;">24h in</th>
        <th style="text-align:right;">Queue</th>
        <th style="text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$channels): ?>
        <tr><td colspan="8" class="muted" style="text-align:center; padding:24px;">
          No channels yet. <a href="/admin/channels.php">Set one up →</a>
        </td></tr>
      <?php endif; ?>

      <?php foreach ($channels as $c):
        $s = $stats[(int)$c['id']] ?? ['last_in' => null, 'last_out' => null, 'in_24h' => 0, 'in_7d' => 0];
        $lastInTs = $s['last_in'] ? db_datetime_to_ts((string)$s['last_in']) : null;
        $mins     = $lastInTs ? max(0, (time() - $lastInTs) / 60) : null;

        if ($c['status'] !== 'active') {
            $healthCls = 'ch-h-off';    $healthLbl = 'Disabled';
        } elseif ($mins === null) {
            $healthCls = 'ch-h-never';  $healthLbl = 'Never received a message';
        } elseif ($mins < 60) {
            $healthCls = 'ch-h-live';   $healthLbl = 'Live — inbound within the last hour';
        } elseif ($mins < 1440) {
            $healthCls = 'ch-h-day';    $healthLbl = 'Inbound within the last 24 hours';
        } elseif ($mins < 10080) {
            $healthCls = 'ch-h-week';   $healthLbl = 'Inbound within the last week';
        } else {
            $healthCls = 'ch-h-dark';   $healthLbl = 'No inbound for over a week — check the gateway';
        }
      ?>
        <tr>
          <td>
            <span class="ch-health <?= e($healthCls) ?>" title="<?= e($healthLbl) ?>"></span>
            <strong><?= e($c['name']) ?></strong>
            <?php if ($c['is_default']): ?><span class="muted small">(default)</span><?php endif; ?>
            <?php if (!empty($c['display_phone'])): ?>
              <div class="muted small"><code><?= e($c['display_phone']) ?></code></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="ch-provider"><?= e((string)$c['provider']) ?></span>
          </td>
          <td>
            <?php
              // Live probe state — filled by cron/evolution_health_ping.php
              // for evolution channels; other providers show '—' since
              // we don't have a comparable ping endpoint for them.
              $probeState = (string)($c['probe_state'] ?? 'unknown');
              $probeLast  = (string)($c['probe_last_at'] ?? '');
              $probeSince = (string)($c['probe_state_since'] ?? '');
              $probeLabel = match ($probeState) {
                  'connected'    => ['🟢 Connected',     '#14532d', '#dcfce7'],
                  'connecting'   => ['🟡 Reconnecting',  '#78350f', '#fef3c7'],
                  'disconnected' => ['🔴 Disconnected',  '#991b1b', '#fee2e2'],
                  default        => ['⚫ Unknown',       '#334155', '#f1f5f9'],
              };
            ?>
            <?php if ($c['provider'] === 'evolution' && $probeLast): ?>
              <span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:600;
                           background:<?= e($probeLabel[2]) ?>; color:<?= e($probeLabel[1]) ?>;">
                <?= e($probeLabel[0]) ?>
              </span>
              <div class="muted small" style="margin-top:2px;">
                <?php if ($probeSince): ?>since <?= e(relative_time($probeSince)) ?> ago<?php endif; ?>
                <br>probed <?= e(relative_time($probeLast)) ?> ago
              </div>
            <?php elseif ($c['provider'] === 'evolution'): ?>
              <span class="muted small">not probed yet</span>
              <div class="muted small">install cron: <code>*/5 * * * *</code> php cron/evolution_health_ping.php</div>
            <?php else: ?>
              <span class="muted small">—</span>
              <div class="muted small">(no ping endpoint for <?= e((string)$c['provider']) ?>)</div>
            <?php endif; ?>
          </td>
          <td class="muted small">
            <?= $s['last_in']  ? e(fmt_dt($s['last_in']))  : '—' ?>
            <?php if ($lastInTs): ?>
              <div><?= e(relative_time((string)$s['last_in'])) ?> ago</div>
            <?php endif; ?>
          </td>
          <td class="muted small">
            <?= $s['last_out'] ? e(fmt_dt($s['last_out'])) : '—' ?>
          </td>
          <td class="ch-count" style="text-align:right;"><?= (int)$s['in_24h'] ?></td>
          <td class="ch-count" style="text-align:right;">
            <?php $q = (int)($c['probe_queue_size'] ?? 0);
                  $qColor = $q === 0 ? '#64748b' : ($q < 10 ? '#78350f' : '#991b1b'); ?>
            <span style="color: <?= e($qColor) ?>; font-weight: <?= $q > 0 ? '700' : '400' ?>;">
              <?= $q ?>
            </span>
            <?php if ($q > 0): ?>
              <div class="muted small">unsent 1h</div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <a class="btn btn-sm" href="/admin/channel_edit.php?id=<?= (int)$c['id'] ?>">Edit</a>
            <?php if ($c['provider'] === 'evolution'): ?>
              <a class="btn btn-sm" href="/admin/evolution_connect.php">Pair</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php
          // Show the webhook URL under any channel that has a stale-ingestion
          // warning, so the operator can copy + re-paste into the provider's
          // dashboard in one place without leaving this page.
          $isStale = $lastInTs && $mins > 60 * 6;
          if ($isStale && !empty($c['webhook_token'])):
            $endpoint = match ($c['provider']) {
                'evolution', 'aiserve_chatbot' => '/webhook/evolution.php',
                'cloud_api'                    => '/webhook/whatsapp.php',
                'facebook_page', 'instagram_business' => '/webhook/meta.php',
                default => '/webhook/evolution.php',
            };
            $webhookUrl = $base . $endpoint . '?ch=' . $c['webhook_token'];
        ?>
        <tr>
          <td colspan="8" style="background:#fef2f2;">
            <div style="margin-bottom:6px; color:#7f1d1d; font-size:13px;">
              🔧 Re-paste this URL into <strong><?= e((string)$c['provider']) ?></strong>'s webhook config if it was cleared:
            </div>
            <div class="ch-url"><?= e($webhookUrl) ?></div>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="muted small" style="margin-top:12px;">
  <strong>Health colours:</strong>
  <span class="ch-health ch-h-live"></span> Live (&lt;1h) &nbsp;
  <span class="ch-health ch-h-day"></span> &lt;24h &nbsp;
  <span class="ch-health ch-h-week"></span> &lt;7d &nbsp;
  <span class="ch-health ch-h-dark"></span> Silent &nbsp;
  <span class="ch-health ch-h-never"></span> Never used &nbsp;
  <span class="ch-health ch-h-off"></span> Disabled
</div>

<?php layout_end(); ?>
