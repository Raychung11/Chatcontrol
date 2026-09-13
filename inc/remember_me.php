<?php
/**
 * Remember-me tokens: WhatsApp-style stay-signed-in-on-device.
 *
 * Flow:
 *   Login: user ticks "Stay signed in" → remember_me_issue() creates a
 *     random 32-byte token, stores sha256(token) in user_remember_tokens
 *     with 30-day expiry, sets an httponly + secure cookie with the raw
 *     value on the user's device.
 *
 *   Every request: current_user() (in auth.php) calls remember_me_try()
 *     when there's no active session. If the cookie value hashes to a
 *     valid, unexpired row, we log the user back in AND rotate the
 *     token (old row deleted, new one issued) so a stolen cookie
 *     works exactly once.
 *
 *   Logout: remember_me_revoke_current() deletes the DB row and
 *     clears the cookie.
 *
 *   Cleanup: expired rows are pruned by the nightly db-cleanup cron
 *     (added below in the aiserve-db-cleanup.sh update).
 */

require_once __DIR__ . '/helpers.php';

const REMEMBER_ME_COOKIE = 'aiserve_rm';
const REMEMBER_ME_TTL_S  = 30 * 86400;   // 30 days

/**
 * Mint a fresh remember-me token for a user + set the cookie on their
 * device. Called from login.php when the operator ticked the checkbox.
 */
function remember_me_issue(int $userId): void
{
    $raw  = bin2hex(random_bytes(32));           // 64 hex chars in the cookie
    $hash = hash('sha256', $raw);
    $exp  = date('Y-m-d H:i:s', time() + REMEMBER_ME_TTL_S);

    try {
        aiserve_db()->prepare(
            'INSERT INTO user_remember_tokens
                (user_id, token_hash, user_agent, ip_address, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $hash,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            mb_substr((string)client_ip(), 0, 64),
            $exp,
        ]);
    } catch (Throwable $e) {
        error_log('[AiServe remember_me_issue] ' . $e->getMessage());
        return;   // silent — user's just logged in via password, feature is best-effort
    }

    setcookie(REMEMBER_ME_COOKIE, $raw, [
        'expires'  => time() + REMEMBER_ME_TTL_S,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * If a remember-me cookie is present and valid, log the matching user
 * in (session-side) AND rotate the token. Returns the user_id we
 * logged in, or null if no valid token.
 */
function remember_me_try(): ?int
{
    $raw = (string)($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
    if ($raw === '' || strlen($raw) !== 64 || !ctype_xdigit($raw)) return null;
    $hash = hash('sha256', $raw);

    try {
        $s = aiserve_db()->prepare(
            'SELECT id, user_id FROM user_remember_tokens
             WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $s->execute([$hash]);
        $row = $s->fetch();
        if (!$row) {
            // Bad or expired — clear the cookie so we don't retry every request.
            _remember_me_clear_cookie();
            return null;
        }

        // Fetch user + check active.
        $u = aiserve_db()->prepare(
            'SELECT id FROM users WHERE id = ? AND status = "active" LIMIT 1'
        );
        $u->execute([(int)$row['user_id']]);
        $userId = (int)($u->fetchColumn() ?: 0);
        if ($userId <= 0) {
            // User disabled since the token was issued — revoke.
            aiserve_db()->prepare('DELETE FROM user_remember_tokens WHERE id = ?')
                        ->execute([(int)$row['id']]);
            _remember_me_clear_cookie();
            return null;
        }

        // Rotate: delete old row, mint new. A stolen cookie survives
        // only until the legit device makes its next request.
        aiserve_db()->prepare('DELETE FROM user_remember_tokens WHERE id = ?')
                    ->execute([(int)$row['id']]);
        remember_me_issue($userId);

        return $userId;
    } catch (Throwable $e) {
        error_log('[AiServe remember_me_try] ' . $e->getMessage());
        return null;
    }
}

/**
 * Revoke this device's remember-me token during a full logout.
 */
function remember_me_revoke_current(): void
{
    $raw = (string)($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
    if ($raw !== '' && strlen($raw) === 64 && ctype_xdigit($raw)) {
        try {
            aiserve_db()->prepare('DELETE FROM user_remember_tokens WHERE token_hash = ?')
                        ->execute([hash('sha256', $raw)]);
        } catch (Throwable $e) { /* ok */ }
    }
    _remember_me_clear_cookie();
}

/**
 * Revoke every remember-me token for a user — used by "sign out of all
 * devices" (future admin tool) and by password change.
 */
function remember_me_revoke_all(int $userId): void
{
    try {
        aiserve_db()->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')
                    ->execute([$userId]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Prune expired tokens. Called by the nightly aiserve-db-cleanup cron
 * (add:
 *     DELETE FROM user_remember_tokens WHERE expires_at < NOW();
 * to /usr/local/bin/aiserve-db-cleanup.sh). Safe to call at any time.
 */
function remember_me_prune_expired(): int
{
    try {
        $r = aiserve_db()->prepare('DELETE FROM user_remember_tokens WHERE expires_at < NOW()');
        $r->execute();
        return $r->rowCount();
    } catch (Throwable $e) { return 0; }
}

function _remember_me_clear_cookie(): void
{
    if (isset($_COOKIE[REMEMBER_ME_COOKIE])) unset($_COOKIE[REMEMBER_ME_COOKIE]);
    setcookie(REMEMBER_ME_COOKIE, '', [
        'expires' => time() - 3600,
        'path'    => '/',
        'secure'  => !empty($_SERVER['HTTPS']),
        'httponly'=> true,
        'samesite'=> 'Lax',
    ]);
}
