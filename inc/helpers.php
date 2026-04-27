<?php
/**
 * Shared helpers: escaping, CSRF, redirects, request inspection,
 * activity logging, datetime formatting, JSON responses.
 */

require_once __DIR__ . '/../config/db_config.php';

// -------------------- Output escaping --------------------
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// -------------------- Session bootstrap --------------------
function aiserve_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(APP_SESSION_NAME);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// -------------------- CSRF --------------------
function csrf_token(): string
{
    aiserve_start_session();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    aiserve_start_session();
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid CSRF token. Please refresh and try again.');
    }
}

// -------------------- Redirects --------------------
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

// -------------------- Request --------------------
function client_ip(): string
{
    $h = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($h, ',')) {
        $h = trim(explode(',', $h)[0]);
    }
    return substr($h, 0, 64);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// -------------------- JSON --------------------
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// -------------------- Activity log --------------------
function log_activity(int $companyId, ?int $userId, string $actionType, ?string $targetType = null, ?int $targetId = null, ?string $description = null): void
{
    try {
        $stmt = aiserve_db()->prepare(
            'INSERT INTO activity_logs (company_id, user_id, action_type, target_type, target_id, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $userId, $actionType, $targetType, $targetId, $description]);
    } catch (Throwable $e) {
        error_log('[AiServe] log_activity failed: ' . $e->getMessage());
    }
}

// -------------------- Datetime --------------------
function fmt_dt(?string $datetime, string $fmt = 'Y-m-d H:i'): string
{
    if (!$datetime) {
        return '';
    }
    try {
        $d = new DateTime($datetime, new DateTimeZone(APP_TIMEZONE));
        return $d->format($fmt);
    } catch (Throwable $e) {
        return $datetime;
    }
}

function relative_time(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60)        return $diff . 's';
    if ($diff < 3600)      return floor($diff / 60) . 'm';
    if ($diff < 86400)     return floor($diff / 3600) . 'h';
    if ($diff < 86400 * 7) return floor($diff / 86400) . 'd';
    return date('M j', $ts);
}

// -------------------- Service window --------------------
function is_within_service_window(?string $expiresAt): bool
{
    if (!$expiresAt) {
        return false;
    }
    return strtotime($expiresAt) > time();
}

// -------------------- Misc --------------------
function normalize_phone(string $raw): string
{
    return preg_replace('/[^0-9]/', '', $raw) ?: '';
}

function role_label(string $role): string
{
    return match ($role) {
        'super_admin' => 'Super Admin',
        'manager'     => 'Manager',
        'agent'       => 'Agent',
        default       => ucfirst($role),
    };
}

/**
 * WhatsApp-style ticks for an outgoing message.
 * pending  -> clock icon
 * sent     -> single tick
 * delivered-> double tick
 * read     -> double tick (blue, styled in CSS via class)
 * failed   -> exclamation
 */
function delivery_ticks(string $status): string
{
    $svg = match ($status) {
        'pending'   => '<svg class="tick-icon tick-pending" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" d="M8 4v4l2.5 1.5"/><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
        'sent'      => '<svg class="tick-icon tick-sent"      viewBox="0 0 18 14" width="16" height="12" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 8 L7 13 L17 2"/></svg>',
        'delivered' => '<svg class="tick-icon tick-delivered" viewBox="0 0 22 14" width="20" height="12" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 8 L6 12 L14 3 M9 12 L20 1"/></svg>',
        'read'      => '<svg class="tick-icon tick-read"      viewBox="0 0 22 14" width="20" height="12" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 8 L6 12 L14 3 M9 12 L20 1"/></svg>',
        'failed'    => '<svg class="tick-icon tick-failed"    viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M8 4v5 M8 11.5v.5"/></svg>',
        default     => '',
    };
    return $svg;
}

function status_badge(string $status): string
{
    $map = [
        'open'      => 'badge-open',
        'pending'   => 'badge-pending',
        'closed'    => 'badge-closed',
        'escalated' => 'badge-escalated',
        'active'    => 'badge-open',
        'inactive'  => 'badge-closed',
        'sent'      => 'badge-sent',
        'delivered' => 'badge-sent',
        'read'      => 'badge-sent',
        'received'  => 'badge-sent',
        'failed'    => 'badge-failed',
    ];
    $cls = $map[$status] ?? 'badge-default';
    return '<span class="badge ' . e($cls) . '">' . e(ucfirst($status)) . '</span>';
}
