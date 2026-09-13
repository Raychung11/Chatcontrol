<?php
/**
 * GET /api/meta_deletion_status.php?code=<confirmation_code>
 *
 * Meta requires the URL we returned from meta_deletion_callback.php to
 * resolve to a real page the user can visit to check on their deletion
 * request. Since our deletion is synchronous — we run it inside the
 * callback — the answer is always "already done". This page just shows
 * that plainly.
 */

require_once __DIR__ . '/../inc/helpers.php';

$code = trim((string)($_GET['code'] ?? ''));

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Data deletion status · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
</head>
<body class="landing-body">
  <div style="max-width:640px; margin:80px auto; padding:32px; background:#fff; border-radius:12px; box-shadow:var(--shadow-md);">
    <h1 style="margin-top:0;">Data deletion request received</h1>
    <p>
      Your request to delete data associated with your Facebook /
      Instagram account has been processed.
    </p>
    <p>
      All comments, conversations, and contact records tied to your Meta
      user id have been removed from this platform.
    </p>
    <?php if ($code !== ''): ?>
      <p class="muted">
        Your confirmation code: <code><?= e($code) ?></code>
      </p>
    <?php endif; ?>
    <p>
      If you have questions about what data was held or removed, please
      contact us and quote the confirmation code above.
    </p>
    <p><a class="btn" href="/">← Back to homepage</a></p>
  </div>
</body>
</html>
