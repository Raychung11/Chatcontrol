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
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <?= pwa_head_tags() ?>
</head>
<body<?= is_impersonating() ? ' class="impersonating"' : '' ?>>
<?php if (is_impersonating()): ?>
  <div class="impersonate-banner">
    <span>
      ⚠ You are signed in as super admin of
      <strong><?= e((string)($current_user['_impersonated_company']['name'] ?? 'workspace')) ?></strong>
      (slug: <code><?= e((string)($current_user['_impersonated_company']['slug'] ?? '')) ?></code>)
      — logged in as <?= e((string)($current_user['_real_name'] ?? '')) ?>
    </span>
    <form method="post" action="/api/impersonate.php" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stop">
      <button type="submit" class="btn-impersonate-stop">Return to your account</button>
    </form>
  </div>
<?php endif; ?>
<div class="app-shell">
<?php
    require __DIR__ . '/sidebar.php';
?>
  <div class="sidebar-overlay" id="sidebar-overlay" hidden></div>
  <main class="app-main">
    <header class="app-header">
      <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Open menu">☰</button>
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
<script src="<?= e(asset_url('/assets/js/app.js')) ?>" defer></script>
<script src="<?= e(asset_url('/assets/js/pwa.js')) ?>" defer></script>
</body>
</html>
<?php
}
