<?php
/**
 * Shared sidebar navigation.
 * Expects $current_user to be available, and optionally $active_nav for highlighting.
 */
$active = $active_nav ?? '';
$role   = $current_user['role'] ?? 'agent';
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="brand-dot" style="background: <?= e($brand_color ?? '#25D366') ?>"></span>
    <span class="brand-text">AiServe Inbox</span>
  </div>

  <nav class="sidebar-nav">
    <a href="/dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="/inbox/index.php" class="<?= $active === 'inbox' ? 'active' : '' ?>">Inbox</a>
    <a href="/contacts.php" class="<?= $active === 'contacts' ? 'active' : '' ?>">Contacts</a>
    <a href="/admin/templates.php" class="<?= $active === 'templates' ? 'active' : '' ?>">Templates</a>
    <?php if (in_array($role, ['super_admin', 'manager'], true)): ?>
      <a href="/admin/tags.php" class="<?= $active === 'tags' ? 'active' : '' ?>">Tags</a>
      <a href="/admin/reports.php" class="<?= $active === 'reports' ? 'active' : '' ?>">Reports</a>
    <?php endif; ?>
    <?php if ($role === 'super_admin'): ?>
      <div class="sidebar-section">Administration</div>
      <a href="/admin/users.php" class="<?= $active === 'users' ? 'active' : '' ?>">Users</a>
      <a href="/admin/departments.php" class="<?= $active === 'departments' ? 'active' : '' ?>">Departments</a>
      <a href="/admin/routing.php" class="<?= $active === 'routing' ? 'active' : '' ?>">Routing rules</a>
      <a href="/admin/settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">Settings</a>
      <a href="/admin/evolution_connect.php" class="<?= $active === 'evolution_connect' ? 'active' : '' ?>">Connect WhatsApp</a>
      <a href="/admin/webhook_log.php" class="<?= $active === 'webhook_log' ? 'active' : '' ?>">Webhook log</a>
      <a href="/admin/ai_settings.php" class="<?= $active === 'ai_settings' ? 'active' : '' ?>">AI Settings</a>
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
