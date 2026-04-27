<?php
/**
 * Authentication & RBAC helpers.
 * All portal pages must include this file and call require_login().
 */

require_once __DIR__ . '/helpers.php';

function current_user(): ?array
{
    aiserve_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cached = null;
    if ($cached && (int)$cached['id'] === (int)$_SESSION['user_id']) {
        return $cached;
    }
    $stmt = aiserve_db()->prepare(
        'SELECT u.*, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = ? AND u.status = "active" LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    if (!$cached) {
        // user disabled / deleted mid-session
        $_SESSION = [];
    }
    return $cached;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login.php?next=' . urlencode($next));
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

function user_can_view_conversation(array $user, array $conversation): bool
{
    if (in_array($user['role'], ['super_admin', 'manager'], true)) {
        return (int)$user['company_id'] === (int)$conversation['company_id'];
    }
    // Agent: assigned to them, or unassigned in their department, or unassigned with no dept.
    if ((int)$conversation['company_id'] !== (int)$user['company_id']) {
        return false;
    }
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

function login_user(array $user): void
{
    aiserve_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['company_id'] = (int)$user['company_id'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['name']       = $user['name'];

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
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
