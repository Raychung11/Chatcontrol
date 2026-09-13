<?php
/**
 * /diag.php - one-shot diagnostic page.
 *
 * Visit https://YOUR-SITE/diag.php and the page below tells you exactly
 * which include is missing, which function is undefined, or which file
 * path is wrong. Safe to leave on a live site (read-only, no secrets
 * disclosed) but delete it once the issue is fixed.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

function diag_row(string $label, string $value, bool $ok = true): void
{
    $cls = $ok ? 'ok' : 'err';
    $icon = $ok ? '✓' : '✗';
    echo "<tr><th>{$label}</th><td class=\"{$cls}\">{$icon} " . htmlspecialchars($value) . "</td></tr>\n";
}

function check_file(string $path): array
{
    $abs = __DIR__ . '/' . ltrim($path, '/');
    return [
        'path'   => $path,
        'exists' => is_file($abs),
        'size'   => is_file($abs) ? filesize($abs) : 0,
        'mtime'  => is_file($abs) ? date('Y-m-d H:i:s', (int)filemtime($abs)) : '—',
        'absolute' => $abs,
    ];
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>AiServe diag</title>
<style>
  body { font-family: -apple-system, sans-serif; max-width: 880px; margin: 24px auto; padding: 0 16px; }
  h1 { font-size: 20px; }
  h2 { font-size: 15px; margin-top: 28px; padding-top: 12px; border-top: 1px solid #ddd; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; }
  th, td { padding: 6px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
  th { width: 220px; color: #444; font-weight: 500; background: #f7f8fa; }
  td.ok { color: #1f7a3f; }
  td.err { color: #b3261e; }
  pre { background: #1f2933; color: #d9e0e8; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px; }
  .alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
  .alert-ok  { background: #e8f7ee; color: #1f7a3f; border: 1px solid #b8e3c5; }
  .alert-bad { background: #fdecea; color: #b3261e; border: 1px solid #f5c6c2; }
</style>
</head>
<body>

<h1>AiServe diagnostics</h1>
<p>This page reports the status of the parts that often break a fresh deploy.
Once everything below is green, delete this file from your server.</p>

<h2>PHP environment</h2>
<table>
<?php
diag_row('PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.0', '>='));
diag_row('curl extension',  extension_loaded('curl') ? 'loaded' : 'missing', extension_loaded('curl'));
diag_row('pdo_mysql',       extension_loaded('pdo_mysql') ? 'loaded' : 'missing', extension_loaded('pdo_mysql'));
diag_row('mbstring',        extension_loaded('mbstring') ? 'loaded' : 'missing', extension_loaded('mbstring'));
diag_row('fileinfo',        extension_loaded('fileinfo') ? 'loaded' : 'missing', extension_loaded('fileinfo'));
diag_row('zip',             extension_loaded('zip') ? 'loaded' : 'missing', extension_loaded('zip'));
diag_row('Working directory', __DIR__);
diag_row('Document root',     (string)($_SERVER['DOCUMENT_ROOT'] ?? '?'));
?>
</table>

<h2>Critical files</h2>
<table>
<?php
$files = [
    'config/db_config.php',
    'inc/helpers.php',
    'inc/auth.php',
    'inc/layout.php',
    'inc/legal_layout.php',
    'inc/cookie_notice.php',
    'inc/provider.php',
    'inc/ai_api.php',
    'inc/knowledge_base.php',
    'index.php',
    'login.php',
    'register.php',
    'terms.php',
    'privacy.php',
    'disclaimer.php',
    'assets/css/app.css',
    'assets/js/app.js',
];
foreach ($files as $f) {
    $info = check_file($f);
    $line = $info['exists']
        ? "({$info['size']} bytes, modified {$info['mtime']})"
        : "MISSING at {$info['absolute']}";
    diag_row($f, $line, $info['exists']);
}
?>
</table>

<h2>Include test</h2>
<?php
$step = 'starting';
try {
    $step = 'loading config/db_config.php';
    require_once __DIR__ . '/config/db_config.php';
    echo '<div class="alert alert-ok">✓ config/db_config.php loaded.</div>';

    $step = 'loading inc/helpers.php';
    require_once __DIR__ . '/inc/helpers.php';
    echo '<div class="alert alert-ok">✓ inc/helpers.php loaded.</div>';

    $step = 'checking asset_url() function exists';
    if (!function_exists('asset_url')) {
        throw new RuntimeException(
            'asset_url() is NOT defined - the inc/helpers.php on the server is OUTDATED. ' .
            'Re-upload inc/helpers.php and the modification timestamp above should change.'
        );
    }
    $sample = asset_url('/assets/css/app.css');
    echo '<div class="alert alert-ok">✓ asset_url() works → <code>' . htmlspecialchars($sample) . '</code></div>';

    $step = 'loading inc/auth.php';
    require_once __DIR__ . '/inc/auth.php';
    echo '<div class="alert alert-ok">✓ inc/auth.php loaded.</div>';

    $step = 'connecting to MySQL';
    $db = aiserve_db();
    $row = $db->query('SELECT id, name, slug FROM companies LIMIT 1')->fetch();
    if ($row) {
        echo '<div class="alert alert-ok">✓ DB OK. Sample company row: id=' . (int)$row['id'] .
             ' name=' . htmlspecialchars((string)$row['name']) .
             ' slug=' . htmlspecialchars((string)($row['slug'] ?? '(none)')) . '</div>';
    } else {
        echo '<div class="alert alert-bad">⚠ DB connected but no companies row exists yet.</div>';
    }

    echo '<div class="alert alert-ok">All critical checks passed. The 500 is probably specific ' .
         'to one page - tell me which URL is 500-ing and I will look closer.</div>';
} catch (Throwable $e) {
    echo '<div class="alert alert-bad">';
    echo '<strong>✗ Failed at step:</strong> ' . htmlspecialchars($step) . '<br>';
    echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '<br>';
    echo '<strong>Where:</strong> ' . htmlspecialchars($e->getFile()) . ':' . (int)$e->getLine();
    echo '</div>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}
?>

<h2>What to do</h2>
<p>Once the page above is fully green, <strong>delete <code>diag.php</code> from your server</strong> -
this page enables verbose PHP error reporting and shouldn't stay on a live site.</p>

</body>
</html>
