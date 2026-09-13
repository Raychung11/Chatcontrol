<?php
/**
 * Bulk contact import via CSV or XLSX.
 *
 * Accepts a flexible shape — we scan for the first row that looks like
 * a header (has a phone-alias in one of its cells), then:
 *   - Match columns by name (case-insensitive, punctuation-tolerant).
 *   - If no header row is detectable, treat every row as data assuming
 *     phone, name, tags, branch in that order.
 *
 * Broad alias set — the same importer handles hand-rolled CSVs AND
 * CRM exports like MemberReport.xlsx (columns: CustomerNo,
 * Membership Code, Membership No, Card No, Printed Name, Status Flag,
 * Join Date, Expiry Date, Date Of Birth, Email, Mobile No). Chrome
 * rows above the header (report title, printed-on, filters) are
 * auto-skipped.
 *
 * Malaysian-friendly phone normalization: strips + / 0 / spaces /
 * dashes, and auto-prefixes a 10-digit local number with 60 when the
 * country code is missing.
 *
 * Every row becomes an upsert against contacts keyed on
 * (company_id, wa_id, platform=whatsapp). Duplicates within the file
 * itself are silently dedupped.
 */

require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/xlsx_reader.php';
require_once __DIR__ . '/inc/contacts_tags.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

$result = null;   // filled on POST after the import runs

if (is_post()) {
    csrf_check();
    // 30 s isn't enough for a 50 k-row xlsx. Give the importer 5 min
    // and lift memory temporarily — big xlsx sharedStrings can pull
    // 100 MB+ into RAM even after we parse.
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    $result = contact_import_run($companyId, (int)$current_user['id']);
}

layout_start($current_user, 'Import contacts', 'contacts');
?>
<div class="card">
  <div class="card-head">
    <h2>Import contacts from CSV</h2>
    <a class="btn" href="/contacts.php">← Back to contacts</a>
  </div>

  <?php if ($result): ?>
    <?php if ($result['ok']): ?>
      <div class="alert alert-success">
        <strong>Import complete.</strong>
        <?= (int)$result['created'] ?> new,
        <?= (int)$result['updated'] ?> updated,
        <?= (int)$result['skipped'] ?> skipped
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

  <!-- Template download strip — sits above the file picker so operators
       who arrive here without a file grab a template first without
       scrolling to find the format docs. -->
  <div style="margin-bottom: 14px; padding: 12px 14px;
       background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
       border: 1px solid #bbf7d0; border-radius: 8px;
       display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
    <div style="flex: 1; min-width: 260px;">
      <strong style="color:#14532d;">📥 First time importing?</strong>
      <div class="muted small" style="margin-top: 2px;">
        Download a ready-to-fill template with the correct columns + 6 example rows + a "How to use" instructions sheet.
      </div>
    </div>
    <div style="display: flex; gap: 8px;">
      <a class="btn btn-sm btn-primary"
         href="/assets/templates/contact_import_template.xlsx"
         download="contact_import_template.xlsx"
         style="background:#16a34a; border-color:#16a34a;">📊 XLSX (recommended)</a>
      <a class="btn btn-sm"
         href="/assets/templates/contact_import_template.csv"
         download="contact_import_template.csv">📄 CSV</a>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="form-grid" id="import-form">
    <?= csrf_field() ?>
    <label>File
      <input type="file" name="csv" id="import-file"
             accept=".csv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <small class="muted">
        Max 5 MB. Accepts <code>.csv</code>, <code>.xlsx</code>, or a plain <code>.txt</code>
        (comma or tab-separated). Report chrome rows above the header
        (title, "Printed on…", filter descriptions) are auto-skipped.
      </small>
      <div id="file-hint" style="display:none; margin-top:6px; padding:8px 12px;
           background:#eff6ff; border:1px solid #dbeafe; border-radius:6px;
           font-size:13px; color:#1e3a8a;"></div>
    </label>

    <label class="check-row">
      <input type="checkbox" name="update_existing" value="1" checked>
      <span>Update existing contacts</span>
      <small class="muted">If unticked, contacts already in the system are skipped instead of updated.</small>
    </label>

    <label class="check-row">
      <input type="checkbox" name="active_only" value="1">
      <span>Skip inactive rows <small class="muted">(Status Flag ≠ 1)</small></span>
      <small class="muted">
        For CRM exports with a <code>Status Flag</code> column: only import rows where the flag
        is <code>1</code>, <code>active</code>, <code>yes</code>, or <code>true</code>.
        Ignored if the file has no status column.
      </small>
    </label>

    <label>Default tag <small class="muted">(optional)</small>
      <input type="text" name="default_tag" maxlength="60" placeholder="e.g. imported-2026-07">
      <small class="muted">
        Applied to every imported contact. Combined with tags in the file's <code>tags</code>
        column. Useful for tracking which upload a contact came from.
      </small>
    </label>

    <button type="submit" class="btn btn-primary" id="import-btn">Import</button>
  </form>

  <!-- Full-screen overlay while the POST is in flight -->
  <div id="import-overlay" style="display:none; position:fixed; inset:0;
       background:rgba(15,23,42,0.72); z-index:9999; align-items:center;
       justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; padding:28px 32px;
         max-width:440px; width:100%; box-shadow:0 20px 40px rgba(0,0,0,.28);
         text-align:center;">
      <div style="width:56px; height:56px; margin:0 auto 18px; border-radius:50%;
           border:5px solid #dbeafe; border-top-color:#0072B2;
           animation:hw-spin 1s linear infinite;"></div>
      <h3 style="margin:0 0 8px; font-size:18px; color:#0f172a;">Importing contacts…</h3>
      <p id="import-hint-msg" style="margin:0 0 6px; color:#475569; font-size:14px;">
        Reading your file and creating contacts.
      </p>
      <p class="muted small" style="margin:14px 0 0; padding-top:14px; border-top:1px solid #eef2f7;">
        ⚠️ Please don't close this tab or hit Back — the import runs
        as one server request. Bigger files take longer:
        <br>~1,000 rows ≈ 5 sec · ~10,000 rows ≈ 30 sec · ~50,000 rows ≈ 2 min
      </p>
    </div>
  </div>
  <style>@keyframes hw-spin { to { transform: rotate(360deg); } }</style>

  <script>
  (function () {
    var fileEl = document.getElementById('import-file');
    var hintEl = document.getElementById('file-hint');
    var msgEl  = document.getElementById('import-hint-msg');
    var form   = document.getElementById('import-form');
    var btn    = document.getElementById('import-btn');
    var overlay= document.getElementById('import-overlay');

    // Live preview when the user picks a file: shows filename, size,
    // rough row count (CSV only — xlsx would need a parser in the browser)
    // and estimated import time.
    fileEl.addEventListener('change', function () {
      var f = fileEl.files && fileEl.files[0];
      if (!f) { hintEl.style.display = 'none'; return; }

      var sizeKB = Math.round(f.size / 1024);
      var sizeText = sizeKB >= 1024
        ? (sizeKB / 1024).toFixed(1) + ' MB'
        : sizeKB + ' KB';
      var ext = (f.name.split('.').pop() || '').toLowerCase();

      // For CSV / TXT / TSV we can peek at the file and count lines
      // for a quick row estimate. XLSX is a zip so we can't peek in
      // the browser without a library — fall back to size-based hint.
      if (['csv','txt','tsv'].indexOf(ext) !== -1 && f.size < 20 * 1024 * 1024) {
        var reader = new FileReader();
        reader.onload = function () {
          var text = String(reader.result || '');
          // Rough row count: count newlines. Minus 1 for the (likely) header.
          var lines = (text.match(/\n/g) || []).length + (text.length > 0 ? 1 : 0);
          var rows  = Math.max(0, lines - 1);
          renderHint(f.name, sizeText, rows);
        };
        reader.onerror = function () { renderHint(f.name, sizeText, null); };
        reader.readAsText(f);
      } else {
        renderHint(f.name, sizeText, null);
      }
    });

    function renderHint(name, sizeText, rowCount) {
      var estSec = null;
      if (rowCount !== null) {
        // ~200 rows/sec locally on the VPS (from smoke test).
        estSec = Math.max(1, Math.ceil(rowCount / 200));
      }
      var human = '';
      if (rowCount !== null) {
        human = ' · ~' + rowCount.toLocaleString() + ' row(s)';
        if (estSec !== null) {
          if (estSec < 60) human += ' · ~' + estSec + 's to import';
          else             human += ' · ~' + Math.round(estSec / 60) + ' min to import';
        }
      }
      hintEl.textContent = '📄 ' + name + ' · ' + sizeText + human;
      hintEl.style.display = '';
    }

    form.addEventListener('submit', function () {
      // Personalise the overlay message with the row count if we have one.
      var text = hintEl.textContent || '';
      var m = text.match(/~([\d,]+) row/);
      if (m) msgEl.textContent = 'Importing ~' + m[1] + ' rows into your contacts…';
      overlay.style.display = 'flex';
      btn.disabled = true;
      btn.textContent = 'Importing…';
      // Note: no return false — form still submits normally to the same URL.
      // The response HTML replaces the whole page and the overlay dies with it.
    });
  })();
  </script>

  <hr style="margin: 24px 0; border:none; border-top:1px solid var(--c-border);">

  <h3>File format</h3>
  <p class="muted small">
    <strong>Recommended headers:</strong> <code>phone</code>, <code>name</code>,
    <code>tags</code>, <code>branch</code>. Column order doesn't matter — matching is case-insensitive
    and punctuation-tolerant. <code>tags</code> can be one tag or several separated by <code>|</code>.
    <code>branch</code> takes a branch name — if it doesn't exist yet, we auto-create it.
  </p>
  <pre style="background:#f6f9fb; border:1px solid #e3e8ee; border-radius:6px; padding:12px; overflow-x:auto;">phone,name,tags,branch
60123456789,Vicky Tan,vip|boat-tour,KL Office
60198765432,Ali Rahman,new-lead,Penang Office
6591234567,Jane Doe,,</pre>

  <p class="muted small" style="margin-top:14px;">
    <strong>Also recognised (CRM-export style):</strong>
  </p>
  <table class="data-table small" style="max-width: 720px;">
    <thead><tr><th>Field</th><th>Any of these headers match</th></tr></thead>
    <tbody>
      <tr><td>phone</td><td><code>phone</code>, <code>mobile</code>, <code>mobile no</code>, <code>mobileno</code>, <code>hp</code>, <code>handphone</code>, <code>number</code>, <code>whatsapp</code>, <code>wa</code>, <code>waid</code>, <code>msisdn</code>, <code>contactno</code></td></tr>
      <tr><td>name</td><td><code>name</code>, <code>fullname</code>, <code>displayname</code>, <code>contactname</code>, <code>printedname</code>, <code>printname</code>, <code>customername</code>, <code>membername</code></td></tr>
      <tr><td>tags</td><td><code>tag</code>, <code>tags</code>, <code>label</code>, <code>labels</code></td></tr>
      <tr><td>branch</td><td><code>branch</code>, <code>office</code>, <code>location</code>, <code>businessunit</code>, <code>outlet</code>, <code>shop</code>, <code>store</code></td></tr>
      <tr><td>email</td><td><code>email</code>, <code>emailaddress</code>, <code>mail</code></td></tr>
      <tr><td>external id</td><td><code>customerno</code>, <code>membershipno</code>, <code>memberno</code>, <code>membercode</code>, <code>cardno</code>, <code>id</code>, <code>code</code>, <code>externalid</code></td></tr>
      <tr><td>status</td><td><code>status</code>, <code>statusflag</code>, <code>active</code></td></tr>
    </tbody>
  </table>

  <p class="muted small" style="margin-top:14px;">
    <strong>Phone format:</strong> country code + number, digits only.
    <code>+</code>, spaces, dashes, and a leading <code>00</code> are stripped automatically.
    <strong>10-digit Malaysian numbers starting with 0 (like <code>0123456789</code>) get
    auto-prefixed with 60</strong> → <code>60123456789</code>. E.164 range check: 8–15 digits.
    Malformed rows are logged as errors and skipped, the rest still import.
  </p>
</div>

<?php layout_end(); ?>

<?php
/**
 * The importer proper. Kept inline (rather than in inc/) because this
 * is the only caller — a top-level helper file would just add a require.
 *
 * @return array{ok:bool,error?:string,created:int,updated:int,skipped:int,errors:array}
 */
function contact_import_run(int $companyId, int $userId): array
{
    $blank = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    if (empty($_FILES['csv']) || (int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file uploaded (or upload failed).'] + $blank;
    }
    $file = $_FILES['csv'];
    if ((int)$file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too big (max 5 MB).'] + $blank;
    }
    $updateExisting = !empty($_POST['update_existing']);
    $activeOnly     = !empty($_POST['active_only']);
    $defaultTag     = trim((string)($_POST['default_tag'] ?? ''));

    // Route on extension + magic bytes. xlsx = zip (starts with "PK");
    // csv/txt = plain text.
    $origName = (string)($file['name'] ?? '');
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $isXlsx   = $ext === 'xlsx' || contact_import_looks_like_zip($file['tmp_name']);

    if ($isXlsx) {
        $x = xlsx_read_rows($file['tmp_name']);
        if (!$x['ok']) {
            return ['ok' => false, 'error' => 'Could not read xlsx: ' . (string)$x['error']] + $blank;
        }
        $rowsRead = $x['rows'];
    } else {
        $rowsRead = contact_import_read_csv($file['tmp_name']);
        if ($rowsRead === null) {
            return ['ok' => false, 'error' => 'CSV appears to be empty or unreadable.'] + $blank;
        }
    }
    if (!$rowsRead) {
        return ['ok' => false, 'error' => 'File contains no rows.'] + $blank;
    }

    // Header detection: scan the first ~20 rows for one that contains a
    // recognisable phone-alias header. Rows above it are treated as
    // chrome (report title, "Printed on…", filter descriptions) and
    // skipped. If no row looks like a header, fall through to the
    // legacy 'phone,name,tags,branch' positional assumption.
    $headerRowIndex = null;
    $col = null;
    $scanLimit = min(20, count($rowsRead));
    for ($i = 0; $i < $scanLimit; $i++) {
        $candidate = array_map(fn($v) => trim((string)$v), $rowsRead[$i]);
        // Skip visibly-chrome rows (0 or 1 non-empty cells).
        $nonEmpty = array_filter($candidate, fn($v) => $v !== '');
        if (count($nonEmpty) < 2) continue;
        // Pass the first 5 rows AFTER the candidate so the mapper can
        // pick the most-populated column when multiple columns match the
        // same field (e.g. Membership No wins over an empty CustomerNo).
        $sample = array_slice($rowsRead, $i + 1, 5);
        $mapped = contact_import_map_columns($candidate, $sample);
        if ($mapped !== null && $mapped['phone'] !== -1) {
            $headerRowIndex = $i;
            $col = $mapped;
            break;
        }
    }
    if ($headerRowIndex === null) {
        // No header found — assume positional order: phone / name / tags / branch.
        $col = ['phone' => 0, 'name' => 1, 'tags' => 2, 'branch' => 3,
                'email' => -1, 'external_id' => -1, 'status' => -1];
        $dataStart = 0;
    } else {
        $dataStart = $headerRowIndex + 1;
    }
    // Discard chrome + header rows so the loop below only sees data.
    $chromeSkipped = $dataStart;
    $rowsRead = array_slice($rowsRead, $dataStart);
    // Track absolute line numbers for error messages so operators can
    // find the row in their original file.
    $lineOffset = $chromeSkipped;   // first data row's absolute index = $chromeSkipped + 1

    // Pre-load workspace branches so we can resolve names -> ids without
    // hitting the DB per row. Also auto-create any branch name seen in
    // the CSV that doesn't yet exist so imports Just Work.
    $branchCache = [];  // name (lower) -> id
    $bLoad = aiserve_db()->prepare(
        'SELECT id, name FROM branches WHERE company_id = ?'
    );
    $bLoad->execute([$companyId]);
    foreach ($bLoad->fetchAll() as $bRow) {
        $branchCache[mb_strtolower((string)$bRow['name'])] = (int)$bRow['id'];
    }

    $db = aiserve_db();

    $created = 0; $updated = 0; $skipped = 0;
    $errors  = [];
    $seenInFile = [];  // wa_id -> already processed this run
    $tagsCache  = [];  // tag name -> tag_id

    $findStmt = $db->prepare(
        'SELECT id, display_name FROM contacts
         WHERE company_id = ? AND wa_id = ? AND platform = "whatsapp" LIMIT 1'
    );
    $insStmt = $db->prepare(
        'INSERT INTO contacts (company_id, wa_id, platform, phone, display_name, last_message_at)
         VALUES (?, ?, "whatsapp", ?, ?, NULL)'
    );
    $updNameStmt = $db->prepare(
        'UPDATE contacts SET display_name = ?, phone = COALESCE(NULLIF(?, ""), phone) WHERE id = ?'
    );
    // email + external_id live behind phase-51. Skip these updates
    // silently on pre-migration DBs so the importer still works.
    $hasEnrichCols = contact_import_has_enrich_columns($db);
    $updEmailStmt  = $hasEnrichCols
        ? $db->prepare('UPDATE contacts SET email = COALESCE(NULLIF(?, ""), email) WHERE id = ?')
        : null;
    $updExtIdStmt  = $hasEnrichCols
        ? $db->prepare('UPDATE contacts SET external_id = COALESCE(NULLIF(?, ""), external_id) WHERE id = ?')
        : null;

    foreach ($rowsRead as $rowIdx => $row) {
        $lineNum = $lineOffset + $rowIdx + 1;   // absolute line number in the original file
        if (!is_array($row) || count(array_filter($row, fn($x) => trim((string)$x) !== '')) === 0) {
            continue; // blank row
        }
        $rawPhone  = trim((string)($row[$col['phone']]  ?? ''));
        $rawName   = trim((string)($row[$col['name']]   ?? ''));
        $rawTags   = $col['tags']        >= 0 ? trim((string)($row[$col['tags']]        ?? '')) : '';
        $rawBranch = $col['branch']      >= 0 ? trim((string)($row[$col['branch']]      ?? '')) : '';
        $rawEmail  = $col['email']       >= 0 ? trim((string)($row[$col['email']]       ?? '')) : '';
        $rawExtId  = $col['external_id'] >= 0 ? trim((string)($row[$col['external_id']] ?? '')) : '';
        $rawStatus = $col['status']      >= 0 ? trim((string)($row[$col['status']]      ?? '')) : '';

        // Active-only filter: skip rows whose status column doesn't look
        // active ('1', 'active', 'yes', 'true'). Only applies when the
        // file actually has a status column AND the operator ticked the
        // checkbox — otherwise every row is treated as active.
        if ($activeOnly && $col['status'] >= 0) {
            $s = mb_strtolower($rawStatus);
            if (!in_array($s, ['1', 'active', 'yes', 'true', 'y', 't'], true)) {
                $skipped++;
                continue;
            }
        }

        $waId = contact_normalize_phone($rawPhone);
        if ($waId === '') {
            $errors[] = ['line' => $lineNum, 'reason' => 'Invalid or missing phone: "' . mb_substr($rawPhone, 0, 40) . '"'];
            continue;
        }
        if (isset($seenInFile[$waId])) {
            $skipped++;
            continue;
        }
        $seenInFile[$waId] = true;

        // Basic email sanity — silently drop if it doesn't look like one.
        if ($rawEmail !== '' && !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) $rawEmail = '';

        try {
            $findStmt->execute([$companyId, $waId]);
            $existing = $findStmt->fetch();

            // Resolve branch name -> id, auto-creating unknown names so
            // the operator doesn't have to pre-seed branches manually.
            $branchIdForRow = null;
            if ($rawBranch !== '') {
                $lc = mb_strtolower($rawBranch);
                if (isset($branchCache[$lc])) {
                    $branchIdForRow = $branchCache[$lc];
                } else {
                    try {
                        $db->prepare(
                            'INSERT INTO branches (company_id, name, status) VALUES (?, ?, "active")'
                        )->execute([$companyId, mb_substr($rawBranch, 0, 120)]);
                        $branchIdForRow = (int)$db->lastInsertId();
                        $branchCache[$lc] = $branchIdForRow;
                    } catch (Throwable $eBranch) {
                        // Uniqueness race (concurrent import) — re-fetch.
                        $r = $db->prepare('SELECT id FROM branches WHERE company_id = ? AND name = ? LIMIT 1');
                        $r->execute([$companyId, $rawBranch]);
                        $branchIdForRow = (int)$r->fetchColumn() ?: null;
                        if ($branchIdForRow) $branchCache[$lc] = $branchIdForRow;
                    }
                }
            }

            $contactId = 0;
            if ($existing) {
                $contactId = (int)$existing['id'];
                if ($updateExisting) {
                    $updNameStmt->execute([
                        $rawName !== '' ? $rawName : ($existing['display_name'] ?: $waId),
                        $rawPhone !== '' ? preg_replace('/\D/', '', $rawPhone) : '',
                        $contactId,
                    ]);
                    if ($branchIdForRow) {
                        $db->prepare('UPDATE contacts SET branch_id = ? WHERE id = ?')
                           ->execute([$branchIdForRow, $contactId]);
                    }
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $insStmt->execute([$companyId, $waId, $waId,
                                   $rawName !== '' ? $rawName : $waId]);
                $contactId = (int)$db->lastInsertId();
                if ($branchIdForRow) {
                    $db->prepare('UPDATE contacts SET branch_id = ? WHERE id = ?')
                       ->execute([$branchIdForRow, $contactId]);
                }
                $created++;
            }

            // Email + external_id — always update when the row has a
            // value (regardless of updateExisting, since these are new
            // fields never previously populated). COALESCE(NULLIF, existing)
            // in the statements means empty values leave old data alone.
            if ($updEmailStmt && $rawEmail !== '') {
                $updEmailStmt->execute([$rawEmail, $contactId]);
            }
            if ($updExtIdStmt && $rawExtId !== '') {
                $updExtIdStmt->execute([mb_substr($rawExtId, 0, 120), $contactId]);
            }

            // Tags — assemble from file column + default tag.
            $tags = [];
            if ($rawTags !== '') {
                foreach (preg_split('/[|,;]+/', $rawTags) as $t) {
                    $t = trim($t);
                    if ($t !== '') $tags[] = $t;
                }
            }
            if ($defaultTag !== '') $tags[] = $defaultTag;

            foreach ($tags as $tagName) {
                contact_ensure_tagged($db, $companyId, $contactId, $tagName, $tagsCache);
            }
        } catch (Throwable $e) {
            $errors[] = ['line' => $lineNum, 'reason' => 'DB error: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    log_activity(
        $companyId, $userId, 'contacts_imported', null, null,
        'created=' . $created . ' updated=' . $updated . ' skipped=' . $skipped
        . ' errors=' . count($errors)
    );

    return [
        'ok' => true, 'created' => $created, 'updated' => $updated,
        'skipped' => $skipped, 'errors' => $errors,
    ];
}

/**
 * Strip everything but digits. Drop a leading + or 00. Auto-prefix
 * local Malaysian numbers (10-11 digits starting with 0) with 60,
 * so `0123456789` becomes `60123456789`. Reject if too short or too
 * long to be a real number. Returns '' on invalid.
 */
function contact_normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === null) return '';
    // Strip international prefix "00" if the user typed it.
    if (strlen($digits) > 10 && strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
    }
    // Malaysian local shorthand: numbers like 0123456789 (mobile) or
    // 0388887777 (landline) — start with 0 and are 9–11 digits. Prepend
    // 60 and drop the leading 0 so they land in international form.
    if (strlen($digits) >= 9 && strlen($digits) <= 11 && $digits[0] === '0') {
        $digits = '60' . substr($digits, 1);
    }
    // 8 digits is the minimum for any country code + local number
    // 15 is the E.164 max.
    $len = strlen($digits);
    if ($len < 8 || $len > 15) return '';
    return $digits;
}

/**
 * Return true if the uploaded file starts with the ZIP magic bytes
 * "PK\x03\x04" — an xlsx is a zip so this is the most reliable format
 * detector even when the extension is missing / wrong.
 */
function contact_import_looks_like_zip(string $localPath): bool
{
    $fh = @fopen($localPath, 'rb');
    if (!$fh) return false;
    $sig = fread($fh, 4);
    fclose($fh);
    return $sig === "PK\x03\x04";
}

/**
 * Cached check: does the contacts table have the phase-51 enrichment
 * columns? Static per request so we don't hit information_schema per
 * row on a 16k-row import.
 */
function contact_import_has_enrich_columns(PDO $db): bool
{
    static $has = null;
    if ($has !== null) return $has;
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contacts'
               AND COLUMN_NAME IN ('email', 'external_id')"
        )->fetchColumn();
        $has = ((int)$r) === 2;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * Read a CSV / TSV / semi-delimited file into a 2D string array —
 * same shape xlsx_read_rows returns, so the row-processing loop
 * doesn't care which format the operator uploaded.
 *
 * Returns null on unreadable / empty file.
 */
function contact_import_read_csv(string $localPath): ?array
{
    $fh = @fopen($localPath, 'r');
    if (!$fh) return null;
    $first = fgets($fh);
    if ($first === false) { fclose($fh); return null; }
    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) $first = substr($first, 3);   // BOM
    // Sniff delimiter — comma / semicolon / tab, whichever appears most.
    $counts = ['comma' => substr_count($first, ','),
               'semi'  => substr_count($first, ';'),
               'tab'   => substr_count($first, "\t")];
    arsort($counts);
    $delim = ['comma' => ',', 'semi' => ';', 'tab' => "\t"][array_key_first($counts)];
    $rows  = [str_getcsv(rtrim($first, "\r\n"), $delim)];
    while (($r = fgetcsv($fh, 0, $delim)) !== false) $rows[] = $r;
    fclose($fh);
    return $rows;
}

/**
 * Given a candidate header row and (optionally) the first few data
 * rows, return the column index map — or NULL if the header doesn't
 * qualify. A row qualifies as a header only if at least one cell
 * matches a phone alias, so chrome rows like ["Member Report"] and
 * data rows like ["ER","ER00000017",…] don't false-positive.
 *
 * When multiple columns match the same field (e.g. CustomerNo,
 * Membership No, and Card No all match external_id), we look at the
 * first ~5 data rows and pick whichever candidate is most consistently
 * populated. This handles CRM exports where the "official" ID column
 * is left blank and the useful id sits in a secondary column.
 *
 * @return array{phone:int,name:int,tags:int,branch:int,email:int,external_id:int,status:int}|null
 */
function contact_import_map_columns(array $headerCells, array $sampleRows = []): ?array
{
    static $aliases = null;
    if ($aliases === null) {
        $aliases = [
            'phone'       => ['phone','mobile','mobileno','number','whatsapp','wa','waid','msisdn','hp','handphone','contactno','phoneno'],
            'name'        => ['name','fullname','displayname','contactname','printedname','printname','customername','membername'],
            'tags'        => ['tag','tags','label','labels'],
            'branch'      => ['branch','office','location','businessunit','outlet','shop','store'],
            'email'       => ['email','emailaddress','mail'],
            'external_id' => ['customerno','membershipno','memberno','membercode','cardno','id','code','externalid','custid','memberid'],
            'status'      => ['status','statusflag','active'],
        ];
    }

    // First pass — collect ALL candidate columns per field.
    // (Same header word can only bind one field — the first match wins,
    //  same as before — but multiple different columns can bind to the
    //  same field via different aliases.)
    $candidates = array_fill_keys(array_keys($aliases), []);
    $used = [];   // column index → field it's already bound to
    foreach ($headerCells as $i => $h) {
        $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', (string)$h)));
        if ($norm === '') continue;
        foreach ($aliases as $field => $words) {
            if (in_array($norm, $words, true) && !isset($used[$i])) {
                $candidates[$field][] = $i;
                $used[$i] = $field;
                break;
            }
        }
    }

    // Second pass — for each field with multiple candidates, prefer the
    // column that's populated in the sample data. Falls back to the
    // first candidate when we have no samples or all are equally empty.
    $map = [];
    foreach ($candidates as $field => $cols) {
        if (!$cols) { $map[$field] = -1; continue; }
        if (count($cols) === 1 || !$sampleRows) { $map[$field] = $cols[0]; continue; }
        $best = $cols[0]; $bestScore = -1;
        foreach ($cols as $c) {
            $score = 0;
            foreach ($sampleRows as $r) {
                if (trim((string)($r[$c] ?? '')) !== '') $score++;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $c; }
        }
        $map[$field] = $best;
    }
    return $map['phone'] !== -1 ? $map : null;
}

// contact_ensure_tagged lives in inc/contacts_tags.php now — required at
// the top of this file so both the import path and the bulk-tag UI on
// /contacts.php stay in sync.
