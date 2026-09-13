<?php
/**
 * Dynamic PWA icon generator.
 *
 * Renders a green-circle "A" PNG at the requested size and caches it to
 * /assets/img/cache/icon-<size>[-maskable].png so subsequent requests are
 * served from disk by PHP (or Apache, if configured).
 *
 * Browser usage in /manifest.json:
 *   /assets/img/icon.php?size=192
 *   /assets/img/icon.php?size=512&maskable=1
 *
 * Replace this file with your real brand PNGs once you have a designer.
 * The manifest references stay the same.
 */

$size     = max(48, min(1024, (int)($_GET['size'] ?? 192)));
$maskable = !empty($_GET['maskable']);

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

$cacheFile = $cacheDir . '/icon-' . $size . ($maskable ? '-maskable' : '') . '.png';

// Custom brand icon uploaded via /admin/branding.php lives here. If
// present, we resize IT into $cacheFile instead of generating the green
// "A" default. The upload endpoint clears $cacheDir on upload so the
// switch is immediate; a stale cached file that predates the current
// upload is treated as invalid.
$brandingIcon = __DIR__ . '/../../uploads/branding/pwa_icon.png';
$hasBranding  = is_file($brandingIcon);

// Serve cached version if fresh (24 h) AND not stale vs the source.
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    $stale = $hasBranding && filemtime($cacheFile) < filemtime($brandingIcon);
    if (!$stale) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($cacheFile));
        readfile($cacheFile);
        exit;
    }
}

// Branded path: resize the uploaded PNG to the requested size.
if ($hasBranding && function_exists('imagecreatefrompng')) {
    $src = @imagecreatefrompng($brandingIcon);
    if ($src) {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $dst  = imagecreatetruecolor($size, $size);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagealphablending($dst, true);

        // Maskable icons need a 10% safe-zone padding so Android/Chrome
        // can crop into a circle/rounded-square without eating the logo.
        $padding = $maskable ? (int)($size * 0.10) : 0;
        $inner   = $size - (2 * $padding);
        imagecopyresampled($dst, $src, $padding, $padding, 0, 0, $inner, $inner, $srcW, $srcH);

        imagepng($dst, $cacheFile, 6);
        imagedestroy($src);
        imagedestroy($dst);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($cacheFile));
        readfile($cacheFile);
        exit;
    }
    // Decode failed — fall through to the default generator so the
    // page never renders a broken icon.
}

if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=86400');
    // Minimal SVG fallback when GD isn't installed.
    echo '<?xml version="1.0" encoding="UTF-8"?>'
       . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
       . '<circle cx="50" cy="50" r="50" fill="#25D366"/>'
       . '<text x="50" y="68" text-anchor="middle" font-family="Arial,sans-serif" '
       . 'font-size="60" font-weight="700" fill="#ffffff">A</text>'
       . '</svg>';
    exit;
}

$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);
imagealphablending($img, false);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefill($img, 0, 0, $transparent);
imagealphablending($img, true);

$green = imagecolorallocate($img, 0x25, 0xD3, 0x66);
$white = imagecolorallocate($img, 255, 255, 255);

// Maskable icons need a safe zone in the centre 80%, with the rest as bleed.
$padding = $maskable ? (int)($size * 0.10) : 0;
$cx = $cy = $size / 2;
$circleD = $size - (2 * $padding);

imagefilledellipse($img, (int)$cx, (int)$cy, (int)$circleD, (int)$circleD, $green);

// Letter "A" centred. Try to use a TrueType font for crisp rendering; fall
// back to imagestring() if no font is available.
$drawn = false;
if (function_exists('imagettftext')) {
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
    ];
    foreach ($candidates as $font) {
        if (is_readable($font)) {
            $fontSize = $circleD * 0.42;
            $bbox = imagettfbbox($fontSize, 0, $font, 'A');
            $w = $bbox[2] - $bbox[0];
            $h = $bbox[1] - $bbox[7];
            $x = ($size - $w) / 2 - $bbox[0];
            $y = ($size + $h) / 2;
            imagettftext($img, $fontSize, 0, (int)$x, (int)$y, $white, $font, 'A');
            $drawn = true;
            break;
        }
    }
}
if (!$drawn) {
    // Built-in font - small but always available.
    $fontIndex = 5;
    $charW = imagefontwidth($fontIndex);
    $charH = imagefontheight($fontIndex);
    imagestring($img, $fontIndex, (int)(($size - $charW) / 2), (int)(($size - $charH) / 2), 'A', $white);
}

imagepng($img, $cacheFile, 6);
imagedestroy($img);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($cacheFile));
readfile($cacheFile);
