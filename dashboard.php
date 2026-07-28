<?php
/**
 * Workspace dashboard.
 *
 * The old dashboard was five stat cards + a "recent activity" table.
 * As the product has grown (multi-channel, broadcasts, flows, branches,
 * rotation, comment inbox) that gave no sense of what was going on.
 * This version adds:
 *
 *   - KPI tiles (open, unassigned, avg first-response today, messages today)
 *   - A 14-day message-volume trend chart
 *   - Agent workload (horizontal bars) and channel breakdown (stacked)
 *   - Live alerts (unassigned pile-up, expiring windows, failed sends,
 *     failed flow instances, running broadcasts)
 *   - Quick-action tiles (new chat, broadcast, import, flow)
 *   - Recent activity table (kept, with channel column)
 *
 * All queries are one-shot per widget - the dashboard is meant to be
 * refreshed on load, not polled every 5s. Every card either shows a
 * number or an actionable link; nothing is decorative.
 *
 * Design: dataviz procedure - form first, color LAST. Single hue for
 * magnitude-over-time (area chart uses one brand green). Categorical
 * hues for the channel breakdown are distinct in both hue AND
 * lightness so red-green colorblind viewers can still tell them apart.
 * All queries wrap potentially-missing tables (flows, broadcasts) in
 * try/catch so a half-migrated workspace still renders.
 */

require_once __DIR__ . '/inc/layout.php';

$current_user = require_login();
$companyId    = (int)$current_user['company_id'];
$userId       = (int)$current_user['id'];
$db           = aiserve_db();

// -------------------- KPI tiles --------------------
$stats = $db->prepare(
    'SELECT
       SUM(status <> "closed")                                          AS open_total,
       SUM(assigned_user_id IS NULL AND status <> "closed")             AS unassigned,
       SUM(assigned_user_id = ? AND status <> "closed")                 AS mine,
       SUM(status = "escalated")                                        AS escalated,
       SUM(status = "closed" AND DATE(updated_at) = CURDATE())          AS closed_today
     FROM conversations WHERE company_id = ?'
);
$stats->execute([$userId, $companyId]);
$stats = $stats->fetch() ?: [];

$msgsToday = (int)$db->query(
    "SELECT COUNT(*) FROM messages
     WHERE company_id = $companyId AND DATE(created_at) = CURDATE()"
)->fetchColumn();

$avgResp = $db->prepare(
    'SELECT AVG(TIMESTAMPDIFF(SECOND, last_customer_message_at, first_response_at))
     FROM conversations
     WHERE company_id = ?
       AND DATE(first_response_at) = CURDATE()
       AND first_response_at IS NOT NULL
       AND last_customer_message_at IS NOT NULL
       AND first_response_at >= last_customer_message_at'
);
$avgResp->execute([$companyId]);
$avgRespSecs = (int)($avgResp->fetchColumn() ?: 0);

// -------------------- 14-day message volume --------------------
$vol = $db->prepare(
    'SELECT DATE(created_at) AS d, COUNT(*) AS n
     FROM messages
     WHERE company_id = ?
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(created_at)'
);
$vol->execute([$companyId]);
$volByDate = [];
foreach ($vol->fetchAll() as $r) { $volByDate[(string)$r['d']] = (int)$r['n']; }

$volumeSeries = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $volumeSeries[] = ['date' => $d, 'n' => (int)($volByDate[$d] ?? 0)];
}
$maxVol = 1;
foreach ($volumeSeries as $p) if ($p['n'] > $maxVol) $maxVol = $p['n'];

// -------------------- Agent workload (top 8) --------------------
$workload = $db->prepare(
    'SELECT u.name, COUNT(c.id) AS n
     FROM users u
     LEFT JOIN conversations c
       ON c.assigned_user_id = u.id AND c.status <> "closed"
     WHERE u.company_id = ? AND u.status = "active" AND u.role IN ("agent","manager")
     GROUP BY u.id, u.name
     ORDER BY n DESC, u.name
     LIMIT 8'
);
$workload->execute([$companyId]);
$workload = $workload->fetchAll();
$maxWorkload = 1;
foreach ($workload as $w) if ((int)$w['n'] > $maxWorkload) $maxWorkload = (int)$w['n'];

// -------------------- Channel breakdown --------------------
$byChannel = $db->prepare(
    'SELECT COALESCE(ch.name, "Unassigned") AS name,
            COALESCE(ch.provider, "none")   AS provider,
            COUNT(c.id) AS n
     FROM conversations c
     LEFT JOIN channels ch ON ch.id = c.channel_id
     WHERE c.company_id = ? AND c.status <> "closed"
     GROUP BY ch.id, ch.name, ch.provider
     ORDER BY n DESC'
);
$byChannel->execute([$companyId]);
$byChannel = $byChannel->fetchAll();
$channelTotal = 0;
foreach ($byChannel as $c) $channelTotal += (int)$c['n'];

// -------------------- Automation status --------------------
$flowsRunning = 0;
$flowsWaiting = 0;
$flowsFailed  = 0;
try {
    $flowsRunning = (int)$db->query("SELECT COUNT(*) FROM flow_instances fi INNER JOIN flows f ON f.id = fi.flow_id WHERE f.company_id = $companyId AND fi.status = 'running'")->fetchColumn();
    $flowsWaiting = (int)$db->query("SELECT COUNT(*) FROM flow_instances fi INNER JOIN flows f ON f.id = fi.flow_id WHERE f.company_id = $companyId AND fi.status = 'waiting'")->fetchColumn();
    $flowsFailed  = (int)$db->query("SELECT COUNT(*) FROM flow_instances fi INNER JOIN flows f ON f.id = fi.flow_id WHERE f.company_id = $companyId AND fi.status = 'failed' AND fi.completed_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
} catch (Throwable $e) { /* flow tables may not exist yet — silently skip */ }

$broadcastsRunning = 0;
try {
    $broadcastsRunning = (int)$db->query("SELECT COUNT(*) FROM broadcasts WHERE company_id = $companyId AND status = 'running'")->fetchColumn();
} catch (Throwable $e) { /* ok */ }

$failedSends24h = (int)$db->query(
    "SELECT COUNT(*) FROM messages
     WHERE company_id = $companyId AND status = 'failed'
       AND created_at >= NOW() - INTERVAL 24 HOUR"
)->fetchColumn();

$windowExpiringSoon = (int)$db->query(
    "SELECT COUNT(*) FROM conversations
     WHERE company_id = $companyId AND status <> 'closed'
       AND service_window_expires_at IS NOT NULL
       AND service_window_expires_at BETWEEN NOW() AND NOW() + INTERVAL 1 HOUR"
)->fetchColumn();

// -------------------- Alerts (only shown when actionable) --------------------
$alerts = [];
if ((int)($stats['unassigned'] ?? 0) >= 10) {
    $alerts[] = ['level' => 'warning', 'msg' => (int)$stats['unassigned'] . ' unassigned conversations — assign or reroute before customers wait longer.', 'href' => '/inbox/index.php?filter=unassigned'];
}
if ($windowExpiringSoon > 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "$windowExpiringSoon conversation(s) with 24-hour reply window expiring within an hour.", 'href' => '/inbox/index.php?filter=all'];
}
if ($failedSends24h > 0) {
    $alerts[] = ['level' => 'serious', 'msg' => "$failedSends24h failed message send(s) in the last 24 hours.", 'href' => '/admin/webhook_log.php'];
}
if ($flowsFailed > 0) {
    $alerts[] = ['level' => 'warning', 'msg' => "$flowsFailed flow instance(s) failed in the last 24 hours.", 'href' => '/admin/flows.php'];
}
if ((int)($stats['escalated'] ?? 0) > 0) {
    $alerts[] = ['level' => 'serious', 'msg' => (int)$stats['escalated'] . ' escalated conversation(s) waiting.', 'href' => '/inbox/index.php?filter=escalated'];
}

// -------------------- Recent activity --------------------
$recent = $db->prepare(
    'SELECT c.id, c.status, c.last_message_text, c.last_message_at,
            ct.display_name, ct.wa_id, u.name AS agent_name,
            ch.name AS channel_name
     FROM conversations c
     INNER JOIN contacts ct ON ct.id = c.contact_id
     LEFT  JOIN users     u  ON u.id = c.assigned_user_id
     LEFT  JOIN channels  ch ON ch.id = c.channel_id
     WHERE c.company_id = ?
     ORDER BY c.last_message_at DESC LIMIT 10'
);
$recent->execute([$companyId]);
$recent = $recent->fetchAll();

// -------------------- Helpers --------------------
$fmtDur = function (int $s): string {
    if ($s <= 0)   return '—';
    if ($s < 60)   return $s . 's';
    if ($s < 3600) return round($s / 60) . 'm';
    return round($s / 3600, 1) . 'h';
};
$brandColor = '#25D366';
// Categorical palette — distinct hue AND lightness so red-green
// colorblind readers still tell adjacent series apart.
$palette = ['#16A34A', '#2563EB', '#9333EA', '#F59E0B', '#EA580C', '#0891B2', '#DB2777', '#65A30D'];

layout_start($current_user, 'Dashboard', 'dashboard');
?>

<style>
:root {
  --dash-surface:    #ffffff;
  --dash-surface-2:  #f6f9fb;
  --dash-ink:        #0f172a;
  --dash-ink-muted:  #64748b;
  --dash-border:     #e3e8ee;
  --dash-good:       #16A34A;
  --dash-warn:       #F59E0B;
  --dash-serious:    #DC2626;
  --dash-brand:      <?= e($brandColor) ?>;
}
@media (prefers-color-scheme: dark) {
  :root {
    --dash-surface:   #0f172a;
    --dash-surface-2: #1e293b;
    --dash-ink:       #f8fafc;
    --dash-ink-muted: #94a3b8;
    --dash-border:    #334155;
  }
}
.dash-wrap { display: grid; gap: 16px; }
.dash-kpi-row {
  display: grid; gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}
.dash-kpi {
  background: var(--dash-surface); border: 1px solid var(--dash-border);
  border-radius: 12px; padding: 16px; text-decoration: none;
  color: inherit; display: block; transition: transform .1s, border-color .1s;
}
.dash-kpi:hover { transform: translateY(-1px); border-color: var(--dash-brand); }
.dash-kpi .label {
  color: var(--dash-ink-muted); font-size: 12.5px;
  text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px;
}
.dash-kpi .value {
  font-size: 28px; font-weight: 700; line-height: 1.1;
  color: var(--dash-ink);
}
.dash-kpi .sub {
  color: var(--dash-ink-muted); font-size: 12px; margin-top: 4px;
}
.dash-kpi .value.warn    { color: var(--dash-warn); }
.dash-kpi .value.serious { color: var(--dash-serious); }
.dash-kpi .value.good    { color: var(--dash-good); }

.dash-actions {
  display: grid; gap: 8px;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}
.dash-action {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 14px; background: var(--dash-surface);
  border: 1px solid var(--dash-border); border-radius: 10px;
  color: var(--dash-ink); text-decoration: none; font-weight: 500;
}
.dash-action:hover { border-color: var(--dash-brand); background: var(--dash-surface-2); }
.dash-action .ico { font-size: 20px; }

.dash-grid {
  display: grid; gap: 16px;
  grid-template-columns: 2fr 1fr;
}
@media (max-width: 900px) { .dash-grid { grid-template-columns: 1fr; } }

.dash-card {
  background: var(--dash-surface); border: 1px solid var(--dash-border);
  border-radius: 12px; padding: 16px;
}
.dash-card h3 {
  margin: 0 0 12px; font-size: 12.5px; text-transform: uppercase;
  letter-spacing: .04em; color: var(--dash-ink-muted); font-weight: 600;
}
.dash-card .card-sub {
  font-size: 12px; color: var(--dash-ink-muted); margin-bottom: 8px;
}

/* Volume area chart */
.dash-vol svg { display: block; width: 100%; height: 200px; }
.dash-vol .grid { stroke: var(--dash-border); stroke-width: 1; opacity: .6; }
.dash-vol .axis { fill: var(--dash-ink-muted); font-size: 10px; }
.dash-vol .area { fill: var(--dash-brand); fill-opacity: .12; }
.dash-vol .line { stroke: var(--dash-brand); stroke-width: 2; fill: none; stroke-linejoin: round; }
.dash-vol .dot  { fill: var(--dash-brand); transition: r .1s; cursor: default; }
.dash-vol .dot:hover { r: 6; }

/* Horizontal bars — agent workload */
.dash-bars { display: grid; gap: 8px; }
.dash-bar-row {
  display: grid; grid-template-columns: 140px 1fr 40px;
  align-items: center; gap: 10px; font-size: 13px;
}
.dash-bar-row .name {
  color: var(--dash-ink); overflow: hidden;
  white-space: nowrap; text-overflow: ellipsis;
}
.dash-bar-row .bar-track {
  background: var(--dash-surface-2); height: 10px; border-radius: 6px;
  overflow: hidden; border: 1px solid var(--dash-border);
}
.dash-bar-row .bar-fill {
  height: 100%; background: var(--dash-brand); border-radius: 4px 0 0 4px;
}
.dash-bar-row .bar-fill.zero { background: var(--dash-ink-muted); opacity: .35; }
.dash-bar-row .n {
  text-align: right; color: var(--dash-ink); font-weight: 600;
}

/* Stacked channel bar */
.dash-stack-bar {
  height: 24px; border-radius: 6px; overflow: hidden;
  display: flex; border: 1px solid var(--dash-border);
  background: var(--dash-surface-2);
}
.dash-stack-bar > span {
  display: block; height: 100%;
  border-right: 2px solid var(--dash-surface);
}
.dash-stack-bar > span:last-child { border-right: none; }
.dash-stack-legend {
  display: grid; gap: 6px; margin-top: 10px;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  font-size: 12.5px; color: var(--dash-ink);
}
.dash-stack-legend .sw {
  display: inline-block; width: 10px; height: 10px;
  border-radius: 3px; margin-right: 6px; vertical-align: middle;
}
.dash-stack-legend .n { color: var(--dash-ink-muted); }

/* Automation row cards */
.dash-auto-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 12px; background: var(--dash-surface-2);
  border-radius: 8px; text-decoration: none; color: var(--dash-ink);
}
.dash-auto-row:hover { background: var(--dash-border); }
.dash-auto-row .title { font-weight: 600; }
.dash-auto-row .sub   { font-size: 12px; color: var(--dash-ink-muted); }
.dash-auto-row .ico   { font-size: 22px; }

/* Alerts */
.dash-alerts { display: grid; gap: 8px; }
.dash-alert {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 8px;
  color: var(--dash-ink); text-decoration: none;
  border: 1px solid transparent; transition: transform .1s;
}
.dash-alert:hover { transform: translateX(2px); }
.dash-alert.warning {
  background: #FEF3C7; border-color: #FCD34D; color: #78350F;
}
.dash-alert.serious {
  background: #FEE2E2; border-color: #FCA5A5; color: #7F1D1D;
}
@media (prefers-color-scheme: dark) {
  .dash-alert.warning { background: rgba(245,158,11,.12); color: #FDE68A; border-color: rgba(245,158,11,.35); }
  .dash-alert.serious { background: rgba(220,38,38,.15); color: #FCA5A5; border-color: rgba(220,38,38,.4); }
}

/* Recent activity */
.dash-recent table { width: 100%; border-collapse: collapse; }
.dash-recent th, .dash-recent td {
  padding: 8px 6px; border-bottom: 1px solid var(--dash-border);
  text-align: left; font-size: 13px;
}
.dash-recent th {
  color: var(--dash-ink-muted); font-weight: 500;
  font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em;
}
.dash-recent tr:hover td { background: var(--dash-surface-2); }
.dash-recent a { color: var(--dash-ink); text-decoration: none; }
.dash-recent a:hover { text-decoration: underline; }

.dash-empty {
  color: var(--dash-ink-muted); font-style: italic;
  padding: 20px 0; text-align: center;
}
</style>

<div class="dash-wrap">

  <!-- ============ Alerts (only when actionable) ============ -->
  <?php if ($alerts): ?>
    <div class="dash-alerts">
      <?php foreach ($alerts as $a): ?>
        <a class="dash-alert <?= e($a['level']) ?>" href="<?= e($a['href']) ?>">
          <span style="font-size:18px;" aria-hidden="true">
            <?= $a['level'] === 'serious' ? '⛔' : '⚠️' ?>
          </span>
          <span style="flex:1;"><?= e($a['msg']) ?></span>
          <span aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ============ KPI tiles ============ -->
  <div class="dash-kpi-row">
    <a class="dash-kpi" href="/inbox/index.php?filter=all">
      <div class="label">Open conversations</div>
      <div class="value"><?= (int)($stats['open_total'] ?? 0) ?></div>
      <div class="sub"><?= (int)($stats['closed_today'] ?? 0) ?> closed today</div>
    </a>
    <a class="dash-kpi" href="/inbox/index.php?filter=unassigned">
      <div class="label">Unassigned</div>
      <div class="value <?= (int)($stats['unassigned'] ?? 0) >= 10 ? 'warn' : '' ?>">
        <?= (int)($stats['unassigned'] ?? 0) ?>
      </div>
      <div class="sub">Assign to route to an agent</div>
    </a>
    <a class="dash-kpi" href="/inbox/index.php?filter=mine">
      <div class="label">Assigned to me</div>
      <div class="value"><?= (int)($stats['mine'] ?? 0) ?></div>
      <div class="sub">Your open queue</div>
    </a>
    <div class="dash-kpi" style="cursor:default;">
      <div class="label">Avg first response · today</div>
      <div class="value <?= $avgRespSecs > 3600 ? 'warn' : ($avgRespSecs > 0 ? 'good' : '') ?>">
        <?= e($fmtDur($avgRespSecs)) ?>
      </div>
      <div class="sub">Customer wait until first reply</div>
    </div>
    <div class="dash-kpi" style="cursor:default;">
      <div class="label">Messages today</div>
      <div class="value"><?= (int)$msgsToday ?></div>
      <div class="sub">All inbound + outbound</div>
    </div>
  </div>

  <!-- ============ Quick actions ============ -->
  <div class="dash-actions">
    <a class="dash-action" href="/inbox/new_chat.php">
      <span class="ico" aria-hidden="true">💬</span><span>Start a new chat</span>
    </a>
    <a class="dash-action" href="/admin/broadcasts.php">
      <span class="ico" aria-hidden="true">📣</span><span>New broadcast</span>
    </a>
    <a class="dash-action" href="/contact_import.php">
      <span class="ico" aria-hidden="true">📄</span><span>Import contacts</span>
    </a>
    <a class="dash-action" href="/admin/flows.php">
      <span class="ico" aria-hidden="true">🔀</span><span>Message flows</span>
    </a>
  </div>

  <!-- ============ Volume chart + Agent workload ============ -->
  <div class="dash-grid">

    <div class="dash-card dash-vol">
      <h3>Message volume · last 14 days</h3>
      <div class="card-sub">Peak day: <strong><?= (int)$maxVol ?></strong> messages</div>
      <?php
        $chartW = 600; $chartH = 180;
        $padL   = 30;  $padR   = 12; $padT = 12; $padB = 22;
        $plotW  = $chartW - $padL - $padR;
        $plotH  = $chartH - $padT - $padB;
        $count  = count($volumeSeries);
        $stepX  = $plotW / max($count - 1, 1);
        $pts    = [];
        foreach ($volumeSeries as $i => $p) {
            $x = $padL + $i * $stepX;
            $y = $padT + $plotH - ($p['n'] / max($maxVol, 1)) * $plotH;
            $pts[] = [$x, $y];
        }
        $line = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts));
        $area = $line . ' ' . end($pts)[0] . ',' . ($padT + $plotH)
              . ' ' . $padL . ',' . ($padT + $plotH);
      ?>
      <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" preserveAspectRatio="none"
           role="img" aria-label="Daily message volume, last 14 days">
        <?php for ($g = 0; $g <= 2; $g++):
          $gy = $padT + $plotH - ($g / 2) * $plotH; ?>
          <line class="grid" x1="<?= $padL ?>" x2="<?= $chartW - $padR ?>"
                y1="<?= $gy ?>" y2="<?= $gy ?>"/>
          <text class="axis" x="<?= $padL - 6 ?>" y="<?= $gy + 4 ?>" text-anchor="end">
            <?= (int)round(($g / 2) * $maxVol) ?>
          </text>
        <?php endfor; ?>
        <polygon class="area" points="<?= e($area) ?>"/>
        <polyline class="line" points="<?= e($line) ?>"/>
        <?php foreach ($volumeSeries as $i => $p):
          [$x, $y] = $pts[$i]; ?>
          <circle class="dot" cx="<?= $x ?>" cy="<?= $y ?>" r="3">
            <title><?= e(date('D M j', strtotime($p['date']))) ?>: <?= (int)$p['n'] ?> messages</title>
          </circle>
        <?php endforeach; ?>
        <?php foreach ($volumeSeries as $i => $p):
          if ($i % 2 !== 0 && $i !== $count - 1) continue;
          [$x] = $pts[$i]; ?>
          <text class="axis" x="<?= $x ?>" y="<?= $chartH - 6 ?>" text-anchor="middle">
            <?= e(date('j/n', strtotime($p['date']))) ?>
          </text>
        <?php endforeach; ?>
      </svg>
    </div>

    <div class="dash-card">
      <h3>Agent workload · open</h3>
      <?php if (!$workload): ?>
        <div class="dash-empty">No active agents yet.</div>
      <?php else: ?>
        <div class="dash-bars">
          <?php foreach ($workload as $w):
            $n   = (int)$w['n'];
            $pct = $maxWorkload > 0 ? ($n / $maxWorkload) * 100 : 0; ?>
            <div class="dash-bar-row">
              <div class="name" title="<?= e($w['name']) ?>"><?= e($w['name']) ?></div>
              <div class="bar-track">
                <div class="bar-fill <?= $n === 0 ? 'zero' : '' ?>"
                     style="width: <?= $n === 0 ? 100 : max(2, (float)$pct) ?>%;"></div>
              </div>
              <div class="n"><?= $n ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Channels + Automation ============ -->
  <div class="dash-grid">

    <div class="dash-card">
      <h3>Open conversations by channel</h3>
      <?php if ($channelTotal === 0): ?>
        <div class="dash-empty">No open conversations right now.</div>
      <?php else: ?>
        <div class="dash-stack-bar" role="img"
             aria-label="Channel breakdown of open conversations">
          <?php foreach ($byChannel as $i => $c):
            $n   = (int)$c['n'];
            $pct = ($n / max($channelTotal, 1)) * 100;
            $col = $palette[$i % count($palette)]; ?>
            <span style="width: <?= (float)$pct ?>%; background: <?= e($col) ?>;"
                  title="<?= e($c['name']) ?>: <?= $n ?> (<?= round($pct) ?>%)"></span>
          <?php endforeach; ?>
        </div>
        <div class="dash-stack-legend">
          <?php foreach ($byChannel as $i => $c):
            $col = $palette[$i % count($palette)]; ?>
            <div>
              <span class="sw" style="background: <?= e($col) ?>;"></span>
              <?= e($c['name']) ?>
              <span class="n"> · <?= (int)$c['n'] ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="dash-card">
      <h3>Automation</h3>
      <div style="display:grid; gap: 10px;">
        <a class="dash-auto-row" href="/admin/flows.php">
          <div>
            <div class="title">Message flows</div>
            <div class="sub">
              <?= $flowsRunning ?> running · <?= $flowsWaiting ?> waiting
              <?php if ($flowsFailed): ?>· <span style="color: var(--dash-serious);"><?= $flowsFailed ?> failed</span><?php endif; ?>
            </div>
          </div>
          <div class="ico" aria-hidden="true">🔀</div>
        </a>
        <a class="dash-auto-row" href="/admin/broadcasts.php">
          <div>
            <div class="title">Broadcasts</div>
            <div class="sub"><?= $broadcastsRunning ?> running</div>
          </div>
          <div class="ico" aria-hidden="true">📣</div>
        </a>
        <a class="dash-auto-row" href="/admin/webhook_log.php">
          <div>
            <div class="title">Webhook log</div>
            <div class="sub">
              <?= $failedSends24h ?> failed send(s) · last 24h
            </div>
          </div>
          <div class="ico" aria-hidden="true">📡</div>
        </a>
      </div>
    </div>
  </div>

  <!-- ============ Recent activity ============ -->
  <div class="dash-card dash-recent">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
      <h3 style="margin:0;">Latest activity</h3>
      <a class="btn btn-sm" href="/inbox/index.php">Open inbox →</a>
    </div>
    <?php if (!$recent): ?>
      <div class="dash-empty">No conversations yet. Inbound messages will appear here.</div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Customer</th>
            <th>Channel</th>
            <th>Last message</th>
            <th>Status</th>
            <th>Assigned</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td>
                <a href="/inbox/chat.php?id=<?= (int)$r['id'] ?>" style="font-weight:500;">
                  <?= e($r['display_name'] ?: $r['wa_id']) ?>
                </a>
              </td>
              <td style="color: var(--dash-ink-muted);"><?= e($r['channel_name'] ?: '—') ?></td>
              <td><?= e(mb_strimwidth((string)$r['last_message_text'], 0, 60, '…')) ?></td>
              <td><?= status_badge($r['status']) ?></td>
              <td style="color: var(--dash-ink-muted);"><?= e($r['agent_name'] ?: 'Unassigned') ?></td>
              <td style="color: var(--dash-ink-muted);"><?= e(relative_time($r['last_message_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<?php layout_end(); ?>
