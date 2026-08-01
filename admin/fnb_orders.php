<?php
/**
 * F&B order dashboard — kanban across 5 statuses.
 *
 * Each column shows order cards for that status. Cards have a "→ next"
 * button that promotes the order (New → Confirmed → Processing →
 * Completed) plus a cancel button. Terminal (Completed / Cancelled)
 * columns are read-only.
 *
 * Also serves as the entry point:
 *   + New order       -> fnb_order_edit.php
 *   click any card    -> fnb_order_view.php?id=X
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('The F&B module is not enabled for this workspace.');
}

$db  = aiserve_db();
$currency = platform_setting('pricing_currency', 'RM');

// -------------------- Filter --------------------
$fSearch = trim((string)($_GET['q']         ?? ''));
$fBranch = (int)($_GET['branch_id']         ?? 0);
$fFrom   = (string)($_GET['from']           ?? '');
$fTo     = (string)($_GET['to']             ?? '');

$where  = ['o.company_id = ?'];
$params = [$companyId];
if ($fSearch !== '') {
    $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    $like = '%' . $fSearch . '%';
    array_push($params, $like, $like, $like);
}
if ($fBranch > 0) { $where[] = 'o.branch_id = ?'; $params[] = $fBranch; }
if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) {
    $where[] = 'o.created_at >= ?'; $params[] = $fFrom . ' 00:00:00';
}
if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) {
    $where[] = 'o.created_at <= ?'; $params[] = $fTo . ' 23:59:59';
}

$sql = 'SELECT o.*, (SELECT COUNT(*) FROM fnb_order_items WHERE order_id = o.id) AS item_count,
               b.name AS branch_name
        FROM fnb_orders o
        LEFT JOIN branches b ON b.id = o.branch_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY o.created_at DESC LIMIT 400';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$byStatus = ['new' => [], 'confirmed' => [], 'processing' => [], 'completed' => [], 'cancelled' => []];
foreach ($orders as $o) $byStatus[(string)$o['status']][] = $o;

// Branches for the filter dropdown.
$branches = [];
try {
    $bs = $db->prepare('SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name');
    $bs->execute([$companyId]);
    $branches = $bs->fetchAll();
} catch (Throwable $e) {}

// Today's KPIs (regardless of filter — a manager wants the real numbers).
$today = date('Y-m-d');
$kpi = $db->prepare(
    "SELECT COUNT(*) AS orders_today,
            SUM(total) AS revenue_today,
            SUM(status = 'new')        AS new_count,
            SUM(status = 'processing') AS processing_count
     FROM fnb_orders
     WHERE company_id = ? AND DATE(created_at) = ?"
);
$kpi->execute([$companyId, $today]);
$kpi = $kpi->fetch() ?: [];

layout_start($current_user, 'F&B · Orders', 'fnb_orders');
?>

<style>
:root {
  --fo-surface: #ffffff;
  --fo-surface-2: #f6f9fb;
  --fo-ink: #0f172a;
  --fo-muted: #64748b;
  --fo-border: #e3e8ee;
}
@media (prefers-color-scheme: dark) {
  :root { --fo-surface: #0f172a; --fo-surface-2: #1e293b; --fo-ink: #f8fafc; --fo-muted: #94a3b8; --fo-border: #334155; }
}
.fo-wrap { display: grid; gap: 16px; }
.fo-kpis {
  display: grid; gap: 10px;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}
.fo-kpi {
  background: var(--fo-surface); border: 1px solid var(--fo-border);
  border-radius: 10px; padding: 12px 14px;
}
.fo-kpi .lbl { color: var(--fo-muted); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.fo-kpi .val { font-size: 22px; font-weight: 700; color: var(--fo-ink); }

.fo-filter {
  background: var(--fo-surface); border: 1px solid var(--fo-border); border-radius: 10px;
  padding: 12px; display: grid; gap: 8px;
  grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
  align-items: end;
}
.fo-filter label { font-size: 11px; color: var(--fo-muted); display: block; }
.fo-filter input, .fo-filter select { width: 100%; padding: 6px 8px; margin-top: 4px; }

.fo-board {
  display: grid; gap: 10px;
  grid-template-columns: repeat(5, minmax(220px, 1fr));
  overflow-x: auto;
}
@media (max-width: 1000px) { .fo-board { grid-template-columns: repeat(3, minmax(240px, 1fr)); } }
@media (max-width: 700px)  { .fo-board { grid-template-columns: 1fr; } }

.fo-col {
  background: var(--fo-surface-2); border: 1px solid var(--fo-border);
  border-radius: 10px; padding: 10px; display: flex; flex-direction: column; gap: 8px;
  min-height: 200px;
}
.fo-col-head {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 12.5px; font-weight: 600; color: var(--fo-ink);
}
.fo-col-head .n { color: var(--fo-muted); }

.fo-card {
  background: var(--fo-surface); border: 1px solid var(--fo-border);
  border-radius: 8px; padding: 10px; display: block;
  text-decoration: none; color: inherit;
  transition: transform .1s, border-color .1s;
}
.fo-card:hover { transform: translateY(-1px); border-color: #25D366; }
.fo-card .num  { font-weight: 700; font-size: 13px; color: var(--fo-ink); }
.fo-card .name { font-size: 13px; margin-top: 2px; }
.fo-card .meta { color: var(--fo-muted); font-size: 11.5px; margin-top: 4px; display: flex; justify-content: space-between; }
.fo-card .type-badge {
  display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 4px;
  background: #f1f5f9; color: #334155;
}
.fo-card-actions {
  display: flex; gap: 4px; margin-top: 8px; padding-top: 8px;
  border-top: 1px solid #f1f5f9;
}
.fo-card-actions form { display: inline; flex: 1; }
.fo-card-actions button {
  width: 100%; font-size: 11.5px; padding: 4px 6px;
  border: 1px solid var(--fo-border); background: transparent; border-radius: 4px;
  color: var(--fo-ink); cursor: pointer;
}
.fo-card-actions .promote { background: #25D366; color: #fff; border-color: #25D366; }
.fo-card-actions .cancel  { color: #DC2626; }

.fo-empty { color: var(--fo-muted); font-size: 12px; text-align: center; padding: 12px 4px; font-style: italic; }
</style>

<div class="fo-wrap">

  <!-- KPI row -->
  <div class="fo-kpis">
    <div class="fo-kpi">
      <div class="lbl">Orders today</div>
      <div class="val"><?= (int)($kpi['orders_today'] ?? 0) ?></div>
    </div>
    <div class="fo-kpi">
      <div class="lbl">Revenue today</div>
      <div class="val"><?= e($currency) ?> <?= number_format((float)($kpi['revenue_today'] ?? 0), 2) ?></div>
    </div>
    <div class="fo-kpi">
      <div class="lbl">New</div>
      <div class="val" style="color: #2563EB;"><?= (int)($kpi['new_count'] ?? 0) ?></div>
    </div>
    <div class="fo-kpi">
      <div class="lbl">Processing</div>
      <div class="val" style="color: #F59E0B;"><?= (int)($kpi['processing_count'] ?? 0) ?></div>
    </div>
    <a class="fo-kpi" href="/admin/fnb_order_edit.php"
       style="text-align:center; text-decoration:none; color:inherit; display:flex; flex-direction:column; justify-content:center; background:#25D366; color:#fff; border-color:#25D366;">
      <div style="font-weight:700; font-size:14px;">+ New order</div>
      <div class="lbl" style="color:rgba(255,255,255,.8);">Manual entry</div>
    </a>
  </div>

  <!-- Filter -->
  <form method="get" class="fo-filter">
    <label>Search
      <input type="search" name="q" value="<?= e($fSearch) ?>" placeholder="Order #, name, phone…">
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
    <?php else: ?><span></span><?php endif; ?>
    <label>From <input type="date" name="from" value="<?= e($fFrom) ?>"></label>
    <label>To   <input type="date" name="to"   value="<?= e($fTo) ?>"></label>
    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
    <a class="btn btn-sm" href="/admin/fnb_orders.php">Clear</a>
  </form>

  <!-- Kanban board -->
  <div class="fo-board">
    <?php
      $columns = [
        'new'        => ['New',        '#2563EB'],
        'confirmed'  => ['Confirmed',  '#9333EA'],
        'processing' => ['Processing', '#F59E0B'],
        'completed'  => ['Completed',  '#16A34A'],
        'cancelled'  => ['Cancelled',  '#94A3B8'],
      ];
      foreach ($columns as $status => [$label, $color]):
        $list = $byStatus[$status] ?? [];
    ?>
      <div class="fo-col">
        <div class="fo-col-head">
          <span style="color: <?= $color ?>;">● <?= e($label) ?></span>
          <span class="n"><?= count($list) ?></span>
        </div>
        <?php if (!$list): ?>
          <div class="fo-empty">No orders here.</div>
        <?php endif; ?>
        <?php foreach ($list as $o):
          $next = fnb_next_status((string)$o['status']);
        ?>
          <a class="fo-card" href="/admin/fnb_order_view.php?id=<?= (int)$o['id'] ?>">
            <div class="num"><?= e($o['order_number']) ?>
              <span class="type-badge"><?= e($o['order_type']) ?></span>
            </div>
            <div class="name"><?= e($o['customer_name']) ?></div>
            <div class="meta">
              <span>
                <?= (int)$o['item_count'] ?> item<?= (int)$o['item_count'] === 1 ? '' : 's' ?>
                · <?= e($currency) ?> <?= number_format((float)$o['total'], 2) ?>
              </span>
              <span><?= e(relative_time($o['created_at'])) ?></span>
            </div>
            <?php if (!empty($o['branch_name'])): ?>
              <div style="color: var(--fo-muted); font-size: 11px; margin-top: 3px;">🏢 <?= e($o['branch_name']) ?></div>
            <?php endif; ?>

            <?php if ($status !== 'completed' && $status !== 'cancelled'): ?>
              <div class="fo-card-actions">
                <?php if ($next): ?>
                  <form method="post" action="/api/fnb_order_action.php" onclick="event.stopPropagation();">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="status" value="<?= e($next[0]) ?>">
                    <button class="promote" type="submit" onclick="event.stopPropagation();"><?= e($next[1]) ?></button>
                  </form>
                <?php endif; ?>
                <form method="post" action="/api/fnb_order_action.php" onclick="event.stopPropagation();"
                      onsubmit="return confirm('Cancel this order?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <input type="hidden" name="status" value="cancelled">
                  <button class="cancel" type="submit" onclick="event.stopPropagation();">Cancel</button>
                </form>
              </div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php layout_end(); ?>
