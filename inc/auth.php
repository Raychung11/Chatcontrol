<?php
/**
 * Authentication & RBAC helpers.
 * All portal pages must include this file and call require_login().
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/remember_me.php';
require_once __DIR__ . '/pin.php';

function current_user(): ?array
{
    aiserve_start_session();
    if (empty($_SESSION['user_id'])) {
        // No live session — try the remember-me cookie. If it resolves,
        // populate the session as if the user had just logged in and
        // fall through to the normal user-load below.
        $rememberedId = remember_me_try();
        if ($rememberedId) {
            $_SESSION['user_id'] = $rememberedId;
        } else {
            return null;
        }
    }
    static $cached = null;
    $sessionTag = (int)$_SESSION['user_id'] . ':' . (int)($_SESSION['_impersonate_company_id'] ?? 0);
    if ($cached && ($cached['_tag'] ?? '') === $sessionTag) {
        return $cached;
    }

    $stmt = aiserve_db()->prepare(
        'SELECT u.*, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = ? AND u.status = "active" LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if (!$user) {
        // user disabled / deleted mid-session
        $_SESSION = [];
        $cached = null;
        return null;
    }

    // Impersonation: swap company_id + role so every existing data-scope
    // and role-check works as if logged in as a super admin of the target.
    if (!empty($_SESSION['_impersonate_company_id'])) {
        $targetId = (int)$_SESSION['_impersonate_company_id'];
        $cStmt = aiserve_db()->prepare('SELECT id, name, slug, plan FROM companies WHERE id = ? AND status = "active" LIMIT 1');
        $cStmt->execute([$targetId]);
        $target = $cStmt->fetch();
        if ($target && !empty($user['is_platform_admin'])) {
            $user['_real_user_id']     = (int)$user['id'];
            $user['_real_company_id']  = (int)$user['company_id'];
            $user['_real_role']        = (string)$user['role'];
            $user['_real_name']        = (string)$user['name'];
            $user['_impersonating']    = true;
            $user['_impersonated_company'] = $target;
            $user['company_id']        = (int)$target['id'];
            $user['role']              = 'super_admin';
        } else {
            // Stale or unauthorized impersonation - drop it.
            unset($_SESSION['_impersonate_company_id']);
        }
    }

    $user['_tag'] = $sessionTag;
    $cached = $user;
    return $cached;
}

function is_platform_admin(): bool
{
    $u = current_user();
    if (!$u) return false;
    // While impersonating, the real role is in _real_role. The is_platform_admin
    // flag stays on the real user row.
    return !empty($u['_real_user_id'])
        ? (bool)(aiserve_db()->query('SELECT is_platform_admin FROM users WHERE id = ' . (int)$u['_real_user_id'])->fetchColumn())
        : (bool)($u['is_platform_admin'] ?? 0);
}

function is_impersonating(): bool
{
    aiserve_start_session();
    return !empty($_SESSION['_impersonate_company_id']);
}

function start_impersonation(int $targetCompanyId): bool
{
    $u = current_user();
    if (!$u || empty($u['is_platform_admin']) || is_impersonating()) {
        return false;
    }
    aiserve_start_session();
    $_SESSION['_impersonate_company_id'] = $targetCompanyId;
    // Rotate the SID whenever effective privileges change - matches the
    // behaviour of login_user() and stops SID fixation across a role swap.
    session_regenerate_id(true);
    log_activity($targetCompanyId, (int)$u['id'], 'impersonation_start', 'company', $targetCompanyId,
        'Platform admin ' . $u['email'] . ' impersonating workspace ' . $targetCompanyId);
    return true;
}

function stop_impersonation(): void
{
    aiserve_start_session();
    if (!empty($_SESSION['_impersonate_company_id'])) {
        $cid = (int)$_SESSION['_impersonate_company_id'];
        $uid = (int)($_SESSION['user_id'] ?? 0);
        log_activity($cid, $uid, 'impersonation_stop', 'company', $cid,
            'Platform admin ended impersonation');
    }
    unset($_SESSION['_impersonate_company_id']);
    // Same rotation on the way back to the real identity.
    session_regenerate_id(true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login.php?next=' . urlencode($next));
    }
    // PIN gate — if the user has a PIN set and hasn't already unlocked
    // this session (i.e. they arrived via remember-me auto-login), send
    // them through /pin.php first. /pin.php itself sets the verified
    // flag and redirects back to $next. We skip the gate on /pin.php
    // and its POST endpoint to avoid a loop.
    $reqPath = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $isPinRoute = in_array($reqPath, ['/pin.php', '/api/pin_verify.php'], true);
    if (!$isPinRoute && !empty($u['pin_hash']) && !pin_is_verified()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/dashboard.php';
        redirect('/pin.php?next=' . urlencode($next));
    }
    return $u;
}

function require_role(array $roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden: insufficient role.');
    }
    return $u;
}

/**
 * Return the channel IDs an agent is restricted to, or null if unrestricted.
 *
 * - Super admin / Manager always return null (they see everything). The
 *   restriction is an agent-level control.
 * - Agent with no user_channels rows returns null (unrestricted — matches
 *   pre-phase-26 behavior).
 * - Agent with one or more rows returns the array of channel_ids.
 *
 * Cached per request via a static so repeated calls in the same page
 * don't hit the DB again.
 */
function user_visible_channel_ids(array $user): ?array
{
    if (in_array($user['role'] ?? 'agent', ['super_admin', 'manager'], true)) {
        return null;
    }
    static $cache = [];
    $uid = (int)$user['id'];
    if (array_key_exists($uid, $cache)) return $cache[$uid];

    try {
        $stmt = aiserve_db()->prepare(
            'SELECT channel_id FROM user_channels WHERE user_id = ?'
        );
        $stmt->execute([$uid]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'channel_id'));
    } catch (Throwable $e) {
        // Table not yet migrated - fall back to unrestricted so we don't
        // 500 the whole inbox on a partially-deployed workspace.
        error_log('[AiServe user_visible_channel_ids] ' . $e->getMessage());
        return $cache[$uid] = null;
    }
    return $cache[$uid] = ($ids ?: null);
}

/**
 * Return the branch IDs a user is restricted to (phase 30), or null if
 * unrestricted. Different from channels:
 *   - Super admin: always null.
 *   - Manager or agent with entries in user_branches: restricted to
 *     conversations for contacts in those branches.
 *   - No entries: unrestricted.
 * For agents the same table (from phase 28) also serves as the rotation
 * pool; ticking a branch does double duty.
 */
function user_visible_branch_ids(array $user): ?array
{
    if (($user['role'] ?? 'agent') === 'super_admin') {
        return null;
    }
    static $cache = [];
    $uid = (int)$user['id'];
    if (array_key_exists($uid, $cache)) return $cache[$uid];

    try {
        $stmt = aiserve_db()->prepare(
            'SELECT branch_id FROM user_branches WHERE user_id = ?'
        );
        $stmt->execute([$uid]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'branch_id'));
    } catch (Throwable $e) {
        error_log('[AiServe user_visible_branch_ids] ' . $e->getMessage());
        return $cache[$uid] = null;
    }
    return $cache[$uid] = ($ids ?: null);
}

function user_can_view_conversation(array $user, array $conversation): bool
{
    // Workspace scope check applies to everyone including super admins.
    if ((int)$conversation['company_id'] !== (int)$user['company_id']) {
        return false;
    }
    // Channel-access restriction (phase 26) — applies to agents only.
    // Manager / super admin get null back and skip this branch.
    $allowedChannels = user_visible_channel_ids($user);
    if ($allowedChannels !== null) {
        $cid = (int)($conversation['channel_id'] ?? 0);
        if ($cid <= 0 || !in_array($cid, $allowedChannels, true)) {
            return false;
        }
    }
    // Branch-access restriction (phase 30) — applies to managers AND
    // agents. Super admins bypass. Conversation's contact must belong
    // to one of the user's allowed branches.
    $allowedBranches = user_visible_branch_ids($user);
    if ($allowedBranches !== null) {
        $convBranchId = (int)($conversation['contact_branch_id'] ?? 0);
        // Row wasn't loaded with the join — fall back to a lookup so
        // the guard still holds for callers that didn't prep the row.
        if ($convBranchId === 0 && !empty($conversation['contact_id'])) {
            $bs = aiserve_db()->prepare('SELECT branch_id FROM contacts WHERE id = ?');
            $bs->execute([(int)$conversation['contact_id']]);
            $convBranchId = (int)($bs->fetchColumn() ?: 0);
        }
        if ($convBranchId === 0 || !in_array($convBranchId, $allowedBranches, true)) {
            return false;
        }
    }

    if (in_array($user['role'], ['super_admin', 'manager'], true)) {
        return true;
    }
    // Agent: assigned to them, or unassigned in their department, or unassigned with no dept.
    if ((int)($conversation['assigned_user_id'] ?? 0) === (int)$user['id']) {
        return true;
    }
    if (empty($conversation['assigned_user_id'])) {
        $cd = (int)($conversation['department_id'] ?? 0);
        $ud = (int)($user['department_id'] ?? 0);
        return $cd === 0 || $cd === $ud;
    }
    return false;
}

function user_can_manage_users(array $user): bool
{
    return $user['role'] === 'super_admin';
}

function user_can_assign(array $user): bool
{
    return in_array($user['role'], ['super_admin', 'manager'], true);
}

function user_can_edit_settings(array $user): bool
{
    return $user['role'] === 'super_admin';
}

/**
 * Basic login throttling: max 8 failed attempts per email or IP in 15 minutes.
 */
function login_is_rate_limited(string $email, string $ip): bool
{
    $stmt = aiserve_db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE success = 0
           AND attempted_at > (NOW() - INTERVAL 15 MINUTE)
           AND (email = ? OR ip_address = ?)'
    );
    $stmt->execute([$email, $ip]);
    return (int)$stmt->fetchColumn() >= 8;
}

function login_record_attempt(string $email, string $ip, bool $success): void
{
    try {
        $stmt = aiserve_db()->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)'
        );
        $stmt->execute([$email, $ip, $success ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('[AiServe] login_record_attempt failed: ' . $e->getMessage());
    }
}

/**
 * Where should a just-authenticated user land?
 *
 * Rules (in order):
 *   1. If $next is provided and looks like a safe internal path
 *      (starts with "/" but not "//"), honor it — that's the
 *      "redirected here to sign in first" case, e.g. clicking a
 *      link to /admin/broadcasts.php while logged out.
 *   2. On mobile UAs (phone or tablet), default to /inbox/ so
 *      operators drop straight into their conversations. The
 *      dashboard's mostly a desktop / manager surface — on a phone
 *      the inbox is the thing they came for.
 *   3. Everyone else defaults to /dashboard.php.
 *
 * This is used by login.php, pin.php, biometric unlock, and the
 * /index.php "already signed in?" bounce, so the rule is defined
 * once and stays consistent across every auth entry point.
 */
function post_login_landing(?string $next = null): string
{
    // Honor a valid explicit next-URL, but only if it's not the
    // stale "/dashboard.php" placeholder that /login.php seeds by
    // default (that placeholder should NOT beat mobile routing).
    if (is_string($next) && $next !== '' && $next !== '/dashboard.php'
        && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
        return $next;
    }
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    // Same regex family used elsewhere in the codebase for mobile
    // detection (e.g. remember-me auto-check in login.php).
    if (preg_match('/(android|iphone|ipad|ipod|mobile|windows phone)/', $ua)) {
        return '/inbox/';
    }
    return '/dashboard.php';
}

function login_user(array $user): void
{
    aiserve_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['company_id'] = (int)$user['company_id'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['name']       = $user['name'];
    // Password login proves identity — no need to also enter PIN.
    pin_mark_verified();
    // Password success clears any PIN lockout too.
    try {
        aiserve_db()->prepare(
            'UPDATE users SET pin_failed_attempts = 0, pin_locked_until = NULL
             WHERE id = ? LIMIT 1'
        )->execute([(int)$user['id']]);
    } catch (Throwable $e) { /* ok */ }

    try {
        $stmt = aiserve_db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$user['id']]);
    } catch (Throwable $e) {
        error_log('[AiServe] update last_login failed: ' . $e->getMessage());
    }

    log_activity((int)$user['company_id'], (int)$user['id'], 'login', 'user', (int)$user['id'], 'User logged in');
}

function logout_user(): void
{
    aiserve_start_session();
    $uid = $_SESSION['user_id'] ?? null;
    $cid = $_SESSION['company_id'] ?? null;
    if ($uid && $cid) {
        log_activity((int)$cid, (int)$uid, 'logout', 'user', (int)$uid, 'User logged out');
    }
    // Kill the remember-me cookie + DB row too, so "Logout" truly
    // signs the user out — otherwise the next request would auto-log
    // them back in via the cookie.
    remember_me_revoke_current();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
