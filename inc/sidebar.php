<?php
/**
 * Shared sidebar navigation.
 * Expects $current_user to be available, and optionally $active_nav for highlighting.
 */
$active = $active_nav ?? '';
$role   = $current_user['role'] ?? 'agent';

// Per-workspace branding: company name + logo. Read once so the header
// carries the customer's brand instead of the default AiServe wordmark.
// Falls back gracefully when the workspace has neither.
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
    } catch (Throwable $e) {
        // Non-fatal — sidebar always renders even if the query fails.
    }
}
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

  <?php
    // Empty-workspace nudge: highlight the setup wizard on top of the
    // sidebar until they have at least one template OR one auto-reply.
    // Cheap two-count SELECT, only for the sidebar of managers+.
    $showWizardBadge = false;
    if (in_array($role, ['super_admin', 'manager'], true) && !empty($current_user['company_id'])) {
        try {
            $wizStmt = aiserve_db()->prepare(
                'SELECT
                   (SELECT COUNT(*) FROM message_templates WHERE company_id = ?)
                   + (SELECT COUNT(*) FROM auto_replies WHERE company_id = ?)
                    AS total'
            );
            $wizStmt->execute([(int)$current_user['company_id'], (int)$current_user['company_id']]);
            $showWizardBadge = ((int)$wizStmt->fetchColumn()) === 0;
        } catch (Throwable $e) { /* migration missing - skip badge silently */ }
    }
  ?>
  <nav class="sidebar-nav">
    <a href="/dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="/inbox/index.php" class="<?= $active === 'inbox' ? 'active' : '' ?>">Inbox</a>
    <a href="/contacts.php" class="<?= $active === 'contacts' ? 'active' : '' ?>">Contacts</a>
    <?php if (in_array($role, ['super_admin', 'manager'], true) && $showWizardBadge): ?>
      <a href="/admin/setup_wizard.php" class="<?= $active === 'setup_wizard' ? 'active' : '' ?>"
         style="background: linear-gradient(135deg, #e7faee 0%, transparent 100%); font-weight: 600;">
        🚀 Quick setup <small style="background:#25D366;color:#fff;padding:1px 6px;border-radius:999px;font-size:10px;margin-left:4px;">NEW</small>
      </a>
    <?php elseif (in_array($role, ['super_admin', 'manager'], true)): ?>
      <a href="/admin/setup_wizard.php" class="<?= $active === 'setup_wizard' ? 'active' : '' ?>">🚀 Quick setup</a>
    <?php endif; ?>
    <a href="/admin/templates.php" class="<?= $active === 'templates' ? 'active' : '' ?>">Templates</a>
    <?php if (in_array($role, ['super_admin', 'manager'], true)): ?>
      <a href="/admin/broadcasts.php" class="<?= $active === 'broadcasts' ? 'active' : '' ?>">Broadcasts</a>
      <a href="/admin/auto_replies.php" class="<?= $active === 'auto_replies' ? 'active' : '' ?>">Auto replies</a>
      <a href="/admin/tags.php" class="<?= $active === 'tags' ? 'active' : '' ?>">Tags</a>
      <a href="/admin/reports.php" class="<?= $active === 'reports' ? 'active' : '' ?>">Reports</a>
      <a href="/admin/topics.php" class="<?= $active === 'topics' ? 'active' : '' ?>">Topics analytics</a>
      <a href="/admin/knowledge.php" class="<?= $active === 'knowledge' ? 'active' : '' ?>">Knowledge base</a>
    <?php endif; ?>
    <?php if ($role === 'super_admin'): ?>
      <div class="sidebar-section">Administration</div>
      <a href="/admin/users.php" class="<?= $active === 'users' ? 'active' : '' ?>">Users</a>
      <a href="/admin/departments.php" class="<?= $active === 'departments' ? 'active' : '' ?>">Departments</a>
      <a href="/admin/branches.php" class="<?= $active === 'branches' ? 'active' : '' ?>">Branches</a>
      <a href="/admin/routing.php" class="<?= $active === 'routing' ? 'active' : '' ?>">Routing rules</a>
      <a href="/admin/flows.php" class="<?= $active === 'flows' ? 'active' : '' ?>">Message flows</a>
      <a href="/admin/channels.php" class="<?= $active === 'channels' ? 'active' : '' ?>">Channels</a>
      <a href="/admin/webchat.php"  class="<?= $active === 'webchat'  ? 'active' : '' ?>">💬 Web chat widget</a>
      <a href="/admin/settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">Settings</a>
      <a href="/admin/ai_settings.php" class="<?= $active === 'ai_settings' ? 'active' : '' ?>">AI Settings</a>
      <a href="/admin/ai_usage.php"    class="<?= $active === 'ai_usage'    ? 'active' : '' ?>">💰 AI usage</a>
      <?php if (fnb_module_active((int)($current_user['company_id'] ?? 0))): ?>
        <div class="sidebar-section" style="margin-top: 12px;">F&amp;B module</div>
        <a href="/admin/fnb_orders.php"    class="<?= $active === 'fnb_orders'    ? 'active' : '' ?>">📋 Orders</a>
        <a href="/admin/fnb_menu.php"      class="<?= $active === 'fnb_menu'      ? 'active' : '' ?>">🍜 Menu</a>
        <a href="/admin/fnb_analytics.php" class="<?= $active === 'fnb_analytics' ? 'active' : '' ?>">📊 Analytics</a>
      <?php endif; ?>
      <?php if (is_platform_admin()): ?>
        <a href="/admin/evolution_connect.php" class="<?= $active === 'evolution_connect' ? 'active' : '' ?>">Connect WhatsApp</a>
        <a href="/admin/webhook_log.php" class="<?= $active === 'webhook_log' ? 'active' : '' ?>">Webhook log</a>
        <a href="/admin/connection_debug.php" class="<?= $active === 'connection_debug' ? 'active' : '' ?>">Connection debug</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (is_platform_admin() && !is_impersonating()): ?>
      <div class="sidebar-section">Platform</div>
      <a href="/admin/workspaces.php" class="<?= $active === 'workspaces' ? 'active' : '' ?>">Workspaces</a>
      <a href="/admin/ai_billing.php" class="<?= $active === 'ai_billing' ? 'active' : '' ?>">💰 AI billing</a>
      <a href="/admin/pricing.php" class="<?= $active === 'pricing' ? 'active' : '' ?>">Pricing</a>
      <a href="/admin/legal.php" class="<?= $active === 'legal' ? 'active' : '' ?>">Legal &amp; operator</a>
      <a href="/admin/branding.php" class="<?= $active === 'branding' ? 'active' : '' ?>">Branding &amp; icon</a>
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
