<?php
/**
 * Products admin — structured product catalog for AI grounding.
 *
 * On top of the freeform knowledge_base at /admin/knowledge.php, this
 * page stores products as proper rows so the AI answers price/stock
 * questions with real fields instead of paraphrases. See
 * inc/product_catalog.php for the retrieval layer that gets injected
 * into the AI reply drafter.
 *
 * Three ways to add products:
 *   - Single-row form (name + optional fields)
 *   - CSV import (drag any spreadsheet exported as CSV — column order
 *     doesn't matter, unknown columns are ignored)
 *   - Bulk edit inline (click any row to edit)
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/product_catalog.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$err = '';
$msg = '';
$importReport = null;

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $err = 'Name is required.';
        } else {
            try {
                $ins = $db->prepare(
                    'INSERT INTO products
                        (company_id, sku, name, category, price, currency, description,
                         image_url, product_url, in_stock, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $priceRaw = trim((string)($_POST['price'] ?? ''));
                $ins->execute([
                    $companyId,
                    trim((string)($_POST['sku'] ?? '')) ?: null,
                    $name,
                    trim((string)($_POST['category'] ?? '')) ?: null,
                    $priceRaw !== '' ? (float)$priceRaw : null,
                    trim((string)($_POST['currency'] ?? '')) ?: null,
                    trim((string)($_POST['description'] ?? '')) ?: null,
                    trim((string)($_POST['image_url'] ?? '')) ?: null,
                    trim((string)($_POST['product_url'] ?? '')) ?: null,
                    empty($_POST['out_of_stock']) ? 1 : 0,
                    (int)$current_user['id'],
                ]);
                $msg = 'Product added.';
                log_activity($companyId, (int)$current_user['id'], 'product_created',
                    'product', (int)$db->lastInsertId(), $name);
            } catch (PDOException $e) {
                if ((int)$e->errorInfo[1] === 1062) {
                    $err = 'A product with that SKU already exists in this workspace. Use the inline edit to update it, or leave the SKU blank.';
                } else {
                    $err = 'Could not save: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($id > 0 && $name !== '') {
            $priceRaw = trim((string)($_POST['price'] ?? ''));
            $db->prepare(
                'UPDATE products
                 SET sku=?, name=?, category=?, price=?, currency=?, description=?,
                     image_url=?, product_url=?, in_stock=?, status=?
                 WHERE id=? AND company_id=?'
            )->execute([
                trim((string)($_POST['sku'] ?? '')) ?: null,
                $name,
                trim((string)($_POST['category'] ?? '')) ?: null,
                $priceRaw !== '' ? (float)$priceRaw : null,
                trim((string)($_POST['currency'] ?? '')) ?: null,
                trim((string)($_POST['description'] ?? '')) ?: null,
                trim((string)($_POST['image_url'] ?? '')) ?: null,
                trim((string)($_POST['product_url'] ?? '')) ?: null,
                empty($_POST['out_of_stock']) ? 1 : 0,
                !empty($_POST['archived']) ? 'archived' : 'active',
                $id, $companyId,
            ]);
            $msg = 'Product updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Scope to workspace so a forged form can't drop another
        // workspace's products.
        $db->prepare('DELETE FROM products WHERE id = ? AND company_id = ?')
           ->execute([$id, $companyId]);
        $msg = 'Product deleted.';
    } elseif ($action === 'import_csv') {
        $up = $_FILES['csv'] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = 'Choose a CSV file first.';
        } else {
            $parse = products_import_parse_csv((string)$up['tmp_name']);
            if (!$parse['ok']) {
                $err = 'CSV: ' . (string)($parse['error'] ?? 'unknown parse error');
            } else {
                $rows = (array)($parse['rows'] ?? []);
                if (!$rows) {
                    $err = 'CSV had no rows with a name column.';
                } else {
                    $importReport = products_import_upsert($companyId, (int)$current_user['id'], $rows);
                    log_activity($companyId, (int)$current_user['id'], 'products_csv_import',
                        'company', $companyId,
                        sprintf('inserted=%d updated=%d errors=%d',
                            $importReport['inserted'], $importReport['updated'], $importReport['errors']));
                    $msg = sprintf('Imported: %d new, %d updated, %d skipped.',
                        $importReport['inserted'], $importReport['updated'], $importReport['errors']);
                }
            }
        }
    }
}

// ---- Data for render ----
$q       = trim((string)($_GET['q'] ?? ''));
$cat     = trim((string)($_GET['category'] ?? ''));
$showAll = !empty($_GET['show_archived']);

$where  = ['company_id = ?'];
$params = [$companyId];
if (!$showAll) { $where[] = 'status = "active"'; }
if ($q !== '') {
    $where[] = '(name LIKE ? OR sku LIKE ? OR description LIKE ?)';
    $like = '%' . str_replace(['%','_'],['\%','\_'],$q) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($cat !== '') { $where[] = 'category = ?'; $params[] = $cat; }

$whereSql = implode(' AND ', $where);
$rows = $db->prepare("SELECT * FROM products WHERE $whereSql ORDER BY id DESC LIMIT 500");
$rows->execute($params);
$rows = $rows->fetchAll();

// Distinct categories for the filter dropdown.
$catStmt = $db->prepare(
    'SELECT DISTINCT category FROM products
     WHERE company_id = ? AND category IS NOT NULL AND category <> ""
     ORDER BY category'
);
$catStmt->execute([$companyId]);
$cats = array_column($catStmt->fetchAll(), 'category');

$totalStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE company_id = ? AND status = 'active'");
$totalStmt->execute([$companyId]);
$activeTotal = (int)$totalStmt->fetchColumn();

$page_title = 'Products';
$active_nav = 'products';
layout_start($current_user, $page_title, $active_nav);
?>

<style>
  .products-page  { max-width: 1100px; }
  .products-hero  { background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px;
                    padding:16px 18px; margin-bottom:16px; }
  .products-hero h3 { margin: 0 0 6px 0; }
  .products-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; }
  .products-form-grid label.full { grid-column: 1 / -1; }
  @media (max-width: 640px) { .products-form-grid { grid-template-columns: 1fr; } }
  .products-table { width: 100%; border-collapse: collapse; margin-top: 14px; }
  .products-table th, .products-table td {
    padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 13.5px;
  }
  .products-table th {
    background: #f8fafc; text-align: left; font-weight: 600; font-size: 12.5px;
    text-transform: uppercase; letter-spacing: .3px;
  }
  .prod-chip {
    display: inline-block; background:#eef2ff; color:#4338ca;
    padding: 1px 8px; border-radius: 10px; font-size: 11.5px; margin-right: 4px;
  }
  .prod-chip.stock-out { background:#fee2e2; color:#b91c1c; }
  .prod-chip.archived  { background:#f1f5f9; color:#64748b; }
  .prod-price { font-weight: 600; }
  .prod-thumb {
    width: 44px; height: 44px; border-radius: 6px; object-fit: cover;
    background:#f1f5f9; border: 1px solid #e5e7eb;
  }
  .prod-edit-panel { display:none; margin-top:8px; background:#f8fafc; border-radius:8px; padding:10px 12px; }
  .prod-edit-panel.open { display:block; }
  .products-filters { display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; align-items:end; }
  .products-filters input, .products-filters select { padding:6px 10px; border:1px solid #d0d7de; border-radius:6px; }
</style>

<div class="products-page">
  <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-ok"><?= e($msg) ?></div><?php endif; ?>

  <div class="products-hero">
    <h3>📦 Product catalog</h3>
    <p>
      Adding products here <strong>trains the AI on real prices and stock</strong>. When a
      customer asks about a product on WhatsApp or the web widget, the AI matches the
      question to your catalog and cites the exact fields (price, currency, stock, product
      URL) instead of paraphrasing from freeform text.
    </p>
    <p class="muted small" style="margin: 4px 0 0 0;">
      This workspace has <strong><?= (int)$activeTotal ?></strong> active product<?= $activeTotal === 1 ? '' : 's' ?>.
      Prose docs (return policy, opening hours, brochures) still go in
      <a href="/admin/knowledge.php">Knowledge base</a>.
    </p>
  </div>

  <details>
    <summary><strong>📤 Import from CSV</strong></summary>
    <div style="margin-top:12px; padding:12px 14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
      <p class="muted small">
        Column headers (case-insensitive, order-agnostic):
        <code>name</code> (required),
        <code>sku</code>, <code>category</code>, <code>price</code>, <code>currency</code>,
        <code>description</code>, <code>image_url</code>, <code>product_url</code>,
        <code>in_stock</code>&nbsp;(1/0/yes/no). Unknown columns are ignored.
      </p>
      <p class="muted small">
        Rows with a matching SKU <strong>update</strong> the existing product; new SKUs
        insert. Blank SKU rows always insert as new.
      </p>
      <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_csv">
        <input type="file" name="csv" accept=".csv,text/csv" required>
        <button type="submit" class="btn btn-primary">Import</button>
      </form>
      <?php if ($importReport): ?>
        <div class="alert alert-info" style="margin-top:10px;">
          Import result: <strong><?= (int)$importReport['inserted'] ?></strong> new,
          <strong><?= (int)$importReport['updated'] ?></strong> updated,
          <strong><?= (int)$importReport['errors'] ?></strong> skipped.
        </div>
      <?php endif; ?>
    </div>
  </details>

  <details style="margin-top:10px;">
    <summary><strong>➕ Add product manually</strong></summary>
    <form method="post" style="margin-top:12px; padding:12px 14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="products-form-grid">
        <label class="full">Name <small class="muted">*</small>
          <input type="text" name="name" required maxlength="255" placeholder="e.g. Aroma Diffuser 200ml">
        </label>
        <label>SKU <small class="muted">(optional but needed for CSV upsert)</small>
          <input type="text" name="sku" maxlength="64" placeholder="e.g. AD-200">
        </label>
        <label>Category
          <input type="text" name="category" maxlength="120" placeholder="e.g. Aromatherapy">
        </label>
        <label>Price
          <input type="number" name="price" step="0.01" min="0" placeholder="49.90">
        </label>
        <label>Currency
          <input type="text" name="currency" maxlength="8" placeholder="RM">
        </label>
        <label class="full">Description <small class="muted">
          What is it, what colors, what sizes, what's included. This is what the AI reads to match customer questions.</small>
          <textarea name="description" rows="4"
                    placeholder="Sleek ultrasonic diffuser. Comes in white and slate grey. Runs for 6 hours per fill. Auto-off when water is low."></textarea>
        </label>
        <label>Image URL
          <input type="url" name="image_url" placeholder="https://…/photo.jpg">
        </label>
        <label>Product URL
          <input type="url" name="product_url" placeholder="https://your-store.com/aroma-diffuser">
        </label>
        <label class="full">
          <input type="checkbox" name="out_of_stock" value="1"> Out of stock right now
        </label>
      </div>
      <div style="margin-top:12px;">
        <button type="submit" class="btn btn-primary">Add product</button>
      </div>
    </form>
  </details>

  <form method="get" class="products-filters">
    <label>Search
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="name, SKU, description">
    </label>
    <label>Category
      <select name="category">
        <option value="">— any —</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="align-self:center;">
      <input type="checkbox" name="show_archived" value="1" <?= $showAll ? 'checked' : '' ?>>
      Show archived
    </label>
    <button type="submit" class="btn">Filter</button>
  </form>

  <?php if (!$rows): ?>
    <div class="alert alert-info" style="margin-top:14px;">
      <?= ($q !== '' || $cat !== '') ? 'No products match this filter.' : 'No products yet — add one above or import a CSV.' ?>
    </div>
  <?php else: ?>
    <table class="products-table">
      <thead>
        <tr>
          <th></th>
          <th>Name / SKU</th>
          <th>Category</th>
          <th>Price</th>
          <th>Stock</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td>
            <?php if (!empty($p['image_url'])): ?>
              <img class="prod-thumb" src="<?= e((string)$p['image_url']) ?>" alt="" loading="lazy">
            <?php endif; ?>
          </td>
          <td>
            <strong><?= e((string)$p['name']) ?></strong>
            <?php if (!empty($p['sku'])): ?>
              <div class="muted small">SKU: <code><?= e((string)$p['sku']) ?></code></div>
            <?php endif; ?>
            <?php if ($p['status'] === 'archived'): ?>
              <span class="prod-chip archived">archived</span>
            <?php endif; ?>
          </td>
          <td class="muted small"><?= $p['category'] ? e((string)$p['category']) : '—' ?></td>
          <td class="prod-price">
            <?= $p['price'] !== null
                ? e((string)($p['currency'] ?? '')) . ' ' . number_format((float)$p['price'], 2)
                : '—' ?>
          </td>
          <td>
            <?= (int)$p['in_stock'] === 1
                ? '<span class="prod-chip">in stock</span>'
                : '<span class="prod-chip stock-out">out</span>' ?>
          </td>
          <td>
            <button type="button" class="btn btn-secondary" data-edit="<?= (int)$p['id'] ?>">Edit</button>
          </td>
        </tr>
        <tr>
          <td colspan="6" style="padding:0;">
            <div class="prod-edit-panel" id="prod-edit-<?= (int)$p['id'] ?>">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <div class="products-form-grid">
                  <label class="full">Name<input type="text" name="name" value="<?= e((string)$p['name']) ?>" required maxlength="255"></label>
                  <label>SKU<input type="text" name="sku" value="<?= e((string)($p['sku'] ?? '')) ?>" maxlength="64"></label>
                  <label>Category<input type="text" name="category" value="<?= e((string)($p['category'] ?? '')) ?>" maxlength="120"></label>
                  <label>Price<input type="number" name="price" step="0.01" min="0" value="<?= $p['price'] !== null ? e((string)$p['price']) : '' ?>"></label>
                  <label>Currency<input type="text" name="currency" value="<?= e((string)($p['currency'] ?? '')) ?>" maxlength="8"></label>
                  <label class="full">Description<textarea name="description" rows="4"><?= e((string)($p['description'] ?? '')) ?></textarea></label>
                  <label>Image URL<input type="url" name="image_url" value="<?= e((string)($p['image_url'] ?? '')) ?>"></label>
                  <label>Product URL<input type="url" name="product_url" value="<?= e((string)($p['product_url'] ?? '')) ?>"></label>
                  <label class="full">
                    <input type="checkbox" name="out_of_stock" value="1" <?= (int)$p['in_stock'] === 0 ? 'checked' : '' ?>>
                    Out of stock right now
                  </label>
                  <label class="full">
                    <input type="checkbox" name="archived" value="1" <?= $p['status'] === 'archived' ? 'checked' : '' ?>>
                    Archived (hidden from AI grounding)
                  </label>
                </div>
                <div style="display:flex; gap:8px; margin-top:10px;">
                  <button type="submit" class="btn btn-primary">Save</button>
                  <button type="button" class="btn btn-secondary" data-close="<?= (int)$p['id'] ?>">Cancel</button>
                </div>
              </form>
              <form method="post" onsubmit="return confirm('Delete this product? This can't be undone.');" style="margin-top:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-danger">Delete permanently</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
  document.querySelectorAll('[data-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-edit');
      const panel = document.getElementById('prod-edit-' + id);
      if (panel) panel.classList.toggle('open');
    });
  });
  document.querySelectorAll('[data-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-close');
      const panel = document.getElementById('prod-edit-' + id);
      if (panel) panel.classList.remove('open');
    });
  });
</script>

<?php layout_end(); ?>
