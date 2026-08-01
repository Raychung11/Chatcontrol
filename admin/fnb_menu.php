<?php
/**
 * F&B menu overview.
 *
 * Two panels stacked:
 *   1. Categories - inline CRUD (create, rename, sort, toggle, delete)
 *   2. Products - grid grouped under each category, with image thumbnails
 *      and a "+ New product" button per category.
 *
 * Product-level details (variants, add-ons, image upload) live on
 * /admin/fnb_product_edit.php so this overview stays scannable.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('The F&B module is not enabled for this workspace. Ask your platform admin.');
}

$db = aiserve_db();
$msg = ''; $err = '';

// -------------------- POST actions --------------------
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cat_create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { $err = 'Category name is required.'; }
        else {
            $sortMax = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) FROM fnb_categories WHERE company_id = $companyId")->fetchColumn();
            $db->prepare(
                'INSERT INTO fnb_categories (company_id, name, sort_order, status) VALUES (?, ?, ?, "active")'
            )->execute([$companyId, mb_substr($name, 0, 120), $sortMax + 10]);
            log_activity($companyId, (int)$current_user['id'], 'fnb_category_created', 'fnb_category', (int)$db->lastInsertId(), $name);
            $msg = 'Category added.';
        }
    } elseif ($action === 'cat_rename') {
        $cid  = (int)($_POST['category_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($cid > 0 && $name !== '') {
            $db->prepare('UPDATE fnb_categories SET name = ? WHERE id = ? AND company_id = ?')
               ->execute([mb_substr($name, 0, 120), $cid, $companyId]);
        }
    } elseif ($action === 'cat_toggle') {
        $cid = (int)($_POST['category_id'] ?? 0);
        if ($cid > 0) {
            $db->prepare('UPDATE fnb_categories SET status = IF(status="active","inactive","active") WHERE id = ? AND company_id = ?')
               ->execute([$cid, $companyId]);
        }
    } elseif ($action === 'cat_move') {
        // Swap sort_order with the immediate neighbor above/below.
        $cid = (int)($_POST['category_id'] ?? 0);
        $dir = (string)($_POST['dir'] ?? '');
        if ($cid > 0 && in_array($dir, ['up', 'down'], true)) {
            $cur = $db->prepare('SELECT sort_order FROM fnb_categories WHERE id = ? AND company_id = ?');
            $cur->execute([$cid, $companyId]);
            $curSort = (int)($cur->fetchColumn() ?: 0);
            $op = $dir === 'up' ? '<' : '>';
            $od = $dir === 'up' ? 'DESC' : 'ASC';
            $nb = $db->prepare("SELECT id, sort_order FROM fnb_categories
                                WHERE company_id = ? AND sort_order $op ? ORDER BY sort_order $od LIMIT 1");
            $nb->execute([$companyId, $curSort]);
            $nbRow = $nb->fetch();
            if ($nbRow) {
                $newSelf     = (int)$nbRow['sort_order'];
                $newNeighbor = $curSort;
                if ($newSelf === $newNeighbor) {
                    $newSelf = $dir === 'up' ? $curSort - 1 : $curSort + 1;
                }
                $upd = $db->prepare('UPDATE fnb_categories SET sort_order = ? WHERE id = ? AND company_id = ?');
                $upd->execute([$newSelf, $cid, $companyId]);
                $upd->execute([$newNeighbor, (int)$nbRow['id'], $companyId]);
            }
        }
    } elseif ($action === 'cat_delete') {
        $cid = (int)($_POST['category_id'] ?? 0);
        if ($cid > 0) {
            // Products cascade-null their category_id — they don't get
            // deleted, just orphaned into the "Uncategorized" bucket.
            $db->prepare('DELETE FROM fnb_categories WHERE id = ? AND company_id = ?')
               ->execute([$cid, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'fnb_category_deleted', 'fnb_category', $cid);
            $msg = 'Category deleted. Its products moved to Uncategorized.';
        }
    } elseif ($action === 'product_toggle') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare('UPDATE fnb_products SET status = IF(status="active","inactive","active") WHERE id = ? AND company_id = ?')
               ->execute([$pid, $companyId]);
        }
    } elseif ($action === 'product_delete') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare('DELETE FROM fnb_products WHERE id = ? AND company_id = ?')->execute([$pid, $companyId]);
            log_activity($companyId, (int)$current_user['id'], 'fnb_product_deleted', 'fnb_product', $pid);
            $msg = 'Product deleted.';
        }
    }

    if (!$err) redirect('/admin/fnb_menu.php' . ($msg ? '?flash=' . rawurlencode($msg) : ''));
}

if (!$msg) $msg = (string)($_GET['flash'] ?? '');

// -------------------- Load --------------------
$categories = $db->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM fnb_products WHERE category_id = c.id) AS product_count
     FROM fnb_categories c
     WHERE c.company_id = ?
     ORDER BY c.sort_order ASC, c.id ASC'
);
$categories->execute([$companyId]);
$categories = $categories->fetchAll();

$products = $db->prepare(
    'SELECT * FROM fnb_products WHERE company_id = ? ORDER BY sort_order ASC, id ASC'
);
$products->execute([$companyId]);
$productsByCat = [];
foreach ($products->fetchAll() as $p) {
    $productsByCat[(int)($p['category_id'] ?? 0)][] = $p;
}

$currency = platform_setting('pricing_currency', 'RM');

layout_start($current_user, 'F&B · Menu', 'fnb_menu');
?>

<style>
.fnb-wrap { display: grid; gap: 16px; }
.fnb-card {
  background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 16px;
}
.fnb-card h2 {
  margin: 0 0 12px; font-size: 15px; display: flex; justify-content: space-between; align-items: center;
}

.fnb-cat-list { display: grid; gap: 6px; }
.fnb-cat-row {
  display: grid; grid-template-columns: 22px 1fr 90px 240px;
  gap: 8px; align-items: center;
  padding: 8px 10px; background: #f6f9fb; border-radius: 8px;
}
.fnb-cat-row.inactive { opacity: .5; }
.fnb-cat-row form { display: inline-flex; gap: 4px; align-items: center; }
.fnb-cat-row input[type="text"] { padding: 4px 8px; font-size: 13px; width: 100%; }

.fnb-cat-section { margin-top: 20px; }
.fnb-cat-section h3 {
  margin: 0 0 8px; font-size: 13px; text-transform: uppercase;
  letter-spacing: .04em; color: #64748b;
  display: flex; justify-content: space-between; align-items: center;
}

.fnb-prod-grid {
  display: grid; gap: 10px;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
}
.fnb-prod {
  border: 1px solid #e3e8ee; border-radius: 10px; overflow: hidden;
  background: #fff; display: flex; flex-direction: column;
}
.fnb-prod .thumb {
  height: 120px; background: #f6f9fb;
  display: flex; align-items: center; justify-content: center;
  color: #94a3b8; font-size: 12px;
}
.fnb-prod .thumb img {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.fnb-prod.inactive { opacity: .55; }
.fnb-prod .body { padding: 10px 12px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
.fnb-prod .name { font-weight: 600; }
.fnb-prod .price { color: #16A34A; font-weight: 600; }
.fnb-prod .desc { color: #64748b; font-size: 12px; }
.fnb-prod .actions {
  padding: 8px 12px; border-top: 1px solid #f1f5f9;
  display: flex; gap: 4px; flex-wrap: wrap;
}
.fnb-prod .actions form { display: inline; }
</style>

<div class="fnb-wrap">

  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <!-- ============ Categories ============ -->
  <div class="fnb-card">
    <h2 style="display:flex; justify-content:space-between; align-items:center;">
      <span>Categories <small class="muted">(<?= count($categories) ?>)</small></span>
      <?php if (!$categories): ?>
        <a class="btn btn-sm" href="/admin/fnb_demo_seed.php"
           title="Quickly populate a Malaysian-style demo menu">🍜 Seed demo data</a>
      <?php endif; ?>
    </h2>

    <form method="post" style="display:flex; gap:6px; margin-bottom:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cat_create">
      <input type="text" name="name" placeholder="New category (e.g. Rice, Drinks, Sides)" required maxlength="120"
             style="flex:1; padding:6px 8px;">
      <button class="btn btn-sm btn-primary" type="submit">+ Add category</button>
    </form>

    <?php if (!$categories): ?>
      <div class="muted small" style="padding:8px 0;">No categories yet — add one above to start organizing your menu.</div>
    <?php else: ?>
      <div class="fnb-cat-list">
        <?php foreach ($categories as $i => $c): ?>
          <div class="fnb-cat-row <?= $c['status'] === 'inactive' ? 'inactive' : '' ?>">
            <div style="text-align:center;">
              <?php if ($i > 0): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cat_move">
                  <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="dir" value="up">
                  <button class="btn btn-sm" type="submit" style="padding:0 4px; font-size:10px;" title="Move up">▲</button>
                </form>
              <?php endif; ?>
              <?php if ($i < count($categories) - 1): ?>
                <form method="post" style="display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="cat_move">
                  <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                  <input type="hidden" name="dir" value="down">
                  <button class="btn btn-sm" type="submit" style="padding:0 4px; font-size:10px;" title="Move down">▼</button>
                </form>
              <?php endif; ?>
            </div>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cat_rename">
              <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
              <input type="text" name="name" value="<?= e($c['name']) ?>" maxlength="120" required>
              <button class="btn btn-sm" type="submit">Save</button>
            </form>
            <span class="muted small">
              <?= (int)$c['product_count'] ?> product<?= (int)$c['product_count'] === 1 ? '' : 's' ?>
            </span>
            <div style="display:flex; gap:4px; justify-content:flex-end;">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cat_toggle">
                <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
              </form>
              <a class="btn btn-sm btn-primary"
                 href="/admin/fnb_product_edit.php?category_id=<?= (int)$c['id'] ?>">+ Product</a>
              <form method="post" onsubmit="return confirm('Delete this category? Its products stay but become Uncategorized.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cat_delete">
                <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============ Products grouped by category ============ -->
  <?php
    // Render one section per category (in sort order), then Uncategorized.
    $categoriesForRender = $categories;
    $categoriesForRender[] = ['id' => 0, 'name' => 'Uncategorized', 'status' => 'active'];
    foreach ($categoriesForRender as $c):
      $cid  = (int)$c['id'];
      $list = $productsByCat[$cid] ?? [];
      if ($cid === 0 && !$list) continue;   // hide empty Uncategorized bucket
  ?>
    <div class="fnb-card">
      <h2>
        <span><?= e($c['name']) ?> <small class="muted">(<?= count($list) ?>)</small></span>
        <a class="btn btn-sm btn-primary"
           href="/admin/fnb_product_edit.php<?= $cid > 0 ? '?category_id=' . $cid : '' ?>">
          + New product
        </a>
      </h2>

      <?php if (!$list): ?>
        <div class="muted small" style="padding:8px 0;">
          No products in this category yet. Click <strong>+ New product</strong> to add one.
        </div>
      <?php else: ?>
        <div class="fnb-prod-grid">
          <?php foreach ($list as $p):
            $pid = (int)$p['id'];
            $imageUrl = !empty($p['image_ext'])
              ? '/api/fnb_product_image.php?product_id=' . $pid . '&v=' . strtotime($p['updated_at'] ?? 'now')
              : '';
          ?>
            <div class="fnb-prod <?= $p['status'] === 'inactive' ? 'inactive' : '' ?>">
              <div class="thumb">
                <?php if ($imageUrl): ?>
                  <img src="<?= e($imageUrl) ?>" alt="">
                <?php else: ?>
                  <span>no image</span>
                <?php endif; ?>
              </div>
              <div class="body">
                <div class="name"><?= e($p['name']) ?></div>
                <div class="price"><?= e($currency) ?> <?= number_format((float)$p['price'], 2) ?></div>
                <?php if (!empty($p['description'])): ?>
                  <div class="desc"><?= e(mb_strimwidth((string)$p['description'], 0, 80, '…')) ?></div>
                <?php endif; ?>
              </div>
              <div class="actions">
                <a class="btn btn-sm" href="/admin/fnb_product_edit.php?id=<?= $pid ?>">Edit</a>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="product_toggle">
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <button class="btn btn-sm" type="submit">
                    <?= $p['status'] === 'active' ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
                <form method="post" onsubmit="return confirm('Delete this product?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="product_delete">
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

</div>

<?php layout_end(); ?>
