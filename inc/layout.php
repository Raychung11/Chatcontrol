<?php
/**
 * Shared HTML layout.
 *
 * Usage:
 *   $page_title = 'Dashboard';
 *   $active_nav = 'dashboard';
 *   require __DIR__ . '/../inc/layout.php';   // calls layout_start()
 *     ... page body ...
 *   layout_end();
 */

require_once __DIR__ . '/auth.php';

function layout_start(array $current_user, string $page_title = '', string $active_nav = '', string $brand_color = '#25D366'): void
{
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title ? $page_title . ' · ' : '') . e(APP_NAME) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body>
<div class="app-shell">
<?php
    require __DIR__ . '/sidebar.php';
?>
  <main class="app-main">
    <header class="app-header">
      <h1 class="app-title"><?= e($page_title) ?></h1>
      <div class="app-header-actions" id="app-header-actions"></div>
    </header>
    <div class="app-content">
<?php
}

function layout_end(): void
{
    ?>
    </div>
  </main>
</div>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
<?php
}
