<?php
/**
 * /admin/fnb_flow_debug.php — F&B chat-order debug page.
 *
 * Answers "why didn't the order I just placed via chat show up on
 * /admin/fnb_orders.php?" Lists every flow_instance for this workspace
 * that ran on a flow containing an fnb_create_order node, and for each
 * one shows:
 *
 *   - what node the instance is currently on (label + type)
 *   - a green ✓ / red ✗ tick showing whether an fnb_orders row was
 *     actually created for its conversation
 *   - the cart items + captured vars from its state JSON (so you can
 *     see the address they typed, the items they added, etc.)
 *   - a direct link to the conversation and, if created, the order
 *
 * Also flags the two most common failure modes right at the top:
 *   • "reached fnb_create_order but cart was empty" → the fnb_cart_add
 *     step didn't actually put anything into state.cart
 *   • "never reached fnb_create_order" → the flow ended before ever
 *     hitting the node (usually a wait_reply with 'end after this step'
 *     as Next node, the Node #163 trap)
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

// ---------------- Load flows that contain fnb_create_order ----------------
// Only flows carrying the create-order node type are interesting for
// debugging chat orders. Other flows won't create fnb_orders rows.
$flows = $db->prepare(
    'SELECT DISTINCT f.id, f.name
     FROM flows f
     INNER JOIN flow_nodes fn ON fn.flow_id = f.id
     WHERE f.company_id = ? AND fn.node_type = "fnb_create_order"
     ORDER BY f.name ASC'
);
$flows->execute([$companyId]);
$flows = $flows->fetchAll();

$flowIds = array_map(fn($f) => (int)$f['id'], $flows);

// ---------------- Load recent flow_instances on those flows ----------------
$rows = [];
if ($flowIds) {
    $ph = implode(',', array_fill(0, count($flowIds), '?'));
    $stmt = $db->prepare(
        "SELECT fi.id, fi.flow_id, fi.conversation_id, fi.current_node_id,
                fi.status, fi.state, fi.error_message, fi.waiting_since,
                fi.completed_at, fi.created_at, fi.updated_at,
                f.name AS flow_name,
                fn.node_type AS current_node_type, fn.label AS current_node_label,
                ct.wa_id, ct.display_name AS contact_display, ct.profile_name,
                cv.channel_id
         FROM flow_instances fi
         INNER JOIN flows f ON f.id = fi.flow_id
         LEFT  JOIN flow_nodes fn ON fn.id = fi.current_node_id
         INNER JOIN conversations cv ON cv.id = fi.conversation_id
         INNER JOIN contacts     ct ON ct.id = cv.contact_id
         WHERE fi.flow_id IN ($ph)
           AND cv.company_id = ?
         ORDER BY fi.updated_at DESC
         LIMIT 100"
    );
    $params = array_merge($flowIds, [$companyId]);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// ---------------- Cross-reference fnb_orders ----------------
$convIds = array_values(array_unique(array_map(fn($r) => (int)$r['conversation_id'], $rows)));
$ordersByConv = [];
if ($convIds) {
    $ph = implode(',', array_fill(0, count($convIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, conversation_id, order_number, status, total, created_at
         FROM fnb_orders
         WHERE company_id = ? AND conversation_id IN ($ph)
         ORDER BY id DESC"
    );
    $stmt->execute(array_merge([$companyId], $convIds));
    foreach ($stmt->fetchAll() as $o) {
        $ordersByConv[(int)$o['conversation_id']][] = $o;
    }
}

// ---------------- Classify each row --------------------
// Build the "diagnosis" chip shown in the leftmost column.
$diag = [];
$stats = ['ok' => 0, 'empty_cart' => 0, 'unreached' => 0, 'waiting' => 0, 'running' => 0, 'failed' => 0];
foreach ($rows as $r) {
    $state = json_decode((string)($r['state'] ?? '{}'), true) ?: [];
    $cart  = (array)($state['cart'] ?? []);
    $vars  = (array)($state['vars'] ?? []);
    $hasOrder = isset($ordersByConv[(int)$r['conversation_id']]);
    $status   = (string)$r['status'];
    $nodeType = (string)($r['current_node_type'] ?? '');

    if ($hasOrder) {
        $d = ['level' => 'ok', 'label' => '✓ order created', 'hint' => ''];
        $stats['ok']++;
    } elseif ($status === 'waiting') {
        $d = ['level' => 'wait', 'label' => '⏸ waiting for reply', 'hint' => 'Customer hasn\'t typed the next answer yet — this is normal mid-flow.'];
        $stats['waiting']++;
    } elseif ($status === 'running') {
        $d = ['level' => 'wait', 'label' => '⏵ running', 'hint' => 'Engine is mid-walk right now, or the last walk crashed before finishing.'];
        $stats['running']++;
    } elseif ($status === 'failed') {
        $d = ['level' => 'bad', 'label' => '✗ failed', 'hint' => (string)($r['error_message'] ?? 'no error_message set')];
        $stats['failed']++;
    } elseif (in_array($status, ['completed', 'cancelled'], true)) {
        // Instance ended without an order — find the most common causes.
        if (!$cart) {
            $d = ['level' => 'bad', 'label' => '✗ never reached create_order',
                  'hint' => 'The instance ended without ever running fnb_create_order (or cart was empty when it did). Check that your wait_reply nodes are wired to the next step — a wait_reply with "Next node = end after this step" silently drops the flow.'];
            $stats['unreached']++;
        } else {
            // Reached create_order but the order didn't land. Most common
            // cause is fnb_create_order_from_flow_state returning ok=false
            // (empty cart edge case, conversation lookup failed, DB error).
            $d = ['level' => 'bad', 'label' => '✗ create_order failed',
                  'hint' => 'Cart was populated but no fnb_orders row was created. Check error_log for "[AiServe flow_engine fnb_create_order]" — the create call returned ok=false.'];
            $stats['empty_cart']++;
        }
    } else {
        $d = ['level' => 'wait', 'label' => '· ' . e($status), 'hint' => ''];
    }
    $diag[(int)$r['id']] = $d + ['cart' => $cart, 'vars' => $vars];
}

layout_start($current_user, 'F&B chat-order debug', 'fnb_flow_debug');
?>
<style>
.fd-hero { display:grid; gap:10px; grid-template-columns: repeat(5, minmax(0, 1fr)); margin-bottom:14px; }
@media (max-width: 900px) { .fd-hero { grid-template-columns: repeat(2, 1fr); } }
.fd-kpi { background:#fff; border:1px solid #e3e8ee; border-radius:10px; padding:12px 14px; }
.fd-kpi .lbl { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.fd-kpi .val { color:#0f172a; font-size:22px; font-weight:700; margin-top:2px; }
.fd-kpi .sub { color:#94a3b8; font-size:11px; margin-top:2px; }
.fd-diag { display:inline-block; padding:3px 9px; border-radius:999px; font-size:12px; font-weight:600; }
.fd-diag.ok   { background:#dcfce7; color:#14532d; }
.fd-diag.bad  { background:#fee2e2; color:#991b1b; }
.fd-diag.wait { background:#fef3c7; color:#78350f; }
.fd-hint { color:#64748b; font-size:12px; margin-top:4px; }
.fd-state { font-family: ui-monospace, Menlo, Consolas, monospace; font-size:12px;
            background:#f6f9fb; border:1px solid #e3e8ee; border-radius:6px;
            padding:6px 8px; max-height:110px; overflow:auto; white-space:pre-wrap;
            word-break:break-word; }
</style>

<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
  <div>
    <h1 style="margin:0;">🍜🔍 F&amp;B chat-order debug</h1>
    <div class="muted small">
      Every recent flow instance on a flow with an <code>fnb_create_order</code> node — with the reason each one did or didn't produce an order row.
    </div>
  </div>
  <div style="display:flex; gap:6px;">
    <a class="btn btn-sm" href="/admin/fnb_orders.php">← Orders dashboard</a>
    <a class="btn btn-sm" href="/admin/flows.php">Flows</a>
  </div>
</div>

<!-- KPI strip -->
<div class="fd-hero">
  <div class="fd-kpi">
    <div class="lbl">Order created</div>
    <div class="val" style="color:#16A34A;"><?= (int)$stats['ok'] ?></div>
    <div class="sub">instance → fnb_orders row ✓</div>
  </div>
  <div class="fd-kpi">
    <div class="lbl">Waiting on customer</div>
    <div class="val" style="color:#F59E0B;"><?= (int)$stats['waiting'] ?></div>
    <div class="sub">mid-flow, no problem</div>
  </div>
  <div class="fd-kpi">
    <div class="lbl">Never reached create_order</div>
    <div class="val" style="color:#DC2626;"><?= (int)$stats['unreached'] ?></div>
    <div class="sub">wait_reply → end trap</div>
  </div>
  <div class="fd-kpi">
    <div class="lbl">create_order returned ok=false</div>
    <div class="val" style="color:#DC2626;"><?= (int)$stats['empty_cart'] ?></div>
    <div class="sub">check error_log</div>
  </div>
  <div class="fd-kpi">
    <div class="lbl">Failed / running</div>
    <div class="val"><?= (int)$stats['failed'] + (int)$stats['running'] ?></div>
    <div class="sub"><?= (int)$stats['failed'] ?> failed · <?= (int)$stats['running'] ?> mid-walk</div>
  </div>
</div>

<?php if (!$flows): ?>
  <div class="card">
    <p><strong>No F&amp;B flow found for this workspace.</strong></p>
    <p class="muted small">
      Debug only shows instances on flows that contain an <code>fnb_create_order</code> node.
      If you already have a flow, open it and confirm at least one node has type "Create F&amp;B order".
      If you haven't built one yet, seed the branch-router template from
      <a href="/admin/flow_templates.php">Flow templates</a>.
    </p>
  </div>
<?php elseif (!$rows): ?>
  <div class="card">
    <p><strong>No flow instances yet.</strong></p>
    <p class="muted small">
      Once a customer messages the channel and triggers the flow (or a bot-first
      trigger fires) at least one row will appear here, showing you exactly where
      that instance is right now and whether an order came out.
    </p>
    <p class="muted small">
      Watching flow(s):
      <?php foreach ($flows as $f): ?>
        <a href="/admin/flow_edit.php?id=<?= (int)$f['id'] ?>"><?= e($f['name']) ?></a>
      <?php endforeach; ?>
    </p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0;">
    <table class="data-table" style="margin:0;">
      <thead>
        <tr>
          <th>Diagnosis</th>
          <th>Contact</th>
          <th>Flow</th>
          <th>Current node</th>
          <th>Cart / vars</th>
          <th>Order</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $d = $diag[(int)$r['id']];
          $name = $r['contact_display'] ?: $r['profile_name'] ?: '—';
          $orders = $ordersByConv[(int)$r['conversation_id']] ?? [];
          $cartCount = count($d['cart']);
          $cartTotal = 0.0;
          foreach ($d['cart'] as $ln) $cartTotal += (float)($ln['line_total'] ?? 0);
        ?>
          <tr>
            <td>
              <span class="fd-diag <?= e($d['level']) ?>"><?= e($d['label']) ?></span>
              <?php if ($d['hint'] !== ''): ?>
                <div class="fd-hint"><?= e($d['hint']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= e($name) ?>
              <div class="muted small"><code><?= e($r['wa_id']) ?></code></div>
              <a class="btn btn-sm" href="/inbox/chat.php?id=<?= (int)$r['conversation_id'] ?>">Open chat</a>
            </td>
            <td>
              <a href="/admin/flow_edit.php?id=<?= (int)$r['flow_id'] ?>"><?= e($r['flow_name']) ?></a>
              <div class="muted small">instance #<?= (int)$r['id'] ?></div>
            </td>
            <td>
              <?php if ($r['current_node_id']): ?>
                #<?= (int)$r['current_node_id'] ?>
                <span class="muted small">(<?= e((string)$r['current_node_type']) ?>)</span>
                <?php if ($r['current_node_label']): ?>
                  <div class="muted small"><?= e((string)$r['current_node_label']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted small">— (ended)</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($cartCount > 0): ?>
                <strong><?= $cartCount ?> item(s)</strong> · <?= e($currency) ?> <?= number_format($cartTotal, 2) ?>
              <?php else: ?>
                <span class="muted small">cart empty</span>
              <?php endif; ?>
              <?php if ($d['vars']): ?>
                <details style="margin-top:4px;">
                  <summary class="muted small" style="cursor:pointer;">state (<?= count($d['vars']) ?> vars)</summary>
                  <div class="fd-state"><?= e(json_encode(['cart' => $d['cart'], 'vars' => $d['vars']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
                </details>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($orders): ?>
                <?php foreach ($orders as $o): ?>
                  <a href="/admin/fnb_order_view.php?id=<?= (int)$o['id'] ?>">
                    <strong><?= e($o['order_number'] ?: '#' . $o['id']) ?></strong>
                  </a>
                  <div class="muted small">
                    <?= e($currency) ?> <?= number_format((float)$o['total'], 2) ?>
                    · <?= e((string)$o['status']) ?>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="muted small">no fnb_orders row for this conversation</span>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= e(fmt_dt($r['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php layout_end(); ?>
