<?php
/**
 * Order detail — read-only view of a single order with:
 *   - status change buttons (forward + cancel)
 *   - print-friendly variant via ?print=1
 *   - customer's previous orders section
 *   - link back to the source WhatsApp conversation if any
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

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) redirect('/admin/fnb_orders.php');

$s = $db->prepare(
    'SELECT o.*, b.name AS branch_name, u.name AS created_by_name
     FROM fnb_orders o
     LEFT JOIN branches b ON b.id = o.branch_id
     LEFT JOIN users    u ON u.id = o.created_by_user_id
     WHERE o.id = ? AND o.company_id = ? LIMIT 1'
);
$s->execute([$orderId, $companyId]);
$order = $s->fetch();
if (!$order) { http_response_code(404); exit('Order not found.'); }

$items = $db->prepare('SELECT * FROM fnb_order_items WHERE order_id = ? ORDER BY sort_order, id');
$items->execute([$orderId]);
$items = $items->fetchAll();

// Previous orders from same phone (or contact) — excluding the current one.
$previousOrders = [];
if (!empty($order['customer_phone']) || !empty($order['contact_id'])) {
    $prevQ = 'SELECT id, order_number, total, status, created_at
              FROM fnb_orders
              WHERE company_id = ? AND id <> ? AND (';
    $prevParams = [$companyId, $orderId];
    $conds = [];
    if (!empty($order['customer_phone'])) { $conds[] = 'customer_phone = ?'; $prevParams[] = $order['customer_phone']; }
    if (!empty($order['contact_id']))     { $conds[] = 'contact_id = ?';     $prevParams[] = (int)$order['contact_id']; }
    $prevQ .= implode(' OR ', $conds) . ') ORDER BY created_at DESC LIMIT 10';
    $q = $db->prepare($prevQ); $q->execute($prevParams);
    $previousOrders = $q->fetchAll();
}

$printMode = !empty($_GET['print']);

if ($printMode) {
    // Print-only view — no layout, no sidebar, plain black-on-white.
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
    <html><head><meta charset="utf-8"><title><?= e($order['order_number']) ?> · <?= e($order['customer_name']) ?></title>
    <style>
      body { font-family: system-ui, sans-serif; color: #000; background: #fff; padding: 20px; max-width: 400px; margin: 0 auto; font-size: 13px; }
      h1 { font-size: 20px; margin: 0 0 4px; }
      .center { text-align: center; }
      .muted { color: #666; font-size: 11px; }
      table { width: 100%; border-collapse: collapse; margin: 10px 0; }
      th, td { padding: 6px 4px; text-align: left; border-bottom: 1px dashed #ccc; vertical-align: top; }
      th { font-size: 11px; text-transform: uppercase; color: #666; }
      .num { text-align: right; white-space: nowrap; }
      .totals { border-top: 2px solid #000; margin-top: 8px; padding-top: 8px; }
      .totals .row { display: flex; justify-content: space-between; padding: 2px 0; }
      .totals .grand { font-size: 16px; font-weight: 700; border-top: 1px solid #000; padding-top: 4px; margin-top: 4px; }
      .sub-list { list-style: none; padding: 0; margin: 4px 0 0; }
      .sub-list li { font-size: 11px; color: #444; }
      @media print { .no-print { display: none !important; } body { padding: 0; } }
    </style></head><body>
      <div class="center">
        <h1><?= e($order['order_number']) ?></h1>
        <div class="muted"><?= e(fmt_dt($order['created_at'])) ?></div>
        <?php if (!empty($order['branch_name'])): ?><div class="muted">Branch: <?= e($order['branch_name']) ?></div><?php endif; ?>
      </div>
      <hr>
      <div>
        <strong><?= e($order['customer_name']) ?></strong><br>
        <?php if (!empty($order['customer_phone'])): ?>Phone: <?= e($order['customer_phone']) ?><br><?php endif; ?>
        Type: <?= $order['order_type'] === 'dine_in' ? '🍽 Dine-in' : e(ucfirst(str_replace('_', ' ', (string)$order['order_type']))) ?><br>
        <?php if ($order['order_type'] === 'delivery' && !empty($order['delivery_address'])): ?>
          Address: <?= nl2br(e($order['delivery_address'])) ?><br>
        <?php endif; ?>
        <?php if ($order['order_type'] === 'pickup' && !empty($order['pickup_time'])): ?>
          Pickup: <?= e(fmt_dt($order['pickup_time'])) ?><br>
        <?php endif; ?>
        <?php if ($order['order_type'] === 'dine_in' && !empty($order['delivery_notes'])): ?>
          🍽 <strong><?= e($order['delivery_notes']) ?></strong><br>
        <?php endif; ?>
        <?php if (!empty($order['delivery_notes'])): ?>Notes: <?= e($order['delivery_notes']) ?><br><?php endif; ?>
      </div>
      <table>
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Line</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it):
            $variants = json_decode((string)$it['variants_json'], true) ?: [];
            $addons   = json_decode((string)$it['addons_json'],   true) ?: [];
          ?>
            <tr>
              <td>
                <?= e($it['product_name']) ?>
                <?php if ($variants || $addons || $it['instructions']): ?>
                  <ul class="sub-list">
                    <?php foreach ($variants as $v): ?>
                      <li>- <?= e($v['group'] ?? '') ?>: <strong><?= e($v['name'] ?? '') ?></strong></li>
                    <?php endforeach; ?>
                    <?php foreach ($addons as $a): ?>
                      <li>+ <?= e($a['name'] ?? '') ?></li>
                    <?php endforeach; ?>
                    <?php if ($it['instructions']): ?><li><em><?= e($it['instructions']) ?></em></li><?php endif; ?>
                  </ul>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int)$it['quantity'] ?></td>
              <td class="num"><?= e($currency) ?> <?= number_format((float)$it['line_total'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="totals">
        <div class="row"><span>Subtotal</span><span><?= e($currency) ?> <?= number_format((float)$order['subtotal'], 2) ?></span></div>
        <?php if ((float)$order['delivery_fee'] > 0): ?>
          <div class="row"><span>Delivery fee</span><span><?= e($currency) ?> <?= number_format((float)$order['delivery_fee'], 2) ?></span></div>
        <?php endif; ?>
        <div class="row grand"><span>TOTAL</span><span><?= e($currency) ?> <?= number_format((float)$order['total'], 2) ?></span></div>
      </div>
      <p class="center muted" style="margin-top: 20px;">Thank you.</p>
      <div class="no-print center" style="margin-top: 16px;">
        <button onclick="window.print()">Print</button>
        <a href="/admin/fnb_order_view.php?id=<?= $orderId ?>">Back</a>
      </div>
      <script>window.print();</script>
    </body></html>
    <?php
    exit;
}

layout_start($current_user, 'Order ' . $order['order_number'], 'fnb_orders');
?>

<style>
.ov-grid { display: grid; gap: 16px; grid-template-columns: 2fr 1fr; }
@media (max-width: 900px) { .ov-grid { grid-template-columns: 1fr; } }
.ov-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 16px; }
.ov-card h3 { margin: 0 0 12px; font-size: 12.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
.ov-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
.ov-num { font-size: 28px; font-weight: 700; letter-spacing: .02em; }
.ov-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.kv { display: grid; grid-template-columns: 120px 1fr; gap: 6px; font-size: 13px; margin-bottom: 4px; }
.kv .k { color: #64748b; }
.ov-items table { width: 100%; border-collapse: collapse; }
.ov-items th, .ov-items td { padding: 8px 6px; border-bottom: 1px solid #f1f5f9; text-align: left; font-size: 13px; vertical-align: top; }
.ov-items th { color: #64748b; font-weight: 500; font-size: 11.5px; text-transform: uppercase; }
.ov-items td.num { text-align: right; white-space: nowrap; }
.ov-sub-line { color: #64748b; font-size: 11.5px; margin-top: 2px; }
.ov-totals { text-align: right; padding: 12px; background: #f6f9fb; border-radius: 8px; margin-top: 12px; }
.ov-totals .row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 14px; }
.ov-totals .grand { font-size: 18px; font-weight: 700; padding-top: 6px; margin-top: 6px; border-top: 1px solid #e3e8ee; }
</style>

<div class="ov-card">
  <div class="ov-header">
    <div>
      <div class="ov-num"><?= e($order['order_number']) ?></div>
      <div style="margin-top: 4px;"><?= fnb_status_label($order['status']) ?>
        <span class="muted small">· <?= e(fmt_dt($order['created_at'])) ?></span>
      </div>
    </div>
    <div class="ov-actions">
      <?php $next = fnb_next_status((string)$order['status']); ?>
      <?php if ($next): ?>
        <form method="post" action="/api/fnb_order_action.php" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="order_id" value="<?= $orderId ?>">
          <input type="hidden" name="status" value="<?= e($next[0]) ?>">
          <input type="hidden" name="redirect" value="view">
          <button class="btn btn-primary" type="submit"><?= e($next[1]) ?></button>
        </form>
      <?php endif; ?>
      <?php if (!in_array($order['status'], ['completed','cancelled'], true)): ?>
        <form method="post" action="/api/fnb_order_action.php" style="display:inline;"
              onsubmit="return confirm('Cancel this order?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="order_id" value="<?= $orderId ?>">
          <input type="hidden" name="status" value="cancelled">
          <input type="hidden" name="redirect" value="view">
          <button class="btn btn-danger" type="submit">Cancel order</button>
        </form>
      <?php endif; ?>
      <a class="btn" href="/admin/fnb_order_view.php?id=<?= $orderId ?>&print=1" target="_blank">🖨️ Print</a>
      <a class="btn" href="/admin/fnb_orders.php">← Back</a>
    </div>
  </div>
</div>

<div class="ov-grid" style="margin-top:16px;">
  <!-- LEFT: items + totals -->
  <div class="ov-card ov-items">
    <h3>Items</h3>
    <table>
      <thead>
        <tr>
          <th>Item</th>
          <th class="num">Qty</th>
          <th class="num">Unit</th>
          <th class="num">Line total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it):
          $variants = json_decode((string)$it['variants_json'], true) ?: [];
          $addons   = json_decode((string)$it['addons_json'],   true) ?: [];
        ?>
          <tr>
            <td>
              <strong><?= e($it['product_name']) ?></strong>
              <?php foreach ($variants as $v): ?>
                <div class="ov-sub-line">◦ <?= e($v['group'] ?? '') ?>: <strong><?= e($v['name'] ?? '') ?></strong>
                  <?php if (!empty($v['price_delta']) && (float)$v['price_delta'] !== 0.0): ?>
                    <span class="muted small">(+ <?= e($currency) ?> <?= number_format((float)$v['price_delta'], 2) ?>)</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php foreach ($addons as $a): ?>
                <div class="ov-sub-line">+ <?= e($a['name'] ?? '') ?>
                  <?php if (!empty($a['price_delta']) && (float)$a['price_delta'] !== 0.0): ?>
                    <span class="muted small">(+ <?= e($currency) ?> <?= number_format((float)$a['price_delta'], 2) ?>)</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if (!empty($it['instructions'])): ?>
                <div class="ov-sub-line" style="color: #78350F; margin-top: 4px;">📝 <?= e($it['instructions']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int)$it['quantity'] ?></td>
            <td class="num muted"><?= e($currency) ?> <?= number_format((float)$it['unit_price'], 2) ?></td>
            <td class="num"><?= e($currency) ?> <?= number_format((float)$it['line_total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="ov-totals">
      <div class="row"><span>Subtotal</span><span><?= e($currency) ?> <?= number_format((float)$order['subtotal'], 2) ?></span></div>
      <?php if ((float)$order['delivery_fee'] > 0): ?>
        <div class="row"><span>Delivery fee</span><span><?= e($currency) ?> <?= number_format((float)$order['delivery_fee'], 2) ?></span></div>
      <?php endif; ?>
      <div class="row grand"><span>Total</span><span><?= e($currency) ?> <?= number_format((float)$order['total'], 2) ?></span></div>
    </div>
    <?php if (!empty($order['notes'])): ?>
      <div style="margin-top: 12px; padding: 10px 12px; background: #FEF3C7; border-radius: 8px; color: #78350F;">
        <strong>Internal notes:</strong> <?= nl2br(e($order['notes'])) ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: customer + conversation link + previous orders -->
  <div>
    <div class="ov-card">
      <h3>Customer</h3>
      <div class="kv"><span class="k">Name</span><strong><?= e($order['customer_name']) ?></strong></div>
      <?php if (!empty($order['customer_phone'])): ?>
        <div class="kv"><span class="k">Phone</span><strong>+<?= e(ltrim($order['customer_phone'], '+')) ?></strong></div>
      <?php endif; ?>
      <div class="kv"><span class="k">Type</span><strong><?= $order['order_type'] === 'dine_in' ? '🍽 Dine-in' : e(ucfirst(str_replace('_', ' ', (string)$order['order_type']))) ?></strong></div>
      <?php if ($order['order_type'] === 'delivery' && !empty($order['delivery_address'])): ?>
        <div class="kv"><span class="k">Address</span><strong><?= nl2br(e($order['delivery_address'])) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($order['delivery_notes'])): ?>
        <div class="kv"><span class="k">Notes</span><strong><?= e($order['delivery_notes']) ?></strong></div>
      <?php endif; ?>
      <?php if ($order['order_type'] === 'pickup' && !empty($order['pickup_time'])): ?>
        <div class="kv"><span class="k">Pickup</span><strong><?= e(fmt_dt($order['pickup_time'])) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($order['branch_name'])): ?>
        <div class="kv"><span class="k">Branch</span><strong><?= e($order['branch_name']) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($order['created_by_name'])): ?>
        <div class="kv"><span class="k">Created by</span><strong><?= e($order['created_by_name']) ?></strong></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($order['conversation_id'])): ?>
      <div class="ov-card" style="margin-top: 16px;">
        <h3>Source</h3>
        <p class="muted small">This order came from a WhatsApp conversation.</p>
        <a class="btn" href="/inbox/chat.php?id=<?= (int)$order['conversation_id'] ?>">→ Open conversation</a>
      </div>
    <?php endif; ?>

    <?php if ($previousOrders): ?>
      <div class="ov-card" style="margin-top: 16px;">
        <h3>Previous orders <small class="muted">(<?= count($previousOrders) ?>)</small></h3>
        <table style="width:100%; font-size:12px;">
          <?php foreach ($previousOrders as $p): ?>
            <tr>
              <td><a href="/admin/fnb_order_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['order_number']) ?></a></td>
              <td><?= e($currency) ?> <?= number_format((float)$p['total'], 2) ?></td>
              <td><?= fnb_status_label($p['status']) ?></td>
              <td class="muted small"><?= e(relative_time($p['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php layout_end(); ?>
