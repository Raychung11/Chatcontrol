<?php
/**
 * Platform-admin branding page.
 *
 * Upload the icon that appears when users "Add to Home Screen" from
 * Safari/Chrome/etc. Same icon is used across manifest.json (Android/
 * desktop PWA install) and apple-touch-icon (iOS home screen).
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_login();
if (!is_platform_admin() || is_impersonating()) {
    http_response_code(403);
    exit('Platform admin only.');
}

$iconPath = __DIR__ . '/../uploads/branding/pwa_icon.png';
$hasIcon  = is_file($iconPath);
$iconMtime = $hasIcon ? filemtime($iconPath) : 0;

$status = (string)($_GET['status'] ?? '');
$error  = (string)($_GET['error']  ?? '');

layout_start($current_user, 'Branding & icon', 'branding');
?>
<div class="card">
  <h2>Home-screen icon</h2>
  <p class="muted small">
    Upload the icon your customers see when they add this site to their
    phone's home screen (or install as an app on desktop). One PNG, one
    place — used by iOS, Android, and manifest.json.
  </p>

  <?php if ($status === 'uploaded'): ?>
    <div class="alert alert-success">
      Icon saved. New installs will see it immediately. People who already
      added the site to their home screen need to <strong>remove and re-add</strong>
      it — iOS caches the icon per-installation and won't refresh it.
    </div>
  <?php elseif ($status === 'deleted'): ?>
    <div class="alert alert-success">Custom icon removed. Reverted to the default green "A".</div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>

  <div style="display:flex; gap:32px; flex-wrap:wrap; align-items:flex-start; margin-top:16px;">
    <div style="flex:0 0 auto; text-align:center;">
      <div class="muted small" style="margin-bottom:8px;">Currently showing</div>
      <div style="display:inline-block; padding:16px; background:#f4f6f8; border-radius:12px;">
        <img src="/assets/img/icon.php?size=180&_=<?= (int)$iconMtime ?>"
             alt="Current PWA icon"
             width="120" height="120"
             style="display:block; border-radius:22px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
      </div>
      <div class="muted small" style="margin-top:8px;">
        <?= $hasIcon ? 'Custom icon' : 'Default (generated)' ?>
      </div>
    </div>

    <div style="flex:1 1 300px; min-width:0;">
      <form method="post" action="/api/branding_upload.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <label style="display:block; margin-bottom:8px; font-weight:600;">Choose an image</label>
        <input type="file" name="icon" accept="image/png,image/jpeg,image/webp" required
               style="display:block; margin-bottom:12px;">
        <p class="muted small">
          PNG, JPG or WebP. Square works best — non-square images will be
          center-cropped. We recommend at least <strong>512×512</strong>
          pixels for a crisp icon on Retina displays.
        </p>
        <button type="submit" class="btn btn-primary">Save icon</button>
      </form>

      <?php if ($hasIcon): ?>
        <form method="post" action="/api/branding_upload.php" style="margin-top:24px;"
              onsubmit="return confirm('Remove your custom icon and revert to the default?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn btn-sm btn-danger">Remove custom icon</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <hr style="margin: 32px 0; border:none; border-top:1px solid var(--c-border);">

  <h3>Tips for a good home-screen icon</h3>
  <ul class="muted small" style="line-height:1.7;">
    <li><strong>Fill the square.</strong> iOS crops your icon into a rounded square. Leave a small margin so the corners aren't clipped.</li>
    <li><strong>Simple wins.</strong> Icons render at 60×60 pixels on many phones. A single bold letter, symbol, or logo works better than fine text.</li>
    <li><strong>Solid background.</strong> Transparent PNGs work but iOS will show black behind them. A solid color background usually reads better.</li>
    <li><strong>Test on your phone.</strong> After uploading, remove the site from your home screen and re-add it — iOS caches the icon per install.</li>
  </ul>
</div>
<?php layout_end(); ?>
