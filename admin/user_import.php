<?php
/**
 * Bulk-import users from a CSV shaped like a branch org chart:
 *
 *   area_manager,branch,name,phone
 *   JOE,KD KOTA DAMANSARA,BINOD,014-6413885
 *   JOE,MGM MEGAMAS,JOANNE,013-6711270
 *   JASON,PTL TEMERLOH,BOBBY,018-8722278
 *   GAN,SSJ SUBANG SEJATI,MARIA,010-5749463
 *   ...
 *
 * What we do per row:
 *   1. Ensure the branch exists in this workspace (create if new).
 *   2. Ensure the area_manager user exists (role=manager, one per name).
 *      Link them to the branch via user_branches — the phase-30 helper
 *      then scopes their visibility automatically.
 *   3. Ensure the staff (name) user exists (role=agent).
 *      Link them to the branch via user_branches — that puts them in
 *      the phase-28 rotation pool.
 *   4. If email / password columns are missing, we generate placeholders
 *      (see notes in the form) so the operator can fix them later.
 *
 * Idempotent: re-running against the same CSV updates names/phones
 * where they've changed and skips existing (email-matched) users
 * instead of duplicating.
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$result = null;
if (is_post()) {
    csrf_check();
    $result = user_import_run($db, $companyId, (int)$current_user['id']);
}

layout_start($current_user, 'Import users', 'users');
?>
<div class="card">
  <div class="card-head">
    <h2>Import users from CSV</h2>
    <a class="btn" href="/admin/users.php">← Back to users</a>
  </div>

  <?php if ($result): ?>
    <?php if ($result['ok']): ?>
      <div class="alert alert-success">
        <strong>Import complete.</strong>
        <?= (int)$result['users_created']    ?> user(s) created,
        <?= (int)$result['users_updated']    ?> updated,
        <?= (int)$result['branches_created'] ?> new branch(es),
        <?= (int)$result['links_created']    ?> branch links added
        <?php if ($result['errors']): ?>, <?= count($result['errors']) ?> error(s)<?php endif; ?>.
      </div>
      <?php if (!empty($result['generated_credentials'])): ?>
        <div class="alert alert-info">
          <strong>Generated passwords</strong> for new users (no password in CSV).
          Save these <em>now</em> — they aren't stored anywhere else. Share each
          with the right person, they can change it after first login.
          <table class="data-table" style="margin-top:8px;">
            <thead><tr><th>Name</th><th>Email</th><th>Password</th></tr></thead>
            <tbody>
              <?php foreach ($result['generated_credentials'] as $g): ?>
                <tr>
                  <td><?= e($g['name']) ?></td>
                  <td><code><?= e($g['email']) ?></code></td>
                  <td><code><?= e($g['password']) ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
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
      <small class="muted">Max 2 MB. Comma / semicolon / tab all work.</small>
    </label>
    <button type="submit" class="btn btn-primary">Import</button>
  </form>

  <hr style="margin: 24px 0; border:none; border-top:1px solid var(--c-border);">

  <h3>CSV format</h3>
  <p class="muted small">Header row required. Column order doesn't matter. Matching is case-insensitive.</p>

  <pre style="background:#f6f9fb; border:1px solid #e3e8ee; border-radius:6px; padding:12px; overflow-x:auto;">area_manager,branch,name,phone
JOE,KD KOTA DAMANSARA,BINOD,014-6413885
JOE,MGM MEGAMAS,JOANNE,013-6711270
JOE,MGM MEGAMAS,RABBI,011-64404030
JASON,PTL TEMERLOH,BOBBY,018-8722278
GAN,SSJ SUBANG SEJATI,MARIA,010-5749463</pre>

  <p class="muted small">
    <strong>Columns:</strong>
    <code>area_manager</code>, <code>branch</code>, <code>name</code>, <code>phone</code>
    are required. <code>email</code> and <code>password</code> are optional:
    if you leave <code>email</code> blank we generate
    <code>&lt;slug&gt;@import.local</code>; if you leave <code>password</code> blank
    we generate a random 12-character one and show it once in the result table.
  </p>
  <p class="muted small">
    <strong>Behavior:</strong> re-running the same CSV is safe — existing users
    (matched by email) are updated in place, new branches are created on the fly,
    and rotation / visibility links (user_branches) are added if missing.
    Area Managers become <code>manager</code> role and are automatically
    branch-scoped (phase 30) so they only see their own branches' conversations.
    Staff become <code>agent</code> role and land in the branch's rotation pool.
  </p>
</div>
<?php layout_end(); ?>

<?php
/**
 * Runs the import. Returns:
 *   { ok, error?, users_created, users_updated, branches_created,
 *     links_created, errors[], generated_credentials[] }
 */
function user_import_run(PDO $db, int $companyId, int $adminUserId): array
{
    $blank = [
        'ok'                => false,
        'users_created'     => 0,
        'users_updated'     => 0,
        'branches_created'  => 0,
        'links_created'     => 0,
        'errors'            => [],
        'generated_credentials' => [],
    ];
    if (empty($_FILES['csv']) || (int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return array_merge($blank, ['error' => 'No file uploaded (or upload failed).']);
    }
    if ((int)$_FILES['csv']['size'] > 2 * 1024 * 1024) {
        return array_merge($blank, ['error' => 'File too big (max 2 MB).']);
    }

    $fh = @fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$fh) return array_merge($blank, ['error' => 'Could not read the uploaded file.']);

    // Sniff delimiter and BOM.
    $first = fgets($fh);
    if ($first === false) { fclose($fh); return array_merge($blank, ['error' => 'CSV appears to be empty.']); }
    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) $first = substr($first, 3);
    $counts = [
        'comma' => substr_count($first, ','),
        'semi'  => substr_count($first, ';'),
        'tab'   => substr_count($first, "\t"),
    ];
    arsort($counts);
    $delim = ['comma' => ',', 'semi' => ';', 'tab' => "\t"][array_key_first($counts)];

    $rows = [str_getcsv(rtrim($first, "\r\n"), $delim)];
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        $rows[] = $r;
    }
    fclose($fh);
    if (count($rows) < 2) return array_merge($blank, ['error' => 'CSV needs a header row and at least one data row.']);

    // Header mapping — case + punctuation insensitive.
    $header = array_map(fn($h) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$h)), $rows[0]);
    $col = [
        'area_manager' => array_search('areamanager', $header, true),
        'branch'       => array_search('branch', $header, true),
        'name'         => array_search('name', $header, true),
        'phone'        => array_search('phone', $header, true),
        'email'        => array_search('email', $header, true),
        'password'     => array_search('password', $header, true),
    ];
    foreach (['area_manager','branch','name','phone'] as $k) {
        if ($col[$k] === false) {
            return array_merge($blank, ['error' => "Missing required column: $k (expected header row with area_manager,branch,name,phone)."]);
        }
    }
    array_shift($rows);

    // Preload existing branches + users so we don't hammer the DB per row.
    $branchIdByName = [];    // lower(name) -> id
    $bStmt = $db->prepare('SELECT id, name FROM branches WHERE company_id = ?');
    $bStmt->execute([$companyId]);
    foreach ($bStmt->fetchAll() as $b) {
        $branchIdByName[mb_strtolower((string)$b['name'])] = (int)$b['id'];
    }

    $userIdByEmail = [];
    $uStmt = $db->prepare('SELECT id, email FROM users WHERE company_id = ?');
    $uStmt->execute([$companyId]);
    foreach ($uStmt->fetchAll() as $u) {
        $userIdByEmail[mb_strtolower((string)$u['email'])] = (int)$u['id'];
    }

    // Cache which (user_id, branch_id) links already exist so we don't
    // fire a redundant INSERT per row.
    $existingLinks = [];   // "user_id:branch_id" -> true
    $lStmt = $db->prepare(
        'SELECT ub.user_id, ub.branch_id
         FROM user_branches ub
         INNER JOIN users u ON u.id = ub.user_id
         WHERE u.company_id = ?'
    );
    $lStmt->execute([$companyId]);
    foreach ($lStmt->fetchAll() as $l) {
        $existingLinks[$l['user_id'] . ':' . $l['branch_id']] = true;
    }

    // Track "did we already touch this area manager this run" so we
    // don't reset their password 8 times when they have 8 branches.
    $touchedAM = [];

    $out = $blank;
    $out['ok'] = true;

    $line = 1;
    foreach ($rows as $r) {
        $line++;
        if (!is_array($r) || count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) continue;

        $areaManager = trim((string)($r[$col['area_manager']] ?? ''));
        $branchName  = trim((string)($r[$col['branch']]       ?? ''));
        $staffName   = trim((string)($r[$col['name']]         ?? ''));
        $staffPhone  = trim((string)($r[$col['phone']]        ?? ''));
        $staffEmail  = $col['email']    !== false ? trim((string)($r[$col['email']]    ?? '')) : '';
        $staffPass   = $col['password'] !== false ? trim((string)($r[$col['password']] ?? '')) : '';

        if ($areaManager === '' || $branchName === '' || $staffName === '') {
            $out['errors'][] = ['line' => $line, 'reason' => 'area_manager, branch and name are all required.'];
            continue;
        }

        try {
            // ---- Ensure branch exists ----
            $lcBranch = mb_strtolower($branchName);
            if (!isset($branchIdByName[$lcBranch])) {
                $db->prepare(
                    'INSERT INTO branches (company_id, name, status) VALUES (?, ?, "active")'
                )->execute([$companyId, mb_substr($branchName, 0, 120)]);
                $branchIdByName[$lcBranch] = (int)$db->lastInsertId();
                $out['branches_created']++;
            }
            $branchId = $branchIdByName[$lcBranch];

            // ---- Ensure area manager user exists ----
            $amEmail = user_import_derive_email($areaManager, $staffEmail = '' /* not used */, 'manager', $companyId);
            $lcAmEmail = mb_strtolower($amEmail);
            if (!isset($userIdByEmail[$lcAmEmail])) {
                $genPass = user_import_random_password();
                $db->prepare(
                    'INSERT INTO users (company_id, name, email, password_hash, role, status)
                     VALUES (?, ?, ?, ?, "manager", "active")'
                )->execute([$companyId, $areaManager, $amEmail, password_hash($genPass, PASSWORD_BCRYPT)]);
                $userIdByEmail[$lcAmEmail] = (int)$db->lastInsertId();
                $out['users_created']++;
                if (!isset($touchedAM[$lcAmEmail])) {
                    $out['generated_credentials'][] = [
                        'name' => $areaManager, 'email' => $amEmail, 'password' => $genPass,
                    ];
                    $touchedAM[$lcAmEmail] = true;
                }
            }
            $amId = $userIdByEmail[$lcAmEmail];

            // Link AM to this branch (scopes their visibility via phase 30).
            $linkKey = $amId . ':' . $branchId;
            if (!isset($existingLinks[$linkKey])) {
                $db->prepare(
                    'INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?, ?)'
                )->execute([$amId, $branchId]);
                $existingLinks[$linkKey] = true;
                $out['links_created']++;
            }

            // ---- Ensure staff user exists ----
            $staffEmailResolved = $staffEmail !== '' ? $staffEmail : user_import_derive_email($staffName, '', 'agent', $companyId);
            $lcStaffEmail = mb_strtolower($staffEmailResolved);

            if (isset($userIdByEmail[$lcStaffEmail])) {
                // Existing — update name + phone in place. Don't touch role,
                // password or status.
                $db->prepare(
                    'UPDATE users SET name = ?, phone = COALESCE(NULLIF(?, ""), phone)
                     WHERE id = ? AND company_id = ?'
                )->execute([$staffName, $staffPhone, $userIdByEmail[$lcStaffEmail], $companyId]);
                $out['users_updated']++;
            } else {
                $pass    = $staffPass !== '' ? $staffPass : user_import_random_password();
                $db->prepare(
                    'INSERT INTO users (company_id, name, email, phone, password_hash, role, status)
                     VALUES (?, ?, ?, ?, ?, "agent", "active")'
                )->execute([$companyId, $staffName, $staffEmailResolved, $staffPhone, password_hash($pass, PASSWORD_BCRYPT)]);
                $userIdByEmail[$lcStaffEmail] = (int)$db->lastInsertId();
                $out['users_created']++;
                if ($staffPass === '') {
                    $out['generated_credentials'][] = [
                        'name' => $staffName, 'email' => $staffEmailResolved, 'password' => $pass,
                    ];
                }
            }
            $staffId = $userIdByEmail[$lcStaffEmail];

            // Link staff to this branch (rotation pool via phase 28).
            $linkKey = $staffId . ':' . $branchId;
            if (!isset($existingLinks[$linkKey])) {
                $db->prepare(
                    'INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?, ?)'
                )->execute([$staffId, $branchId]);
                $existingLinks[$linkKey] = true;
                $out['links_created']++;
            }
        } catch (Throwable $e) {
            $out['errors'][] = ['line' => $line, 'reason' => 'DB error: ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    log_activity($companyId, $adminUserId, 'users_imported', null, null,
        'created=' . $out['users_created']
        . ' updated=' . $out['users_updated']
        . ' branches=' . $out['branches_created']
        . ' links=' . $out['links_created']
        . ' errors=' . count($out['errors']));

    return $out;
}

/**
 * Slug a name into an @import.local email so the user has a valid
 * unique login even if the CSV didn't provide one. Operator can edit
 * later from admin/user_edit.
 */
function user_import_derive_email(string $name, string $override, string $role, int $companyId): string
{
    if ($override !== '') return $override;
    $slug = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower($name));
    $slug = trim($slug ?? '', '.');
    if ($slug === '') $slug = 'user' . bin2hex(random_bytes(2));
    return $slug . '@import.local';
}

function user_import_random_password(int $len = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}
