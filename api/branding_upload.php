<?php
/**
 * POST /api/branding_upload.php
 *
 * Platform-admin only. Accepts a square-ish image (PNG / JPG / WebP),
 * normalizes it to a 1024x1024 PNG, saves as uploads/branding/pwa_icon.png.
 * assets/img/icon.php will read that file on the next request instead of
 * generating the default green "A".
 *
 * We also clear the icon cache dir so the new icon shows up immediately
 * regardless of the 24-hour cache TTL. iOS home-screen icons are cached
 * on the device though — users may need to remove + re-add the app to
 * see the update; we surface a note about that in the admin UI.
 *
 * action=delete removes the uploaded icon so we fall back to default.
 */

require_once __DIR__ . '/../inc/auth.php';

$user = require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_check();

if (!is_platform_admin()) {
    http_response_code(403);
    exit('Platform admin only.');
}

$action = (string)($_POST['action'] ?? 'upload');

$brandingDir = __DIR__ . '/../uploads/branding';
$iconPath    = $brandingDir . '/pwa_icon.png';
$cacheDir    = __DIR__ . '/../assets/img/cache';

if (!is_dir($brandingDir)) {
    @mkdir($brandingDir, 0775, true);
}

/**
 * Wipe every cached PNG so the next request to icon.php regenerates from
 * the new source. Best-effort — don't fail the upload if a stale file
 * can't be removed (e.g. permissions).
 */
$clearCache = function () use ($cacheDir) {
    if (!is_dir($cacheDir)) return;
    foreach (glob($cacheDir . '/icon-*.png') ?: [] as $f) {
        @unlink($f);
    }
};

if ($action === 'delete') {
    if (is_file($iconPath)) @unlink($iconPath);
    $clearCache();
    log_activity(0, (int)$user['id'], 'pwa_icon_deleted', null, null, 'Custom PWA icon removed');
    redirect('/admin/branding.php?status=deleted');
}

// --------- upload path ---------
if (empty($_FILES['icon']) || !is_array($_FILES['icon'])) {
    redirect('/admin/branding.php?error=' . rawurlencode('No file uploaded.'));
}
$file = $_FILES['icon'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    redirect('/admin/branding.php?error=' . rawurlencode('Upload failed (code ' . (int)$file['error'] . ').'));
}
$maxBytes = 4 * 1024 * 1024; // 4 MB is plenty for an icon
if ((int)$file['size'] > $maxBytes) {
    redirect('/admin/branding.php?error=' . rawurlencode('File too big (max 4 MB).'));
}

$mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
$allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/webp' => 'webp'];
if (!isset($allowedMimes[$mime])) {
    redirect('/admin/branding.php?error=' . rawurlencode('Unsupported file type. Use PNG, JPG or WebP.'));
}

if (!function_exists('imagecreatefrompng')) {
    redirect('/admin/branding.php?error=' . rawurlencode('Server missing GD extension — contact platform admin.'));
}

// Decode source image via GD.
$src = null;
switch ($allowedMimes[$mime]) {
    case 'png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
    case 'jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
    case 'webp': $src = function_exists('imagecreatefromwebp')
                    ? @imagecreatefromwebp($file['tmp_name'])
                    : null;
                 break;
}
if (!$src) {
    redirect('/admin/branding.php?error=' . rawurlencode('Could not decode image.'));
}

$srcW = imagesx($src);
$srcH = imagesy($src);
if ($srcW < 64 || $srcH < 64) {
    imagedestroy($src);
    redirect('/admin/branding.php?error=' . rawurlencode('Image is too small. Please upload at least 512×512 pixels for a crisp home-screen icon.'));
}

// Normalize to a 1024x1024 PNG with transparent bleed. If the source
// is already square, this is a straight resize; if it's rectangular,
// we center-crop to square first so we don't distort the artwork.
$target = 1024;
$dst = imagecreatetruecolor($target, $target);
imagesavealpha($dst, true);
imagealphablending($dst, false);
$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
imagefill($dst, 0, 0, $transparent);
imagealphablending($dst, true);

// Center-crop the source to the shorter side.
$side  = min($srcW, $srcH);
$srcX  = (int)(($srcW - $side) / 2);
$srcY  = (int)(($srcH - $side) / 2);

imagecopyresampled(
    $dst, $src,
    0, 0, $srcX, $srcY,
    $target, $target, $side, $side
);

// Ensure destination directory writable, then write PNG.
if (!is_writable($brandingDir)) {
    @chmod($brandingDir, 0775);
}
if (!imagepng($dst, $iconPath, 6)) {
    imagedestroy($src); imagedestroy($dst);
    redirect('/admin/branding.php?error=' . rawurlencode('Failed to save the icon file — check server permissions on uploads/branding/.'));
}
imagedestroy($src);
imagedestroy($dst);
@chmod($iconPath, 0644);

$clearCache();

log_activity(0, (int)$user['id'], 'pwa_icon_uploaded', null, null,
    'Custom PWA icon uploaded (' . number_format(filesize($iconPath)) . ' bytes)');

redirect('/admin/branding.php?status=uploaded');
