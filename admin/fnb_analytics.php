<?php
/**
 * F&B analytics — the operator's BI dashboard.
 *
 * Answers the questions "what's selling, where, and when?":
 *   - KPI row (revenue, orders, AOV, peak day) vs prior period
 *   - Top 10 products (by revenue OR quantity — toggle)
 *   - Sales by branch          (which location is winning)
 *   - Sales by widget/channel  (which QR / touchpoint is winning)
 *   - Daily revenue trend      (line over the selected range)
 *   - Peak hours               (bar per hour-of-day)
 *
 * Everything scoped by company_id + a period picker (7 / 30 / 90 / custom).
 * Cancelled orders are excluded from revenue math but stay counted in
 * "orders placed" (so KPI truthfully mirrors the kanban).
 *
 * Charts are inline SVG — no external libs, no JS. That keeps this page
 * fast, matches the rest of the admin, and stays legible in both light
 * and dark mode. Colors follow the CVD-safe palette used elsewhere.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('The F&B module is not enabled for this workspace.');
}

$db       = aiserve_db();
$currency = platform_setting('pricing_currency', 'RM');

// -------------------- Range picker --------------------
// Preset days: 7 / 30 / 90. "custom" honours from/to query params.
$period = (string)($_GET['period'] ?? '30');
$validPresets = ['7', '30', '90'];
$fromStr = (string)($_GET['from'] ?? '');
$toStr   = (string)($_GET['to']   ?? '');

if (in_array($period, $validPresets, true)) {
    $days = (int)$period;
    $to   = new DateTimeImmutable('today 23:59:59');
    $from = $to->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);
} elseif ($period === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromStr) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toStr)) {
    $from = new DateTimeImmutable($fromStr . ' 00:00:00');
    $to   = new DateTimeImmutable($toStr   . ' 23:59:59');
    if ($to < $from) { [$from, $to] = [$to, $from]; }
    $days = (int)$from->diff($to)->format('%a') + 1;
    $period = 'custom';
} else {
    // Fall back to 30d.
    $period = '30';
    $days = 30;
    $to   = new DateTimeImmutable('today 23:59:59');
    $from = $to->modify('-29 days')->setTime(0, 0, 0);
}
$fromSql = $from->format('Y-m-d H:i:s');
$toSql   = $to->format('Y-m-d H:i:s');

// Prior comparable window for delta arrows.
$span      = $to->getTimestamp() - $from->getTimestamp();
$prevTo    = $from->modify('-1 second');
$prevFrom  = (new DateTimeImmutable('@' . ($prevTo->getTimestamp() - $span)))->setTimezone($from->getTimezone());
$prevFromSql = $prevFrom->format('Y-m-d H:i:s');
$prevToSql   = $prevTo->format('Y-m-d H:i:s');

// Metric toggle: rev|qty on the Top Products chart.
$topBy = (string)($_GET['by'] ?? 'rev');
if (!in_array($topBy, ['rev', 'qty'], true)) $topBy = 'rev';

// -------------------- KPI queries --------------------
// Revenue math excludes cancelled orders. Order count includes them.
$kpi = function (string $fromSql, string $toSql) use ($db, $companyId): array {
    $r = $db->prepare(
        'SELECT
            COUNT(*)                                            AS orders,
            COALESCE(SUM(CASE WHEN status <> "cancelled" THEN total ELSE 0 END), 0) AS revenue,
            COALESCE(AVG(NULLIF(CASE WHEN status <> "cancelled" THEN total ELSE NULL END, 0)), 0) AS aov
         FROM fnb_orders
         WHERE company_id = ? AND created_at BETWEEN ? AND ?'
    );
    $r->execute([$companyId, $fromSql, $toSql]);
    return $r->fetch() ?: ['orders' => 0, 'revenue' => 0, 'aov' => 0];
};
$now  = $kpi($fromSql, $toSql);
$prev = $kpi($prevFromSql, $prevToSql);

// Peak day within range.
$peakStmt = $db->prepare(
    'SELECT DATE(created_at) AS d,
            SUM(CASE WHEN status <> "cancelled" THEN total ELSE 0 END) AS rev
     FROM fnb_orders
     WHERE company_id = ? AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at)
     ORDER BY rev DESC LIMIT 1'
);
$peakStmt->execute([$companyId, $fromSql, $toSql]);
$peak = $peakStmt->fetch();

// -------------------- Top products --------------------
$topStmt = $db->prepare(
    'SELECT oi.product_name,
            SUM(oi.quantity)   AS qty,
            SUM(oi.line_total) AS revenue,
            COUNT(DISTINCT oi.order_id) AS orders
     FROM fnb_order_items oi
     INNER JOIN fnb_orders o ON o.id = oi.order_id
     WHERE o.company_id = ? AND o.status <> "cancelled"
       AND o.created_at BETWEEN ? AND ?
     GROUP BY oi.product_name
     ORDER BY ' . ($topBy === 'qty' ? 'qty' : 'revenue') . ' DESC
     LIMIT 10'
);
$topStmt->execute([$companyId, $fromSql, $toSql]);
$topProducts = $topStmt->fetchAll();

// -------------------- Sales by branch --------------------
$branchStmt = $db->prepare(
    'SELECT COALESCE(b.name, "(Unassigned)") AS name,
            COUNT(*)         AS orders,
            SUM(o.total)     AS revenue
     FROM fnb_orders o
     LEFT JOIN branches b ON b.id = o.branch_id
     WHERE o.company_id = ? AND o.status <> "cancelled"
       AND o.created_at BETWEEN ? AND ?
     GROUP BY o.branch_id, b.name
     ORDER BY revenue DESC'
);
$branchStmt->execute([$companyId, $fromSql, $toSql]);
$branchRows = $branchStmt->fetchAll();

// -------------------- Sales by channel (widget / QR) --------------------
$channelStmt = $db->prepare(
    'SELECT COALESCE(c.name, "(Unknown channel)") AS name,
            c.provider                             AS provider,
            COUNT(*)     AS orders,
            SUM(o.total) AS revenue
     FROM fnb_orders o
     LEFT JOIN conversations conv ON conv.id = o.conversation_id
     LEFT JOIN channels c         ON c.id = conv.channel_id
     WHERE o.company_id = ? AND o.status <> "cancelled"
       AND o.created_at BETWEEN ? AND ?
     GROUP BY c.id, c.name, c.provider
     ORDER BY revenue DESC
     LIMIT 12'
);
$channelStmt->execute([$companyId, $fromSql, $toSql]);
$channelRows = $channelStmt->fetchAll();

// -------------------- Daily revenue trend --------------------
$trendStmt = $db->prepare(
    'SELECT DATE(created_at) AS d,
            SUM(CASE WHEN status <> "cancelled" THEN total ELSE 0 END) AS revenue,
            COUNT(*) AS orders
     FROM fnb_orders
     WHERE company_id = ? AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at)
     ORDER BY d ASC'
);
$trendStmt->execute([$companyId, $fromSql, $toSql]);
$trendRaw = [];
foreach ($trendStmt->fetchAll() as $t) {
    $trendRaw[(string)$t['d']] = ['revenue' => (float)$t['revenue'], 'orders' => (int)$t['orders']];
}
// Fill zero-days so the line stays honest (no phantom gaps).
$trendPoints = [];
for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
    $d = $cursor->format('Y-m-d');
    $trendPoints[] = [
        'd' => $d,
        'revenue' => $trendRaw[$d]['revenue'] ?? 0,
        'orders'  => $trendRaw[$d]['orders']  ?? 0,
    ];
}

// -------------------- Peak hours --------------------
$hourStmt = $db->prepare(
    'SELECT HOUR(created_at) AS h,
            COUNT(*)         AS orders,
            SUM(CASE WHEN status <> "cancelled" THEN total ELSE 0 END) AS revenue
     FROM fnb_orders
     WHERE company_id = ? AND created_at BETWEEN ? AND ?
     GROUP BY HOUR(created_at)'
);
$hourStmt->execute([$companyId, $fromSql, $toSql]);
$hourMap = [];
foreach ($hourStmt->fetchAll() as $h) $hourMap[(int)$h['h']] = (int)$h['orders'];
$hourBars = [];
for ($h = 0; $h < 24; $h++) $hourBars[] = ['h' => $h, 'orders' => $hourMap[$h] ?? 0];

// -------------------- helpers --------------------
$fmtRev = function ($n) use ($currency): string {
    return $currency . ' ' . number_format((float)$n, 2);
};
$deltaPct = function (float $now, float $prev): ?float {
    if ($prev <= 0) return null;
    return round((($now - $prev) / $prev) * 100, 1);
};
$deltaHtml = function (?float $pct): string {
    if ($pct === null) return '<span class="ana-delta ana-delta-flat">—</span>';
    $sign = $pct > 0 ? '▲' : ($pct < 0 ? '▼' : '·');
    $cls  = $pct > 0 ? 'ana-delta-up' : ($pct < 0 ? 'ana-delta-dn' : 'ana-delta-flat');
    return '<span class="ana-delta ' . $cls . '">' . $sign . ' '
         . htmlspecialchars(number_format(abs($pct), 1)) . '%</span>';
};

// CVD-safe categorical palette (colorblind-friendly Okabe–Ito subset).
$catPalette = [
    '#0072B2', // blue
    '#D55E00', // vermillion
    '#009E73', // bluish green
    '#CC79A7', // reddish purple
    '#F0E442', // yellow
    '#56B4E9', // sky blue
    '#E69F00', // orange
    '#94A3B8', // slate (Unassigned / neutral)
];

layout_start($current_user, 'F&B analytics', 'fnb_analytics');
?>

<style>
:root {
  --ana-fg:      #0f172a;
  --ana-muted:   #64748b;
  --ana-line:    #e3e8ee;
  --ana-card-bg: #ffffff;
  --ana-grid:    #eef2f7;
  --ana-primary: #0072B2;
  --ana-accent:  #009E73;
}
@media (prefers-color-scheme: dark) {
  :root {
    --ana-fg:      #e2e8f0;
    --ana-muted:   #94a3b8;
    --ana-line:    #1f2937;
    --ana-card-bg: #0f172a;
    --ana-grid:    #1e293b;
  }
}

.ana-toolbar {
  display: flex; gap: 8px; flex-wrap: wrap;
  align-items: center; margin-bottom: 14px;
}
.ana-toolbar .btn { padding: 4px 10px; font-size: 13px; }
.ana-toolbar .btn.active {
  background: var(--ana-primary); color: #fff; border-color: var(--ana-primary);
}
.ana-kpi-row {
  display: grid; gap: 12px; margin-bottom: 16px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
@media (max-width: 900px) { .ana-kpi-row { grid-template-columns: repeat(2, 1fr); } }
.ana-kpi {
  background: var(--ana-card-bg); border: 1px solid var(--ana-line);
  border-radius: 12px; padding: 14px 16px;
}
.ana-kpi .k-label { color: var(--ana-muted); font-size: 12px; text-transform: uppercase; letter-spacing:.04em; }
.ana-kpi .k-val   { color: var(--ana-fg);    font-size: 24px; font-weight: 700; margin-top: 4px; }
.ana-kpi .k-sub   { color: var(--ana-muted); font-size: 12px; margin-top: 6px; display: flex; gap: 8px; align-items: center; }
.ana-delta        { font-weight: 600; font-size: 12px; }
.ana-delta-up     { color: #16A34A; }
.ana-delta-dn     { color: #DC2626; }
.ana-delta-flat   { color: var(--ana-muted); }

.ana-grid2 {
  display: grid; gap: 12px; margin-bottom: 16px;
  grid-template-columns: 1fr 1fr;
}
@media (max-width: 1000px) { .ana-grid2 { grid-template-columns: 1fr; } }

.ana-card {
  background: var(--ana-card-bg); border: 1px solid var(--ana-line);
  border-radius: 12px; padding: 14px 16px; margin-bottom: 12px;
}
.ana-card h3 { margin: 0 0 10px; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
.ana-card .ana-toggle { font-size: 12px; }
.ana-card .ana-toggle a { color: var(--ana-muted); text-decoration: none; margin-left: 8px; }
.ana-card .ana-toggle a.active { color: var(--ana-primary); font-weight: 600; }

.ana-empty { color: var(--ana-muted); padding: 24px 0; text-align: center; font-size: 13px; }

.ana-bar-list { display: flex; flex-direction: column; gap: 6px; }
.ana-bar-row  { display: grid; gap: 8px; grid-template-columns: 140px 1fr 80px; align-items: center; font-size: 12.5px; }
.ana-bar-row .lbl  { color: var(--ana-fg); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ana-bar-row .num  { color: var(--ana-muted); text-align: right; }
.ana-bar-bar  { height: 14px; background: var(--ana-grid); border-radius: 4px; overflow: hidden; }
.ana-bar-fill { height: 100%; border-radius: 4px; }

.ana-svg { display: block; width: 100%; height: auto; }

.ana-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; font-size: 12px; color: var(--ana-muted); }
.ana-legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
</style>

<div class="ana-toolbar">
  <strong style="font-size:15px; margin-right: 6px;">📊 F&amp;B analytics</strong>
  <?php
    $ranges = ['7' => 'Last 7d', '30' => 'Last 30d', '90' => 'Last 90d'];
    foreach ($ranges as $k => $lbl):
        $cls = $period === $k ? 'btn btn-sm active' : 'btn btn-sm';
  ?>
    <a class="<?= $cls ?>" href="?period=<?= $k ?>&amp;by=<?= e($topBy) ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>

  <form method="get" style="display:flex; gap:4px; align-items:center; margin-left:auto;">
    <input type="hidden" name="period" value="custom">
    <input type="hidden" name="by" value="<?= e($topBy) ?>">
    <label style="font-size:12px; color:var(--ana-muted);">From</label>
    <input type="date" name="from" value="<?= e($from->format('Y-m-d')) ?>" style="font-size:12px;">
    <label style="font-size:12px; color:var(--ana-muted);">To</label>
    <input type="date" name="to"   value="<?= e($to->format('Y-m-d')) ?>" style="font-size:12px;">
    <button class="btn btn-sm" type="submit">Apply</button>
  </form>
</div>

<div class="ana-toolbar" style="margin-top:-6px; margin-bottom:16px; color:var(--ana-muted); font-size:12px;">
  <?= e($from->format('D, j M Y')) ?> → <?= e($to->format('D, j M Y')) ?>
  · <?= (int)$days ?> day<?= $days === 1 ? '' : 's' ?>
  · vs prior <?= (int)$days ?> day<?= $days === 1 ? '' : 's' ?>
</div>

<!-- KPI ROW -->
<div class="ana-kpi-row">
  <div class="ana-kpi">
    <div class="k-label">Revenue</div>
    <div class="k-val"><?= $fmtRev($now['revenue']) ?></div>
    <div class="k-sub"><?= $deltaHtml($deltaPct((float)$now['revenue'], (float)$prev['revenue'])) ?> vs prior</div>
  </div>
  <div class="ana-kpi">
    <div class="k-label">Orders</div>
    <div class="k-val"><?= number_format((int)$now['orders']) ?></div>
    <div class="k-sub"><?= $deltaHtml($deltaPct((float)$now['orders'], (float)$prev['orders'])) ?> vs prior</div>
  </div>
  <div class="ana-kpi">
    <div class="k-label">Avg order value</div>
    <div class="k-val"><?= $fmtRev($now['aov']) ?></div>
    <div class="k-sub"><?= $deltaHtml($deltaPct((float)$now['aov'], (float)$prev['aov'])) ?> vs prior</div>
  </div>
  <div class="ana-kpi">
    <div class="k-label">Peak day</div>
    <?php if ($peak): ?>
      <div class="k-val"><?= e((new DateTimeImmutable((string)$peak['d']))->format('j M')) ?></div>
      <div class="k-sub"><?= $fmtRev($peak['rev']) ?></div>
    <?php else: ?>
      <div class="k-val" style="color:var(--ana-muted); font-size:16px;">No orders</div>
      <div class="k-sub">—</div>
    <?php endif; ?>
  </div>
</div>

<!-- TOP PRODUCTS + SALES BY BRANCH -->
<div class="ana-grid2">
  <div class="ana-card">
    <h3>
      <span>Top 10 products</span>
      <span class="ana-toggle">
        <a href="?period=<?= e($period) ?>&amp;by=rev<?= $period==='custom' ? '&from='.e($from->format('Y-m-d')).'&to='.e($to->format('Y-m-d')) : '' ?>"
           class="<?= $topBy === 'rev' ? 'active' : '' ?>">by revenue</a>
        <a href="?period=<?= e($period) ?>&amp;by=qty<?= $period==='custom' ? '&from='.e($from->format('Y-m-d')).'&to='.e($to->format('Y-m-d')) : '' ?>"
           class="<?= $topBy === 'qty' ? 'active' : '' ?>">by quantity</a>
      </span>
    </h3>
    <?php if (!$topProducts): ?>
      <div class="ana-empty">No orders in this range yet.</div>
    <?php else:
      $topMax = 0;
      foreach ($topProducts as $tp) $topMax = max($topMax, (float)($topBy === 'qty' ? $tp['qty'] : $tp['revenue']));
      if ($topMax <= 0) $topMax = 1;
    ?>
      <div class="ana-bar-list">
        <?php foreach ($topProducts as $i => $tp):
          $val = (float)($topBy === 'qty' ? $tp['qty'] : $tp['revenue']);
          $pct = ($val / $topMax) * 100;
          $color = $catPalette[$i % count($catPalette)];
        ?>
          <div class="ana-bar-row" title="<?= e($tp['product_name']) ?>">
            <div class="lbl"><?= e($tp['product_name']) ?></div>
            <div class="ana-bar-bar">
              <div class="ana-bar-fill" style="width: <?= number_format($pct, 2) ?>%; background: <?= $color ?>;"></div>
            </div>
            <div class="num">
              <?php if ($topBy === 'qty'): ?>
                <?= (int)$tp['qty'] ?>x
              <?php else: ?>
                <?= $fmtRev($tp['revenue']) ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="ana-card">
    <h3><span>Sales by branch</span></h3>
    <?php if (!$branchRows): ?>
      <div class="ana-empty">No orders in this range yet.</div>
    <?php else:
      $bMax = 0;
      foreach ($branchRows as $br) $bMax = max($bMax, (float)$br['revenue']);
      if ($bMax <= 0) $bMax = 1;
    ?>
      <div class="ana-bar-list">
        <?php foreach ($branchRows as $i => $br):
          $pct = ((float)$br['revenue'] / $bMax) * 100;
          $color = $catPalette[$i % count($catPalette)];
        ?>
          <div class="ana-bar-row" title="<?= e((string)$br['name']) ?>">
            <div class="lbl"><?= e((string)$br['name']) ?></div>
            <div class="ana-bar-bar">
              <div class="ana-bar-fill" style="width: <?= number_format($pct, 2) ?>%; background: <?= $color ?>;"></div>
            </div>
            <div class="num">
              <?= $fmtRev($br['revenue']) ?><br>
              <span style="font-size:11px;"><?= (int)$br['orders'] ?> orders</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="muted small" style="margin-top:10px;">
        Widgets without a branch show as <em>(Unassigned)</em>.
        Tag your QR widgets in <a href="/admin/webchat.php">Admin → Web chat widget</a>.
      </p>
    <?php endif; ?>
  </div>
</div>

<!-- SALES BY CHANNEL / WIDGET -->
<div class="ana-card">
  <h3><span>Sales by widget / channel</span></h3>
  <?php if (!$channelRows): ?>
    <div class="ana-empty">No orders in this range yet.</div>
  <?php else:
    $cMax = 0;
    foreach ($channelRows as $cr) $cMax = max($cMax, (float)$cr['revenue']);
    if ($cMax <= 0) $cMax = 1;
  ?>
    <div class="ana-bar-list">
      <?php foreach ($channelRows as $i => $cr):
        $pct = ((float)$cr['revenue'] / $cMax) * 100;
        $color = $catPalette[$i % count($catPalette)];
        $prov  = (string)($cr['provider'] ?? '');
        $provIcon = match ($prov) {
            'web_chat'          => '💬',
            'cloud_api'         => '📱',
            'evolution'         => '📱',
            'aiserve_chatbot'   => '🤖',
            'facebook_page'     => '📘',
            'instagram_business'=> '📸',
            default             => '·',
        };
      ?>
        <div class="ana-bar-row" title="<?= e((string)$cr['name']) ?>">
          <div class="lbl"><?= $provIcon ?> <?= e((string)$cr['name']) ?></div>
          <div class="ana-bar-bar">
            <div class="ana-bar-fill" style="width: <?= number_format($pct, 2) ?>%; background: <?= $color ?>;"></div>
          </div>
          <div class="num">
            <?= $fmtRev($cr['revenue']) ?><br>
            <span style="font-size:11px;"><?= (int)$cr['orders'] ?> orders</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- DAILY TREND (inline SVG line chart) -->
<div class="ana-card">
  <h3><span>Daily revenue</span></h3>
  <?php
    $trendMax = 0;
    foreach ($trendPoints as $tp) $trendMax = max($trendMax, $tp['revenue']);
    if ($trendMax <= 0) $trendMax = 1;
    $svgW = 800; $svgH = 220;
    $padL = 48; $padR = 12; $padT = 12; $padB = 32;
    $plotW = $svgW - $padL - $padR;
    $plotH = $svgH - $padT - $padB;
    $n = max(1, count($trendPoints));
    $stepX = $n > 1 ? $plotW / ($n - 1) : 0;
    $pts = [];
    foreach ($trendPoints as $i => $tp) {
        $x = $padL + $i * $stepX;
        $y = $padT + $plotH - ($tp['revenue'] / $trendMax) * $plotH;
        $pts[] = [$x, $y, $tp];
    }
    // Y-axis ticks (0, 25, 50, 75, 100% of max).
    $yTicks = [];
    for ($t = 0; $t <= 4; $t++) {
        $frac = $t / 4;
        $y = $padT + $plotH - $frac * $plotH;
        $val = $trendMax * $frac;
        $yTicks[] = [$y, $val];
    }
    // X-axis labels: show ~6 evenly-spaced dates.
    $labelStep = max(1, (int)ceil($n / 6));
  ?>
  <svg class="ana-svg" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" role="img" aria-label="Daily revenue trend">
    <!-- gridlines + y ticks -->
    <?php foreach ($yTicks as [$y, $val]): ?>
      <line x1="<?= $padL ?>" y1="<?= $y ?>" x2="<?= $svgW - $padR ?>" y2="<?= $y ?>"
            stroke="var(--ana-grid)" stroke-width="1"/>
      <text x="<?= $padL - 6 ?>" y="<?= $y + 3 ?>" font-size="10" fill="var(--ana-muted)" text-anchor="end">
        <?= e($currency) ?> <?= number_format($val, 0) ?>
      </text>
    <?php endforeach; ?>
    <!-- area fill -->
    <?php if (count($pts) > 1):
      $areaD = 'M ' . $pts[0][0] . ' ' . ($padT + $plotH);
      foreach ($pts as [$x, $y]) $areaD .= ' L ' . $x . ' ' . $y;
      $areaD .= ' L ' . end($pts)[0] . ' ' . ($padT + $plotH) . ' Z';
    ?>
      <path d="<?= $areaD ?>" fill="var(--ana-primary)" fill-opacity="0.12"/>
    <?php endif; ?>
    <!-- line -->
    <?php if (count($pts) > 1):
      $lineD = 'M ' . $pts[0][0] . ' ' . $pts[0][1];
      for ($k = 1; $k < count($pts); $k++) $lineD .= ' L ' . $pts[$k][0] . ' ' . $pts[$k][1];
    ?>
      <path d="<?= $lineD ?>" fill="none" stroke="var(--ana-primary)" stroke-width="2"/>
    <?php endif; ?>
    <!-- points + hover titles -->
    <?php foreach ($pts as $i => [$x, $y, $tp]): ?>
      <circle cx="<?= $x ?>" cy="<?= $y ?>" r="2.5" fill="var(--ana-primary)">
        <title><?= e((string)$tp['d']) ?>: <?= $fmtRev($tp['revenue']) ?> · <?= (int)$tp['orders'] ?> orders</title>
      </circle>
      <?php if ($i % $labelStep === 0 || $i === $n - 1): ?>
        <text x="<?= $x ?>" y="<?= $svgH - 10 ?>" font-size="10" fill="var(--ana-muted)" text-anchor="middle">
          <?= e((new DateTimeImmutable((string)$tp['d']))->format('j M')) ?>
        </text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <div class="ana-legend">
    <span><span class="sw" style="background: var(--ana-primary);"></span>Revenue (hover a point for order count)</span>
  </div>
</div>

<!-- PEAK HOURS -->
<div class="ana-card">
  <h3><span>Peak hours (orders per hour of day)</span></h3>
  <?php
    $hMax = 0;
    foreach ($hourBars as $hb) $hMax = max($hMax, $hb['orders']);
    if ($hMax <= 0) $hMax = 1;
    $svgW2 = 800; $svgH2 = 180;
    $padL2 = 40; $padR2 = 8; $padT2 = 8; $padB2 = 30;
    $plotW2 = $svgW2 - $padL2 - $padR2;
    $plotH2 = $svgH2 - $padT2 - $padB2;
    $barW = $plotW2 / 24 * 0.7;
    $gap  = $plotW2 / 24 * 0.3;
  ?>
  <svg class="ana-svg" viewBox="0 0 <?= $svgW2 ?> <?= $svgH2 ?>" role="img" aria-label="Orders per hour of day">
    <?php for ($tk = 0; $tk <= 4; $tk++):
      $frac = $tk / 4; $y = $padT2 + $plotH2 - $frac * $plotH2; $val = $hMax * $frac; ?>
      <line x1="<?= $padL2 ?>" y1="<?= $y ?>" x2="<?= $svgW2 - $padR2 ?>" y2="<?= $y ?>"
            stroke="var(--ana-grid)" stroke-width="1"/>
      <text x="<?= $padL2 - 6 ?>" y="<?= $y + 3 ?>" font-size="10" fill="var(--ana-muted)" text-anchor="end">
        <?= number_format($val, 0) ?>
      </text>
    <?php endfor; ?>
    <?php foreach ($hourBars as $i => $hb):
      $x = $padL2 + $i * ($plotW2 / 24) + $gap / 2;
      $bh = ($hb['orders'] / $hMax) * $plotH2;
      $y = $padT2 + $plotH2 - $bh;
    ?>
      <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barW ?>" height="<?= $bh ?>"
            fill="var(--ana-accent)" rx="2">
        <title><?= sprintf('%02d', $i) ?>:00 — <?= (int)$hb['orders'] ?> orders</title>
      </rect>
      <?php if ($i % 3 === 0): ?>
        <text x="<?= $x + $barW / 2 ?>" y="<?= $svgH2 - 10 ?>" font-size="10" fill="var(--ana-muted)" text-anchor="middle">
          <?= sprintf('%02d', $i) ?>
        </text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
</div>

<?php layout_end(); ?>
