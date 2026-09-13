<?php
/**
 * F&B menu bulk import page.
 *
 * Same UX shape as /contact_import.php — template download strip,
 * file picker with live size + row-count hint, full-screen overlay
 * during the POST, format cheatsheet at the bottom.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/fnb_import.php';
require_once __DIR__ . '/../inc/fnb_helpers.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];

if (!fnb_module_active($companyId)) {
    http_response_code(403);
    exit('The F&B module is not enabled for this workspace.');
}

$result = null;
if (is_post()) {
    csrf_check();
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    $result = fnb_import_run($companyId, $_FILES['csv'] ?? []);
}

layout_start($current_user, 'F&B · Import menu', 'fnb_menu');
?>
<div class="card">
  <div class="card-head">
    <h2>🍜 Import menu from CSV / XLSX</h2>
    <a class="btn" href="/admin/fnb_menu.php">← Back to menu</a>
  </div>

  <?php if ($result): ?>
    <?php if ($result['ok']): ?>
      <div class="alert alert-success">
        <strong>Import complete.</strong>
        <?= (int)$result['created'] ?> new product(s),
        <?= (int)$result['updated'] ?> updated
        (+<?= (int)$result['categories_created'] ?> new categor(y|ies),
         <?= (int)$result['variants_created'] ?> variant row(s),
         <?= (int)$result['addons_created'] ?> add-on row(s))
        <?php if ($result['errors']): ?>, <?= count($result['errors']) ?> error(s)<?php endif; ?>.
      </div>
    <?php else: ?>
      <div class="alert alert-error"><?= e((string)$result['error']) ?></div>
    <?php endif; ?>

    <?php if (!empty($result['errors'])): ?>
      <details style="margin: 8px 0 16px;">
        <summary class="muted small" style="cursor:pointer;">Error details (<?= count($result['errors']) ?>)</summary>
        <ul class="small" style="margin: 8px 0 0 20px; color:#c33;">
          <?php foreach ($result['errors'] as $err): ?>
            <li>Line <?= (int)$err['line'] ?>: <?= e((string)$err['reason']) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Template download strip -->
  <div style="margin-bottom: 14px; padding: 12px 14px;
       background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
       border: 1px solid #bbf7d0; border-radius: 8px;
       display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 260px;">
      <strong style="color:#14532d;">📥 First time importing a menu?</strong>
      <div class="muted small" style="margin-top: 2px;">
        Download a ready-to-fill template with 10 sample Malaysian F&amp;B items
        (variants + add-ons + prices) and a "Format guide" sheet.
      </div>
    </div>
    <div style="display: flex; gap: 8px;">
      <a class="btn btn-sm btn-primary"
         href="/assets/templates/fnb_menu_import_template.xlsx"
         download="fnb_menu_import_template.xlsx"
         style="background:#16a34a; border-color:#16a34a;">📊 XLSX (recommended)</a>
      <a class="btn btn-sm"
         href="/assets/templates/fnb_menu_import_template.csv"
         download="fnb_menu_import_template.csv">📄 CSV</a>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="form-grid" id="import-form">
    <?= csrf_field() ?>
    <label>File
      <input type="file" name="csv" id="import-file"
             accept=".csv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <small class="muted">
        Max 5 MB. Header row required. Recognised columns:
        <code>category, name, description, price, variants, addons, status</code>.
      </small>
      <div id="file-hint" style="display:none; margin-top:6px; padding:8px 12px;
           background:#eff6ff; border:1px solid #dbeafe; border-radius:6px;
           font-size:13px; color:#1e3a8a;"></div>
    </label>

    <button type="submit" class="btn btn-primary" id="import-btn">Import</button>
  </form>

  <!-- Reuse the overlay pattern from contact_import.php -->
  <div id="import-overlay" style="display:none; position:fixed; inset:0;
       background:rgba(15,23,42,0.72); z-index:9999; align-items:center;
       justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; padding:28px 32px;
         max-width:440px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,.28);
         text-align:center;">
      <div style="width:56px; height:56px; margin:0 auto 18px; border-radius:50%;
           border:5px solid #dcfce7; border-top-color:#16a34a;
           animation:hw-spin 1s linear infinite;"></div>
      <h3 style="margin:0 0 8px; font-size:18px; color:#0f172a;">Importing menu…</h3>
      <p id="import-hint-msg" style="margin:0 0 6px; color:#475569; font-size:14px;">
        Creating categories, products, variants + add-ons.
      </p>
      <p class="muted small" style="margin:14px 0 0; padding-top:14px; border-top:1px solid #eef2f7;">
        ⚠️ Please don't close this tab. Bigger menus take longer:
        ~50 items ≈ 3 sec · ~500 items ≈ 30 sec.
      </p>
    </div>
  </div>
  <style>@keyframes hw-spin { to { transform: rotate(360deg); } }</style>

  <hr style="margin: 24px 0; border:none; border-top:1px solid var(--c-border);">

  <h3>📋 File format cheatsheet</h3>
  <p class="muted small">
    See the <strong>Format guide</strong> sheet inside the xlsx for the full spec.
  </p>
  <table class="data-table small" style="max-width: 820px;">
    <thead>
      <tr><th>Column</th><th>Required?</th><th>Format</th></tr>
    </thead>
    <tbody>
      <tr><td><code>category</code></td><td>No</td>
        <td>Category name. Auto-created if new. Blank = uncategorised.</td></tr>
      <tr><td><code>name</code></td><td><strong>Yes</strong></td>
        <td>Product name. Matched on (workspace + name) for upsert.</td></tr>
      <tr><td><code>description</code></td><td>No</td>
        <td>Short description shown to customers.</td></tr>
      <tr><td><code>price</code></td><td><strong>Yes</strong></td>
        <td>Base price in RM (number only, no currency symbol).</td></tr>
      <tr><td><code>variants</code></td><td>No</td>
        <td>
          <code>Group:Opt1=+0*|Opt2=+3.00</code> — the <strong>*</strong> marks the default.<br>
          Multiple groups separated by <code>;</code> —
          <code>Spice:Mild=0|Medium=0*|Spicy=0;Portion:Regular=0*|Large=+3.00</code>
        </td></tr>
      <tr><td><code>addons</code></td><td>No</td>
        <td>Pipe-separated: <code>Extra egg=+1.50|Extra sauce=+1.00|No cockles=0</code></td></tr>
      <tr><td><code>status</code></td><td>No</td>
        <td><code>active</code> (default) or <code>inactive</code>.</td></tr>
    </tbody>
  </table>

  <p class="muted small" style="margin-top: 14px;">
    <strong>On re-import:</strong> products matched by name are updated in place,
    and their variants + add-ons are wiped and rebuilt from the new row (the file
    is the source of truth per product). Products NOT in the file are left alone —
    this is additive/update, not a full sync.
  </p>
</div>

<script>
(function () {
  var fileEl = document.getElementById('import-file');
  var hintEl = document.getElementById('file-hint');
  var msgEl  = document.getElementById('import-hint-msg');
  var form   = document.getElementById('import-form');
  var btn    = document.getElementById('import-btn');
  var overlay= document.getElementById('import-overlay');

  fileEl.addEventListener('change', function () {
    var f = fileEl.files && fileEl.files[0];
    if (!f) { hintEl.style.display = 'none'; return; }
    var sizeKB = Math.round(f.size / 1024);
    var sizeText = sizeKB >= 1024 ? (sizeKB / 1024).toFixed(1) + ' MB' : sizeKB + ' KB';
    var ext = (f.name.split('.').pop() || '').toLowerCase();

    if (['csv','txt','tsv'].indexOf(ext) !== -1 && f.size < 5 * 1024 * 1024) {
      var reader = new FileReader();
      reader.onload = function () {
        var text = String(reader.result || '');
        var lines = (text.match(/\n/g) || []).length + (text.length > 0 ? 1 : 0);
        var items = Math.max(0, lines - 1);
        hintEl.textContent = '📄 ' + f.name + ' · ' + sizeText + ' · ~' + items.toLocaleString() + ' item(s)';
        hintEl.style.display = '';
      };
      reader.onerror = function () {
        hintEl.textContent = '📄 ' + f.name + ' · ' + sizeText;
        hintEl.style.display = '';
      };
      reader.readAsText(f);
    } else {
      hintEl.textContent = '📄 ' + f.name + ' · ' + sizeText;
      hintEl.style.display = '';
    }
  });

  form.addEventListener('submit', function () {
    var text = hintEl.textContent || '';
    var m = text.match(/~([\d,]+) item/);
    if (m) msgEl.textContent = 'Importing ~' + m[1] + ' menu items…';
    overlay.style.display = 'flex';
    btn.disabled = true;
    btn.textContent = 'Importing…';
  });
})();
</script>

<?php layout_end(); ?>
