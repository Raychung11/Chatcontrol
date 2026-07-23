<?php
/**
 * Bulk contact import via CSV.
 *
 * Accepts a flexible CSV shape — we look at the first row and:
 *   - If it contains header names (phone/name/tags), match columns by
 *     name (case-insensitive, punctuation-tolerant).
 *   - Otherwise treat the first row as data and assume phone, name, tags
 *     in that order.
 *
 * Phone numbers are normalized (digits only, drops leading + and 0)
 * and validated to look like a real E.164-ish number (8-15 digits).
 *
 * Every row becomes an upsert against contacts keyed on
 * (company_id, wa_id, platform=whatsapp). Duplicates within the CSV
 * itself are silently dedupped.
 */

require_once __DIR__ . '/inc/layout.php';

$current_user = require_role(['super_admin', 'manager']);
$companyId    = (int)$current_user['company_id'];

$result = null;   // filled on POST after the import runs

if (is_post()) {
    csrf_check();
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

  <form method="post" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?>
    <label>CSV file
      <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
      <small class="muted">
        Max 5 MB. Also accepts a plain <code>.txt</code> if it's comma or tab-separated.
      </small>
    </label>

    <label class="check-row">
      <input type="checkbox" name="update_existing" value="1" checked>
      <span>Update existing contacts</span>
      <small class="muted">If unticked, contacts already in the system are skipped instead of updated.</small>
    </label>

    <label>Default tag <small class="muted">(optional)</small>
      <input type="text" name="default_tag" maxlength="60" placeholder="e.g. imported-2026-07">
      <small class="muted">
        Applied to every imported contact. Combined with tags in the CSV's <code>tags</code>
        column. Useful for tracking which upload a contact came from.
      </small>
    </label>

    <button type="submit" class="btn btn-primary">Import</button>
  </form>

  <hr style="margin: 24px 0; border:none; border-top:1px solid var(--c-border);">

  <h3>CSV format</h3>
  <p class="muted small">
    Recommended: a header row with column names <code>phone</code>, <code>name</code>,
    <code>tags</code>, <code>branch</code>. Column order doesn't matter and matching is case-insensitive.
    <code>tags</code> can be one tag or several separated by <code>|</code>.
    <code>branch</code> takes a branch name — if it doesn't exist yet, we auto-create it.
  </p>
  <pre style="background:#f6f9fb; border:1px solid #e3e8ee; border-radius:6px; padding:12px; overflow-x:auto;">phone,name,tags,branch
60123456789,Vicky Tan,vip|boat-tour,KL Office
60198765432,Ali Rahman,new-lead,Penang Office
6591234567,Jane Doe,,</pre>

  <p class="muted small">
    <strong>No header row?</strong> That's fine — the importer assumes the order
    <code>phone, name, tags, branch</code>.<br>
    <strong>Phone format:</strong> country code + number, digits only. No <code>+</code>,
    no leading zero. e.g. <code>60123456789</code>, not <code>+60 12-345 6789</code> or
    <code>0123456789</code>. Malformed rows are logged as errors and skipped, the rest
    still import.
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
    if (empty($_FILES['csv']) || (int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file uploaded (or upload failed).',
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    }
    $file = $_FILES['csv'];
    if ((int)$file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too big (max 5 MB).',
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    }
    $updateExisting = !empty($_POST['update_existing']);
    $defaultTag     = trim((string)($_POST['default_tag'] ?? ''));

    $fh = @fopen($file['tmp_name'], 'r');
    if (!$fh) {
        return ['ok' => false, 'error' => 'Could not read the uploaded file.',
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    }

    // Sniff delimiter: comma, semicolon, or tab.
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['ok' => false, 'error' => 'CSV appears to be empty.',
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    }
    // Strip BOM if present (Excel exports).
    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) $first = substr($first, 3);

    $counts = ['comma' => substr_count($first, ','),
               'semi'  => substr_count($first, ';'),
               'tab'   => substr_count($first, "\t")];
    arsort($counts);
    $delim = ['comma' => ',', 'semi' => ';', 'tab' => "\t"][array_key_first($counts)];

    rewind($fh);
    // Re-read + BOM strip on the first row (the fgets above ate it).
    stream_filter_prepend($fh, 'convert.iconv.UTF-8-MAC/UTF-8'); // no-op on Linux
    // Actually simpler: parse the sniffed line ourselves, then loop.
    $rowsRead = [];
    $rowsRead[] = str_getcsv(rtrim($first, "\r\n"), $delim);
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $rowsRead[] = $row;
    }
    fclose($fh);

    if (!$rowsRead) {
        return ['ok' => false, 'error' => 'CSV appears to be empty.',
                'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    }

    // Header detection: if the first row's "phone" candidate cell doesn't
    // contain enough digits to be a real phone, treat the row as headers.
    $firstRow = array_map(function ($x) { return trim((string)$x); }, $rowsRead[0]);
    $isHeader = false;
    if (!empty($firstRow[0])) {
        $digitCount = strlen(preg_replace('/\D/', '', $firstRow[0]));
        if ($digitCount < 6) $isHeader = true;
    }

    // Column mapping: default (no header) is phone / name / tags / branch.
    $col = ['phone' => 0, 'name' => 1, 'tags' => 2, 'branch' => 3];
    if ($isHeader) {
        $col = ['phone' => -1, 'name' => -1, 'tags' => -1, 'branch' => -1];
        foreach ($firstRow as $i => $h) {
            $norm = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '', $h)));
            if (in_array($norm, ['phone','mobile','number','whatsapp','wa','waid','msisdn'], true)) {
                $col['phone'] = $i;
            } elseif (in_array($norm, ['name','fullname','displayname','contactname'], true)) {
                $col['name'] = $i;
            } elseif (in_array($norm, ['tag','tags','label','labels'], true)) {
                $col['tags'] = $i;
            } elseif (in_array($norm, ['branch','office','location','businessunit'], true)) {
                $col['branch'] = $i;
            }
        }
        if ($col['phone'] === -1) {
            return ['ok' => false,
                    'error' => 'Could not find a phone column. Expected one of: phone, mobile, number, whatsapp, msisdn.',
                    'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        }
        array_shift($rowsRead);
    }

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

    $lineNum = $isHeader ? 2 : 1;
    foreach ($rowsRead as $row) {
        $lineNum++;
        if (!is_array($row) || count(array_filter($row, fn($x) => trim((string)$x) !== '')) === 0) {
            continue; // blank row
        }
        $rawPhone  = trim((string)($row[$col['phone']]  ?? ''));
        $rawName   = trim((string)($row[$col['name']]   ?? ''));
        $rawTags   = trim((string)($row[$col['tags']]   ?? ''));
        $rawBranch = $col['branch'] >= 0 ? trim((string)($row[$col['branch']] ?? '')) : '';

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

            // Tags — assemble from CSV column + default tag.
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
 * Strip everything but digits. Drop a leading + or 00. Reject if too
 * short or too long to be a real number. Returns '' on invalid.
 */
function contact_normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === null) return '';
    // Strip international prefix "00" if the user typed it.
    if (strlen($digits) > 10 && strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
    }
    // 8 digits is the minimum for any country code + local number
    // 15 is the E.164 max.
    $len = strlen($digits);
    if ($len < 8 || $len > 15) return '';
    return $digits;
}

/**
 * Ensure a tag exists on the workspace, then attach it to the contact via
 * conversation_tag_map. Tag-to-contact routing goes through the
 * contact's most-recent conversation. If the contact has no conversation
 * yet (new import row), we open a placeholder conversation so the tag
 * has somewhere to hang.
 */
function contact_ensure_tagged(PDO $db, int $companyId, int $contactId, string $tagName, array &$cache): void
{
    $tagName = mb_substr($tagName, 0, 60);
    if (!isset($cache[$tagName])) {
        $s = $db->prepare(
            'SELECT id FROM conversation_tags WHERE company_id = ? AND name = ? LIMIT 1'
        );
        $s->execute([$companyId, $tagName]);
        $tid = (int)$s->fetchColumn();
        if (!$tid) {
            $db->prepare(
                'INSERT INTO conversation_tags (company_id, name, color) VALUES (?, ?, "#25D366")'
            )->execute([$companyId, $tagName]);
            $tid = (int)$db->lastInsertId();
        }
        $cache[$tagName] = $tid;
    }
    $tid = $cache[$tagName];

    // Find (or open) a conversation to hang the tag on.
    $s = $db->prepare(
        'SELECT id FROM conversations WHERE company_id = ? AND contact_id = ?
         ORDER BY id DESC LIMIT 1'
    );
    $s->execute([$companyId, $contactId]);
    $convId = (int)$s->fetchColumn();
    if (!$convId) {
        // No channel context for pure imports — pick the workspace's
        // default channel so the placeholder conversation isn't orphaned.
        $chId = (int)$db->query(
            'SELECT id FROM channels WHERE company_id = ' . $companyId
            . ' AND status = "active" ORDER BY is_default DESC, id ASC LIMIT 1'
        )->fetchColumn();
        if (!$chId) return; // no channels yet — skip tagging, contact still saved.
        $db->prepare(
            'INSERT INTO conversations
                (company_id, channel_id, contact_id, status,
                 last_message_text, last_message_at, unread_count)
             VALUES (?, ?, ?, "open", "(imported contact)", NOW(), 0)'
        )->execute([$companyId, $chId, $contactId]);
        $convId = (int)$db->lastInsertId();
    }

    $db->prepare(
        'INSERT IGNORE INTO conversation_tag_map (conversation_id, tag_id) VALUES (?, ?)'
    )->execute([$convId, $tid]);
}
