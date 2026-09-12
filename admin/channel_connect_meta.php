<?php
/**
 * Connect Facebook / Instagram landing page.
 *
 * Explains the flow, checks that the Meta App is configured, then hands
 * off to /api/meta_oauth_start.php which begins the Facebook Login redirect.
 * Two GET params:
 *   platform = 'facebook' | 'instagram'  (defaults to facebook)
 */

require_once __DIR__ . '/../inc/layout.php';

$current_user = require_role(['super_admin']);
$isPlatform   = is_platform_admin();
if (!$isPlatform) {
    http_response_code(403);
    exit('Platform admin only.');
}

$platform = (string)($_GET['platform'] ?? 'facebook');
if (!in_array($platform, ['facebook', 'instagram'], true)) {
    $platform = 'facebook';
}

$configured = meta_is_configured();

layout_start($current_user, 'Connect ' . ucfirst($platform), 'channels');
?>
<div class="card">
  <div class="card-head">
    <h2>
      <?php if ($platform === 'facebook'): ?>
        Connect a Facebook Page
      <?php else: ?>
        Connect an Instagram Business account
      <?php endif; ?>
    </h2>
    <a class="btn" href="/admin/channels.php">← Back to channels</a>
  </div>

  <?php if (!$configured): ?>
    <div class="alert alert-error">
      <strong>Meta App not configured yet.</strong>
      The platform admin needs to fill in <code>META_APP_ID</code> and
      <code>META_APP_SECRET</code>. See
      <a href="/docs/META_SETUP.md"><code>docs/META_SETUP.md</code></a> for the setup guide.
    </div>
  <?php else: ?>
    <p>
      When you click <strong>Continue to Facebook</strong> below, Facebook will ask
      <em>your customer</em> to sign in and pick which of their
      <?= $platform === 'facebook' ? 'Pages' : 'Instagram Business accounts (linked to their Pages)' ?>
      to grant access to. We only store the access tokens Facebook returns —
      never a password.
    </p>

    <p>
      After they approve, they land back here and the
      <?= $platform === 'facebook' ? 'Page' : 'IG account' ?>
      appears in the channels list. New comments start flowing into the shared
      inbox within seconds.
    </p>

    <h3 style="margin-top:24px;">Before you continue — the customer needs to be:</h3>
    <ul style="margin: 8px 0 24px 20px;">
      <li>An <strong>Admin, Editor, or Moderator</strong> of the Facebook Page</li>
      <?php if ($platform === 'instagram'): ?>
        <li>Their Instagram account must be a <strong>Business or Creator account</strong>
            (not Personal) and <strong>linked to the Facebook Page</strong> in
            Instagram → Settings → Account → Linked Accounts</li>
      <?php endif; ?>
      <li>Signed in to the same Facebook account they use to manage the Page</li>
    </ul>

    <form method="post" action="/api/meta_oauth_start.php" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="platform" value="<?= e($platform) ?>">
      <button type="submit" class="btn btn-lg"
              style="background: <?= $platform === 'facebook' ? '#1877F2' : 'linear-gradient(45deg,#833AB4,#FD1D1D,#FCB045)' ?>; color:#fff; border-color:transparent;">
        Continue to Facebook →
      </button>
      <span class="muted small">Opens Facebook Login in this tab.</span>
    </form>

    <details style="margin-top:32px;">
      <summary style="cursor:pointer;" class="muted small">What permissions will be requested?</summary>
      <ul style="margin: 8px 0 0 20px;" class="small">
        <li><code>pages_show_list</code> — see which Pages the user administers</li>
        <li><code>pages_read_engagement</code> — read post + comment data</li>
        <li><code>pages_manage_engagement</code> — post replies, hide/delete comments</li>
        <li><code>pages_manage_metadata</code> — subscribe the Page to comment webhooks</li>
        <?php if ($platform === 'instagram'): ?>
          <li><code>instagram_basic</code> — link IG Business account to Page</li>
          <li><code>instagram_manage_comments</code> — read/reply/hide/delete IG comments</li>
        <?php endif; ?>
      </ul>
      <p class="muted small" style="margin-top:12px;">
        We do NOT request <code>pages_messaging</code> yet — private DM
        replies to comments will land in a later phase.
      </p>
    </details>
  <?php endif; ?>
</div>
<?php layout_end(); ?>
