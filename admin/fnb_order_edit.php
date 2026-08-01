<?php
/**
 * Manual order entry — the "phone order" flow.
 *
 * Two-column layout:
 *   LEFT: menu grid — active products grouped by category. Click a
 *         product to open a small config sheet (variants + addons +
 *         qty + instructions) that pushes a line into the cart.
 *   RIGHT: customer info form + cart (line list) + totals + Save.
 *
 * On submit the whole state (customer + cart JSON) POSTs back here.
 * We compute totals server-side, insert fnb_orders + fnb_order_items,
 * stamp order_number, and redirect to the view page.
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

$err = '';

// -------------------- POST --------------------
if (is_post()) {
    csrf_check();

    $orderType    = (string)($_POST['order_type']    ?? 'delivery');
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $customerPhone= trim((string)($_POST['customer_phone'] ?? ''));
    $address      = trim((string)($_POST['delivery_address'] ?? ''));
    $deliveryNotes= trim((string)($_POST['delivery_notes'] ?? ''));
    $pickupTime   = trim((string)($_POST['pickup_time'] ?? ''));
    $branchId     = (int)($_POST['branch_id']        ?? 0);
    $notes        = trim((string)($_POST['notes']    ?? ''));
    $deliveryFee  = (float)($_POST['delivery_fee']   ?? 0);
    $cartJson     = (string)($_POST['cart']          ?? '[]');
    $cart         = json_decode($cartJson, true);

    if (!is_array($cart) || !$cart) $err = 'Add at least one item to the order.';
    elseif (!in_array($orderType, ['delivery','pickup'], true)) $err = 'Pick delivery or pickup.';
    elseif ($customerName === '') $err = 'Customer name is required.';
    elseif ($orderType === 'delivery' && $address === '') $err = 'Delivery address is required.';

    // Validate branch belongs to workspace, if picked.
    if (!$err && $branchId > 0) {
        $c = $db->prepare('SELECT id FROM branches WHERE id = ? AND company_id = ?');
        $c->execute([$branchId, $companyId]);
        if (!$c->fetchColumn()) $err = 'Branch not found.';
    }

    // Validate + look up every cart item against real products so a
    // tampered POST can't inject arbitrary prices.
    $lines = [];
    if (!$err) {
        $subtotal = 0.0;
        foreach ($cart as $ln) {
            $productId = (int)($ln['product_id'] ?? 0);
            $qty       = max(1, min(999, (int)($ln['quantity'] ?? 1)));
            $variants  = is_array($ln['variants'] ?? null) ? $ln['variants'] : [];
            $addons    = is_array($ln['addons']   ?? null) ? $ln['addons']   : [];
            $instr     = trim((string)($ln['instructions'] ?? ''));

            if ($productId <= 0) { $err = 'Bad cart item.'; break; }

            $p = $db->prepare('SELECT * FROM fnb_products WHERE id = ? AND company_id = ? LIMIT 1');
            $p->execute([$productId, $companyId]);
            $prod = $p->fetch();
            if (!$prod) { $err = 'Product not found for a cart line.'; break; }

            // Look up variants + addons from the DB (never trust POSTed
            // price_delta values). We match by id from the cart.
            $vFinal = [];
            $vIds = array_map('intval', array_column($variants, 'id'));
            $vIds = array_values(array_filter($vIds, fn($x) => $x > 0));
            if ($vIds) {
                $ph = implode(',', array_fill(0, count($vIds), '?'));
                $q = $db->prepare("SELECT id, group_name, name, price_delta FROM fnb_variants
                                   WHERE product_id = ? AND id IN ($ph)");
                $q->execute(array_merge([$productId], $vIds));
                foreach ($q->fetchAll() as $r) {
                    $vFinal[] = [
                        'id' => (int)$r['id'],
                        'group' => (string)$r['group_name'],
                        'name' => (string)$r['name'],
                        'price_delta' => (float)$r['price_delta'],
                    ];
                }
            }
            $aFinal = [];
            $aIds = array_map('intval', array_column($addons, 'id'));
            $aIds = array_values(array_filter($aIds, fn($x) => $x > 0));
            if ($aIds) {
                $ph = implode(',', array_fill(0, count($aIds), '?'));
                $q = $db->prepare("SELECT id, name, price_delta FROM fnb_addons
                                   WHERE product_id = ? AND id IN ($ph)");
                $q->execute(array_merge([$productId], $aIds));
                foreach ($q->fetchAll() as $r) {
                    $aFinal[] = [
                        'id' => (int)$r['id'],
                        'name' => (string)$r['name'],
                        'price_delta' => (float)$r['price_delta'],
                    ];
                }
            }
            $lineTotal = fnb_line_total((float)$prod['price'], $vFinal, $aFinal, $qty);
            $subtotal += $lineTotal;
            $lines[] = [
                'product_id'   => (int)$prod['id'],
                'product_name' => (string)$prod['name'],
                'quantity'     => $qty,
                'unit_price'   => (float)$prod['price'],
                'variants'     => $vFinal,
                'addons'       => $aFinal,
                'instructions' => $instr,
                'line_total'   => $lineTotal,
            ];
        }
        $total = round($subtotal + $deliveryFee, 2);
    }

    if (!$err) {
        try {
            $db->beginTransaction();
            $ins = $db->prepare(
                'INSERT INTO fnb_orders
                    (company_id, branch_id, order_type, customer_name, customer_phone,
                     delivery_address, delivery_notes, pickup_time,
                     subtotal, delivery_fee, total, status, notes, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", ?, ?)'
            );
            $ins->execute([
                $companyId,
                $branchId ?: null,
                $orderType,
                $customerName,
                $customerPhone ?: null,
                $orderType === 'delivery' ? ($address ?: null) : null,
                $deliveryNotes ?: null,
                $orderType === 'pickup' && $pickupTime ? $pickupTime : null,
                $subtotal, $deliveryFee, $total,
                $notes ?: null,
                (int)$current_user['id'],
            ]);
            $newId = (int)$db->lastInsertId();

            // Stamp the human-facing order number now that we have the id.
            $orderNum = fnb_order_number_from_id($newId);
            $db->prepare('UPDATE fnb_orders SET order_number = ? WHERE id = ?')->execute([$orderNum, $newId]);

            // Insert lines.
            $iIns = $db->prepare(
                'INSERT INTO fnb_order_items
                    (order_id, product_id, product_name, quantity, unit_price,
                     variants_json, addons_json, instructions, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $i => $ln) {
                $iIns->execute([
                    $newId, $ln['product_id'], $ln['product_name'],
                    $ln['quantity'], $ln['unit_price'],
                    $ln['variants'] ? json_encode($ln['variants'], JSON_UNESCAPED_UNICODE) : null,
                    $ln['addons']   ? json_encode($ln['addons'],   JSON_UNESCAPED_UNICODE) : null,
                    $ln['instructions'] ?: null,
                    $ln['line_total'], 10 * ($i + 1),
                ]);
            }

            $db->commit();
            log_activity($companyId, (int)$current_user['id'], 'fnb_order_created', 'fnb_order', $newId, $orderNum);
            redirect('/admin/fnb_order_view.php?id=' . $newId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[AiServe fnb_order_edit] ' . $e->getMessage());
            $err = 'Could not save order: ' . $e->getMessage();
        }
    }
}

// -------------------- Load menu data --------------------
$products = $db->prepare(
    'SELECT p.*, c.name AS category_name
     FROM fnb_products p
     LEFT JOIN fnb_categories c ON c.id = p.category_id
     WHERE p.company_id = ? AND p.status = "active"
     ORDER BY COALESCE(c.sort_order, 999), c.name, p.sort_order, p.name'
);
$products->execute([$companyId]);
$products = $products->fetchAll();

// Attach variants + addons to each product.
$menu = [];
foreach ($products as $p) {
    $pid = (int)$p['id'];
    $v = $db->prepare('SELECT * FROM fnb_variants WHERE product_id = ? ORDER BY group_name, sort_order');
    $v->execute([$pid]);
    $variants = $v->fetchAll();
    $a = $db->prepare('SELECT * FROM fnb_addons WHERE product_id = ? ORDER BY sort_order');
    $a->execute([$pid]);
    $addons = $a->fetchAll();
    $menu[] = [
        'id'          => $pid,
        'name'        => $p['name'],
        'description' => $p['description'],
        'price'       => (float)$p['price'],
        'category'    => $p['category_name'] ?: 'Uncategorized',
        'image_url'   => !empty($p['image_ext'])
            ? '/api/fnb_product_image.php?product_id=' . $pid . '&v=' . strtotime($p['updated_at'] ?? 'now')
            : '',
        'variants'    => array_map(fn($v) => [
            'id' => (int)$v['id'], 'group' => $v['group_name'], 'name' => $v['name'],
            'price_delta' => (float)$v['price_delta'], 'is_default' => (int)$v['is_default'] === 1,
        ], $variants),
        'addons'      => array_map(fn($a) => [
            'id' => (int)$a['id'], 'name' => $a['name'], 'price_delta' => (float)$a['price_delta'],
        ], $addons),
    ];
}

$branches = [];
try {
    $bs = $db->prepare('SELECT id, name FROM branches WHERE company_id = ? AND status = "active" ORDER BY name');
    $bs->execute([$companyId]);
    $branches = $bs->fetchAll();
} catch (Throwable $e) {}

layout_start($current_user, 'New order', 'fnb_orders');
?>

<style>
.oe-wrap { display: grid; gap: 16px; grid-template-columns: 3fr 2fr; }
@media (max-width: 1000px) { .oe-wrap { grid-template-columns: 1fr; } }
.oe-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 14px; }
.oe-card h3 { margin: 0 0 10px; font-size: 12.5px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }

.oe-cat-group { margin-bottom: 12px; }
.oe-cat-group h4 { margin: 0 0 6px; font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; }
.oe-menu-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
.oe-menu-item {
  border: 1px solid #e3e8ee; border-radius: 8px; padding: 8px; background: #f6f9fb;
  cursor: pointer; display: flex; flex-direction: column; gap: 4px;
  transition: transform .1s, border-color .1s;
}
.oe-menu-item:hover { transform: translateY(-1px); border-color: #25D366; }
.oe-menu-item img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; }
.oe-menu-item .name { font-weight: 600; font-size: 13px; color: #0f172a; }
.oe-menu-item .price { color: #16A34A; font-weight: 600; font-size: 13px; }

.oe-form label { display: block; font-size: 12px; color: #64748b; margin-bottom: 8px; }
.oe-form label input, .oe-form label select, .oe-form label textarea {
  display: block; width: 100%; padding: 6px 8px; margin-top: 3px;
  border: 1px solid #e3e8ee; border-radius: 6px; font-size: 14px;
}

.oe-cart { display: grid; gap: 6px; margin: 8px 0; }
.oe-cart-line {
  padding: 8px; background: #f6f9fb; border-radius: 6px; font-size: 13px;
  display: grid; gap: 4px;
}
.oe-cart-line .top { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
.oe-cart-line .name { font-weight: 600; }
.oe-cart-line .rm { background: transparent; border: none; color: #DC2626; cursor: pointer; font-size: 16px; }
.oe-cart-line .sub { color: #64748b; font-size: 11px; }

.oe-totals { border-top: 2px solid #e3e8ee; padding-top: 8px; margin-top: 8px; }
.oe-totals .row { display: flex; justify-content: space-between; padding: 3px 0; }
.oe-totals .grand { font-size: 18px; font-weight: 700; padding-top: 6px; }

/* Product config modal */
.oe-modal-bg {
  position: fixed; inset: 0; background: rgba(0,0,0,.4);
  display: none; align-items: center; justify-content: center; z-index: 100;
}
.oe-modal-bg.on { display: flex; }
.oe-modal {
  background: #fff; border-radius: 12px; padding: 20px; max-width: 480px; width: 92%;
  max-height: 90vh; overflow-y: auto;
}
.oe-modal h2 { margin: 0 0 8px; }
.oe-modal .opt-group { margin: 12px 0; }
.oe-modal .opt-group .head { font-weight: 600; font-size: 13px; margin-bottom: 4px; }
.oe-modal .opt { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 13px; }
.oe-modal .opt .price { color: #16A34A; margin-left: auto; font-size: 12px; }
</style>

<form method="post" id="oe-form">
  <?= csrf_field() ?>
  <input type="hidden" name="cart" id="oe-cart-input" value="[]">

  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <div class="oe-wrap">

    <!-- LEFT: menu -->
    <div class="oe-card">
      <h3>Menu <small class="muted">(click a product to add)</small></h3>
      <?php if (!$menu): ?>
        <p class="muted">No active products yet. <a href="/admin/fnb_menu.php">Go add some →</a></p>
      <?php else:
        // Group products by category for the render pass.
        $byCat = [];
        foreach ($menu as $m) $byCat[$m['category']][] = $m;
        foreach ($byCat as $catName => $items):
      ?>
        <div class="oe-cat-group">
          <h4><?= e($catName) ?></h4>
          <div class="oe-menu-grid">
            <?php foreach ($items as $m): ?>
              <div class="oe-menu-item" onclick="openConfig(<?= (int)$m['id'] ?>)">
                <?php if ($m['image_url']): ?>
                  <img src="<?= e($m['image_url']) ?>" alt="">
                <?php endif; ?>
                <div class="name"><?= e($m['name']) ?></div>
                <div class="price"><?= e($currency) ?> <?= number_format($m['price'], 2) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- RIGHT: customer + cart -->
    <div>
      <div class="oe-card oe-form">
        <h3>Customer</h3>
        <label>Order type
          <select name="order_type" id="oe-type" onchange="updateType()">
            <option value="delivery">Delivery</option>
            <option value="pickup">Self-pickup</option>
          </select>
        </label>
        <label>Customer name
          <input type="text" name="customer_name" required maxlength="150">
        </label>
        <label>Phone
          <input type="text" name="customer_phone" maxlength="40" placeholder="60123456789">
        </label>
        <div id="oe-delivery">
          <label>Delivery address
            <textarea name="delivery_address" rows="2"></textarea>
          </label>
          <label>Delivery notes
            <input type="text" name="delivery_notes" maxlength="500" placeholder="Landmark, gate code, floor…">
          </label>
        </div>
        <div id="oe-pickup" style="display:none;">
          <label>Pickup time
            <input type="datetime-local" name="pickup_time">
          </label>
        </div>
        <?php if ($branches): ?>
          <label>Branch
            <select name="branch_id">
              <option value="0">— None —</option>
              <?php foreach ($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
      </div>

      <div class="oe-card oe-form" style="margin-top: 16px;">
        <h3>Cart</h3>
        <div class="oe-cart" id="oe-cart">
          <div class="muted small" id="oe-cart-empty">Empty — click a product on the left.</div>
        </div>
        <label>Delivery fee (<?= e($currency) ?>)
          <input type="number" name="delivery_fee" id="oe-fee" step="0.01" min="0" value="0" oninput="renderCart()">
        </label>
        <label>Internal notes
          <textarea name="notes" rows="2" placeholder="Anything the kitchen or dispatcher needs to know…"></textarea>
        </label>
        <div class="oe-totals">
          <div class="row"><span>Subtotal</span><span id="oe-subtotal"><?= e($currency) ?> 0.00</span></div>
          <div class="row"><span>Delivery fee</span><span id="oe-fee-out"><?= e($currency) ?> 0.00</span></div>
          <div class="row grand"><span>Total</span><span id="oe-total"><?= e($currency) ?> 0.00</span></div>
        </div>
        <button class="btn btn-primary" type="submit" style="width:100%; margin-top: 12px;" onclick="return oeSubmit()">Save order</button>
        <a class="btn" href="/admin/fnb_orders.php" style="width:100%; text-align:center; margin-top:6px;">Cancel</a>
      </div>
    </div>
  </div>
</form>

<!-- Product config modal -->
<div class="oe-modal-bg" id="oe-modal-bg" onclick="if(event.target === this) closeConfig()">
  <div class="oe-modal" id="oe-modal"></div>
</div>

<script>
window.MENU = <?= json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CURRENCY = <?= json_encode($currency) ?>;
let CART = [];

function money(n) { return CURRENCY + ' ' + (n).toFixed(2); }

function findProduct(id) { return window.MENU.find(p => p.id === id); }

function updateType() {
  const t = document.getElementById('oe-type').value;
  document.getElementById('oe-delivery').style.display = t === 'delivery' ? '' : 'none';
  document.getElementById('oe-pickup').style.display   = t === 'pickup'   ? '' : 'none';
}

function openConfig(productId) {
  const p = findProduct(productId);
  if (!p) return;
  const modal = document.getElementById('oe-modal');

  // Group variants by group name for radio rendering.
  const groups = {};
  (p.variants || []).forEach(v => { (groups[v.group] = groups[v.group] || []).push(v); });

  let html = '<h2>' + escapeHtml(p.name) + '</h2>'
           + '<div class="muted small">' + CURRENCY + ' ' + p.price.toFixed(2)
           + (p.description ? ' — ' + escapeHtml(p.description) : '') + '</div>';

  for (const g in groups) {
    html += '<div class="opt-group"><div class="head">' + escapeHtml(g) + '</div>';
    groups[g].forEach((v, i) => {
      const checked = (v.is_default || i === 0) ? 'checked' : '';
      html += '<label class="opt">'
           +   '<input type="radio" name="v_' + g.replace(/\W/g,'_') + '" value="' + v.id + '" ' + checked + '>'
           +   escapeHtml(v.name)
           +   '<span class="price">' + (v.price_delta > 0 ? '+ ' + CURRENCY + ' ' + v.price_delta.toFixed(2) : '') + '</span>'
           + '</label>';
    });
    html += '</div>';
  }
  if ((p.addons || []).length) {
    html += '<div class="opt-group"><div class="head">Add-ons</div>';
    p.addons.forEach(a => {
      html += '<label class="opt">'
           +   '<input type="checkbox" name="a" value="' + a.id + '">'
           +   escapeHtml(a.name)
           +   '<span class="price">+ ' + CURRENCY + ' ' + a.price_delta.toFixed(2) + '</span>'
           + '</label>';
    });
    html += '</div>';
  }
  html += '<div class="opt-group"><div class="head">Quantity</div>'
       +    '<input type="number" id="oe-qty" value="1" min="1" max="99" style="width:80px; padding: 6px 8px;">'
       + '</div>'
       + '<div class="opt-group"><div class="head">Special instructions</div>'
       +    '<input type="text" id="oe-instr" maxlength="500" placeholder="e.g. Less spicy" style="width:100%; padding: 6px 8px;">'
       + '</div>'
       + '<div style="display:flex; gap:6px; justify-content: flex-end; margin-top: 12px;">'
       +    '<button type="button" class="btn" onclick="closeConfig()">Cancel</button>'
       +    '<button type="button" class="btn btn-primary" onclick="addToCart(' + p.id + ')">+ Add to cart</button>'
       + '</div>';
  modal.innerHTML = html;
  document.getElementById('oe-modal-bg').classList.add('on');
}

function closeConfig() { document.getElementById('oe-modal-bg').classList.remove('on'); }

function addToCart(productId) {
  const p = findProduct(productId);
  if (!p) return;
  const modal = document.getElementById('oe-modal');
  const variants = [];
  const groups = {};
  (p.variants || []).forEach(v => { (groups[v.group] = groups[v.group] || []).push(v); });
  for (const g in groups) {
    const picked = modal.querySelector('input[name="v_' + g.replace(/\W/g,'_') + '"]:checked');
    if (picked) {
      const v = groups[g].find(vv => vv.id === parseInt(picked.value, 10));
      if (v) variants.push({ id: v.id, group: v.group, name: v.name, price_delta: v.price_delta });
    }
  }
  const addons = [];
  modal.querySelectorAll('input[name="a"]:checked').forEach(el => {
    const a = (p.addons || []).find(aa => aa.id === parseInt(el.value, 10));
    if (a) addons.push({ id: a.id, name: a.name, price_delta: a.price_delta });
  });
  const qty = Math.max(1, parseInt(modal.querySelector('#oe-qty').value, 10) || 1);
  const instr = (modal.querySelector('#oe-instr').value || '').trim();
  CART.push({
    product_id: p.id, product_name: p.name, unit_price: p.price,
    variants, addons, quantity: qty, instructions: instr,
  });
  closeConfig();
  renderCart();
}

function removeCartLine(i) { CART.splice(i, 1); renderCart(); }

function lineTotal(ln) {
  let extra = 0;
  (ln.variants || []).forEach(v => extra += v.price_delta || 0);
  (ln.addons   || []).forEach(a => extra += a.price_delta || 0);
  return Math.round((ln.unit_price + extra) * ln.quantity * 100) / 100;
}

function renderCart() {
  const el = document.getElementById('oe-cart');
  const empty = document.getElementById('oe-cart-empty');
  if (!CART.length) {
    el.innerHTML = '';
    if (empty) el.appendChild(empty);
  } else {
    let html = '';
    CART.forEach((ln, i) => {
      html += '<div class="oe-cart-line">'
           +   '<div class="top">'
           +     '<span class="name">' + ln.quantity + ' × ' + escapeHtml(ln.product_name) + '</span>'
           +     '<span>' + money(lineTotal(ln)) + '</span>'
           +     '<button class="rm" type="button" onclick="removeCartLine(' + i + ')" title="Remove">×</button>'
           +   '</div>';
      if ((ln.variants || []).length) {
        ln.variants.forEach(v => {
          html += '<div class="sub">◦ ' + escapeHtml(v.group) + ': ' + escapeHtml(v.name) + '</div>';
        });
      }
      if ((ln.addons || []).length) {
        ln.addons.forEach(a => {
          html += '<div class="sub">+ ' + escapeHtml(a.name) + '</div>';
        });
      }
      if (ln.instructions) {
        html += '<div class="sub" style="color:#78350F;">📝 ' + escapeHtml(ln.instructions) + '</div>';
      }
      html += '</div>';
    });
    el.innerHTML = html;
  }
  const sub = CART.reduce((a, ln) => a + lineTotal(ln), 0);
  const fee = parseFloat(document.getElementById('oe-fee').value) || 0;
  document.getElementById('oe-subtotal').textContent = money(sub);
  document.getElementById('oe-fee-out').textContent  = money(fee);
  document.getElementById('oe-total').textContent    = money(sub + fee);
}

function oeSubmit() {
  if (!CART.length) {
    alert('Add at least one item.');
    return false;
  }
  document.getElementById('oe-cart-input').value = JSON.stringify(CART);
  return true;
}

function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

// Initial render.
renderCart();
</script>

<?php layout_end(); ?>
