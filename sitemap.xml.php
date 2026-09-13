<?php
/**
 * /sitemap.xml.php — XML sitemap for search engines.
 *
 * PHP-driven so it uses APP_BASE_URL (or auto-detects) instead of
 * a hardcoded hostname — the same file works on inbox.aiserve.my,
 * a staging domain, or a customer's own vanity host.
 *
 * Only public marketing pages listed. Private surfaces (admin,
 * inbox, per-workspace URLs) are blocked in robots.txt AND
 * omitted here so Google doesn't discover them accidentally.
 *
 * Google reads .xml.php just fine — no rewrite rule needed. The
 * bots follow the Sitemap: line in robots.txt regardless of
 * extension.
 */

require_once __DIR__ . '/inc/helpers.php';

$base = defined('APP_BASE_URL') && APP_BASE_URL !== ''
    ? rtrim((string)APP_BASE_URL, '/')
    : ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'inbox.aiserve.my'));

// Static-ish last-modified. We stamp today so search engines re-crawl
// at least every week — cheap and correct for a marketing site whose
// content shifts as we add features or update pricing.
$today = date('Y-m-d');

// Each entry: [path, changefreq, priority]. Ordered by priority so
// the higher-value marketing pages come first.
$urls = [
    ['/',              'weekly',  '1.0'],
    ['/pricing.php',   'weekly',  '0.9'],
    ['/register.php',  'monthly', '0.7'],
    ['/terms.php',     'yearly',  '0.3'],
    ['/privacy.php',   'yearly',  '0.3'],
    ['/refund.php',    'yearly',  '0.3'],
    ['/disclaimer.php','yearly',  '0.3'],
];

header('Content-Type: application/xml; charset=utf-8');
// Cache for an hour so crawlers can hit us cheaply without hammering
// PHP-FPM, but stay fresh enough to reflect the same-day $today.
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as [$path, $freq, $prio]): ?>
  <url>
    <loc><?= htmlspecialchars($base . $path, ENT_QUOTES | ENT_XML1, 'UTF-8') ?></loc>
    <lastmod><?= $today ?></lastmod>
    <changefreq><?= $freq ?></changefreq>
    <priority><?= $prio ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
