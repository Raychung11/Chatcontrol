<?php
/**
 * Shared sidebar navigation.
 *
 * Grouped into collapsible sections so a super-admin+platform view
 * doesn't scroll off the screen. Groups use native <details>/<summary>
 * — no JS needed to toggle. localStorage remembers each group's
 * open/closed state across pages, but the group containing the active
 * link ALWAYS starts open on page load so the user's current location
 * is discoverable.
 *
 * Expects $current_user to be available, and optionally $active_nav
 * for highlighting.
 */
$active = $active_nav ?? '';
$role   = $current_user['role'] ?? 'agent';

// Per-workspace branding: company name + logo.
$sb_companyId   = (int)($current_user['company_id'] ?? 0);
$sb_companyName = '';
$sb_hasLogo     = false;
$sb_logoMtime   = 0;
if ($sb_companyId > 0) {
    try {
        $sb = aiserve_db()->prepare('SELECT name, logo FROM companies WHERE id = ? LIMIT 1');
        $sb->execute([$sb_companyId]);
        $sbRow = $sb->fetch();
        if ($sbRow) {
            $sb_companyName = (string)($sbRow['name'] ?? '');
            if (!empty($sbRow['logo'])) {
                $lp = __DIR__ . '/../uploads/companies/' . $sb_companyId . '/logo.png';
                if (is_file($lp)) {
                    $sb_hasLogo   = true;
                    $sb_logoMtime = (int)filemtime($lp);
                }
            }
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// Empty-workspace nudge for the setup wizard.
$showWizardBadge = false;
if (in_array($role, ['super_admin', 'manager'], true) && !empty($current_user['company_id'])) {
    try {
        $wizStmt = aiserve_db()->prepare(
            'SELECT (SELECT COUNT(*) FROM message_templates WHERE company_id = ?)
                  + (SELECT COUNT(*) FROM auto_replies      WHERE company_id = ?) AS total'
        );
        $wizStmt->execute([(int)$current_user['company_id'], (int)$current_user['company_id']]);
        $showWizardBadge = ((int)$wizStmt->fetchColumn()) === 0;
    } catch (Throwable $e) { /* skip badge */ }
}

/**
 * Group → active-nav-keys map. Used to auto-expand the group that
 * contains the currently-active page. Every nav key that lives inside
 * a collapsible group should appear in exactly one bucket here.
 */
$sb_groups = [
    'messaging' => ['templates', 'auto_replies', 'broadcasts', 'flows', 'knowledge', 'tags'],
    'reports'   => ['reports', 'topics', 'ai_usage'],
    'workspace' => ['channels', 'webchat', 'users', 'departments', 'branches', 'routing'],
    'settings'  => ['settings', 'ai_settings'],
    'fnb'       => ['fnb_orders', 'fnb_menu', 'fnb_analytics'],
    'platform'  => ['workspaces', 'channels_debug', 'ai_billing', 'pricing', 'legal', 'branding',
                    'evolution_connect', 'webhook_log', 'connection_debug'],
];
$sb_openGroup = fn(string $g): string =>
    in_array($active, $sb_groups[$g] ?? [], true) ? ' open' : '';

$fnbActive = fnb_module_active((int)($current_user['company_id'] ?? 0));
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <?php if ($sb_hasLogo): ?>
      <img class="sidebar-logo"
           src="/assets/img/company_logo.php?company_id=<?= $sb_companyId ?>&v=<?= $sb_logoMtime ?>"
           alt="<?= e($sb_companyName ?: 'Workspace') ?> logo">
    <?php else: ?>
      <span class="brand-dot" style="background: <?= e($brand_color ?? '#25D366') ?>"></span>
    <?php endif; ?>
    <span class="brand-text"><?= e($sb_companyName !== '' ? $sb_companyName : 'AiServe Inbox') ?></span>
  </div>

  <nav class="sidebar-nav" id="sidebar-nav">

    <!-- ============ Top-level: always visible, no group ============ -->
    <a href="/dashboard.php"   class="<?= $active === 'dashboard' ? 'active' : '' ?>">🏠 Dashboard</a>
    <a href="/inbox/index.php" class="<?= $active === 'inbox'     ? 'active' : '' ?>">📥 Inbox</a>
    <a href="/contacts.php"    class="<?= $active === 'contacts'  ? 'active' : '' ?>">👤 Contacts</a>

    <?php if (in_array($role, ['super_admin', 'manager'], true)): ?>
      <?php if ($showWizardBadge): ?>
        <a href="/admin/setup_wizard.php" class="nav-highlight <?= $active === 'setup_wizard' ? 'active' : '' ?>">
          🚀 Quick setup <span class="nav-badge">NEW</span>
        </a>
      <?php else: ?>
        <a href="/admin/setup_wizard.php" class="<?= $active === 'setup_wizard' ? 'active' : '' ?>">🚀 Quick setup</a>
      <?php endif; ?>
    <?php endif; ?>

    <!-- ============ 💬 Messaging ============ -->
    <details class="nav-group" data-group="messaging"<?= $sb_openGroup('messaging') ?>>
      <summary>💬 Messaging</summary>
      <a href="/admin/templates.php"    class="<?= $active === 'templates'    ? 'active' : '' ?>">Templates</a>
      <?php if (in_array($role, ['super_admin', 'manager'], true)): ?>
        <a href="/admin/broadcasts.php"   class="<?= $active === 'broadcasts'   ? 'active' : '' ?>">Broadcasts</a>
        <a href="/admin/auto_replies.php" class="<?= $active === 'auto_replies' ? 'active' : '' ?>">Auto replies</a>
        <a href="/admin/knowledge.php"    class="<?= $active === 'knowledge'    ? 'active' : '' ?>">Knowledge base</a>
        <a href="/admin/tags.php"         class="<?= $active === 'tags'         ? 'active' : '' ?>">Tags</a>
      <?php endif; ?>
      <?php if ($role === 'super_admin'): ?>
        <a href="/admin/flows.php"        class="<?= $active === 'flows'        ? 'active' : '' ?>">Message flows</a>
      <?php endif; ?>
    </details>

    <?php if (in_array($role, ['super_admin', 'manager'], true)): ?>
      <!-- ============ 📊 Reports & Analytics ============ -->
      <details class="nav-group" data-group="reports"<?= $sb_openGroup('reports') ?>>
        <summary>📊 Reports</summary>
        <a href="/admin/reports.php"  class="<?= $active === 'reports'  ? 'active' : '' ?>">Reports</a>
        <a href="/admin/topics.php"   class="<?= $active === 'topics'   ? 'active' : '' ?>">Topics analytics</a>
        <a href="/admin/ai_usage.php" class="<?= $active === 'ai_usage' ? 'active' : '' ?>">💰 AI usage</a>
      </details>
    <?php endif; ?>

    <?php if ($role === 'super_admin'): ?>
      <!-- ============ 🏢 Workspace setup ============ -->
      <details class="nav-group" data-group="workspace"<?= $sb_openGroup('workspace') ?>>
        <summary>🏢 Workspace</summary>
        <a href="/admin/channels.php"    class="<?= $active === 'channels'    ? 'active' : '' ?>">Channels</a>
        <a href="/admin/webchat.php"     class="<?= $active === 'webchat'     ? 'active' : '' ?>">💬 Web chat widget</a>
        <a href="/admin/users.php"       class="<?= $active === 'users'       ? 'active' : '' ?>">Users</a>
        <a href="/admin/departments.php" class="<?= $active === 'departments' ? 'active' : '' ?>">Departments</a>
        <a href="/admin/branches.php"    class="<?= $active === 'branches'    ? 'active' : '' ?>">Branches</a>
        <a href="/admin/routing.php"     class="<?= $active === 'routing'     ? 'active' : '' ?>">Routing rules</a>
      </details>

      <!-- ============ ⚙️ Settings ============ -->
      <details class="nav-group" data-group="settings"<?= $sb_openGroup('settings') ?>>
        <summary>⚙️ Settings</summary>
        <a href="/admin/settings.php"    class="<?= $active === 'settings'    ? 'active' : '' ?>">Workspace settings</a>
        <a href="/admin/ai_settings.php" class="<?= $active === 'ai_settings' ? 'active' : '' ?>">AI settings</a>
      </details>

      <?php if ($fnbActive): ?>
        <!-- ============ 🍜 F&B ============ -->
        <details class="nav-group" data-group="fnb"<?= $sb_openGroup('fnb') ?>>
          <summary>🍜 F&amp;B module</summary>
          <a href="/admin/fnb_orders.php"    class="<?= $active === 'fnb_orders'    ? 'active' : '' ?>">📋 Orders</a>
          <a href="/admin/fnb_menu.php"      class="<?= $active === 'fnb_menu'      ? 'active' : '' ?>">🍜 Menu</a>
          <a href="/admin/fnb_analytics.php" class="<?= $active === 'fnb_analytics' ? 'active' : '' ?>">📊 Analytics</a>
        </details>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (is_platform_admin() && !is_impersonating()): ?>
      <!-- ============ 🛠 Platform tools (platform admin) ============ -->
      <details class="nav-group nav-group-platform" data-group="platform"<?= $sb_openGroup('platform') ?>>
        <summary>🛠 Platform</summary>
        <a href="/admin/workspaces.php"      class="<?= $active === 'workspaces'      ? 'active' : '' ?>">Workspaces</a>
        <a href="/admin/channels_debug.php"  class="<?= $active === 'channels_debug'  ? 'active' : '' ?>">🩺 Channels health</a>
        <a href="/admin/ai_billing.php"      class="<?= $active === 'ai_billing'      ? 'active' : '' ?>">💰 AI billing</a>
        <a href="/admin/pricing.php"         class="<?= $active === 'pricing'         ? 'active' : '' ?>">Pricing</a>
        <a href="/admin/legal.php"           class="<?= $active === 'legal'           ? 'active' : '' ?>">Legal &amp; operator</a>
        <a href="/admin/branding.php"        class="<?= $active === 'branding'        ? 'active' : '' ?>">Branding &amp; icon</a>
        <a href="/admin/evolution_connect.php" class="<?= $active === 'evolution_connect' ? 'active' : '' ?>">Connect WhatsApp</a>
        <a href="/admin/webhook_log.php"       class="<?= $active === 'webhook_log'       ? 'active' : '' ?>">Webhook log</a>
        <a href="/admin/connection_debug.php"  class="<?= $active === 'connection_debug'  ? 'active' : '' ?>">Connection debug</a>
      </details>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= e(strtoupper(substr($current_user['name'] ?? '?', 0, 1))) ?></div>
      <div class="user-meta">
        <div class="user-name"><?= e($current_user['name'] ?? '') ?></div>
        <div class="user-role"><?= e(role_label($current_user['role'] ?? 'agent')) ?></div>
      </div>
    </div>
    <a class="btn-logout" href="/logout.php">Logout</a>
  </div>
</aside>

<script>
// Remember each nav group's open/closed state across page loads.
// The group containing the current active link is force-opened by
// the server via the `open` attribute — we don't override that here
// (that'd hide the user's own location). We DO restore user-toggled
// state for every other group so the sidebar respects preferences.
(function () {
    var nav = document.getElementById('sidebar-nav');
    if (!nav) return;
    var groups = nav.querySelectorAll('details.nav-group');
    groups.forEach(function (g) {
        var key = 'aiserve_nav_' + g.dataset.group;
        // If server didn't force it open, restore last saved state.
        if (!g.hasAttribute('open')) {
            try {
                if (localStorage.getItem(key) === '1') g.setAttribute('open', '');
            } catch (e) {}
        }
        g.addEventListener('toggle', function () {
            try { localStorage.setItem(key, g.open ? '1' : '0'); } catch (e) {}
        });
    });
})();
</script>
