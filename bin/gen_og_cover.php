<?php
/**
 * bin/gen_og_cover.php — one-shot generator for the Open Graph
 * share-preview image at /assets/img/og-cover.png.
 *
 * Run once per branding change:
 *   php bin/gen_og_cover.php
 *
 * Outputs a 1200×630 PNG (the size WhatsApp / Facebook / LinkedIn /
 * X all render as the big preview card) with the brand mark, tagline,
 * and locale accent, on a WhatsApp-green gradient. Uses PHP GD +
 * DejaVu Sans (falls back gracefully if a font isn't installed).
 *
 * The generator lives in bin/ so the asset directory stays static —
 * the CDN cache never gets confused between "the tool" and "the
 * thing it produced". Commit both the generator and its output.
 */

const OUT_PATH = __DIR__ . '/../assets/img/og-cover.png';
const W        = 1200;
const H        = 630;

// Pick the first font in the list that exists — different distros
// name the DejaVu family slightly differently.
function pick_font(array $candidates): ?string
{
    foreach ($candidates as $p) if (is_file($p)) return $p;
    return null;
}
$fontBold = pick_font([
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    '/Library/Fonts/DejaVuSans-Bold.ttf',
]);
$fontReg  = pick_font([
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/TTF/DejaVuSans.ttf',
    '/Library/Fonts/DejaVuSans.ttf',
]);
if (!$fontBold || !$fontReg) {
    fwrite(STDERR, "Missing DejaVu Sans (install fonts-dejavu or point at another TTF)\n");
    exit(1);
}

$img = imagecreatetruecolor(W, H);
imagesavealpha($img, true);

// -------------------- Background gradient --------------------
// Deep teal-to-WhatsApp-green diagonal. Rendered as a filled rect
// per row so we get a smooth diagonal fade without a huge blit.
$cTop = [0x0b, 0x38, 0x2d];     // deep forest
$cBot = [0x14, 0x76, 0x4d];     // WhatsApp-adjacent
for ($y = 0; $y < H; $y++) {
    $t = $y / (H - 1);
    $r = (int)round($cTop[0] + ($cBot[0] - $cTop[0]) * $t);
    $g = (int)round($cTop[1] + ($cBot[1] - $cTop[1]) * $t);
    $b = (int)round($cTop[2] + ($cBot[2] - $cTop[2]) * $t);
    $col = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, W - 1, $y, $col);
}

// Colors used by both type + shapes
$white  = imagecolorallocate($img, 0xff, 0xff, 0xff);
$muted  = imagecolorallocate($img, 0xd6, 0xf1, 0xe1);   // soft mint
$accent = imagecolorallocate($img, 0xfc, 0xd3, 0x4d);   // Malaysian-flag yellow
$deep   = imagecolorallocate($img, 0x0b, 0x38, 0x2d);   // brand deep for chip text

// Subtle bubble motif — concentric rings on the right side hint at
// a chat conversation without competing with the type. Pulled all
// the way to the right + slightly shrunk so the headline never
// crashes into it.
$ring = imagecolorallocatealpha($img, 0xff, 0xff, 0xff, 118);
for ($i = 0; $i < 7; $i++) {
    imageellipse($img, 1130, 500, 320 + $i * 20, 320 + $i * 20, $ring);
}
$ringSoft = imagecolorallocatealpha($img, 0xff, 0xff, 0xff, 105);
imagefilledellipse($img, 1130, 500, 260, 260, $ringSoft);

// Chat-bubble motif inside the ring (moved down + right so it
// doesn't crowd the second line of headline text).
$bubbleF = imagecolorallocatealpha($img, 0xff, 0xff, 0xff, 20);
imagefilledroundedcorner($img, 1035, 455, 1195, 545, 18, $bubbleF);
// Bubble tail
imagefilledpolygon($img, [1045, 545, 1075, 545, 1055, 570], $bubbleF);

// Typing dots in the bubble
$dot = imagecolorallocate($img, 0x14, 0x76, 0x4d);
imagefilledellipse($img, 1080, 500, 16, 16, $dot);
imagefilledellipse($img, 1115, 500, 16, 16, $dot);
imagefilledellipse($img, 1150, 500, 16, 16, $dot);

// -------------------- Type --------------------
// Locale badge — a solid yellow chip with "MY" text instead of the
// 🇲🇾 emoji, since DejaVu Sans doesn't ship color-emoji glyphs and
// GD would render the flag as garbled bytes.
imagefilledroundedcorner($img, 80, 105, 165, 148, 8, $accent);
imagettftext($img, 20, 0, 100, 138, $deep, $fontBold, 'MY');
imagettftext($img, 22, 0, 185, 138, $accent, $fontBold, 'Built for Malaysian SMEs');

// Headline — big + bold. Two lines with generous negative space so
// the chat-bubble motif in the bottom-right doesn't crowd them.
imagettftext($img, 72, 0, 80, 240, $white, $fontBold, 'One WhatsApp,');
imagettftext($img, 72, 0, 80, 330, $white, $fontBold, 'your whole team.');

// Product name — smaller, under the tagline as a signature
imagettftext($img, 32, 0, 80, 420, $white, $fontBold, 'AiServe Inbox');
imagettftext($img, 22, 0, 80, 462, $muted, $fontReg,  'Shared WhatsApp inbox · AI drafts · F&B ordering · Broadcast');

// URL footer at the bottom-left
imagettftext($img, 22, 0, 80, 570, $muted, $fontReg,  'inbox.aiserve.my');

// -------------------- Write --------------------
if (!is_dir(dirname(OUT_PATH))) mkdir(dirname(OUT_PATH), 0755, true);
imagepng($img, OUT_PATH, 4);   // level 4 = balanced size/quality
imagedestroy($img);

$bytes = filesize(OUT_PATH);
echo "wrote " . OUT_PATH . " (" . number_format($bytes) . " bytes)\n";

/**
 * GD doesn't ship a rounded-rect primitive, so we roll our own using
 * one rectangle for the middle band + two rectangles for the sides +
 * four filled-arc corners. Called via imagefilledroundedcorner() —
 * the name is deliberate so grep-finds the helper.
 */
function imagefilledroundedcorner(?GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
{
    if ($img === null) return;
    // Body rectangle (minus corner overhang)
    imagefilledrectangle($img, $x1 + $r, $y1,     $x2 - $r, $y2,     $color);
    imagefilledrectangle($img, $x1,     $y1 + $r, $x2,     $y2 - $r, $color);
    // Four corners
    imagefilledarc($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color, IMG_ARC_PIE);
    imagefilledarc($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color, IMG_ARC_PIE);
    imagefilledarc($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2,  90, 180, $color, IMG_ARC_PIE);
    imagefilledarc($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2,   0,  90, $color, IMG_ARC_PIE);
}
