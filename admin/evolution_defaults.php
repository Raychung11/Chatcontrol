<?php
/**
 * Platform-level Evolution defaults.
 *
 * Sets the shared base URL + API key that channel_edit.php and
 * evolution_connect.php auto-fill for every workspace admin. When
 * these are configured, a workspace admin only has to pick an
 * instance name to pair a new WhatsApp number — the platform
 * takes care of the plumbing.
 *
 * Platform-admin-only page. Written on top of the same
 * platform_settings_helper.php that /admin/pricing.php uses, so
 * the shape is identical: one form, key/value upsert, log to
 * activity, changes are live immediately.
 */

require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/platform_settings_helper.php';
require_once __DIR__ . '/../inc/evolution_api.php';

$current_user = require_role(['super_admin']);
if (!is_platform_admin()) {
    http_response_code(403);
    exit('Forbidden — platform administrator only.');
}

$db  = aiserve_db();
$msg = '';
$err = '';

$fields = [
    'evolution_default_base_url' => [
        'label'    => 'Evolution base URL',
        'type'     => 'text',
        'hint'     => 'The public URL of your Evolution API server (no trailing slash). Example: https://evo.aiserve.my',
        'max'      => 255,
        'optional' => true,
    ],
    'evolution_default_api_key' => [
        'label'    => 'Evolution API key',
        'type'     => 'password',
        'hint'     => 'The master API key you set as EVOLUTION_API_KEY in the Evolution container\'s .env. Leave blank to keep the saved value; enter a new one only when you\'ve rotated the key.',
        'max'      => 255,
        'optional' => true,
    ],
];

if (is_post()) {
    csrf_check();
    $r = platform_settings_save(
        $db, $fields, 'platform_evolution_defaults_updated',
        (int)$current_user['company_id'], (int)$current_user['id']
    );
    $msg = $r['msg'];
    $err = $r['err'];
}

$loaded  = platform_settings_load($db);
$current = $loaded['rows'];
if ($loaded['err'] && !$err) $err = $loaded['err'];

// Count how many channels are still missing per-row config so we can
// motivate the operator with real numbers: "12 channels will benefit
// once you save this."
$missingCount = 0;
try {
    $missingCount = (int)$db->query(
        "SELECT COUNT(*) FROM channels
         WHERE provider = 'evolution'
           AND (evolution_base_url IS NULL OR evolution_base_url = ''
                OR evolution_api_key  IS NULL OR evolution_api_key  = '')"
    )->fetchColumn();
} catch (Throwable $e) { /* schema drift — leave zero */ }

layout_start($current_user, 'Evolution defaults', 'evolution_defaults');
?>
<div class="card">
  <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endif; ?>

  <h2 style="margin-top:0;">Evolution shared server defaults</h2>
  <p class="muted small">
    Fill these in once as the platform admin. Every workspace's channel_edit and
    pairing wizard then auto-fills the base URL and API key — a workspace admin
    only needs to pick an instance name to onboard a new WhatsApp number.
    Individual channels can still override these values if a workspace runs
    its own dedicated Evolution box.
  </p>

  <?php if ($missingCount > 0): ?>
    <div class="alert alert-info">
      <?= (int)$missingCount ?> existing Evolution channel<?= $missingCount === 1 ? '' : 's' ?>
      still <?= $missingCount === 1 ? 'has' : 'have' ?> blank base URL / API key.
      Once these defaults are saved, the pairing wizard will back-fill them on
      first visit so those channels start working without a manual edit.
    </div>
  <?php endif; ?>

  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <?php foreach ($fields as $key => $meta) {
        render_platform_setting_field($key, $meta, $current[$key] ?? '');
    } ?>
    <button class="btn btn-primary" type="submit">Save Evolution defaults</button>
  </form>

  <?php
    $defs = evolution_platform_defaults();
    if ($defs['base_url'] !== '' && $defs['api_key'] !== ''):
  ?>
    <div class="alert alert-ok" style="margin-top:16px;">
      ✅ Defaults are set. Base URL: <code><?= e($defs['base_url']) ?></code> ·
      API key: <code><?= e(substr($defs['api_key'], 0, 6)) ?>…<?= e(substr($defs['api_key'], -4)) ?></code>
      (<?= strlen($defs['api_key']) ?> chars).
    </div>
  <?php else: ?>
    <div class="alert alert-warning" style="margin-top:16px;">
      ⚠ Defaults are not fully set yet. Both the base URL and the API key must
      be filled in for auto-fill to work.
    </div>
  <?php endif; ?>
</div>

<?php layout_end(); ?>
