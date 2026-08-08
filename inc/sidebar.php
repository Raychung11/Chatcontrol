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

// Fetch this user's current availability (phase 41) + whether they've
// set a PIN (phase 43). Falls back silently on pre-migration DBs.
$sb_availability = 'available';
$sb_hasPin       = false;
try {
    $sa = aiserve_db()->prepare('SELECT availability, pin_hash FROM users WHERE id = ? LIMIT 1');
    $sa->execute([(int)($current_user['id'] ?? 0)]);
    if ($row = $sa->fetch()) {
        $sb_availability = (string)($row['availability'] ?? 'available');
        $sb_hasPin       = !empty($row['pin_hash']);
    }
} catch (Throwable $e) {
    // Fall back to availability-only if pin_hash column doesn't exist yet.
    try {
        $sa = aiserve_db()->prepare('SELECT availability FROM users WHERE id = ? LIMIT 1');
        $sa->execute([(int)($current_user['id'] ?? 0)]);
        $sb_availability = (string)($sa->fetchColumn() ?: 'available');
    } catch (Throwable $e2) { /* both missing = defaults */ }
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
    'settings'  => ['settings', 'ai_settings', 'plan', 'my_invoices'],
    'fnb'       => ['fnb_orders', 'fnb_menu', 'fnb_analytics'],
    'platform'  => ['workspaces', 'channels_debug', 'ai_billing', 'invoices',
                    'mail_settings', 'mail_test', 'business_info', 'broadcast_pricing',
                    'pricing', 'legal', 'branding',
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

  <!-- ============ Search + collapse-all toolbar ============ -->
  <div class="sidebar-toolbar">
    <div class="sidebar-search">
      <span class="sidebar-search-icon">🔎</span>
      <input type="search" id="sidebar-search" placeholder="Search…"
             autocomplete="off" spellcheck="false">
      <button type="button" id="sidebar-search-clear" title="Clear search"
              aria-label="Clear search">×</button>
    </div>
    <button type="button" id="sidebar-toggle-all" title="Collapse / expand all groups">
      <span data-when="all-open">⇕</span>
    </button>
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
        <a href="/admin/plan.php"        class="<?= $active === 'plan'        ? 'active' : '' ?>">💳 Plan &amp; billing</a>
        <a href="/admin/my_invoices.php" class="<?= $active === 'my_invoices' ? 'active' : '' ?>">📄 My invoices</a>
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
        <a href="/admin/invoices.php"        class="<?= $active === 'invoices'        ? 'active' : '' ?>">💳 Invoices</a>
        <a href="/admin/mail_settings.php"     class="<?= $active === 'mail_settings'     ? 'active' : '' ?>">✉️ Mail settings</a>
        <a href="/admin/mail_test.php"         class="<?= $active === 'mail_test'         ? 'active' : '' ?>">🧪 Mail test</a>
        <a href="/admin/business_info.php"     class="<?= $active === 'business_info'     ? 'active' : '' ?>">🏢 Business info</a>
        <a href="/admin/broadcast_pricing.php" class="<?= $active === 'broadcast_pricing' ? 'active' : '' ?>">📣 Broadcast pricing</a>
        <a href="/admin/pricing.php"           class="<?= $active === 'pricing'           ? 'active' : '' ?>">Seat pricing</a>
        <a href="/admin/legal.php"             class="<?= $active === 'legal'             ? 'active' : '' ?>">Legal text (T&amp;C)</a>
        <a href="/admin/branding.php"          class="<?= $active === 'branding'          ? 'active' : '' ?>">Branding &amp; icon</a>
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

    <!-- Availability toggle — used by branch rotation to skip busy/away
         users when picking the next assignee. Purely self-serve. -->
    <a href="/admin/set_pin.php" class="pin-link" title="Set a 6-digit PIN for quick unlock on this device">
      🔒 <?= $sb_hasPin ?? false ? 'Change PIN' : 'Set PIN' ?>
    </a>

    <div class="avail-toggle" id="avail-toggle" data-current="<?= e($sb_availability) ?>">
      <span class="avail-label muted small">Status</span>
      <button type="button" data-value="available"
              class="avail-btn <?= $sb_availability === 'available' ? 'on' : '' ?>"
              title="Available for new leads">🟢</button>
      <button type="button" data-value="busy"
              class="avail-btn <?= $sb_availability === 'busy' ? 'on' : '' ?>"
              title="Busy — only get a lead if nobody free">🟡</button>
      <button type="button" data-value="away"
              class="avail-btn <?= $sb_availability === 'away' ? 'on' : '' ?>"
              title="Away — skipped by rotation">⚫</button>
    </div>

    <a class="btn-logout" href="/logout.php">Logout</a>
  </div>
</aside>

<script>
// Self-serve availability toggle in the sidebar footer.
(function () {
    var box = document.getElementById('avail-toggle');
    if (!box) return;
    box.querySelectorAll('.avail-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.dataset.value;
            var fd  = new FormData();
            fd.append('availability', val);
            // CSRF is shared per session — read from any csrf_field meta on the page.
            var t = document.querySelector('meta[name="csrf-token"]');
            if (t) fd.append('_csrf', t.getAttribute('content'));
            fetch('/api/set_availability.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        box.querySelectorAll('.avail-btn').forEach(function (b) {
                            b.classList.toggle('on', b.dataset.value === val);
                        });
                        box.dataset.current = val;
                    }
                });
        });
    });
})();
</script>

<script>
(function () {
    var nav = document.getElementById('sidebar-nav');
    if (!nav) return;
    var groups   = nav.querySelectorAll('details.nav-group');
    var allLinks = nav.querySelectorAll('a');
    var input    = document.getElementById('sidebar-search');
    var clearBtn = document.getElementById('sidebar-search-clear');
    var toggleAll= document.getElementById('sidebar-toggle-all');

    // ----- Per-group open/closed state -----
    // Server force-opens the group containing the current active link;
    // we never override that on load. Every other group restores from
    // localStorage so user preferences survive across pages.
    groups.forEach(function (g) {
        var key = 'aiserve_nav_' + g.dataset.group;
        if (!g.hasAttribute('open')) {
            try {
                if (localStorage.getItem(key) === '1') g.setAttribute('open', '');
            } catch (e) {}
        }
        g.addEventListener('toggle', function () {
            // Don't persist state changes made by the search auto-open —
            // those are ephemeral. `data-search-forced` marks groups
            // whose open state was changed by the search input, so we
            // skip localStorage writes for them.
            if (g.dataset.searchForced === '1') return;
            try { localStorage.setItem(key, g.open ? '1' : '0'); } catch (e) {}
        });
    });

    // ----- Collapse / expand all button -----
    // Middle state (mixed): default label ⇕. All open → clicking closes
    // everything. All closed → clicking opens everything. Anything else
    // → clicking opens everything (moves toward more info).
    function updateToggleLabel() {
        var openCount = 0;
        groups.forEach(function (g) { if (g.open) openCount++; });
        var lbl = toggleAll.querySelector('span');
        if (openCount === 0)                   lbl.textContent = '▾'; // expand
        else if (openCount === groups.length)  lbl.textContent = '▴'; // collapse
        else                                    lbl.textContent = '⇕'; // mixed
    }
    toggleAll.addEventListener('click', function () {
        var openCount = 0;
        groups.forEach(function (g) { if (g.open) openCount++; });
        var open = openCount < groups.length;   // if not all open, open all
        groups.forEach(function (g) {
            if (open) g.setAttribute('open', ''); else g.removeAttribute('open');
        });
        updateToggleLabel();
    });
    updateToggleLabel();

    // ----- Live search -----
    // Filters visible links by substring (case-insensitive) on their
    // text content. When any child of a group matches, force-open the
    // group. Blank query = restore original state.
    function applySearch(q) {
        q = (q || '').trim().toLowerCase();
        if (q === '') {
            // Clear filter — restore every link + close any group we
            // force-opened during search, respecting the group's stored
            // preference again.
            allLinks.forEach(function (a) { a.style.display = ''; });
            groups.forEach(function (g) {
                if (g.dataset.searchForced === '1') {
                    var key = 'aiserve_nav_' + g.dataset.group;
                    var savedOpen = false;
                    try { savedOpen = localStorage.getItem(key) === '1'; } catch (e) {}
                    // Keep server-forced (contains active) groups open regardless.
                    var hasActive = !!g.querySelector('a.active');
                    if (savedOpen || hasActive) g.setAttribute('open', '');
                    else                        g.removeAttribute('open');
                    g.removeAttribute('data-search-forced');
                }
            });
            clearBtn.style.display = 'none';
            updateToggleLabel();
            return;
        }
        clearBtn.style.display = '';
        // Filter every link + track which groups had a match.
        var matchedGroups = new Set();
        allLinks.forEach(function (a) {
            var txt = a.textContent.toLowerCase();
            var hit = txt.indexOf(q) !== -1;
            a.style.display = hit ? '' : 'none';
            if (hit) {
                var g = a.closest('details.nav-group');
                if (g) matchedGroups.add(g);
            }
        });
        // Auto-open any group that had a match, close the rest so the
        // hit list is compact. Mark as search-forced so we don't leak
        // this state into localStorage.
        groups.forEach(function (g) {
            if (matchedGroups.has(g)) {
                if (!g.open) g.dataset.searchForced = '1';
                g.setAttribute('open', '');
            } else {
                // If no match AND no matching top-level link, no reason
                // to render this group open — hide it entirely (not just
                // collapsed) so the sidebar reads as pure results.
                g.style.display = '';    // group summary stays visible
                if (g.open) g.dataset.searchForced = '1';
                g.removeAttribute('open');
            }
        });
        updateToggleLabel();
    }
    input.addEventListener('input',  function () { applySearch(input.value); });
    clearBtn.addEventListener('click', function () {
        input.value = ''; applySearch(''); input.focus();
    });
    // Esc clears the search.
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { input.value = ''; applySearch(''); }
    });

    // Hide clear button on first paint.
    clearBtn.style.display = 'none';
})();
</script>
