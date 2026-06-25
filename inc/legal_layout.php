<?php
/**
 * Legal page wrapper.
 *
 * The three /terms.php, /privacy.php, /disclaimer.php pages are
 * boilerplate templates that this workspace operator should review
 * with their own legal counsel before relying on them for compliance.
 * Edit the page bodies directly to reflect your jurisdiction, your
 * data-processing arrangements, and your billing terms.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cookie_notice.php';

function legal_page_start(string $title): void
{
    $lastUpdated = date('F j, Y');
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
  <?= pwa_head_tags() ?>
</head>
<body class="landing-body">

<header class="landing-nav">
  <a class="landing-brand" href="/">
    <span class="brand-dot" style="background:#25D366"></span>
    <span class="brand-text"><?= e(APP_NAME) ?></span>
  </a>
  <nav class="landing-nav-links">
    <a href="/#features">Features</a>
    <a href="/#plans">Plans</a>
    <a class="btn btn-primary btn-sm" href="/login.php">Sign in</a>
  </nav>
</header>

<main class="legal-page">
  <h1><?= e($title) ?></h1>
  <p class="muted small">Last updated: <?= e($lastUpdated) ?></p>
<?php
}

function legal_page_end(): void
{
    $year = date('Y');
?>
</main>

<footer class="landing-footer">
  <div>&copy; <?= e((string)$year) ?> <?= e(APP_NAME) ?></div>
  <div>
    <a href="/terms.php">Terms</a>
    <span class="dot">·</span>
    <a href="/privacy.php">Privacy</a>
    <span class="dot">·</span>
    <a href="/disclaimer.php">Disclaimer</a>
    <span class="dot">·</span>
    <a href="/login.php">Sign in</a>
  </div>
</footer>

<?php cookie_notice(); ?>
<script src="<?= e(asset_url('/assets/js/pwa.js')) ?>" defer></script>
</body>
</html>
<?php
}
