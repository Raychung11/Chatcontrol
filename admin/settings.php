<?php
require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$companyId    = (int)$current_user['company_id'];
$db           = aiserve_db();

$msg = '';
$err = '';

if (is_post()) {
    csrf_check();

    // Settings is now workspace-level ONLY: company identity, brand,
    // timezone, default department, operational alerts. All WhatsApp
    // connection config (provider choice, tokens, URLs, webhook verify
    // token) lives on /admin/channels.php per channel - the AiServe
    // real-world setup uses one Bearer token PER number, so a single
    // "primary" config here would be misleading.
    $name           = trim((string)($_POST['name']           ?? ''));
    $brandColor     = trim((string)($_POST['brand_color']    ?? '#25D366'));
    $timezone       = trim((string)($_POST['timezone']       ?? APP_TIMEZONE));
    $defaultDeptId  = $_POST['default_department_id'] ?? '';
    $defaultDeptId  = ($defaultDeptId === '' || $defaultDeptId === '0') ? null : (int)$defaultDeptId;

    $alertEnabled   = !empty($_POST['alert_failed_sends_enabled']) ? 1 : 0;
    $alertThreshold = max(1, (int)($_POST['alert_failed_sends_threshold'] ?? 5));
    $alertEmail     = trim((string)($_POST['alert_email'] ?? ''));

    // Storage / retention. Skipping stickers is the biggest single win.
    $skipStickers   = !empty($_POST['skip_stickers']) ? 1 : 0;
    $mediaRetention = max(7, min(3650, (int)($_POST['media_retention_days'] ?? 90)));
    $mediaMaxKb     = max(64, min(102400, (int)($_POST['media_max_kb'] ?? 10240)));

    if ($name === '') {
        $err = 'Company name is required.';
    } else {
        if ($defaultDeptId !== null) {
            $check = $db->prepare('SELECT id FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
            $check->execute([$defaultDeptId, $companyId]);
            if (!$check->fetchColumn()) $defaultDeptId = null;
        }
        $db->prepare(
            'UPDATE companies SET
                name = ?, brand_color = ?, timezone = ?, default_department_id = ?,
                alert_failed_sends_enabled = ?, alert_failed_sends_threshold = ?, alert_email = ?,
                skip_stickers = ?, media_retention_days = ?, media_max_kb = ?
             WHERE id = ?'
        )->execute([
            $name,
            $brandColor ?: '#25D366',
            $timezone   ?: APP_TIMEZONE,
            $defaultDeptId,
            $alertEnabled, $alertThreshold, $alertEmail ?: null,
            $skipStickers, $mediaRetention, $mediaMaxKb,
            $companyId,
        ]);

        // ---- Company logo upload (optional). ----
        // Saved to uploads/companies/<company_id>/logo.png, normalized
        // to a 512x512 PNG via GD so every workspace's logo renders at
        // the same size in the sidebar / anywhere else it appears.
        // Non-square sources are center-cropped. Empty = leave alone.
        if (!empty($_FILES['logo']) && (int)($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $lErr = settings_save_company_logo($companyId, $_FILES['logo']);
            if ($lErr !== null) {
                $err = $lErr;
            } else {
                $db->prepare('UPDATE companies SET logo = "logo.png" WHERE id = ?')
                   ->execute([$companyId]);
            }
        } elseif (!empty($_POST['logo_delete'])) {
            $logoPath = __DIR__ . '/../uploads/companies/' . $companyId . '/logo.png';
            if (is_file($logoPath)) @unlink($logoPath);
            $db->prepare('UPDATE companies SET logo = NULL WHERE id = ?')->execute([$companyId]);
        }

        log_activity($companyId, (int)$current_user['id'], 'settings_updated', 'company', $companyId, 'Workspace settings updated');
        if ($err === '') $msg = 'Settings saved.';
    }
}

/**
 * Validate + normalize an uploaded logo. Returns null on success or an
 * error message on failure. On success writes to
 * uploads/companies/<company_id>/logo.png as a 512x512 PNG.
 */
function settings_save_company_logo(int $companyId, array $file): ?string
{
    $maxBytes = 4 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) return 'Logo too big (max 4 MB).';

    $mime = function_exists('mime_content_type') ? (string)mime_content_type($file['tmp_name']) : '';
    $decoders = [
        'image/png'  => 'imagecreatefrompng',
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/webp' => 'imagecreatefromwebp',
    ];
    if (!isset($decoders[$mime]) || !function_exists($decoders[$mime])) {
        return 'Unsupported logo type (' . ($mime ?: 'unknown') . '). Use PNG, JPG, or WebP.';
    }
    $src = @($decoders[$mime])($file['tmp_name']);
    if (!$src) return 'Could not decode the uploaded image.';

    $srcW = imagesx($src); $srcH = imagesy($src);
    if ($srcW < 64 || $srcH < 64) {
        imagedestroy($src);
        return 'Logo is too small. Upload at least 128x128 pixels for a crisp render.';
    }

    $target = 512;
    $dst = imagecreatetruecolor($target, $target);
    imagesavealpha($dst, true);
    imagealphablending($dst, false);
    $tp = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $tp);
    imagealphablending($dst, true);

    $side = min($srcW, $srcH);
    $srcX = (int)(($srcW - $side) / 2);
    $srcY = (int)(($srcH - $side) / 2);
    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $target, $target, $side, $side);

    $dir = __DIR__ . '/../uploads/companies/' . $companyId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        imagedestroy($src); imagedestroy($dst);
        return 'Could not create uploads/companies/ — check server permissions.';
    }
    $ok = imagepng($dst, $dir . '/logo.png', 6);
    imagedestroy($src); imagedestroy($dst);
    if (!$ok) return 'Could not save the logo file to disk.';
    @chmod($dir . '/logo.png', 0644);
    return null;
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch() ?: [];

$dstmt = $db->prepare('SELECT id, name FROM departments WHERE company_id = ? AND status = "active" ORDER BY name');
$dstmt->execute([$companyId]);
$departments = $dstmt->fetchAll();

layout_start($current_user, 'Workspace settings', 'settings', $company['brand_color'] ?? '#25D366');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="form-grid" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <h2>Company</h2>
    <div class="alert alert-info" style="margin-bottom:8px;">
      Workspace identifier: <code><?= e($company['slug'] ?? '') ?></code> · Plan: <strong><?= e(ucfirst((string)($company['plan'] ?? 'starter'))) ?></strong>
      (<?= (int)company_user_count($companyId) ?> / <?= (int)plan_seat_limit((string)($company['plan'] ?? 'starter')) ?> seats used).
      The slug is used in your webhook URLs and cannot be changed once issued.
    </div>
    <label>Company name
      <input type="text" name="name" value="<?= e($company['name'] ?? '') ?>" required>
    </label>
    <label>Brand color
      <input type="color" name="brand_color" value="<?= e($company['brand_color'] ?? '#25D366') ?>">
    </label>

    <?php
      $hasLogo   = !empty($company['logo']);
      $logoPath  = __DIR__ . '/../uploads/companies/' . $companyId . '/logo.png';
      $logoMtime = $hasLogo && is_file($logoPath) ? filemtime($logoPath) : 0;
    ?>
    <label>Company logo <small class="muted">(optional — shown in the sidebar and anywhere your workspace is branded)</small>
      <?php if ($hasLogo && $logoMtime > 0): ?>
        <div style="display:flex; align-items:center; gap:12px; margin: 4px 0 8px;">
          <img src="/assets/img/company_logo.php?company_id=<?= (int)$companyId ?>&v=<?= (int)$logoMtime ?>"
               alt="Current logo"
               style="width:64px; height:64px; border-radius:8px; border:1px solid var(--c-border); background:#f4f6f8; object-fit:contain;">
          <label style="display:inline-flex; align-items:center; gap:6px; font-weight:normal;">
            <input type="checkbox" name="logo_delete" value="1">
            <span class="muted small">Remove current logo</span>
          </label>
        </div>
      <?php endif; ?>
      <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
      <small class="muted">
        PNG, JPG or WebP. Square works best (128&times;128+). We center-crop
        non-square uploads and normalize to a 512&times;512 PNG.
      </small>
    </label>
    <label>Default timezone
      <input type="text" name="timezone" value="<?= e($company['timezone'] ?? APP_TIMEZONE) ?>" placeholder="Asia/Kuala_Lumpur">
    </label>
    <label>Default department for new conversations
      <select name="default_department_id">
        <option value="0">— None (leave unrouted) —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= ((int)($company['default_department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="muted">New customer messages land here when no <a href="/admin/routing.php">routing rule</a> matches.</small>
    </label>

    <div class="alert alert-info">
      <strong>WhatsApp connection:</strong>
      each WhatsApp number has its own provider config and Bearer token, so
      the connection lives per-channel — not here. Manage numbers at
      <a href="/admin/channels.php"><strong>Admin → Channels</strong></a>.
      Test send + webhook URLs are also on that page, per channel.
    </div>

    <h2>Operational alerts</h2>
    <p class="muted small">
      Get an email when outbound sends start failing in bulk — usually means
      a wrong Bearer token, an expired gateway, or partner-side downtime.
    </p>
    <label class="check-row">
      <input type="checkbox" name="alert_failed_sends_enabled" value="1"
             <?= !empty($company['alert_failed_sends_enabled']) ? 'checked' : '' ?>>
      <span><strong>Email me when outbound sends fail in bulk</strong></span>
    </label>
    <label>Threshold <small class="muted">(N failures in 10 min triggers one alert; min 1)</small>
      <input type="number" name="alert_failed_sends_threshold" min="1" max="999"
             value="<?= (int)($company['alert_failed_sends_threshold'] ?? 5) ?>">
    </label>
    <label>Recipient email <small class="muted">(leave blank to send to all Workspace Admins)</small>
      <input type="email" name="alert_email"
             value="<?= e((string)($company['alert_email'] ?? '')) ?>"
             placeholder="ops@yourcompany.com">
    </label>
    <div class="alert alert-info">
      Alerts run via cron every 5 minutes. Same workspace gets at most one
      alert per 30 minutes (cooldown) so a sustained outage doesn't spam you.
      See <a href="/docs/CRON.md" target="_blank">CRON setup</a> if you
      haven't installed the cron job yet.
    </div>

    <h2>Storage &amp; media retention</h2>
    <p class="muted small">
      Photos, videos, PDFs and stickers customers send are downloaded to your
      server so agents can view them without hitting WhatsApp every time. This
      controls how long we keep them.
    </p>

    <?php
      // Live-computed usage - reports just this workspace's inbound folder.
      $inboundDir = __DIR__ . '/../uploads/' . $companyId . '/inbound';
      $usedBytes  = 0;
      $usedFiles  = 0;
      if (is_dir($inboundDir)) {
          try {
              $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inboundDir, FilesystemIterator::SKIP_DOTS));
              foreach ($it as $f) {
                  if ($f->isFile()) { $usedBytes += $f->getSize(); $usedFiles++; }
              }
          } catch (Throwable $e) { /* ignore */ }
      }
      $usedMb = $usedBytes / 1024 / 1024;
    ?>

    <div class="alert alert-info">
      <strong>Current storage:</strong>
      <?= number_format($usedFiles) ?> file<?= $usedFiles === 1 ? '' : 's' ?>
      · <?= $usedMb < 1 ? number_format($usedBytes / 1024, 1) . ' KB' : number_format($usedMb, 2) . ' MB' ?>
      of inbound customer media on disk right now.
      <?php if ($usedFiles > 0): ?>
        <br>Next cron sweep will delete anything older than the retention window below.
      <?php endif; ?>
    </div>

    <label class="check-row">
      <input type="checkbox" name="skip_stickers" value="1"
             <?= (int)($company['skip_stickers'] ?? 1) === 1 ? 'checked' : '' ?>>
      <span><strong>Don't save WhatsApp stickers</strong>
        <small class="muted">— stickers are visible in the chat as "[sticker]" but the .webp file is dropped, not stored. Biggest single space saver.</small>
      </span>
    </label>

    <label>Media retention (days)
      <input type="number" name="media_retention_days" id="f-media-retention"
             min="7" max="3650"
             data-current="<?= (int)($company['media_retention_days'] ?? 90) ?>"
             value="<?= (int)($company['media_retention_days'] ?? 90) ?>">
      <small class="muted">Files older than this get swept nightly by <code>cron/cleanup_media.php</code>. Minimum 7 days. The chat view shows "media expired" once a file is cleaned. <strong>Decreasing this value permanently deletes older files on the next nightly sweep.</strong></small>
    </label>
    <script>
      // Warn the operator if they decrease the retention window - the
      // orphan sweep will PERMANENTLY delete anything older than the new
      // value on the next cron run. Nothing else in the form is
      // destructive so we only guard this input.
      (function () {
        const inp = document.getElementById('f-media-retention');
        if (!inp) return;
        const form = inp.closest('form');
        if (!form) return;
        form.addEventListener('submit', (e) => {
          const now  = parseInt(inp.getAttribute('data-current'), 10) || 0;
          const next = parseInt(inp.value, 10) || 0;
          if (next < now) {
            const ok = confirm(
              'Media retention decreased from ' + now + ' to ' + next + ' days.\n\n' +
              'Any inbound media older than ' + next + ' days will be PERMANENTLY deleted on the next nightly cleanup run.\n\n' +
              'Continue?'
            );
            if (!ok) { e.preventDefault(); return; }
          }
        });
      })();
    </script>

    <label>Max inbound media size (KB)
      <input type="number" name="media_max_kb" min="64" max="102400"
             value="<?= (int)($company['media_max_kb'] ?? 10240) ?>">
      <small class="muted">Any single incoming file larger than this is dropped without being saved (10240 = 10 MB, 51200 = 50 MB).</small>
    </label>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </form>
</div>
<?php layout_end(); ?>
