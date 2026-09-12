<?php
/**
 * F&B demo data seeder — populates the current workspace with a
 * realistic Malaysian F&B menu + a few sample orders in various
 * statuses, so a fresh workspace has something to show in a demo
 * within seconds instead of manually creating 20+ products.
 *
 * The button is destructive-safe: it prompts before running, and the
 * seed logic uses INSERT IGNORE + name-based dedupe so re-running does
 * not double up the menu (though it will always create a fresh sample
 * order batch — those are meant to demo the kanban).
 *
 * Only visible when the workspace has the F&B module enabled AND the
 * current user is a super_admin.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('The F&B module is not enabled for this workspace.');
}

$db = aiserve_db();
$msg = '';
$err = '';

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'seed_menu') {
        try {
            $db->beginTransaction();
            $result = fnb_demo_seed_menu($db, $companyId);
            $db->commit();
            $msg = "Menu seeded: {$result['categories']} categor(y|ies), {$result['products']} product(s), "
                 . "{$result['variants']} variant(s), {$result['addons']} add-on(s).";
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'Menu seed failed: ' . $e->getMessage();
        }
    }
    elseif ($action === 'seed_orders') {
        try {
            $result = fnb_demo_seed_orders($db, $companyId, (int)$current_user['id']);
            $msg = "Sample orders created: " . implode(' · ', $result);
        } catch (Throwable $e) {
            $err = 'Order seed failed: ' . $e->getMessage();
        }
    }
    elseif ($action === 'wipe_menu') {
        try {
            $db->beginTransaction();
            // Order via CASCADE takes care of items; menu products
            // cascade variants + addons. Wipe categories last.
            $db->prepare('DELETE FROM fnb_products   WHERE company_id = ?')->execute([$companyId]);
            $db->prepare('DELETE FROM fnb_categories WHERE company_id = ?')->execute([$companyId]);
            $db->commit();
            $msg = 'All demo menu data removed for this workspace.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'Wipe failed: ' . $e->getMessage();
        }
    }
    elseif ($action === 'wipe_orders') {
        try {
            $db->prepare('DELETE FROM fnb_orders WHERE company_id = ?')->execute([$companyId]);
            $msg = 'All orders removed for this workspace.';
        } catch (Throwable $e) {
            $err = 'Wipe failed: ' . $e->getMessage();
        }
    }
}

// -------------------- Seeders --------------------

/**
 * Insert a Malaysian-restaurant-style menu. Idempotent: skips a
 * category / product if the exact name already exists for this company.
 */
function fnb_demo_seed_menu(PDO $db, int $companyId): array
{
    // [name, [ [product name, description, price, variants[], addons[]] ] ]
    // variant shape: [group_name, [name, price_delta, is_default_bool]]
    // addon shape:   [name, price_delta]
    $data = [
        ['Rice', [
            ['Chicken Rice', 'Hainanese steamed chicken with fragrant rice + garlic-ginger sauce', 9.90,
             [['Portion', [['Regular', 0, true], ['Large', 3.00, false]]]],
             [['Extra chicken', 4.00], ['Extra rice', 2.00], ['Egg', 1.50]]],
            ['Nasi Lemak Ayam', 'Coconut rice, fried chicken, sambal, egg, peanuts, anchovies',
             12.90,
             [['Spice level', [['Mild', 0, false], ['Medium', 0, true], ['Spicy', 0, false]]]],
             [['Extra sambal', 1.00], ['Extra egg', 1.50], ['Extra ayam', 5.00]]],
            ['Nasi Goreng Kampung', 'Village-style fried rice, anchovies, kangkung, fried egg',
             11.90,
             [['Spice level', [['Mild', 0, false], ['Medium', 0, true], ['Spicy', 0, false]]],
              ['Portion', [['Regular', 0, true], ['Large', 3.00, false]]]],
             [['Chicken', 4.00], ['Prawns', 6.00], ['Extra egg', 1.50]]],
            ['Nasi Ayam Penyet', 'Smashed fried chicken, sambal, rice, cucumber, tofu',
             13.90,
             [],
             [['Extra sambal', 1.00], ['Extra chicken', 5.00]]],
        ]],
        ['Noodles', [
            ['Char Kuey Teow', 'Wok-fried flat noodles, prawns, cockles, egg, chives',
             10.90,
             [['Spice level', [['Mild', 0, false], ['Medium', 0, true], ['Spicy', 0, false]]]],
             [['Extra prawns', 5.00], ['Extra egg', 1.50], ['No cockles', 0]]],
            ['Wan Tan Mee', 'Egg noodles, char siew, wontons, greens', 9.90,
             [['Style', [['Dry', 0, true], ['Soup', 0, false]]]],
             [['Extra char siew', 4.00], ['Extra wontons (3)', 3.00]]],
            ['Mee Goreng Mamak', 'Yellow noodles, tofu, potato, egg, tomato — Mamak-style',
             10.90,
             [['Spice level', [['Mild', 0, false], ['Medium', 0, true], ['Spicy', 0, false]]]],
             [['Chicken', 4.00], ['Prawns', 5.00]]],
        ]],
        ['Roti & Sides', [
            ['Roti Canai', 'Flaky flatbread served with dhal + curry', 2.20,
             [['Type', [['Kosong', 0, true], ['Telur', 2.00, false], ['Bawang', 1.50, false], ['Planta', 1.20, false]]]],
             []],
            ['Roti Bom', 'Flatbread with condensed milk + sugar dome', 3.50, [], []],
            ['Fries', 'Crispy shoestring fries', 6.90,
             [['Portion', [['Regular', 0, true], ['Large', 3.00, false]]]],
             [['Cheese sauce', 2.00], ['Chilli sauce', 0]]],
        ]],
        ['Drinks', [
            ['Teh Tarik', 'Pulled milk tea, Malaysian classic', 3.50,
             [['Serving', [['Hot', 0, true], ['Iced', 0.50, false]]],
              ['Sugar', [['Regular', 0, true], ['Less sugar', 0, false], ['No sugar', 0, false]]]],
             []],
            ['Kopi O', 'Black coffee, no milk', 3.00,
             [['Serving', [['Hot', 0, true], ['Iced', 0.50, false]]]],
             []],
            ['Ice Lemon Tea', 'Fresh brew, cold, sweetened', 5.00, [], []],
            ['Bandung', 'Rose milk syrup, iced', 4.50, [], []],
            ['Milo Ais', 'Iced chocolate malt, thick and cold', 5.00, [], []],
        ]],
        ['Desserts', [
            ['Cendol', 'Coconut milk, palm sugar, pandan jelly, red beans', 6.90, [], []],
            ['Ais Kacang (ABC)', 'Shaved ice, red beans, corn, jelly, syrup, evaporated milk', 7.90, [], []],
        ]],
    ];

    $counts = ['categories' => 0, 'products' => 0, 'variants' => 0, 'addons' => 0];

    // Dedupe by name-per-workspace: fetch existing before inserting.
    $catStmt = $db->prepare('SELECT id, name FROM fnb_categories WHERE company_id = ?');
    $catStmt->execute([$companyId]);
    $existingCats = [];
    foreach ($catStmt->fetchAll() as $c) $existingCats[mb_strtolower($c['name'])] = (int)$c['id'];

    $prodStmt = $db->prepare('SELECT name FROM fnb_products WHERE company_id = ?');
    $prodStmt->execute([$companyId]);
    $existingProds = [];
    foreach ($prodStmt->fetchAll() as $p) $existingProds[mb_strtolower($p['name'])] = true;

    $catSort = 100;
    foreach ($data as [$catName, $products]) {
        $lcCat = mb_strtolower($catName);
        if (isset($existingCats[$lcCat])) {
            $catId = $existingCats[$lcCat];
        } else {
            $db->prepare(
                'INSERT INTO fnb_categories (company_id, name, sort_order, status)
                 VALUES (?, ?, ?, "active")'
            )->execute([$companyId, $catName, $catSort]);
            $catId = (int)$db->lastInsertId();
            $existingCats[$lcCat] = $catId;
            $counts['categories']++;
        }
        $catSort += 10;

        $prodSort = 100;
        foreach ($products as [$pName, $pDesc, $price, $variants, $addons]) {
            if (isset($existingProds[mb_strtolower($pName)])) {
                $prodSort += 10;
                continue;
            }
            $db->prepare(
                'INSERT INTO fnb_products
                    (company_id, category_id, name, description, price, sort_order, status)
                 VALUES (?, ?, ?, ?, ?, ?, "active")'
            )->execute([$companyId, $catId, $pName, $pDesc, $price, $prodSort]);
            $pid = (int)$db->lastInsertId();
            $existingProds[mb_strtolower($pName)] = true;
            $counts['products']++;
            $prodSort += 10;

            $vSort = 100;
            foreach ($variants as [$group, $opts]) {
                foreach ($opts as [$vName, $vPrice, $isDefault]) {
                    $db->prepare(
                        'INSERT INTO fnb_variants
                            (product_id, group_name, name, price_delta, sort_order, is_default)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([$pid, $group, $vName, $vPrice, $vSort, $isDefault ? 1 : 0]);
                    $vSort += 10;
                    $counts['variants']++;
                }
            }
            $aSort = 100;
            foreach ($addons as [$aName, $aPrice]) {
                $db->prepare(
                    'INSERT INTO fnb_addons (product_id, name, price_delta, sort_order)
                     VALUES (?, ?, ?, ?)'
                )->execute([$pid, $aName, $aPrice, $aSort]);
                $aSort += 10;
                $counts['addons']++;
            }
        }
    }
    return $counts;
}

/**
 * Create a spread of sample orders across all 5 statuses so the demo
 * kanban has cards to show. Each order picks 2–3 random products from
 * the workspace's active menu, with realistic Malaysian customer names.
 */
function fnb_demo_seed_orders(PDO $db, int $companyId, int $userId): array
{
    $products = $db->prepare('SELECT id, name, price FROM fnb_products WHERE company_id = ? AND status = "active"');
    $products->execute([$companyId]);
    $products = $products->fetchAll();
    if (!$products) throw new RuntimeException('No products found — seed the menu first.');

    $customers = [
        ['Vicky Tan',       '60123456789', 'Petalz Residences, A-12-3, Kuala Lumpur',    'delivery', 5.00],
        ['Ali Rahman',      '60198765432', 'Bangsar South, Kerinchi',                    'delivery', 6.00],
        ['Siti Aminah',     '60122334455', 'Bukit Bintang, near Sungei Wang',            'delivery', 4.00],
        ['Kenneth Soo',     '60162334944', 'PJ Old Town, near Section 14',               'pickup',   0.00],
        ['Chan Wei Ming',   '60127788990', 'TTDI, near Sprint Highway',                  'pickup',   0.00],
        ['Fatimah Zahra',   '60174455667', 'Ampang, near LRT station',                   'delivery', 5.00],
    ];
    $statuses = ['new', 'confirmed', 'processing', 'completed', 'cancelled'];

    $created = [];
    foreach ($statuses as $status) {
        $count = $status === 'new' ? 3 : ($status === 'confirmed' ? 2 : 1);
        for ($n = 0; $n < $count; $n++) {
            $c = $customers[array_rand($customers)];
            [$name, $phone, $addr, $type, $fee] = $c;

            // Pick 2-3 random products
            shuffle($products);
            $pick = array_slice($products, 0, rand(2, 3));
            $subtotal = 0;
            $lines = [];
            foreach ($pick as $p) {
                $qty  = rand(1, 3);
                $ltot = round((float)$p['price'] * $qty, 2);
                $subtotal += $ltot;
                $lines[] = [
                    'product_id'   => (int)$p['id'],
                    'product_name' => (string)$p['name'],
                    'quantity'     => $qty,
                    'unit_price'   => (float)$p['price'],
                    'line_total'   => $ltot,
                ];
            }
            $total = round($subtotal + ($type === 'delivery' ? $fee : 0), 2);

            $db->prepare(
                'INSERT INTO fnb_orders
                    (company_id, order_type, customer_name, customer_phone,
                     delivery_address, subtotal, delivery_fee, total, status,
                     created_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $companyId, $type, $name, $phone,
                $type === 'delivery' ? $addr : null,
                $subtotal, $type === 'delivery' ? $fee : 0, $total,
                $status, $userId,
                // Spread creation times across last 24 hours for realism
                date('Y-m-d H:i:s', time() - rand(0, 86400)),
            ]);
            $orderId = (int)$db->lastInsertId();
            $orderNum = fnb_order_number_from_id($orderId);
            $db->prepare('UPDATE fnb_orders SET order_number = ? WHERE id = ?')->execute([$orderNum, $orderId]);

            $iIns = $db->prepare(
                'INSERT INTO fnb_order_items
                    (order_id, product_id, product_name, quantity, unit_price,
                     line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($lines as $i => $ln) {
                $iIns->execute([
                    $orderId, $ln['product_id'], $ln['product_name'],
                    $ln['quantity'], $ln['unit_price'], $ln['line_total'],
                    10 * ($i + 1),
                ]);
            }
        }
        $created[] = "$count $status";
    }
    return $created;
}

layout_start($current_user, 'F&B · Demo seed', 'fnb_menu');
?>

<div class="card">
  <div class="card-head">
    <h2>🍜 Seed demo data</h2>
    <a class="btn" href="/admin/fnb_menu.php">← Back to menu</a>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <p class="muted">
    Populate this workspace with a Malaysian-restaurant-style demo menu +
    sample orders so you have something visual to show without typing up
    products by hand. Safe to run more than once — the menu seed is
    idempotent (skips items that already exist by name).
  </p>

  <div style="display:grid; gap:12px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-top: 18px;">

    <div style="border:1px solid #e3e8ee; border-radius:12px; padding:16px;">
      <h3 style="margin-top:0;">1. Seed the menu</h3>
      <p class="muted small">
        Creates 5 categories (Rice, Noodles, Roti &amp; Sides, Drinks,
        Desserts) with 15 products, variants (spice level, portion size,
        hot/iced), and add-ons (extra chicken, extra egg, etc).
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="seed_menu">
        <button class="btn btn-primary" type="submit">➕ Seed menu</button>
      </form>
    </div>

    <div style="border:1px solid #e3e8ee; border-radius:12px; padding:16px;">
      <h3 style="margin-top:0;">2. Seed sample orders</h3>
      <p class="muted small">
        Creates 8 orders spread across all 5 kanban statuses (New,
        Confirmed, Processing, Completed, Cancelled) using realistic
        Malaysian customer names and addresses. Requires the menu to be
        seeded first.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="seed_orders">
        <button class="btn btn-primary" type="submit">➕ Seed orders</button>
      </form>
    </div>

    <div style="border:1px solid #FCA5A5; border-radius:12px; padding:16px; background: #FEF2F2;">
      <h3 style="margin-top:0; color: #7F1D1D;">Wipe data</h3>
      <p class="small" style="color: #991B1B;">
        Danger zone — removes all F&amp;B data for this workspace (menu +
        orders). Use before a fresh demo run.
      </p>
      <form method="post" onsubmit="return confirm('Delete all F&B menu items? Cannot be undone.');" style="margin-bottom:6px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="wipe_menu">
        <button class="btn btn-danger btn-sm" type="submit">Wipe menu</button>
      </form>
      <form method="post" onsubmit="return confirm('Delete all F&B orders? Cannot be undone.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="wipe_orders">
        <button class="btn btn-danger btn-sm" type="submit">Wipe orders</button>
      </form>
    </div>

  </div>

  <hr style="margin: 24px 0; border:none; border-top:1px solid #e3e8ee;">

  <h3>After seeding — 3 clicks to a live demo</h3>
  <ol style="line-height: 1.8;">
    <li>Open <a href="/admin/fnb_menu.php">🍜 Menu</a> — see the seeded products with variants + add-ons</li>
    <li>Open <a href="/admin/fnb_orders.php">📋 Orders</a> — kanban board with sample orders across all statuses</li>
    <li>Open <a href="/admin/flows.php">Message flows</a> → click <strong>🍜 Seed a starter F&amp;B ordering flow</strong>, then activate it</li>
  </ol>
  <p class="muted small">
    Once the flow is active, message your workspace's WhatsApp number with
    <code>order</code> to run the full AI order-taking demo. Ensure your
    Anthropic API key is set on <a href="/admin/ai_settings.php">AI Settings</a>.
  </p>
</div>

<?php layout_end(); ?>
