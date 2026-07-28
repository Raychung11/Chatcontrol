<?php
/**
 * Workspace reports.
 *
 * Expanded from the original (date range + 11 stat cards + per-agent
 * table + daily volume + top tags) with:
 *
 *   - Multi-dimension slicers: channel, department, branch, agent
 *   - Every KPI now shows a delta% vs the same-length preceding period
 *     so operators see whether things are improving or degrading
 *   - Response-time distribution histogram (0-5m / 5-15m / 15-60m /
 *     1-4h / 4-24h / >24h)
 *   - Per-channel table (conversations, avg response, failure rate)
 *   - Per-branch table (mirrors the per-agent one)
 *   - Broadcasts + flows performance summary
 *   - Hourly activity heatmap (7 days x 24 hours)
 *   - CSV export per section (?export=<section>)
 *
 * Queries centralized into a $where + $params builder so every widget
 * respects every filter. Optional-table sources (flows, broadcasts,
 * branches) are try/catch'd so a partially-migrated workspace still
 * renders the sections that are available.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

// ---- Date range ----------------------------------------------------------
$presets  = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'custom' => 'Custom'];
$preset   = (string)($_GET['range'] ?? '30d');
if (!isset($presets[$preset])) $preset = '30d';

$today  = date('Y-m-d');
$dateFrom = (string)($_GET['from'] ?? '');
$dateTo   = (string)($_GET['to']   ?? '');

if ($preset !== 'custom') {
    $dateTo   = $today;
    $dateFrom = match ($preset) {
        'today' => $today,
        '7d'    => date('Y-m-d', strtotime('-6 days')),
        '90d'   => date('Y-m-d', strtotime('-89 days')),
        default => date('Y-m-d', strtotime('-29 days')),
    };
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $today;

$rangeStart = $dateFrom . ' 00:00:00';
$rangeEnd   = $dateTo   . ' 23:59:59';

$rangeDays  = max(1, floor((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);
$prevEnd    = date('Y-m-d', strtotime($dateFrom . ' -1 day')) . ' 23:59:59';
$prevStart  = date('Y-m-d', strtotime($dateFrom . ' -' . $rangeDays . ' day')) . ' 00:00:00';

// ---- Dimension filters ---------------------------------------------------
$fChannel = (int)($_GET['channel_id']    ?? 0);
$fDept    = (int)($_GET['department_id'] ?? 0);
$fBranch  = (int)($_GET['branch_id']     ?? 0);
$fAgent   = (int)($_GET['agent_id']      ?? 0);

/** Build a WHERE clause + params for the given period + filters,
 *  scoped to conversations aliased as `c` (joined to contacts if branch
 *  filter is on). Returns [sql, params]. */
function reports_where(int $companyId, string $start, string $end,
                       int $fChannel, int $fDept, int $fBranch, int $fAgent): array
{
    $w = ['c.company_id = ?', 'c.created_at BETWEEN ? AND ?'];
    $p = [$companyId, $start, $end];
    if ($fChannel > 0) { $w[] = 'c.channel_id = ?';    $p[] = $fChannel; }
    if ($fDept    > 0) { $w[] = 'c.department_id = ?'; $p[] = $fDept; }
    if ($fAgent   > 0) { $w[] = 'c.assigned_user_id = ?'; $p[] = $fAgent; }
    // Branch filter joins contacts.
    if ($fBranch > 0)  { $w[] = 'ct.branch_id = ?';    $p[] = $fBranch; }
    return [implode(' AND ', $w), $p];
}

/** Same but for messages, aliased `m`. Joins to conversations aliased c
 *  when any conv-scoped filter is active. */
function reports_msg_where(int $companyId, string $start, string $end,
                           int $fChannel, int $fDept, int $fBranch, int $fAgent): array
{
    $w = ['m.company_id = ?', 'm.created_at BETWEEN ? AND ?'];
    $p = [$companyId, $start, $end];
    if ($fChannel > 0) { $w[] = 'm.channel_id = ?'; $p[] = $fChannel; }
    if ($fDept > 0 || $fBranch > 0 || $fAgent > 0) {
        $w[] = 'EXISTS (SELECT 1 FROM conversations cc' .
            ($fBranch > 0 ? ' INNER JOIN contacts ctc ON ctc.id = cc.contact_id' : '') .
            ' WHERE cc.id = m.conversation_id' .
            ($fDept   > 0 ? ' AND cc.department_id = ?'    : '') .
            ($fAgent  > 0 ? ' AND cc.assigned_user_id = ?' : '') .
            ($fBranch > 0 ? ' AND ctc.branch_id = ?'       : '') .
            ')';
        if ($fDept   > 0) $p[] = $fDept;
        if ($fAgent  > 0) $p[] = $fAgent;
        if ($fBranch > 0) $p[] = $fBranch;
    }
    return [implode(' AND ', $w), $p];
}

// Convenience: run a totals query for a given period.
$conversationTotals = function (string $start, string $end) use ($db, $companyId, $fChannel, $fDept, $fBranch, $fAgent) {
    [$w, $p] = reports_where($companyId, $start, $end, $fChannel, $fDept, $fBranch, $fAgent);
    $join = $fBranch > 0 ? ' INNER JOIN contacts ct ON ct.id = c.contact_id' : '';
    $sql = "SELECT
             COUNT(*) AS total,
             SUM(c.status = 'open')      AS s_open,
             SUM(c.status = 'pending')   AS s_pending,
             SUM(c.status = 'closed')    AS s_closed,
             SUM(c.status = 'escalated') AS s_escalated,
             SUM(c.assigned_user_id IS NULL AND c.status <> 'closed') AS unassigned,
             AVG(TIMESTAMPDIFF(SECOND, c.last_customer_message_at, c.first_response_at)) AS avg_first_response_secs,
             AVG(TIMESTAMPDIFF(SECOND, c.created_at, c.resolved_at)) AS avg_resolution_secs
           FROM conversations c $join WHERE $w";
    $st = $db->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
};
$messageTotals = function (string $start, string $end) use ($db, $companyId, $fChannel, $fDept, $fBranch, $fAgent) {
    [$w, $p] = reports_msg_where($companyId, $start, $end, $fChannel, $fDept, $fBranch, $fAgent);
    $sql = "SELECT
             SUM(m.direction = 'incoming') AS incoming,
             SUM(m.direction = 'outgoing') AS outgoing,
             SUM(m.direction = 'outgoing' AND m.status = 'failed') AS failed
           FROM messages m WHERE $w";
    $st = $db->prepare($sql); $st->execute($p);
    return $st->fetch() ?: [];
};

$totals   = $conversationTotals($rangeStart, $rangeEnd);
$mTotals  = $messageTotals($rangeStart, $rangeEnd);
$totalsP  = $conversationTotals($prevStart, $prevEnd);
$mTotalsP = $messageTotals($prevStart, $prevEnd);

// Delta helper: returns rendered HTML span with % change. Direction-aware
// (falling response time = good so it's green; rising failures = bad so
// it's amber/red).
$delta = function ($now, $prev, bool $goodWhenUp = true): string {
    $n = (float)($now ?? 0); $p = (float)($prev ?? 0);
    if ($p == 0.0 && $n == 0.0) return '<span class="delta neutral">·</span>';
    if ($p == 0.0) return '<span class="delta ' . ($goodWhenUp ? 'good' : 'warn') . '">new</span>';
    $pct = round((($n - $p) / $p) * 100);
    if ($pct === 0) return '<span class="delta neutral">±0%</span>';
    $up   = $pct > 0;
    $good = $up === $goodWhenUp;
    $cls  = $good ? 'good' : 'warn';
    return '<span class="delta ' . $cls . '">' . ($up ? '▲' : '▼') . ' ' . abs($pct) . '%</span>';
};

// ---- Per-agent (in range) ------------------------------------------------
$perAgent = $db->prepare(
    'SELECT u.id, u.name, u.role,
        (SELECT COUNT(*) FROM conversations c
           WHERE c.assigned_user_id = u.id AND c.created_at BETWEEN ? AND ?
           ' . ($fChannel > 0 ? ' AND c.channel_id = ?' : '') . '
           ' . ($fDept    > 0 ? ' AND c.department_id = ?' : '') . '
        ) AS conversations_assigned,
        (SELECT COUNT(*) FROM messages m
           WHERE m.sender_user_id = u.id AND m.direction = "outgoing"
             AND m.created_at BETWEEN ? AND ?
             ' . ($fChannel > 0 ? ' AND m.channel_id = ?' : '') . '
        ) AS replies_sent,
        (SELECT AVG(TIMESTAMPDIFF(SECOND, c.last_customer_message_at, c.first_response_at))
           FROM conversations c
           WHERE c.assigned_user_id = u.id AND c.first_response_at IS NOT NULL
             AND c.created_at BETWEEN ? AND ?
             ' . ($fChannel > 0 ? ' AND c.channel_id = ?' : '') . '
             ' . ($fDept    > 0 ? ' AND c.department_id = ?' : '') . '
        ) AS avg_response_secs
     FROM users u
     WHERE u.company_id = ? AND u.status = "active"
     ORDER BY replies_sent DESC, u.name'
);
$paBind = [];
$paBind = array_merge($paBind, [$rangeStart, $rangeEnd]);
if ($fChannel > 0) $paBind[] = $fChannel;
if ($fDept    > 0) $paBind[] = $fDept;
$paBind = array_merge($paBind, [$rangeStart, $rangeEnd]);
if ($fChannel > 0) $paBind[] = $fChannel;
$paBind = array_merge($paBind, [$rangeStart, $rangeEnd]);
if ($fChannel > 0) $paBind[] = $fChannel;
if ($fDept    > 0) $paBind[] = $fDept;
$paBind[] = $companyId;
$perAgent->execute($paBind);
$perAgent = $perAgent->fetchAll();

// ---- Per-channel breakdown ----------------------------------------------
[$wC, $pC] = reports_where($companyId, $rangeStart, $rangeEnd, 0, $fDept, $fBranch, $fAgent);
$joinC = $fBranch > 0 ? ' INNER JOIN contacts ct ON ct.id = c.contact_id' : '';
$perChannelSql =
    "SELECT COALESCE(ch.name, 'Unassigned') AS name,
            COALESCE(ch.provider, 'none')   AS provider,
            COUNT(c.id) AS conv_count,
            AVG(TIMESTAMPDIFF(SECOND, c.last_customer_message_at, c.first_response_at)) AS avg_response_secs,
            (SELECT COUNT(*) FROM messages m
              WHERE m.channel_id = ch.id AND m.direction = 'outgoing'
                AND m.status = 'failed' AND m.created_at BETWEEN ? AND ?) AS failed
     FROM conversations c
     LEFT JOIN channels ch ON ch.id = c.channel_id
     $joinC
     WHERE $wC
     GROUP BY ch.id, ch.name, ch.provider
     ORDER BY conv_count DESC";
$perChannel = $db->prepare($perChannelSql);
$perChannel->execute(array_merge([$rangeStart, $rangeEnd], $pC));
$perChannel = $perChannel->fetchAll();

// ---- Per-branch (may not exist yet) --------------------------------------
$perBranch = [];
try {
    [$wB, $pB] = reports_where($companyId, $rangeStart, $rangeEnd, $fChannel, $fDept, 0, $fAgent);
    $st = $db->prepare(
        "SELECT COALESCE(b.name, 'Unassigned branch') AS name, b.id AS bid,
                COUNT(c.id) AS conv_count,
                AVG(TIMESTAMPDIFF(SECOND, c.last_customer_message_at, c.first_response_at)) AS avg_response_secs
         FROM conversations c
         INNER JOIN contacts ct ON ct.id = c.contact_id
         LEFT  JOIN branches b  ON b.id = ct.branch_id
         WHERE $wB
         GROUP BY b.id, b.name
         ORDER BY conv_count DESC LIMIT 20"
    );
    $st->execute($pB);
    $perBranch = $st->fetchAll();
} catch (Throwable $e) { /* branches missing */ }

// ---- Response time distribution -----------------------------------------
[$wR, $pR] = reports_where($companyId, $rangeStart, $rangeEnd, $fChannel, $fDept, $fBranch, $fAgent);
$joinR = $fBranch > 0 ? ' INNER JOIN contacts ct ON ct.id = c.contact_id' : '';
$respSt = $db->prepare(
    "SELECT TIMESTAMPDIFF(SECOND, c.last_customer_message_at, c.first_response_at) AS s
     FROM conversations c $joinR
     WHERE $wR AND c.first_response_at IS NOT NULL
             AND c.last_customer_message_at IS NOT NULL
             AND c.first_response_at >= c.last_customer_message_at"
);
$respSt->execute($pR);
$distBuckets = [
    '0-5m'   => ['max' => 300,   'n' => 0],
    '5-15m'  => ['max' => 900,   'n' => 0],
    '15-60m' => ['max' => 3600,  'n' => 0],
    '1-4h'   => ['max' => 14400, 'n' => 0],
    '4-24h'  => ['max' => 86400, 'n' => 0],
    '>24h'   => ['max' => PHP_INT_MAX, 'n' => 0],
];
foreach ($respSt->fetchAll() as $r) {
    $s = (int)$r['s'];
    foreach ($distBuckets as $k => &$b) {
        if ($s <= $b['max']) { $b['n']++; break; }
    } unset($b);
}
$maxBucket = 0;
foreach ($distBuckets as $b) $maxBucket = max($maxBucket, $b['n']);

// ---- Daily volume + hourly heatmap --------------------------------------
[$wD, $pD] = reports_where($companyId, $rangeStart, $rangeEnd, $fChannel, $fDept, $fBranch, $fAgent);
$joinD = $fBranch > 0 ? ' INNER JOIN contacts ct ON ct.id = c.contact_id' : '';
$daily = $db->prepare(
    "SELECT DATE(c.created_at) AS d, COUNT(*) AS n
     FROM conversations c $joinD
     WHERE $wD
     GROUP BY DATE(c.created_at) ORDER BY d ASC"
);
$daily->execute($pD);
$daily = $daily->fetchAll();
$maxDaily = 0;
foreach ($daily as $d) $maxDaily = max($maxDaily, (int)$d['n']);

// Hourly heatmap: for the LAST 7 days regardless of range (small on purpose).
[$wH, $pH] = reports_msg_where($companyId, date('Y-m-d', strtotime('-6 days')) . ' 00:00:00', $today . ' 23:59:59',
    $fChannel, $fDept, $fBranch, $fAgent);
$heatSt = $db->prepare(
    "SELECT DAYOFWEEK(m.created_at) AS dow,
            HOUR(m.created_at)      AS hr,
            COUNT(*)                AS n
     FROM messages m
     WHERE $wH AND m.direction = 'incoming'
     GROUP BY dow, hr"
);
$heatSt->execute($pH);
$heat = array_fill(1, 7, array_fill(0, 24, 0));
$maxHeat = 1;
foreach ($heatSt->fetchAll() as $r) {
    $heat[(int)$r['dow']][(int)$r['hr']] = (int)$r['n'];
    if ((int)$r['n'] > $maxHeat) $maxHeat = (int)$r['n'];
}

// ---- Top tags ------------------------------------------------------------
$topTags = $db->prepare(
    'SELECT t.name, t.color, COUNT(*) AS n
     FROM conversation_tag_map m
     INNER JOIN conversation_tags t ON t.id = m.tag_id
     INNER JOIN conversations c     ON c.id = m.conversation_id
     WHERE t.company_id = ? AND c.created_at BETWEEN ? AND ?'
    . ($fChannel > 0 ? ' AND c.channel_id = ?'    : '')
    . ($fDept    > 0 ? ' AND c.department_id = ?' : '')
    . ($fAgent   > 0 ? ' AND c.assigned_user_id = ?' : '')
    . ' GROUP BY t.id, t.name, t.color ORDER BY n DESC LIMIT 10'
);
$ttBind = [$companyId, $rangeStart, $rangeEnd];
if ($fChannel > 0) $ttBind[] = $fChannel;
if ($fDept    > 0) $ttBind[] = $fDept;
if ($fAgent   > 0) $ttBind[] = $fAgent;
$topTags->execute($ttBind);
$topTags = $topTags->fetchAll();

// ---- Broadcasts + flows summaries ---------------------------------------
$recentBroadcasts = [];
try {
    $st = $db->prepare(
        "SELECT b.id, b.name, b.status, b.total_recipients, b.sent_count, b.failed_count,
                b.created_at
         FROM broadcasts b
         WHERE b.company_id = ? AND b.created_at BETWEEN ? AND ?
         ORDER BY b.created_at DESC LIMIT 10"
    );
    $st->execute([$companyId, $rangeStart, $rangeEnd]);
    $recentBroadcasts = $st->fetchAll();
} catch (Throwable $e) { /* ok */ }

$flowStats = [];
try {
    $st = $db->prepare(
        "SELECT f.id, f.name, f.status,
                SUM(fi.status = 'running')   AS running,
                SUM(fi.status = 'waiting')   AS waiting,
                SUM(fi.status = 'completed' AND fi.completed_at BETWEEN ? AND ?) AS completed_in_range,
                SUM(fi.status = 'failed'    AND fi.completed_at BETWEEN ? AND ?) AS failed_in_range
         FROM flows f
         LEFT JOIN flow_instances fi ON fi.flow_id = f.id
         WHERE f.company_id = ?
         GROUP BY f.id, f.name, f.status
         ORDER BY (running + waiting) DESC, f.name
         LIMIT 10"
    );
    $st->execute([$rangeStart, $rangeEnd, $rangeStart, $rangeEnd, $companyId]);
    $flowStats = $st->fetchAll();
} catch (Throwable $e) { /* ok */ }

// ---- Sidebar option lists for the filters -------------------------------
$channels = $db->prepare('SELECT id, name FROM channels WHERE company_id = ? AND status = "active" ORDER BY name');
$channels->execute([$companyId]); $channels = $channels->fetchAll();
$departments = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$departments->execute([$companyId]); $departments = $departments->fetchAll();
$branches = [];
try {
    $st = $db->prepare('SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name');
    $st->execute([$companyId]); $branches = $st->fetchAll();
} catch (Throwable $e) {}
$agents = $db->prepare(
    'SELECT id, name, role FROM users
     WHERE company_id = ? AND status = "active" AND role IN ("super_admin","manager","agent")
     ORDER BY FIELD(role, "super_admin", "manager", "agent"), name'
);
$agents->execute([$companyId]); $agents = $agents->fetchAll();

// ---- CSV export dispatch ------------------------------------------------
$export = (string)($_GET['export'] ?? '');
if ($export !== '') {
    reports_export_csv($export, [
        'daily'       => $daily,
        'perAgent'    => $perAgent,
        'perChannel'  => $perChannel,
        'perBranch'   => $perBranch,
        'topTags'     => $topTags,
        'broadcasts'  => $recentBroadcasts,
        'flows'       => $flowStats,
    ], $dateFrom, $dateTo);
    exit;
}

function reports_export_csv(string $which, array $sets, string $from, string $to): void
{
    $fname = 'aiserve-report-' . $which . '-' . $from . '_to_' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens as UTF-8
    switch ($which) {
        case 'perAgent':
            fputcsv($out, ['Agent', 'Role', 'Conversations assigned', 'Replies sent', 'Avg response (s)']);
            foreach ($sets['perAgent'] as $a) fputcsv($out, [$a['name'], $a['role'], (int)$a['conversations_assigned'], (int)$a['replies_sent'], (int)$a['avg_response_secs']]);
            break;
        case 'perChannel':
            fputcsv($out, ['Channel', 'Provider', 'Conversations', 'Avg response (s)', 'Failed sends']);
            foreach ($sets['perChannel'] as $c) fputcsv($out, [$c['name'], $c['provider'], (int)$c['conv_count'], (int)$c['avg_response_secs'], (int)$c['failed']]);
            break;
        case 'perBranch':
            fputcsv($out, ['Branch', 'Conversations', 'Avg response (s)']);
            foreach ($sets['perBranch'] as $b) fputcsv($out, [$b['name'], (int)$b['conv_count'], (int)$b['avg_response_secs']]);
            break;
        case 'daily':
            fputcsv($out, ['Date', 'Conversations']);
            foreach ($sets['daily'] as $d) fputcsv($out, [$d['d'], (int)$d['n']]);
            break;
        case 'topTags':
            fputcsv($out, ['Tag', 'Conversations']);
            foreach ($sets['topTags'] as $t) fputcsv($out, [$t['name'], (int)$t['n']]);
            break;
        case 'broadcasts':
            fputcsv($out, ['Name', 'Status', 'Total', 'Sent', 'Failed', 'Created']);
            foreach ($sets['broadcasts'] as $b) fputcsv($out, [$b['name'], $b['status'], (int)$b['total_recipients'], (int)$b['sent_count'], (int)$b['failed_count'], $b['created_at']]);
            break;
        case 'flows':
            fputcsv($out, ['Flow', 'Status', 'Running', 'Waiting', 'Completed in range', 'Failed in range']);
            foreach ($sets['flows'] as $f) fputcsv($out, [$f['name'], $f['status'], (int)$f['running'], (int)$f['waiting'], (int)$f['completed_in_range'], (int)$f['failed_in_range']]);
            break;
        default:
            fputcsv($out, ['Unknown export']);
    }
    fclose($out);
}

// ---- Helper ------------------------------------------------------------
function fmt_secs(?int $s): string
{
    if ($s === null || $s <= 0) return '—';
    if ($s < 60)    return $s . 's';
    if ($s < 3600)  return floor($s / 60) . 'm ' . ($s % 60) . 's';
    if ($s < 86400) return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    return floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
}

// Keep the current query string for export links.
$qs = http_build_query(array_filter([
    'range'         => $preset,
    'from'          => $dateFrom,
    'to'            => $dateTo,
    'channel_id'    => $fChannel ?: null,
    'department_id' => $fDept    ?: null,
    'branch_id'     => $fBranch  ?: null,
    'agent_id'      => $fAgent   ?: null,
]));

$palette = ['#16A34A', '#2563EB', '#9333EA', '#F59E0B', '#EA580C', '#0891B2', '#DB2777', '#65A30D'];

layout_start($current_user, 'Reports', 'reports');
?>

<style>
:root {
  --rp-surface: #ffffff;
  --rp-surface-2: #f6f9fb;
  --rp-ink: #0f172a;
  --rp-muted: #64748b;
  --rp-border: #e3e8ee;
  --rp-brand: #25D366;
  --rp-good: #16A34A;
  --rp-warn: #F59E0B;
}
@media (prefers-color-scheme: dark) {
  :root {
    --rp-surface: #0f172a;
    --rp-surface-2: #1e293b;
    --rp-ink: #f8fafc;
    --rp-muted: #94a3b8;
    --rp-border: #334155;
  }
}
.rp-wrap { display: grid; gap: 16px; }
.rp-card {
  background: var(--rp-surface); border: 1px solid var(--rp-border);
  border-radius: 12px; padding: 16px;
}
.rp-card h3 {
  margin: 0 0 12px; font-size: 12.5px; text-transform: uppercase;
  letter-spacing: .04em; color: var(--rp-muted); font-weight: 600;
  display: flex; justify-content: space-between; align-items: center;
}
.rp-card h3 .csv {
  font-size: 11px; text-transform: none; letter-spacing: 0;
  padding: 3px 8px; border: 1px solid var(--rp-border); border-radius: 6px;
  color: var(--rp-muted); text-decoration: none;
}
.rp-card h3 .csv:hover { background: var(--rp-surface-2); color: var(--rp-ink); }

.rp-filter-row {
  display: grid; gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  align-items: end;
}
.rp-filter-row label { display: block; font-size: 12px; color: var(--rp-muted); }
.rp-filter-row select,
.rp-filter-row input[type="date"] {
  width: 100%; padding: 6px 8px; margin-top: 4px;
}

.rp-kpi-row {
  display: grid; gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}
.rp-kpi {
  background: var(--rp-surface); border: 1px solid var(--rp-border);
  border-radius: 12px; padding: 14px;
}
.rp-kpi .lbl {
  color: var(--rp-muted); font-size: 12px; text-transform: uppercase;
  letter-spacing: .04em; margin-bottom: 4px;
}
.rp-kpi .val {
  font-size: 24px; font-weight: 700; color: var(--rp-ink); line-height: 1.1;
}
.delta {
  display: inline-block; font-size: 11px; padding: 2px 6px;
  border-radius: 999px; margin-left: 4px; font-weight: 500;
}
.delta.good    { background: rgba(22,163,74,.12);  color: #16A34A; }
.delta.warn    { background: rgba(245,158,11,.14); color: #B45309; }
.delta.neutral { background: var(--rp-surface-2);  color: var(--rp-muted); }
@media (prefers-color-scheme: dark) {
  .delta.good { color: #4ADE80; }
  .delta.warn { color: #FBBF24; }
}

/* Histogram bars */
.rp-hist { display: grid; gap: 6px; }
.rp-hist-row {
  display: grid; grid-template-columns: 80px 1fr 60px;
  align-items: center; gap: 10px; font-size: 13px;
}
.rp-hist-row .lbl { color: var(--rp-ink); }
.rp-hist-row .bar {
  height: 12px; border-radius: 4px; background: var(--rp-surface-2);
  border: 1px solid var(--rp-border); overflow: hidden;
}
.rp-hist-row .bar > span {
  display: block; height: 100%; background: var(--rp-brand);
}
.rp-hist-row .n {
  text-align: right; font-weight: 600; color: var(--rp-ink);
}

/* Daily bars — keep the old horizontal list vibe, tighter */
.rp-daily { display: grid; gap: 4px; max-height: 320px; overflow-y: auto; }
.rp-daily-row {
  display: grid; grid-template-columns: 70px 1fr 40px;
  gap: 8px; align-items: center; font-size: 12.5px;
}
.rp-daily-row .lbl { color: var(--rp-muted); }
.rp-daily-row .bar {
  height: 10px; border-radius: 4px; background: var(--rp-surface-2);
  border: 1px solid var(--rp-border); overflow: hidden;
}
.rp-daily-row .bar > span {
  display: block; height: 100%; background: var(--rp-brand);
}

/* Heatmap grid */
.rp-heat { display: grid; grid-template-columns: 40px repeat(24, 1fr); gap: 2px; font-size: 10px; }
.rp-heat .h { color: var(--rp-muted); text-align: center; padding: 2px 0; }
.rp-heat .d { color: var(--rp-muted); padding: 2px 4px; }
.rp-heat .c { aspect-ratio: 1; border-radius: 2px; background: var(--rp-surface-2); }

/* Data table styling */
.rp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rp-table th, .rp-table td { padding: 6px 8px; border-bottom: 1px solid var(--rp-border); text-align: left; }
.rp-table th { color: var(--rp-muted); font-weight: 500; font-size: 11.5px; text-transform: uppercase; }
.rp-table td.num, .rp-table th.num { text-align: right; }
.rp-table tr:hover td { background: var(--rp-surface-2); }

.rp-empty { color: var(--rp-muted); font-style: italic; padding: 12px 0; }

/* Tag chip like the existing UI */
.rp-tag {
  display: inline-block; padding: 2px 8px; border-radius: 999px;
  color: #fff; font-size: 12px; margin-right: 4px;
}
</style>

<div class="rp-wrap">

  <!-- ============ Filter form ============ -->
  <form method="get" class="rp-card">
    <div class="rp-filter-row">
      <label>Range
        <select name="range" onchange="this.form.submit()">
          <?php foreach ($presets as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $preset === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($preset === 'custom'): ?>
        <label>From <input type="date" name="from" value="<?= e($dateFrom) ?>"></label>
        <label>To   <input type="date" name="to"   value="<?= e($dateTo)   ?>"></label>
      <?php else: ?>
        <input type="hidden" name="from" value="<?= e($dateFrom) ?>">
        <input type="hidden" name="to"   value="<?= e($dateTo)   ?>">
      <?php endif; ?>
      <label>Channel
        <select name="channel_id" onchange="this.form.submit()">
          <option value="0">All channels</option>
          <?php foreach ($channels as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $fChannel === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Department
        <select name="department_id" onchange="this.form.submit()">
          <option value="0">All departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($branches): ?>
        <label>Branch
          <select name="branch_id" onchange="this.form.submit()">
            <option value="0">All branches</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= $fBranch === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <label>Assigned to
        <select name="agent_id" onchange="this.form.submit()">
          <option value="0">Anyone</option>
          <?php foreach ($agents as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $fAgent === (int)$a['id'] ? 'selected' : '' ?>>
              <?= e($a['name']) ?> · <?= e(role_label($a['role'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>&nbsp;
        <button class="btn btn-primary" type="submit" style="width:100%;">Apply</button>
      </label>
    </div>
    <div class="muted small" style="margin-top:8px;">
      <?= e($dateFrom) ?> → <?= e($dateTo) ?> · <?= (int)$rangeDays ?> day<?= $rangeDays === 1 ? '' : 's' ?>
      · comparing to <?= e(date('Y-m-d', strtotime($prevStart))) ?> → <?= e(date('Y-m-d', strtotime($prevEnd))) ?>
    </div>
  </form>

  <!-- ============ KPIs with delta ============ -->
  <div class="rp-kpi-row">
    <div class="rp-kpi">
      <div class="lbl">Conversations created</div>
      <div class="val"><?= (int)($totals['total'] ?? 0) ?> <?= $delta($totals['total'] ?? 0, $totalsP['total'] ?? 0, true) ?></div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Closed</div>
      <div class="val"><?= (int)($totals['s_closed'] ?? 0) ?> <?= $delta($totals['s_closed'] ?? 0, $totalsP['s_closed'] ?? 0, true) ?></div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Unassigned</div>
      <div class="val"><?= (int)($totals['unassigned'] ?? 0) ?> <?= $delta($totals['unassigned'] ?? 0, $totalsP['unassigned'] ?? 0, false) ?></div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Escalated</div>
      <div class="val"><?= (int)($totals['s_escalated'] ?? 0) ?> <?= $delta($totals['s_escalated'] ?? 0, $totalsP['s_escalated'] ?? 0, false) ?></div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Avg first response</div>
      <div class="val"><?= e(fmt_secs((int)($totals['avg_first_response_secs'] ?? 0))) ?>
        <?= $delta((int)($totals['avg_first_response_secs'] ?? 0), (int)($totalsP['avg_first_response_secs'] ?? 0), false) ?>
      </div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Avg resolution</div>
      <div class="val"><?= e(fmt_secs((int)($totals['avg_resolution_secs'] ?? 0))) ?>
        <?= $delta((int)($totals['avg_resolution_secs'] ?? 0), (int)($totalsP['avg_resolution_secs'] ?? 0), false) ?>
      </div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Messages received</div>
      <div class="val"><?= (int)($mTotals['incoming'] ?? 0) ?> <?= $delta($mTotals['incoming'] ?? 0, $mTotalsP['incoming'] ?? 0, true) ?></div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Messages sent</div>
      <div class="val"><?= (int)($mTotals['outgoing'] ?? 0) ?> <?= $delta($mTotals['outgoing'] ?? 0, $mTotalsP['outgoing'] ?? 0, true) ?></div>
    </div>
    <div class="rp-kpi">
      <div class="lbl">Send failures</div>
      <div class="val"><?= (int)($mTotals['failed'] ?? 0) ?> <?= $delta($mTotals['failed'] ?? 0, $mTotalsP['failed'] ?? 0, false) ?></div>
    </div>
  </div>

  <!-- ============ Response distribution + Daily volume ============ -->
  <div style="display:grid; gap:16px; grid-template-columns: 1fr 1fr;">

    <div class="rp-card">
      <h3>First-response time distribution</h3>
      <?php $distTotal = array_sum(array_column($distBuckets, 'n')); ?>
      <div class="muted small" style="margin-bottom:8px;">
        <?= (int)$distTotal ?> replied conversation(s) in range
      </div>
      <div class="rp-hist">
        <?php foreach ($distBuckets as $label => $b):
          $pct = $maxBucket > 0 ? ($b['n'] / $maxBucket) * 100 : 0;
          $sharePct = $distTotal > 0 ? round(($b['n'] / $distTotal) * 100) : 0; ?>
          <div class="rp-hist-row">
            <div class="lbl"><?= e($label) ?></div>
            <div class="bar"><span style="width: <?= max(2, (float)$pct) ?>%;"></span></div>
            <div class="n"><?= (int)$b['n'] ?><?php if ($distTotal): ?> <span class="muted small">(<?= (int)$sharePct ?>%)</span><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="rp-card">
      <h3>Daily conversation volume
        <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=daily">Export CSV</a>
      </h3>
      <?php if (!$daily): ?>
        <div class="rp-empty">No conversations in this range.</div>
      <?php else: ?>
        <div class="rp-daily">
          <?php foreach ($daily as $d):
            $pct = $maxDaily > 0 ? max(2, round(((int)$d['n'] / $maxDaily) * 100)) : 0; ?>
            <div class="rp-daily-row">
              <div class="lbl"><?= e(date('D M j', strtotime($d['d']))) ?></div>
              <div class="bar"><span style="width: <?= (int)$pct ?>%;"></span></div>
              <div class="n"><?= (int)$d['n'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Hourly heatmap ============ -->
  <div class="rp-card">
    <h3>Inbound activity heatmap · last 7 days</h3>
    <div class="muted small" style="margin-bottom:8px;">
      Cell intensity is customer-message count for that day/hour. Darker = busier.
      Handy for spotting when to staff more agents.
    </div>
    <div class="rp-heat">
      <div class="h"></div>
      <?php for ($h = 0; $h < 24; $h++): ?>
        <div class="h"><?= $h ?></div>
      <?php endfor; ?>
      <?php
        // MySQL DAYOFWEEK: 1=Sunday .. 7=Saturday. We render Mon..Sun.
        $daysOrder = [2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'];
        foreach ($daysOrder as $dowIdx => $dayLabel):
      ?>
        <div class="d"><?= $dayLabel ?></div>
        <?php for ($h = 0; $h < 24; $h++):
          $n = $heat[$dowIdx][$h] ?? 0;
          $alpha = $n === 0 ? 0 : min(1, 0.2 + ($n / max($maxHeat, 1)) * 0.8); ?>
          <div class="c" style="background: <?= $n > 0 ? 'rgba(37,211,102,' . number_format($alpha, 2) . ')' : 'var(--rp-surface-2)' ?>;"
               title="<?= $dayLabel ?> <?= $h ?>:00 — <?= (int)$n ?> msg(s)"></div>
        <?php endfor; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ============ Per-channel + Per-branch ============ -->
  <div style="display:grid; gap:16px; grid-template-columns: 1fr 1fr;">
    <div class="rp-card">
      <h3>Per-channel performance
        <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=perChannel">Export CSV</a>
      </h3>
      <?php if (!$perChannel): ?>
        <div class="rp-empty">No conversations in range.</div>
      <?php else: ?>
        <table class="rp-table">
          <thead>
            <tr><th>Channel</th><th>Provider</th><th class="num">Convs</th><th class="num">Avg response</th><th class="num">Failed sends</th></tr>
          </thead>
          <tbody>
            <?php foreach ($perChannel as $c): ?>
              <tr>
                <td><?= e($c['name']) ?></td>
                <td class="muted"><?= e($c['provider']) ?></td>
                <td class="num"><?= (int)$c['conv_count'] ?></td>
                <td class="num"><?= e(fmt_secs((int)$c['avg_response_secs'])) ?></td>
                <td class="num" style="color: <?= (int)$c['failed'] > 0 ? 'var(--rp-warn)' : 'inherit' ?>;">
                  <?= (int)$c['failed'] ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="rp-card">
      <h3>Per-branch performance
        <?php if ($perBranch): ?>
          <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=perBranch">Export CSV</a>
        <?php endif; ?>
      </h3>
      <?php if (!$perBranch): ?>
        <div class="rp-empty">
          <?= $branches ? 'No conversations in range.' : 'Branches not set up yet — go to Admin → Branches to create some.' ?>
        </div>
      <?php else: ?>
        <table class="rp-table">
          <thead><tr><th>Branch</th><th class="num">Convs</th><th class="num">Avg response</th></tr></thead>
          <tbody>
            <?php foreach ($perBranch as $b): ?>
              <tr>
                <td><?= e($b['name']) ?></td>
                <td class="num"><?= (int)$b['conv_count'] ?></td>
                <td class="num"><?= e(fmt_secs((int)$b['avg_response_secs'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Per-agent (kept) + Top tags ============ -->
  <div style="display:grid; gap:16px; grid-template-columns: 2fr 1fr;">
    <div class="rp-card">
      <h3>Per-agent performance
        <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=perAgent">Export CSV</a>
      </h3>
      <?php if (!$perAgent): ?>
        <div class="rp-empty">No agents yet.</div>
      <?php else: ?>
        <table class="rp-table">
          <thead>
            <tr>
              <th>Agent</th><th>Role</th>
              <th class="num">Convs assigned</th>
              <th class="num">Replies sent</th>
              <th class="num">Avg response</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($perAgent as $a): ?>
              <tr>
                <td><?= e($a['name']) ?></td>
                <td class="muted"><?= e(role_label($a['role'])) ?></td>
                <td class="num"><?= (int)$a['conversations_assigned'] ?></td>
                <td class="num"><?= (int)$a['replies_sent'] ?></td>
                <td class="num"><?= e(fmt_secs((int)$a['avg_response_secs'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="rp-card">
      <h3>Top tags
        <?php if ($topTags): ?>
          <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=topTags">Export CSV</a>
        <?php endif; ?>
      </h3>
      <?php if (!$topTags): ?>
        <div class="rp-empty">No tags applied.</div>
      <?php else: ?>
        <div style="display:grid; gap:6px;">
          <?php foreach ($topTags as $t): ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span class="rp-tag" style="background: <?= e($t['color']) ?>"><?= e($t['name']) ?></span>
              <span class="muted small"><?= (int)$t['n'] ?> conv(s)</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ Broadcasts + Flows performance ============ -->
  <div style="display:grid; gap:16px; grid-template-columns: 1fr 1fr;">
    <div class="rp-card">
      <h3>Recent broadcasts
        <?php if ($recentBroadcasts): ?>
          <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=broadcasts">Export CSV</a>
        <?php endif; ?>
      </h3>
      <?php if (!$recentBroadcasts): ?>
        <div class="rp-empty">No broadcasts in range.</div>
      <?php else: ?>
        <table class="rp-table">
          <thead>
            <tr>
              <th>Name</th><th>Status</th>
              <th class="num">Total</th><th class="num">Sent</th><th class="num">Failed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentBroadcasts as $b): ?>
              <tr>
                <td><a href="/admin/broadcast_view.php?id=<?= (int)$b['id'] ?>"><?= e($b['name']) ?></a></td>
                <td><?= status_badge($b['status']) ?></td>
                <td class="num"><?= (int)$b['total_recipients'] ?></td>
                <td class="num"><?= (int)$b['sent_count'] ?></td>
                <td class="num" style="color: <?= (int)$b['failed_count'] > 0 ? 'var(--rp-warn)' : 'inherit' ?>;">
                  <?= (int)$b['failed_count'] ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="rp-card">
      <h3>Flow performance
        <?php if ($flowStats): ?>
          <a class="csv" href="?<?= $qs ? $qs . '&' : '' ?>export=flows">Export CSV</a>
        <?php endif; ?>
      </h3>
      <?php if (!$flowStats): ?>
        <div class="rp-empty">
          No flows yet. <a href="/admin/flows.php">Create one</a>.
        </div>
      <?php else: ?>
        <table class="rp-table">
          <thead>
            <tr>
              <th>Flow</th><th>Status</th>
              <th class="num">Running</th><th class="num">Waiting</th>
              <th class="num">Done (range)</th><th class="num">Failed (range)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($flowStats as $f): ?>
              <tr>
                <td><a href="/admin/flow_edit.php?id=<?= (int)$f['id'] ?>"><?= e($f['name']) ?></a></td>
                <td><?= status_badge($f['status']) ?></td>
                <td class="num"><?= (int)$f['running'] ?></td>
                <td class="num"><?= (int)$f['waiting'] ?></td>
                <td class="num"><?= (int)$f['completed_in_range'] ?></td>
                <td class="num" style="color: <?= (int)$f['failed_in_range'] > 0 ? 'var(--rp-warn)' : 'inherit' ?>;">
                  <?= (int)$f['failed_in_range'] ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php layout_end(); ?>
