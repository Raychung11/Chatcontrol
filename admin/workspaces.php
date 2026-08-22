<?php
/**
 * Platform admin workspaces list.
 *
 * The operator's control tower — every tenant workspace in one view with:
 *   - KPI strip (workspaces, active this week, MRR estimate, broadcast MTD,
 *     F&B orders MTD)
 *   - Health dot per row (🟢 msg in 7d · 🟡 30d · ⚫ dead)
 *   - Search + filter (name/slug + plan + provider + health + F&B)
 *   - Sortable columns (click header)
 *   - Compact icon actions (sign-in / F&B toggle / archive)
 *
 * Every existing action (plan change, broadcast plan change, F&B toggle,
 * archive, sign in as super admin) is preserved — just presented tighter.
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ai_billing.php';

$current_user = require_login();
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin access only.');
}
if (is_impersonating()) {
    redirect('/dashboard.php');
}

$db = aiserve_db();

// -------------------- Query params --------------------
$q         = trim((string)($_GET['q']        ?? ''));
$fPlan     = (string)($_GET['plan']          ?? 'all');
$fProvider = (string)($_GET['provider']      ?? 'all');
$fHealth   = (string)($_GET['health']        ?? 'all');
$fFnb      = (string)($_GET['fnb']           ?? 'all');
$sort      = (string)($_GET['sort']          ?? 'created');
$dir       = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

// -------------------- Fetch all workspaces --------------------
// Provider comes from the DEFAULT CHANNEL, not companies.provider (which
// went stale after the multi-channel refactor).
$where  = ['c.status = "active"'];
$params = [];
if ($q !== '') {
    $where[]  = '(c.name LIKE ? OR c.slug LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($fPlan, ['starter', 'growth', 'enterprise'], true)) {
    $where[]  = 'c.plan = ?';
    $params[] = $fPlan;
}
if ($fFnb === 'on') {
    $where[] = 'c.fnb_plan IN ("active","paid")';
} elseif ($fFnb === 'off') {
    $where[] = '(c.fnb_plan IS NULL OR c.fnb_plan NOT IN ("active","paid"))';
}

$sql = 'SELECT c.id, c.name, c.slug, c.plan, c.broadcast_plan, c.broadcast_billing_cycle,
               c.fnb_plan, c.ai_chatbot_plan, c.ai_chatbot_multiplier,
               c.created_at,
               (SELECT provider FROM channels
                 WHERE company_id = c.id AND is_default = 1 LIMIT 1) AS provider,
               (SELECT COUNT(*) FROM users WHERE company_id = c.id AND status = "active") AS active_users,
               (SELECT COUNT(*) FROM conversations WHERE company_id = c.id) AS conversations,
               (SELECT MAX(created_at) FROM messages WHERE company_id = c.id) AS last_message_at,
               (SELECT COUNT(*) FROM channels WHERE company_id = c.id) AS channel_count
        FROM companies c
        WHERE ' . implode(' AND ', $where);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// -------------------- Enrich each row: health, MRR --------------------
$pricing = pricing_get();
$now     = time();
$sevenD  = $now - 7  * 86400;
$thirtyD = $now - 30 * 86400;

// Fetch this month's F&B revenue per workspace in ONE query.
$fnbGmv = [];
try {
    $g = $db->query(
        "SELECT company_id, SUM(total) AS gmv, COUNT(*) AS orders
         FROM fnb_orders
         WHERE status <> 'cancelled'
           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
         GROUP BY company_id"
    );
    foreach ($g->fetchAll() as $row) {
        $fnbGmv[(int)$row['company_id']] = [
            'gmv'    => (float)$row['gmv'],
            'orders' => (int)$row['orders'],
        ];
    }
} catch (Throwable $e) { /* fnb_orders may not exist yet */ }

// Fetch this month's AI raw USD per workspace in ONE query. We compute
// billed MYR per-row below (needs each workspace's multiplier).
$aiRawUsd = [];
try {
    $g = $db->query(
        "SELECT company_id,
                SUM(raw_cost_usd) AS raw_usd,
                SUM(prompt_tokens + completion_tokens) AS tokens,
                COUNT(*)          AS calls
         FROM ai_usage_events
         WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
         GROUP BY company_id"
    );
    foreach ($g->fetchAll() as $row) {
        $aiRawUsd[(int)$row['company_id']] = [
            'raw_usd' => (float)$row['raw_usd'],
            'tokens'  => (int)$row['tokens'],
            'calls'   => (int)$row['calls'],
        ];
    }
} catch (Throwable $e) { /* ai_usage_events may not exist pre-phase37 */ }
$aiFx = ai_usd_to_myr();
$aiDefaultMx = (float)platform_setting('ai_default_multiplier', '5.00');

$enriched = [];
foreach ($rows as $r) {
    $lastMs = $r['last_message_at'] ? strtotime((string)$r['last_message_at']) : 0;
    $health = ($lastMs >= $sevenD)  ? 'active'
            : (($lastMs >= $thirtyD) ? 'dormant' : 'dead');

    // Per-workspace MRR: plan seats + broadcast + PAYG accrued.
    // Enterprise = 0 (custom pricing lives outside the app).
    $planPrice = match ((string)$r['plan']) {
        'starter'    => (float)$pricing['starter_price'],
        'growth'     => (float)$pricing['bundle_price'],
        'enterprise' => 0.0,
        default      => (float)$pricing['starter_price'],
    };
    $bq = broadcast_quota_for_workspace((int)$r['id']);
    $bcastMonthly = match ($bq['plan']) {
        'paid' => $bq['billing_cycle'] === 'yearly'
                    ? (float)$bq['yearly_price'] / 12
                    : (float)$bq['price'],
        'payg' => (float)$bq['payg_accrued'],
        default => 0.0,
    };
    $fnbRow = $fnbGmv[(int)$r['id']] ?? ['gmv' => 0.0, 'orders' => 0];

    // AI chatbot billing — MTD raw × per-workspace multiplier × FX.
    $aiRow = $aiRawUsd[(int)$r['id']] ?? ['raw_usd' => 0.0, 'tokens' => 0, 'calls' => 0];
    $aiMx  = (float)($r['ai_chatbot_multiplier'] ?? 0);
    if ($aiMx <= 0) $aiMx = $aiDefaultMx;
    $aiChargeMyr = round($aiRow['raw_usd'] * $aiMx * $aiFx, 2);

    $enriched[] = [
        'r'             => $r,
        'health'        => $health,
        'last_ms'       => $lastMs,
        'plan_price'    => $planPrice,
        'bcast_monthly' => $bcastMonthly,
        'bcast_used'    => (int)$bq['used'],
        'bcast_limit'   => $bq['unlimited'] ? PHP_INT_MAX : (int)$bq['limit'],
        'bcast_plan'    => (string)$bq['plan'],
        'bcast_cycle'   => (string)$bq['billing_cycle'],
        'bcast'         => $bq,
        'ai_charge_myr' => $aiChargeMyr,
        'ai_tokens'     => (int)$aiRow['tokens'],
        'ai_calls'      => (int)$aiRow['calls'],
        'ai_plan'       => (string)($r['ai_chatbot_plan'] ?? 'none'),
        // MRR now includes AI chatbot billed revenue.
        'mrr'           => $planPrice + $bcastMonthly + $aiChargeMyr,
        'fnb_gmv'       => (float)$fnbRow['gmv'],
        'fnb_orders'    => (int)$fnbRow['orders'],
    ];
}

// PHP-side filters (provider + health depend on enrichment / subqueries).
if ($fProvider !== 'all') {
    $enriched = array_filter($enriched, fn($x) => (string)($x['r']['provider'] ?? '') === $fProvider);
}
if (in_array($fHealth, ['active', 'dormant', 'dead'], true)) {
    $enriched = array_filter($enriched, fn($x) => $x['health'] === $fHealth);
}
$enriched = array_values($enriched);

// Sort
$sortKey = match ($sort) {
    'name'          => fn($x) => mb_strtolower((string)$x['r']['name']),
    'plan'          => fn($x) => (string)$x['r']['plan'],
    'users'         => fn($x) => (int)$x['r']['active_users'],
    'conversations' => fn($x) => (int)$x['r']['conversations'],
    'last_message'  => fn($x) => (int)$x['last_ms'],
    'mrr'           => fn($x) => (float)$x['mrr'],
    'health'        => fn($x) => ['active' => 0, 'dormant' => 1, 'dead' => 2][$x['health']] ?? 3,
    default         => fn($x) => strtotime((string)$x['r']['created_at']),
};
usort($enriched, function ($a, $b) use ($sortKey, $dir) {
    $ka = $sortKey($a); $kb = $sortKey($b);
    if ($ka === $kb) return 0;
    return $dir === 'asc' ? ($ka < $kb ? -1 : 1) : ($ka > $kb ? -1 : 1);
});

// -------------------- KPI strip totals --------------------
$totalWs      = count($enriched);
$activeWs     = 0;
$totalMrr     = 0.0;
$totalBcast   = 0;
$totalFnbGmv  = 0.0;
$totalFnbOrd  = 0;
foreach ($enriched as $x) {
    if ($x['health'] === 'active') $activeWs++;
    $totalMrr    += $x['mrr'];
    $totalBcast  += $x['bcast_used'];
    $totalFnbGmv += $x['fnb_gmv'];
    $totalFnbOrd += $x['fnb_orders'];
}
$currency = platform_setting('pricing_currency', 'RM');

// Distinct provider values in current unfiltered list (for the dropdown).
$providers = array_values(array_unique(array_filter(array_map(
    fn($x) => (string)($x['r']['provider'] ?? ''),
    $enriched
))));

// -------------------- Flash messages --------------------
$archivedId = (int)($_GET['archived'] ?? 0);
$archivedName = '';
if ($archivedId > 0) {
    $s = $db->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
    $s->execute([$archivedId]);
    $archivedName = (string)($s->fetchColumn() ?: '');
}

// Helper for header sort links — preserves current filters, toggles dir.
$qsBase = [];
foreach (['q','plan','provider','health','fnb'] as $k) {
    $v = (string)($_GET[$k] ?? '');
    if ($v !== '' && $v !== 'all') $qsBase[$k] = $v;
}
$sortLink = function (string $col) use ($qsBase, $sort, $dir) {
    $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return '/admin/workspaces.php?' . http_build_query(array_merge($qsBase, ['sort' => $col, 'dir' => $nextDir]));
};
$sortIcon = function (string $col) use ($sort, $dir) {
    if ($sort !== $col) return '<span class="ws-sort-i" style="opacity:0.3;">↕</span>';
    return $dir === 'asc' ? '<span class="ws-sort-i">↑</span>' : '<span class="ws-sort-i">↓</span>';
};

layout_start($current_user, 'Workspaces', 'workspaces');
?>
<style>
.ws-kpi-row {
    display: grid; gap: 10px; margin-bottom: 14px;
    grid-template-columns: repeat(5, minmax(0, 1fr));
}
@media (max-width: 1000px) { .ws-kpi-row { grid-template-columns: repeat(2, 1fr); } }
.ws-kpi { background: #fff; border: 1px solid #e3e8ee; border-radius: 10px; padding: 12px 14px; }
.ws-kpi .lbl { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.ws-kpi .val { color: #0f172a; font-size: 22px; font-weight: 700; margin-top: 2px; }
.ws-kpi .sub { color: #94a3b8; font-size: 11px; margin-top: 2px; }

.ws-toolbar {
    display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
    background: #f6f9fb; border: 1px solid #e3e8ee; border-radius: 10px;
    padding: 10px 12px; margin-bottom: 12px;
}
.ws-toolbar input, .ws-toolbar select {
    padding: 5px 8px; font-size: 13px;
    border: 1px solid #d0d7de; border-radius: 6px; background: #fff;
}
.ws-toolbar input[type="search"] { min-width: 200px; }
.ws-toolbar .ws-clear { margin-left: auto; color: #64748b; font-size: 12px; text-decoration: none; }
.ws-toolbar .ws-clear:hover { color: #dc2626; }
.ws-count { color: #64748b; font-size: 12px; }

.data-table th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
.data-table th a:hover { color: #0072B2; }
.ws-sort-i { font-size: 11px; }

.ws-health {
    display: inline-block; width: 10px; height: 10px; border-radius: 50%;
    vertical-align: middle; margin-right: 6px;
}
.ws-health-active  { background: #16A34A; }
.ws-health-dormant { background: #F59E0B; }
.ws-health-dead    { background: #94A3B8; }

.ws-provider-chip {
    display: inline-block; padding: 2px 8px; border-radius: 999px;
    font-size: 11px; font-weight: 600; background: #eef2ff; color: #3730a3;
}
.ws-provider-cloud_api        { background: #dcfce7; color: #14532d; }
.ws-provider-evolution        { background: #fef3c7; color: #78350f; }
.ws-provider-aiserve_chatbot  { background: #e0f2fe; color: #075985; }
.ws-provider-web_chat         { background: #f3e8ff; color: #5b21b6; }
.ws-provider-facebook_page    { background: #dbeafe; color: #1e3a8a; }
.ws-provider-instagram_business { background: #fce7f3; color: #831843; }

.ws-fnb-chip {
    display: inline-block; padding: 1px 6px; border-radius: 999px;
    font-size: 10px; background: #fef3c7; color: #78350f; margin-left: 4px;
}
.ws-mrr { font-variant-numeric: tabular-nums; font-weight: 600; color: #0f172a; }

.ws-bar { height: 4px; background: #e3e8ee; border-radius: 2px; margin-top: 2px; overflow: hidden; }
.ws-bar-fill { height: 100%; border-radius: 2px; }
.ws-bar-ok   { background: #16A34A; }
.ws-bar-warn { background: #F59E0B; }
.ws-bar-full { background: #DC2626; }

.ws-icon-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 6px; border: 1px solid #d0d7de;
    background: #fff; cursor: pointer; font-size: 14px; padding: 0;
    margin-right: 2px;
}
.ws-icon-btn:hover        { border-color: #0072B2; }
.ws-icon-btn.on           { background: #dcfce7; border-color: #16A34A; }
.ws-icon-btn.danger:hover { border-color: #DC2626; color: #DC2626; }

.ws-inline-form { display: inline-flex; gap: 3px; align-items: center; }
.ws-inline-form select {
    padding: 2px 4px; font-size: 11.5px; border: 1px solid #d0d7de;
    border-radius: 4px; max-width: 110px;
}
</style>

<!-- KPI STRIP -->
<div class="ws-kpi-row">
    <div class="ws-kpi">
        <div class="lbl">Workspaces</div>
        <div class="val"><?= number_format($totalWs) ?></div>
        <div class="sub"><?= number_format($activeWs) ?> active this week</div>
    </div>
    <div class="ws-kpi">
        <div class="lbl">Monthly recurring revenue</div>
        <div class="val"><?= e($currency) ?> <?= number_format($totalMrr, 0) ?></div>
        <div class="sub">plans + broadcast · excl. F&amp;B</div>
    </div>
    <div class="ws-kpi">
        <div class="lbl">Broadcast (this month)</div>
        <div class="val"><?= number_format($totalBcast) ?></div>
        <div class="sub">recipients across all workspaces</div>
    </div>
    <div class="ws-kpi">
        <div class="lbl">F&amp;B revenue (this month)</div>
        <div class="val"><?= e($currency) ?> <?= number_format($totalFnbGmv, 0) ?></div>
        <div class="sub"><?= number_format($totalFnbOrd) ?> orders placed</div>
    </div>
    <div class="ws-kpi">
        <div class="lbl">Health mix</div>
        <div class="val" style="font-size: 15px; margin-top:6px;">
            <?php
                $h = ['active' => 0, 'dormant' => 0, 'dead' => 0];
                foreach ($enriched as $x) $h[$x['health']]++;
            ?>
            <span title="Active (7d)"  style="color:#16A34A;">🟢 <?= $h['active']  ?></span>
            &nbsp;<span title="Dormant (30d)" style="color:#F59E0B;">🟡 <?= $h['dormant'] ?></span>
            &nbsp;<span title="Dead"          style="color:#94A3B8;">⚫ <?= $h['dead']    ?></span>
        </div>
        <div class="sub">click a filter above to isolate</div>
    </div>
</div>

<?php if ($archivedName !== ''): ?>
    <div class="alert alert-success" style="margin: 0 0 10px;">
        Workspace <strong><?= e($archivedName) ?></strong> archived — hidden from this list, data preserved.
    </div>
<?php endif; ?>
<?php $planChanged = (string)($_GET['plan_changed'] ?? ''); if ($planChanged !== ''): ?>
    <div class="alert alert-success" style="margin: 0 0 10px;">Plan updated: <?= e($planChanged) ?>.</div>
<?php endif; ?>
<?php $planError = (string)($_GET['plan_error'] ?? ''); if ($planError !== ''): ?>
    <div class="alert alert-error" style="margin: 0 0 10px;"><?= e($planError) ?></div>
<?php endif; ?>

<!-- TOOLBAR -->
<form method="get" class="ws-toolbar">
    <input type="search" name="q" placeholder="Search name or slug…" value="<?= e($q) ?>" autofocus>
    <select name="plan">
        <option value="all">All plans</option>
        <?php foreach (['starter' => 'Starter', 'growth' => 'Growth', 'enterprise' => 'Enterprise'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= $fPlan === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <select name="provider">
        <option value="all">All providers</option>
        <?php foreach ($providers as $p): ?>
            <option value="<?= e($p) ?>" <?= $fProvider === $p ? 'selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="health">
        <option value="all">All health</option>
        <option value="active"  <?= $fHealth === 'active'  ? 'selected' : '' ?>>🟢 Active (7d)</option>
        <option value="dormant" <?= $fHealth === 'dormant' ? 'selected' : '' ?>>🟡 Dormant (30d)</option>
        <option value="dead"    <?= $fHealth === 'dead'    ? 'selected' : '' ?>>⚫ Dead</option>
    </select>
    <select name="fnb">
        <option value="all">F&amp;B: all</option>
        <option value="on"  <?= $fFnb === 'on'  ? 'selected' : '' ?>>F&amp;B on</option>
        <option value="off" <?= $fFnb === 'off' ? 'selected' : '' ?>>F&amp;B off</option>
    </select>
    <button class="btn btn-sm" type="submit">Filter</button>
    <?php if ($q !== '' || $fPlan !== 'all' || $fProvider !== 'all' || $fHealth !== 'all' || $fFnb !== 'all'): ?>
        <a class="ws-clear" href="/admin/workspaces.php">✕ clear</a>
    <?php endif; ?>
    <span class="ws-count" style="margin-left:auto;"><?= number_format($totalWs) ?> workspace<?= $totalWs === 1 ? '' : 's' ?></span>
</form>

<div class="card" style="padding: 0;">
<table class="data-table" style="margin: 0;">
    <thead>
        <tr>
            <th style="width: 32px;"></th>
            <th><a href="<?= e($sortLink('name')) ?>">Workspace <?= $sortIcon('name') ?></a></th>
            <th><a href="<?= e($sortLink('plan')) ?>">Plan <?= $sortIcon('plan') ?></a></th>
            <th>Broadcast</th>
            <th>Provider</th>
            <th><a href="<?= e($sortLink('users')) ?>">Users <?= $sortIcon('users') ?></a></th>
            <th><a href="<?= e($sortLink('conversations')) ?>">Convs <?= $sortIcon('conversations') ?></a></th>
            <th><a href="<?= e($sortLink('mrr')) ?>">MRR <?= $sortIcon('mrr') ?></a></th>
            <th><a href="<?= e($sortLink('last_message')) ?>">Last msg <?= $sortIcon('last_message') ?></a></th>
            <th style="text-align: right;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$enriched): ?>
            <tr><td colspan="10" class="muted" style="text-align:center; padding:24px;">
                No workspaces match this filter.
                <?php if ($q !== '' || $fPlan !== 'all' || $fProvider !== 'all' || $fHealth !== 'all' || $fFnb !== 'all'): ?>
                    <a href="/admin/workspaces.php">Reset filters →</a>
                <?php endif; ?>
            </td></tr>
        <?php endif; ?>

        <?php foreach ($enriched as $x):
            $r          = $x['r'];
            $fnbActive  = in_array((string)($r['fnb_plan'] ?? ''), ['active', 'paid'], true);
            $isMe       = (int)$r['id'] === (int)$current_user['company_id'];
            $healthLbl  = ['active' => 'msg in last 7 days', 'dormant' => 'msg in last 30 days', 'dead' => 'no message in 30d+'][$x['health']];
            $lastMsgTxt = $x['last_ms'] > 0 ? fmt_dt($r['last_message_at']) : '—';

            // Broadcast bar %
            $bcastPct = 0;
            if ($x['bcast_limit'] > 0 && $x['bcast_limit'] !== PHP_INT_MAX) {
                $bcastPct = min(100, ($x['bcast_used'] / $x['bcast_limit']) * 100);
            }
            $barCls = $bcastPct >= 100 ? 'ws-bar-full'
                    : ($bcastPct >= 80 ? 'ws-bar-warn' : 'ws-bar-ok');
        ?>
            <tr>
                <td style="text-align:center;">
                    <span class="ws-health ws-health-<?= $x['health'] ?>" title="<?= e($healthLbl) ?>"></span>
                </td>
                <td>
                    <strong><?= e((string)$r['name']) ?></strong>
                    <?php if ($fnbActive): ?><span class="ws-fnb-chip">🍜 F&amp;B</span><?php endif; ?>
                    <div class="muted small"><code><?= e((string)$r['slug']) ?></code></div>
                </td>
                <td>
                    <form method="post" action="/api/workspace_action.php" class="ws-inline-form"
                          onsubmit="return confirm('Change plan for &quot;<?= e(addslashes((string)$r['name'])) ?>&quot;?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_plan">
                        <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                        <select name="plan">
                            <?php foreach (['starter' => 'Starter', 'growth' => 'Growth', 'enterprise' => 'Enterprise'] as $key => $label): ?>
                                <option value="<?= $key ?>" <?= (string)$r['plan'] === $key ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="ws-icon-btn" title="Change plan">✓</button>
                    </form>
                </td>
                <td>
                    <form method="post" action="/api/workspace_action.php" class="ws-inline-form"
                          style="flex-wrap:wrap;"
                          onsubmit="return confirm('Change broadcast plan?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_broadcast_plan">
                        <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                        <select name="broadcast_plan">
                            <option value="free" <?= $x['bcast_plan'] === 'free' ? 'selected' : '' ?>>Free</option>
                            <option value="paid" <?= $x['bcast_plan'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="payg" <?= $x['bcast_plan'] === 'payg' ? 'selected' : '' ?>>PAYG</option>
                        </select>
                        <select name="broadcast_billing_cycle">
                            <option value="monthly" <?= $x['bcast_cycle'] === 'monthly' ? 'selected' : '' ?>>M</option>
                            <option value="yearly"  <?= $x['bcast_cycle'] === 'yearly'  ? 'selected' : '' ?>>Y</option>
                        </select>
                        <button type="submit" class="ws-icon-btn" title="Change broadcast plan">✓</button>
                    </form>
                    <div class="muted small" style="margin-top:3px;">
                        <?php if ($x['bcast_plan'] === 'payg'): ?>
                            <?= number_format($x['bcast_used']) ?> · <?= e($currency) ?> <?= number_format($x['bcast']['payg_accrued'], 2) ?>
                        <?php elseif ($x['bcast_limit'] === PHP_INT_MAX): ?>
                            <?= number_format($x['bcast_used']) ?> sent
                        <?php else: ?>
                            <?= number_format($x['bcast_used']) ?> / <?= number_format($x['bcast_limit']) ?>
                            <?php $bcastCredits = (int)($x['bcast']['credits'] ?? 0); ?>
                            <?php if ($bcastCredits !== 0): ?>
                              <span title="Manual bonus credits from /admin/broadcast_credits.php"
                                    style="display:inline-block; padding:1px 6px; border-radius:999px;
                                           font-size:10px; font-weight:600;
                                           background: <?= $bcastCredits > 0 ? '#dcfce7' : '#fee2e2' ?>;
                                           color: <?= $bcastCredits > 0 ? '#14532d' : '#991b1b' ?>;
                                           margin-left:4px;">
                                🎁 <?= $bcastCredits > 0 ? '+' : '' ?><?= number_format($bcastCredits) ?>
                              </span>
                            <?php endif; ?>
                            <div class="ws-bar"><div class="ws-bar-fill <?= $barCls ?>" style="width: <?= number_format($bcastPct, 1) ?>%;"></div></div>
                        <?php endif; ?>
                        <a href="/admin/broadcast_credits.php?company_id=<?= (int)$r['id'] ?>"
                           title="Grant / revoke bonus broadcast credits"
                           style="text-decoration:none; margin-left:2px;">🎁+</a>
                    </div>
                </td>
                <td>
                    <?php if (!empty($r['provider'])):
                        $prov = (string)$r['provider'];
                    ?>
                        <span class="ws-provider-chip ws-provider-<?= e($prov) ?>"><?= e($prov) ?></span>
                        <?php if ((int)$r['channel_count'] > 1): ?>
                            <div class="muted small">+<?= (int)$r['channel_count'] - 1 ?> more</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted small">(no channel)</span>
                    <?php endif; ?>
                </td>
                <td><?= (int)$r['active_users'] ?> / <?= (int)plan_seat_limit((string)$r['plan']) === 9999 ? '∞' : (int)plan_seat_limit((string)$r['plan']) ?></td>
                <td><?= number_format((int)$r['conversations']) ?></td>
                <td class="ws-mrr">
                    <?= e($currency) ?> <?= number_format($x['mrr'], 0) ?>
                    <?php if ($x['ai_charge_myr'] > 0): ?>
                        <div class="muted small" style="font-weight:normal;" title="AI chatbot MTD billed to workspace">
                            🤖 <?= e($currency) ?> <?= number_format($x['ai_charge_myr'], 0) ?>
                            · <?= number_format($x['ai_tokens']) ?> tok
                        </div>
                    <?php endif; ?>
                    <?php if ($x['fnb_gmv'] > 0): ?>
                        <div class="muted small" style="font-weight:normal;" title="F&amp;B GMV this month (not billed to workspace)">
                            🍜 <?= e($currency) ?> <?= number_format($x['fnb_gmv'], 0) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="muted small"><?= e($lastMsgTxt) ?></td>
                <td style="text-align:right; white-space:nowrap;">
                    <form method="post" action="/api/workspace_action.php" style="display:inline;"
                          onsubmit="return confirm('<?= $fnbActive ? 'Disable' : 'Enable' ?> F&amp;B for &quot;<?= e(addslashes((string)$r['name'])) ?>&quot;?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_fnb">
                        <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="ws-icon-btn <?= $fnbActive ? 'on' : '' ?>"
                                title="<?= $fnbActive ? 'F&B module ON — click to disable' : 'F&B module OFF — click to enable' ?>">🍜</button>
                    </form>
                    <?php if (!$isMe): ?>
                        <form method="post" action="/api/impersonate.php" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="ws-icon-btn" title="Sign in as super admin">🔑</button>
                        </form>
                        <form method="post" action="/api/workspace_action.php" style="display:inline;"
                              onsubmit="return confirm('Archive &quot;<?= e(addslashes((string)$r['name'])) ?>&quot;? Data preserved, hidden from list.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="company_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="ws-icon-btn danger" title="Archive workspace">🗄</button>
                        </form>
                    <?php else: ?>
                        <span class="muted small">you</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php layout_end(); ?>
