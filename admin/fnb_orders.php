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

/* Live-arrival flash — new order cards ease in with a soft green
   ring pulse so operators notice them at a glance. Auto-fades after
   a few seconds. */
@keyframes fo-flash-in {
  0%   { transform: scale(.94); opacity: 0; box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
  15%  { transform: scale(1);   opacity: 1; box-shadow: 0 0 0 6px rgba(37, 211, 102, .35); }
  100% { transform: scale(1);   opacity: 1; box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
}
.fo-card.fo-new { animation: fo-flash-in 2.2s ease-out; border-color: #25D366; }

/* Floating toast for each new arrival — bottom-right, stacks upward,
   auto-dismisses after 6 s or on click. Never blocks pointer events
   on the underlying board. */
.fo-toaster {
  position: fixed; right: 16px; bottom: 16px;
  display: flex; flex-direction: column; gap: 8px;
  z-index: 9999; pointer-events: none;
  max-width: min(360px, calc(100vw - 32px));
}
.fo-toast {
  pointer-events: auto; cursor: pointer;
  background: #0f172a; color: #f8fafc;
  border-radius: 10px; padding: 12px 14px;
  box-shadow: 0 10px 30px rgba(0,0,0,.25);
  display: flex; gap: 10px; align-items: center;
  animation: fo-toast-in .25s ease-out;
  font-size: 13.5px; line-height: 1.35;
}
.fo-toast .ic { font-size: 22px; line-height: 1; }
.fo-toast .body { flex: 1; }
.fo-toast .body strong { color: #fff; display: block; margin-bottom: 2px; }
.fo-toast .body .sub { color: #cbd5e1; font-size: 12px; }
.fo-toast .close { color: #64748b; padding: 0 4px; }
@keyframes fo-toast-in {
  from { transform: translateY(20px); opacity: 0; }
  to   { transform: none;              opacity: 1; }
}

/* Header "auto-refresh: on" indicator so the operator knows the
   page is watching for new orders (and can pause it if they need
   to). Small, subtle, sits by the "+ New order" tile. */
.fo-live-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px 10px; border-radius: 999px;
  background: #dcfce7; color: #14532d; font-size: 11.5px; font-weight: 600;
  cursor: pointer; user-select: none;
}
.fo-live-chip.off { background: #f1f5f9; color: #64748b; }
.fo-live-chip .dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #16A34A; animation: fo-pulse 1.6s ease-in-out infinite;
}
.fo-live-chip.off .dot { background: #94a3b8; animation: none; }
@keyframes fo-pulse {
  0%,100% { opacity: 1; }
  50%     { opacity: .35; }
}
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

  <!-- Live-refresh chip. Click to pause / resume; state persists per
       operator via localStorage so if they were paused yesterday they
       stay paused today. Sits above the filter row so it's visible
       without scrolling. -->
  <div style="display:flex; justify-content:flex-end; margin: -4px 0 6px 0;">
    <span class="fo-live-chip" id="fo-live-chip" title="Auto-refresh is on — new orders appear without a page reload. Click to pause.">
      <span class="dot"></span>
      <span class="txt">Live · watching for new orders</span>
    </span>
  </div>

  <!-- Chat-order missing? Direct link to the diagnostic page — cheaper
       than pinging support with "the order I placed on chat isn't showing." -->
  <div class="muted small" style="margin: -4px 0 12px 0;">
    Expecting a chat order that hasn't shown up?
    <a href="/admin/fnb_flow_debug.php">🔍 F&amp;B chat-order debug</a>
    lists every recent flow instance and why each did or didn't create an order.
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
      <div class="fo-col" data-status="<?= e($status) ?>" data-color="<?= e($color) ?>">
        <div class="fo-col-head">
          <span style="color: <?= $color ?>;">● <?= e($label) ?></span>
          <span class="n" data-count><?= count($list) ?></span>
        </div>
        <?php if (!$list): ?>
          <div class="fo-empty">No orders here.</div>
        <?php endif; ?>
        <?php foreach ($list as $o):
          $next = fnb_next_status((string)$o['status']);
        ?>
          <a class="fo-card" href="/admin/fnb_order_view.php?id=<?= (int)$o['id'] ?>"
             data-order-id="<?= (int)$o['id'] ?>">
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

<!-- Toast anchor — populated by fo-live.js below. -->
<div class="fo-toaster" id="fo-toaster" aria-live="polite"></div>

<script>
(function () {
  'use strict';

  // --------------------------------------------------
  // Live-refresh loop for the F&B orders kanban.
  //
  // Polls /api/fnb_orders_ping.php every POLL_MS while the tab is
  // visible. New orders are injected into their status column with a
  // ring-flash animation + a bottom-right toast + a subtle chime.
  // The operator's active branch filter (if any) is passed through
  // so a Kepong-only view doesn't get flooded with Ampang orders.
  //
  // Everything is idempotent: if a card with matching data-order-id
  // is already on the page (e.g. we polled twice in flight), we skip.
  // --------------------------------------------------

  const POLL_MS   = 15000;
  const PAUSE_KEY = 'fo_live_paused';
  const currency  = <?= json_encode($currency) ?>;
  const branchId  = <?= json_encode($fBranch > 0 ? (int)$fBranch : 0) ?>;

  const board    = document.querySelector('.fo-board');
  const toaster  = document.getElementById('fo-toaster');
  const chip     = document.getElementById('fo-live-chip');
  if (!board || !toaster) return;

  // Read the highest order id currently rendered — becomes our "since"
  // cursor. Falls back to 0 if the board is empty (fresh workspace).
  let sinceId = 0;
  document.querySelectorAll('.fo-card[data-order-id]').forEach(el => {
    const id = parseInt(el.getAttribute('data-order-id'), 10);
    if (id > sinceId) sinceId = id;
  });

  let polling = false;
  let paused  = false;
  try { paused = localStorage.getItem(PAUSE_KEY) === '1'; } catch (e) {}
  syncChip();

  function syncChip() {
    if (!chip) return;
    if (paused) {
      chip.classList.add('off');
      chip.querySelector('.txt').textContent = 'Paused · click to resume';
      chip.title = 'Auto-refresh is paused. Click to resume.';
    } else {
      chip.classList.remove('off');
      chip.querySelector('.txt').textContent = 'Live · watching for new orders';
      chip.title = 'Auto-refresh is on — new orders appear without a page reload. Click to pause.';
    }
  }
  chip && chip.addEventListener('click', function () {
    paused = !paused;
    try { localStorage.setItem(PAUSE_KEY, paused ? '1' : '0'); } catch (e) {}
    syncChip();
    if (!paused) tick();
  });

  async function tick() {
    if (polling || paused || document.hidden) return;
    polling = true;
    try {
      const qs = new URLSearchParams({ since: String(sinceId) });
      if (branchId > 0) qs.append('branch_id', String(branchId));
      const res  = await fetch('/api/fnb_orders_ping.php?' + qs.toString(),
                               { credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok && Array.isArray(data.orders)) {
        for (const o of data.orders) {
          if (!document.querySelector('.fo-card[data-order-id="' + o.id + '"]')) {
            injectCard(o);
            toast(o);
          }
        }
        if (data.orders.length) {
          chime();
          bumpKpis(data.orders.length);
        }
        if (data.latest_id > sinceId) sinceId = data.latest_id;
      }
    } catch (e) { /* stay silent; the next tick retries */ }
    finally { polling = false; }
  }

  function injectCard(o) {
    const col = board.querySelector('.fo-col[data-status="' + o.status + '"]');
    if (!col) return;
    // Drop the "No orders here." placeholder if this is the first arrival.
    const empty = col.querySelector('.fo-empty');
    if (empty) empty.remove();

    const card = document.createElement('a');
    card.className = 'fo-card fo-new';
    card.href = '/admin/fnb_order_view.php?id=' + o.id;
    card.setAttribute('data-order-id', String(o.id));

    const items = o.item_count === 1 ? 'item' : 'items';
    const branchLine = o.branch_name
      ? '<div style="color:var(--fo-muted);font-size:11px;margin-top:3px;">🏢 ' + escapeHtml(o.branch_name) + '</div>'
      : '';
    card.innerHTML =
        '<div class="num">' + escapeHtml(o.order_number)
      + '<span class="type-badge">' + escapeHtml(o.order_type) + '</span></div>'
      + '<div class="name">' + escapeHtml(o.customer_name) + '</div>'
      + '<div class="meta">'
      +   '<span>' + o.item_count + ' ' + items + ' · '
      +     escapeHtml(currency) + ' ' + Number(o.total).toFixed(2) + '</span>'
      +   '<span>just now</span>'
      + '</div>'
      + branchLine;

    // Prepend so newest sits at the top of the column, matching
    // the server-side ORDER BY created_at DESC.
    // Skip the col-head + placeholder — insert after the head.
    const head = col.querySelector('.fo-col-head');
    if (head && head.nextSibling) {
      col.insertBefore(card, head.nextSibling);
    } else {
      col.appendChild(card);
    }
    // Bump the column count badge.
    const cnt = col.querySelector('[data-count]');
    if (cnt) cnt.textContent = String(parseInt(cnt.textContent || '0', 10) + 1);
  }

  function toast(o) {
    const el = document.createElement('div');
    el.className = 'fo-toast';
    el.innerHTML =
        '<div class="ic">🔔</div>'
      + '<div class="body">'
      +   '<strong>' + escapeHtml(o.order_number) + ' · '
      +      escapeHtml(currency) + ' ' + Number(o.total).toFixed(2) + '</strong>'
      +   '<span class="sub">' + escapeHtml(o.customer_name)
      +     ' · ' + escapeHtml(o.order_type) + ' · click to open</span>'
      + '</div>'
      + '<div class="close">✕</div>';
    el.addEventListener('click', function (e) {
      if (e.target.classList.contains('close')) { el.remove(); return; }
      location.href = '/admin/fnb_order_view.php?id=' + o.id;
    });
    toaster.appendChild(el);
    setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; }, 6000);
    setTimeout(() => el.remove(), 6600);
  }

  // Cheap two-tone chime via WebAudio — no asset load, no external
  // dep. Skipped if the browser doesn't grant an AudioContext
  // (some mobile browsers require a prior user gesture).
  function chime() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const now = ctx.currentTime;
      [880, 1320].forEach((freq, i) => {
        const osc = ctx.createOscillator();
        const g   = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        osc.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, now + i * 0.18);
        g.gain.exponentialRampToValueAtTime(0.12, now + i * 0.18 + 0.01);
        g.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.18 + 0.22);
        osc.start(now + i * 0.18);
        osc.stop(now + i * 0.18 + 0.24);
      });
      setTimeout(() => ctx.close(), 600);
    } catch (e) { /* silence is golden */ }
  }

  // Nudge the "Orders today" + "Revenue today" tiles on arrival so
  // the operator sees the KPI move too, not just the column.
  function bumpKpis(n) {
    const tiles = document.querySelectorAll('.fo-kpi .val');
    if (!tiles || !tiles.length) return;
    const first = tiles[0];
    const cur = parseInt((first.textContent || '0').replace(/[^\d]/g, ''), 10);
    if (!isNaN(cur)) first.textContent = String(cur + n);
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[c]);
  }

  // Kick off. Also refresh on tab-focus / visibility-change so the
  // operator switching back to the tab sees any missed arrivals.
  const timer = setInterval(tick, POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
  window.addEventListener('focus', tick);
})();
</script>

<?php layout_end(); ?>
