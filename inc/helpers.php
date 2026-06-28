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

// -------------------- PWA head tags --------------------
/**
 * Emit the standard PWA / iOS web-app meta tags. Drop into every <head>
 * so every entry page (landing, login, register, app shell, legal pages)
 * advertises the same install metadata to browsers.
 */
function pwa_head_tags(): string
{
    $h  = '<link rel="manifest" href="/manifest.json">' . "\n";
    $h .= '  <meta name="theme-color" content="#25D366">' . "\n";
    $h .= '  <meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    $h .= '  <meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    $h .= '  <meta name="apple-mobile-web-app-title" content="AiServe">' . "\n";
    $h .= '  <link rel="apple-touch-icon" href="/assets/img/icon.php?size=180">' . "\n";
    $h .= '  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/icon.php?size=32">';
    return $h;
}

// -------------------- Asset cache-busting --------------------
function asset_url(string $relPath): string
{
    try {
        $abs = __DIR__ . '/..' . $relPath;
        if (is_file($abs)) {
            $v = @filemtime($abs);
            if ($v) {
                return $relPath . '?v=' . $v;
            }
        }
    } catch (Throwable $e) {
        // Open_basedir restriction or weird host - fall through to bare URL.
    }
    return $relPath;
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
        'super_admin' => 'Workspace Admin',
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
        // Broadcast statuses
        'draft'     => 'badge-default',
        'running'   => 'badge-open',
        'paused'    => 'badge-pending',
        'done'      => 'badge-closed',
        'cancelled' => 'badge-failed',
        'queued'    => 'badge-pending',
        'skipped'   => 'badge-default',
    ];
    $cls = $map[$status] ?? 'badge-default';
    return '<span class="badge ' . e($cls) . '">' . e(ucfirst($status)) . '</span>';
}

// -------------------- Multi-tenant helpers --------------------
function plan_seat_limit(string $plan): int
{
    $p = pricing_get();
    return match ($plan) {
        'starter'    => (int)$p['starter_seats'],
        'growth'     => (int)$p['bundle_seats'],
        'enterprise' => 9999,
        default      => (int)$p['starter_seats'],
    };
}

/**
 * Per-process cache of /admin/pricing.php settings. All public pricing pages
 * read through this so editing settings is reflected immediately on the next
 * request. Falls back to the original RM 12 / RM 60 / RM 12-extra defaults
 * if the platform_settings table is missing or rows are deleted.
 *
 * Computed fields:
 *  starter_price = per_seat × starter_seats
 *  growth_price  = bundle_price
 *  effective_per_seat_growth = bundle_price / bundle_seats
 *
 * @return array{currency:string, period_label:string, per_seat:float,
 *               starter_seats:int, starter_price:float,
 *               bundle_seats:int, bundle_price:float,
 *               extra_seat_price:float, payment_methods:string,
 *               footer_note:string, effective_per_seat_growth:float}
 */
function pricing_get(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'pricing_currency'         => 'RM',
        'pricing_period_label'     => '/ month',
        'pricing_per_seat'         => '12',
        'pricing_starter_seats'    => '3',
        'pricing_bundle_seats'     => '10',
        'pricing_bundle_price'     => '60',
        'pricing_extra_seat_price' => '12',
        'pricing_payment_methods'  => 'Bank transfer (Malaysia), DuitNow, or e-wallet. Talk to us if you need annual billing for a discount.',
        'pricing_footer_note'      => 'You can change plans any time. We prorate the difference for the current month.',
    ];

    try {
        $rows = aiserve_db()->query('SELECT `key`, `value` FROM platform_settings')->fetchAll();
        foreach ($rows as $r) {
            if (array_key_exists($r['key'], $defaults)) {
                $defaults[$r['key']] = (string)$r['value'];
            }
        }
    } catch (Throwable $e) {
        // Table doesn't exist yet (migration not run) - silently use defaults.
        error_log('[AiServe] pricing_get fell back to defaults: ' . $e->getMessage());
    }

    $perSeat       = max(0.0, (float)$defaults['pricing_per_seat']);
    $starterSeats  = max(1,   (int)$defaults['pricing_starter_seats']);
    $bundleSeats   = max(1,   (int)$defaults['pricing_bundle_seats']);
    $bundlePrice   = max(0.0, (float)$defaults['pricing_bundle_price']);
    $extraSeat     = max(0.0, (float)$defaults['pricing_extra_seat_price']);

    $cache = [
        'currency'                 => (string)$defaults['pricing_currency'],
        'period_label'             => (string)$defaults['pricing_period_label'],
        'per_seat'                 => $perSeat,
        'starter_seats'            => $starterSeats,
        'starter_price'            => $perSeat * $starterSeats,
        'bundle_seats'             => $bundleSeats,
        'bundle_price'             => $bundlePrice,
        'extra_seat_price'         => $extraSeat,
        'payment_methods'          => (string)$defaults['pricing_payment_methods'],
        'footer_note'              => (string)$defaults['pricing_footer_note'],
        'effective_per_seat_growth'=> $bundleSeats > 0 ? $bundlePrice / $bundleSeats : 0.0,
    ];
    return $cache;
}

/**
 * Operator legal info (the entity behind this Service) for use in the legal
 * page footers, the Terms governing-law clause, and the Privacy contact
 * section. Editable via /admin/pricing.php.
 *
 * Returns empty strings when nothing is configured - callers should treat
 * missing values as "fall back to APP_NAME / generic copy".
 *
 * @return array{legal_name:string, registration_no:string, address:string,
 *               email:string, jurisdiction:string, courts:string}
 */
function operator_legal_info(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'operator_legal_name'      => '',
        'operator_registration_no' => '',
        'operator_address'         => '',
        'operator_email'           => '',
        'operator_jurisdiction'    => 'Malaysia',
        'operator_courts'          => 'the courts of Kuala Lumpur, Malaysia',
    ];
    try {
        $rows = aiserve_db()->query('SELECT `key`, `value` FROM platform_settings')->fetchAll();
        foreach ($rows as $r) {
            if (array_key_exists($r['key'], $defaults)) {
                $defaults[$r['key']] = (string)$r['value'];
            }
        }
    } catch (Throwable $e) {
        // Table missing - fine, use defaults.
    }
    $cache = [
        'legal_name'      => $defaults['operator_legal_name'],
        'registration_no' => $defaults['operator_registration_no'],
        'address'         => $defaults['operator_address'],
        'email'           => $defaults['operator_email'],
        'jurisdiction'    => $defaults['operator_jurisdiction'] ?: 'Malaysia',
        'courts'          => $defaults['operator_courts'] ?: 'the courts of Kuala Lumpur, Malaysia',
    ];
    return $cache;
}

/**
 * Single platform_settings string by key, with a default. Used by the legal
 * pages to look up dates and small editable copy fragments.
 */
function platform_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = aiserve_db()->query('SELECT `key`, `value` FROM platform_settings')->fetchAll();
            foreach ($rows as $r) $cache[$r['key']] = (string)$r['value'];
        } catch (Throwable $e) {
            // Table missing - leave the cache empty so every key falls back.
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Format an amount + currency for display. "12" -> "RM 12", "59.5" -> "RM 59.50".
 */
function fmt_price(float $amount, ?string $currency = null): string
{
    $currency = $currency ?? pricing_get()['currency'];
    // Drop trailing .00 so whole numbers stay clean (RM 12, not RM 12.00).
    $rounded = round($amount, 2);
    if (abs($rounded - round($rounded)) < 0.005) {
        return $currency . ' ' . number_format($rounded, 0);
    }
    return $currency . ' ' . number_format($rounded, 2);
}

function company_user_count(int $companyId): int
{
    $stmt = aiserve_db()->prepare(
        'SELECT COUNT(*) FROM users WHERE company_id = ? AND status = "active"'
    );
    $stmt->execute([$companyId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Resolve which company a webhook request is for.
 *  - Prefer the `?company=<slug>` query param (set per-tenant in the webhook URL).
 *  - Fall back to the legacy ACTIVE_COMPANY_ID for backward compatibility with
 *    pre-multi-tenant deployments (single-tenant installs).
 */
function resolve_company_for_webhook(): ?array
{
    $slug = trim((string)($_GET['company'] ?? ''));
    if ($slug !== '') {
        $stmt = aiserve_db()->prepare('SELECT * FROM companies WHERE slug = ? AND status = "active" LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row) return $row;
        // Slug provided but not found -> hard fail so we don't silently
        // write into the wrong tenant.
        return null;
    }
    $stmt = aiserve_db()->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $stmt->execute([(int)ACTIVE_COMPANY_ID]);
    return $stmt->fetch() ?: null;
}

/**
 * Build a webhook URL that includes the tenant slug + verify token.
 */
function webhook_url_for(array $company, string $endpoint): string
{
    $host = APP_BASE_URL ?: ((!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
    $qs = ['company' => (string)($company['slug'] ?? '')];
    if (!empty($company['webhook_verify_token'])) {
        $qs['token'] = (string)$company['webhook_verify_token'];
    }
    return $host . $endpoint . '?' . http_build_query($qs);
}

function slugify(string $raw): string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return substr($s ?: 'company', 0, 64);
}
