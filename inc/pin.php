<?php
/**
 * 6-digit PIN quick-unlock (WhatsApp-style screen lock).
 *
 *   pin_user_has_pin($userId)          — bool, has this user set a PIN?
 *   pin_user_set($userId, $pin)        — bcrypt-hash + persist the PIN
 *   pin_user_clear($userId)            — remove the PIN entirely
 *   pin_verify_and_unlock($userId, $pin) — check + record success/failure
 *   pin_user_locked_until($userId)     — seconds until unlock, or 0 if OK
 *   pin_mark_verified()                — flag the current session as unlocked
 *   pin_is_verified()                  — has THIS session already unlocked?
 *
 * Rate limit: 5 wrong PINs in a 10-min window locks PIN unlock for 15
 * minutes. Locked users must sign in with their password (which
 * clears the lock). Failed attempts outside the 10-min window reset.
 */

require_once __DIR__ . '/helpers.php';

const PIN_LENGTH             = 6;
const PIN_MAX_ATTEMPTS       = 5;
const PIN_ATTEMPT_WINDOW_S   = 600;   // 10 min
const PIN_LOCKOUT_S          = 900;   // 15 min
const PIN_SESSION_FLAG       = '_pin_verified';

function pin_is_valid_shape(string $pin): bool
{
    return strlen($pin) === PIN_LENGTH && ctype_digit($pin);
}

function pin_user_has_pin(int $userId): bool
{
    try {
        $s = aiserve_db()->prepare('SELECT pin_hash FROM users WHERE id = ? LIMIT 1');
        $s->execute([$userId]);
        return !empty((string)$s->fetchColumn());
    } catch (Throwable $e) { return false; }
}

function pin_user_set(int $userId, string $pin): bool
{
    if (!pin_is_valid_shape($pin)) return false;
    try {
        aiserve_db()->prepare(
            'UPDATE users
             SET pin_hash = ?, pin_set_at = NOW(),
                 pin_failed_attempts = 0, pin_last_failed_at = NULL, pin_locked_until = NULL
             WHERE id = ? LIMIT 1'
        )->execute([password_hash($pin, PASSWORD_BCRYPT), $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('[AiServe pin_user_set] ' . $e->getMessage());
        return false;
    }
}

function pin_user_clear(int $userId): void
{
    try {
        aiserve_db()->prepare(
            'UPDATE users SET pin_hash = NULL, pin_set_at = NULL,
                              pin_failed_attempts = 0, pin_last_failed_at = NULL,
                              pin_locked_until = NULL
             WHERE id = ? LIMIT 1'
        )->execute([$userId]);
    } catch (Throwable $e) { /* silent */ }
}

/**
 * Seconds until the user's PIN lockout expires. 0 = not locked.
 */
function pin_user_locked_until(int $userId): int
{
    try {
        $s = aiserve_db()->prepare('SELECT pin_locked_until FROM users WHERE id = ? LIMIT 1');
        $s->execute([$userId]);
        $lock = (string)($s->fetchColumn() ?: '');
        if ($lock === '') return 0;
        $t = strtotime($lock);
        return $t > time() ? ($t - time()) : 0;
    } catch (Throwable $e) { return 0; }
}

/**
 * Verify a submitted PIN. On success, resets the attempt counter and
 * returns true. On failure, increments the counter and (past the
 * threshold) sets a lockout. Returns [ok, message, locked_seconds].
 */
function pin_verify_and_unlock(int $userId, string $pin): array
{
    if (!pin_is_valid_shape($pin)) {
        return ['ok' => false, 'message' => 'Enter your 6-digit PIN.', 'locked_s' => 0];
    }
    $locked = pin_user_locked_until($userId);
    if ($locked > 0) {
        return ['ok' => false,
                'message' => 'Too many wrong PINs. Locked for ' . ceil($locked / 60) . ' min. Sign in with your password instead.',
                'locked_s' => $locked];
    }
    try {
        $db = aiserve_db();
        $s = $db->prepare('SELECT pin_hash, pin_failed_attempts, pin_last_failed_at FROM users WHERE id = ? LIMIT 1');
        $s->execute([$userId]);
        $row = $s->fetch();
        if (!$row || empty($row['pin_hash'])) {
            return ['ok' => false, 'message' => 'No PIN set for this account.', 'locked_s' => 0];
        }
        if (password_verify($pin, (string)$row['pin_hash'])) {
            // Reset counters + mark session unlocked.
            $db->prepare(
                'UPDATE users SET pin_failed_attempts = 0, pin_last_failed_at = NULL,
                                  pin_locked_until = NULL
                 WHERE id = ? LIMIT 1'
            )->execute([$userId]);
            pin_mark_verified();
            return ['ok' => true, 'message' => 'Unlocked.', 'locked_s' => 0];
        }

        // Failure — bump counter, respecting the rolling window.
        $fails = (int)$row['pin_failed_attempts'];
        $last  = $row['pin_last_failed_at'] ? strtotime((string)$row['pin_last_failed_at']) : 0;
        if ($last && ($last < time() - PIN_ATTEMPT_WINDOW_S)) {
            $fails = 0;   // outside the window — reset before incrementing
        }
        $fails++;

        $lockUntil = null;
        if ($fails >= PIN_MAX_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + PIN_LOCKOUT_S);
            $fails = 0;   // reset so the next post-lockout attempt starts fresh
        }
        $db->prepare(
            'UPDATE users SET pin_failed_attempts = ?, pin_last_failed_at = NOW(),
                              pin_locked_until = ?
             WHERE id = ? LIMIT 1'
        )->execute([$fails, $lockUntil, $userId]);

        if ($lockUntil) {
            return ['ok' => false,
                    'message' => 'Too many wrong PINs. Locked for '
                               . ceil(PIN_LOCKOUT_S / 60) . ' min. Sign in with your password instead.',
                    'locked_s' => PIN_LOCKOUT_S];
        }
        $left = PIN_MAX_ATTEMPTS - $fails;
        return ['ok' => false,
                'message' => 'Wrong PIN. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left before lockout.',
                'locked_s' => 0];
    } catch (Throwable $e) {
        error_log('[AiServe pin_verify_and_unlock] ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Server error. Try again.', 'locked_s' => 0];
    }
}

function pin_mark_verified(): void
{
    aiserve_start_session();
    $_SESSION[PIN_SESSION_FLAG] = time();
}

function pin_is_verified(): bool
{
    aiserve_start_session();
    return !empty($_SESSION[PIN_SESSION_FLAG]);
}

/**
 * Called by remember_me_try() when it auto-logs a user in — clears any
 * stale PIN verification so the /pin.php gate always kicks in on a
 * fresh remember-me resolution.
 */
function pin_reset_verification(): void
{
    aiserve_start_session();
    unset($_SESSION[PIN_SESSION_FLAG]);
}
