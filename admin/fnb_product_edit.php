<?php
/**
 * F&B product edit — create + edit a menu item, including its variants
 * (mutually-exclusive choices grouped by name) and add-ons (checkbox
 * extras). Also handles image upload.
 *
 * Single form saves everything atomically. Variants + add-ons are
 * rendered as repeaters; a small chunk of JS adds/removes rows client-
 * side. Server always wipes and re-inserts the whole variant/add-on
 * set from POST so the state on save = state in the form.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('The F&B module is not enabled for this workspace.');
}

$db = aiserve_db();

$productId  = (int)($_GET['id']          ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);
$product    = null;

if ($productId > 0) {
    $s = $db->prepare('SELECT * FROM fnb_products WHERE id = ? AND company_id = ? LIMIT 1');
    $s->execute([$productId, $companyId]);
    $product = $s->fetch();
    if (!$product) { http_response_code(404); exit('Product not found.'); }
    $categoryId = (int)($product['category_id'] ?? 0);
}

$msg = ''; $err = '';

// -------------------- POST --------------------
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $name        = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $price       = (float)($_POST['price'] ?? 0);
        $categoryPost= (int)($_POST['category_id'] ?? 0);
        $status      = (string)($_POST['status'] ?? 'active');
        if (!in_array($status, ['active','inactive'], true)) $status = 'active';

        if ($name === '')                     $err = 'Product name is required.';
        elseif (mb_strlen($name) > 150)       $err = 'Name too long (max 150).';
        elseif ($price < 0 || $price > 99999) $err = 'Price must be between 0 and 99,999.';

        // Verify category belongs to this workspace when picked.
        $verifiedCategory = null;
        if (!$err && $categoryPost > 0) {
            $c = $db->prepare('SELECT id FROM fnb_categories WHERE id = ? AND company_id = ?');
            $c->execute([$categoryPost, $companyId]);
            if (!$c->fetchColumn()) $err = 'Category not found.';
            else $verifiedCategory = $categoryPost;
        }

        // -------- image handling --------
        $imageExt = $product['image_ext'] ?? null;
        $newImageUpload = null;
        if (!$err && !empty($_FILES['image']) && (int)($_FILES['image']['error'] ?? 4) === UPLOAD_ERR_OK) {
            $mime = function_exists('mime_content_type') ? (string)mime_content_type($_FILES['image']['tmp_name']) : '';
            $extMap = [
                'image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'image/gif' => 'gif',
            ];
            if (!isset($extMap[$mime])) {
                $err = 'Unsupported image type. Use JPG / PNG / WebP / GIF.';
            } elseif ((int)$_FILES['image']['size'] > 4 * 1024 * 1024) {
                $err = 'Image too big (max 4 MB).';
            } else {
                $newImageUpload = [
                    'ext' => $extMap[$mime],
                    'tmp' => $_FILES['image']['tmp_name'],
                ];
            }
        }
        if (!$err && !empty($_POST['image_delete']) && $imageExt) {
            $existingPath = __DIR__ . '/../uploads/fnb/' . $companyId . '/' . $productId . '.' . $imageExt;
            if (is_file($existingPath)) @unlink($existingPath);
            $imageExt = null;
        }

        if (!$err) {
            try {
                $db->beginTransaction();

                if ($product) {
                    $db->prepare(
                        'UPDATE fnb_products
                         SET category_id = ?, name = ?, description = ?, price = ?, status = ?
                         WHERE id = ? AND company_id = ?'
                    )->execute([$verifiedCategory, $name, $description ?: null, $price, $status, $productId, $companyId]);
                } else {
                    $sortMax = (int)$db->query(
                        "SELECT COALESCE(MAX(sort_order), 0) FROM fnb_products WHERE company_id = $companyId"
                    )->fetchColumn();
                    $db->prepare(
                        'INSERT INTO fnb_products
                            (company_id, category_id, name, description, price, sort_order, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$companyId, $verifiedCategory, $name, $description ?: null, $price, $sortMax + 10, $status]);
                    $productId = (int)$db->lastInsertId();
                }

                // -------- save image file if uploaded --------
                if ($newImageUpload) {
                    // Remove any old file whose ext differs from the new one.
                    if ($imageExt && $imageExt !== $newImageUpload['ext']) {
                        $oldPath = __DIR__ . '/../uploads/fnb/' . $companyId . '/' . $productId . '.' . $imageExt;
                        if (is_file($oldPath)) @unlink($oldPath);
                    }
                    $dir = __DIR__ . '/../uploads/fnb/' . $companyId;
                    if (!is_dir($dir)) @mkdir($dir, 0775, true);
                    $dest = $dir . '/' . $productId . '.' . $newImageUpload['ext'];
                    @move_uploaded_file($newImageUpload['tmp'], $dest);
                    @chmod($dest, 0644);
                    $imageExt = $newImageUpload['ext'];
                }
                $db->prepare('UPDATE fnb_products SET image_ext = ? WHERE id = ? AND company_id = ?')
                   ->execute([$imageExt, $productId, $companyId]);

                // -------- variants: wipe + re-insert --------
                $db->prepare('DELETE FROM fnb_variants WHERE product_id = ?')->execute([$productId]);
                $vGroups = (array)($_POST['variant_group'] ?? []);
                $vNames  = (array)($_POST['variant_name']  ?? []);
                $vPrices = (array)($_POST['variant_price'] ?? []);
                $vDefault= (array)($_POST['variant_default'] ?? []);
                $vIns = $db->prepare(
                    'INSERT INTO fnb_variants (product_id, group_name, name, price_delta, sort_order, is_default)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($vNames as $i => $vname) {
                    $g = trim((string)($vGroups[$i] ?? ''));
                    $n = trim((string)$vname);
                    if ($g === '' || $n === '') continue;
                    $vIns->execute([
                        $productId, mb_substr($g, 0, 80), mb_substr($n, 0, 120),
                        (float)($vPrices[$i] ?? 0), 10 * ($i + 1),
                        (isset($vDefault[$i]) && $vDefault[$i] === '1') ? 1 : 0,
                    ]);
                }

                // -------- add-ons: wipe + re-insert --------
                $db->prepare('DELETE FROM fnb_addons WHERE product_id = ?')->execute([$productId]);
                $aNames  = (array)($_POST['addon_name']  ?? []);
                $aPrices = (array)($_POST['addon_price'] ?? []);
                $aIns = $db->prepare(
                    'INSERT INTO fnb_addons (product_id, name, price_delta, sort_order)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($aNames as $i => $an) {
                    $n = trim((string)$an);
                    if ($n === '') continue;
                    $aIns->execute([
                        $productId, mb_substr($n, 0, 120),
                        (float)($aPrices[$i] ?? 0), 10 * ($i + 1),
                    ]);
                }

                $db->commit();
                log_activity($companyId, (int)$current_user['id'],
                    $product ? 'fnb_product_updated' : 'fnb_product_created',
                    'fnb_product', $productId, $name);
                redirect('/admin/fnb_product_edit.php?id=' . $productId . '&saved=1');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[AiServe fnb_product_edit] ' . $e->getMessage());
                $err = 'Could not save product: ' . $e->getMessage();
            }
        }
    }
}

// -------------------- Load ------------------------------
if ($productId > 0) {
    $s = $db->prepare('SELECT * FROM fnb_products WHERE id = ? AND company_id = ? LIMIT 1');
    $s->execute([$productId, $companyId]);
    $product = $s->fetch();
}
$categories = $db->prepare('SELECT id, name FROM fnb_categories WHERE company_id = ? ORDER BY sort_order, id');
$categories->execute([$companyId]);
$categories = $categories->fetchAll();

$variants = []; $addons = [];
if ($product) {
    $v = $db->prepare('SELECT * FROM fnb_variants WHERE product_id = ? ORDER BY group_name, sort_order');
    $v->execute([$productId]); $variants = $v->fetchAll();
    $a = $db->prepare('SELECT * FROM fnb_addons WHERE product_id = ? ORDER BY sort_order');
    $a->execute([$productId]); $addons = $a->fetchAll();
}

$currency = platform_setting('pricing_currency', 'RM');
$imageUrl = ($product && !empty($product['image_ext']))
    ? '/api/fnb_product_image.php?product_id=' . $productId . '&v=' . strtotime($product['updated_at'] ?? 'now')
    : '';

layout_start($current_user, $product ? 'Edit product · ' . $product['name'] : 'New product', 'fnb_menu');
?>

<style>
.pe-grid { display: grid; gap: 16px; grid-template-columns: 1fr 320px; }
@media (max-width: 800px) { .pe-grid { grid-template-columns: 1fr; } }
.pe-card { background: #fff; border: 1px solid #e3e8ee; border-radius: 12px; padding: 16px; }
.pe-card h3 {
  margin: 0 0 12px; font-size: 12.5px; text-transform: uppercase;
  letter-spacing: .04em; color: #64748b;
}
.pe-form label { display: block; font-size: 12.5px; color: #64748b; margin-bottom: 10px; }
.pe-form label input[type="text"],
.pe-form label input[type="number"],
.pe-form label textarea,
.pe-form label select {
  display: block; width: 100%; padding: 6px 8px; margin-top: 4px;
  border: 1px solid #e3e8ee; border-radius: 6px; font-size: 14px;
}
.pe-form textarea { min-height: 80px; }

.pe-thumb {
  width: 100%; aspect-ratio: 4/3; background: #f6f9fb;
  border: 1px dashed #cbd5e1; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden; margin-bottom: 8px;
}
.pe-thumb img { width: 100%; height: 100%; object-fit: cover; }

.pe-repeater { display: grid; gap: 8px; }
.pe-repeater-row {
  display: grid; gap: 6px; align-items: center;
  grid-template-columns: 1fr 1fr 100px 90px 30px;
  padding: 6px; background: #f6f9fb; border-radius: 6px;
}
.pe-repeater-row.no-group {
  grid-template-columns: 1fr 100px 30px;
}
.pe-repeater-row input {
  padding: 4px 8px; font-size: 13px;
  border: 1px solid #e3e8ee; border-radius: 4px;
}
.pe-repeater-row .rm { background: transparent; border: none; color: #DC2626; cursor: pointer; font-size: 16px; }
</style>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Product saved.</div>
  <?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <div class="pe-grid">

    <!-- LEFT column -->
    <div>

      <!-- Basic info -->
      <div class="pe-card pe-form">
        <h3><?= $product ? 'Edit product · #' . $productId : 'New product' ?></h3>

        <label>Name
          <input type="text" name="name" required maxlength="150"
                 value="<?= e($product['name'] ?? ($_POST['name'] ?? '')) ?>"
                 placeholder="e.g. Chicken Rice">
        </label>

        <label>Category
          <select name="category_id">
            <option value="0">Uncategorized</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$categoryId === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Base price (<?= e($currency) ?>)
          <input type="number" name="price" step="0.01" min="0" required
                 value="<?= e((string)($product['price'] ?? '0.00')) ?>">
        </label>

        <label>Description
          <textarea name="description" maxlength="4000" placeholder="What's in it? Any highlights, allergens…"><?= e((string)($product['description'] ?? '')) ?></textarea>
        </label>

        <label>Status
          <select name="status">
            <option value="active"   <?= (($product['status'] ?? 'active') === 'active')   ? 'selected' : '' ?>>Active — available for order</option>
            <option value="inactive" <?= (($product['status'] ?? '')       === 'inactive') ? 'selected' : '' ?>>Inactive — hidden from customers</option>
          </select>
        </label>
      </div>

      <!-- Variants -->
      <div class="pe-card" style="margin-top:16px;">
        <h3>Variants <small class="muted">(mutually-exclusive choices, e.g. Size, Spice level)</small></h3>
        <p class="muted small" style="margin: 0 0 10px;">
          Group them under a group name — a customer picks <em>one</em> per group. Mark one as default per group.
          Price delta adds to the base price.
        </p>
        <div class="pe-repeater" id="variant-rows">
          <?php foreach ($variants as $i => $v): ?>
            <div class="pe-repeater-row">
              <input type="text" name="variant_group[]" placeholder="Group (e.g. Size)" value="<?= e($v['group_name']) ?>" maxlength="80" required>
              <input type="text" name="variant_name[]"  placeholder="Name (e.g. Large)"  value="<?= e($v['name']) ?>"        maxlength="120" required>
              <input type="number" name="variant_price[]" step="0.01" placeholder="+ price" value="<?= e((string)$v['price_delta']) ?>">
              <label style="margin: 0; font-size: 12px; color: #64748b; display: flex; align-items:center; gap:4px;">
                <input type="checkbox" name="variant_default[<?= $i ?>]" value="1" <?= (int)$v['is_default'] === 1 ? 'checked' : '' ?>>
                default
              </label>
              <button type="button" class="rm" onclick="this.parentElement.remove()">×</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm" onclick="addVariant()" style="margin-top:8px;">+ Add variant</button>
      </div>

      <!-- Add-ons -->
      <div class="pe-card" style="margin-top:16px;">
        <h3>Add-ons <small class="muted">(optional extras, checkbox-style)</small></h3>
        <p class="muted small" style="margin: 0 0 10px;">
          Customers can pick <em>any</em> of these. Each carries a price delta added on top.
        </p>
        <div class="pe-repeater" id="addon-rows">
          <?php foreach ($addons as $i => $a): ?>
            <div class="pe-repeater-row no-group">
              <input type="text" name="addon_name[]" placeholder="Name (e.g. Extra egg)" value="<?= e($a['name']) ?>" maxlength="120" required>
              <input type="number" name="addon_price[]" step="0.01" placeholder="+ price" value="<?= e((string)$a['price_delta']) ?>">
              <button type="button" class="rm" onclick="this.parentElement.remove()">×</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm" onclick="addAddon()" style="margin-top:8px;">+ Add add-on</button>
      </div>

    </div>

    <!-- RIGHT column -->
    <div>
      <div class="pe-card">
        <h3>Product image</h3>
        <div class="pe-thumb">
          <?php if ($imageUrl): ?>
            <img src="<?= e($imageUrl) ?>" alt="">
          <?php else: ?>
            <span class="muted small">No image yet</span>
          <?php endif; ?>
        </div>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" style="display:block; margin-bottom:6px;">
        <small class="muted">JPG, PNG, WebP, or GIF. Max 4 MB. Square works best.</small>

        <?php if ($imageUrl): ?>
          <label style="display:flex; gap:6px; align-items:center; margin-top: 10px; color:#DC2626;">
            <input type="checkbox" name="image_delete" value="1"> Remove current image on save
          </label>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="width:100%; margin-top:16px;">
          <?= $product ? 'Save changes' : 'Create product' ?>
        </button>
        <a class="btn" href="/admin/fnb_menu.php" style="width:100%; text-align:center; margin-top:6px;">Cancel</a>
      </div>
    </div>
  </div>
</form>

<script>
function addVariant() {
  var div = document.createElement('div');
  div.className = 'pe-repeater-row';
  div.innerHTML =
    '<input type="text" name="variant_group[]" placeholder="Group (e.g. Size)" maxlength="80" required>'
  + '<input type="text" name="variant_name[]"  placeholder="Name (e.g. Large)" maxlength="120" required>'
  + '<input type="number" name="variant_price[]" step="0.01" placeholder="+ price" value="0">'
  + '<label style="margin:0; font-size:12px; color:#64748b; display:flex; align-items:center; gap:4px;">'
  +   '<input type="checkbox" name="variant_default[' + Date.now() + ']" value="1"> default'
  + '</label>'
  + '<button type="button" class="rm" onclick="this.parentElement.remove()">×</button>';
  document.getElementById('variant-rows').appendChild(div);
}
function addAddon() {
  var div = document.createElement('div');
  div.className = 'pe-repeater-row no-group';
  div.innerHTML =
    '<input type="text" name="addon_name[]" placeholder="Name" maxlength="120" required>'
  + '<input type="number" name="addon_price[]" step="0.01" placeholder="+ price" value="0">'
  + '<button type="button" class="rm" onclick="this.parentElement.remove()">×</button>';
  document.getElementById('addon-rows').appendChild(div);
}
</script>

<?php layout_end(); ?>
