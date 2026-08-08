<?php
/**
 * WebAuthn (biometric / passkey) helpers.
 *
 * Strategy — trust the browser to give us the public key in SPKI format
 * via credential.response.getPublicKey(). Skips server-side CBOR + COSE
 * parsing entirely. Signature verification is a straight openssl_verify()
 * on (authenticatorData || sha256(clientDataJSON)).
 *
 * Client → server uses base64url everywhere so binary safely round-trips
 * through JSON.
 */

require_once __DIR__ . '/helpers.php';

const WA_CHALLENGE_TTL_S = 300;   // challenge good for 5 min
const WA_SESSION_KEY     = '_wa_challenge';

// ---------- base64url helpers ----------
function wa_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function wa_b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string)base64_decode($s, true);
}

// ---------- challenge management ----------
function wa_new_challenge(): string
{
    aiserve_start_session();
    $raw = random_bytes(32);
    $_SESSION[WA_SESSION_KEY] = [
        'raw'    => wa_b64url_encode($raw),
        'issued' => time(),
    ];
    return wa_b64url_encode($raw);
}
function wa_consume_challenge(string $expectedB64url): bool
{
    aiserve_start_session();
    $s = $_SESSION[WA_SESSION_KEY] ?? null;
    unset($_SESSION[WA_SESSION_KEY]);        // one-shot
    if (!$s) return false;
    if ((time() - (int)$s['issued']) > WA_CHALLENGE_TTL_S) return false;
    return hash_equals((string)$s['raw'], (string)$expectedB64url);
}

// ---------- RP config ----------
function wa_rp_id(): string
{
    // The origin's registrable domain (etld+1 or subdomain, must match host).
    // WebAuthn ties creds to this rpId — cross-subdomain scope is opt-in via
    // longer suffix.  Use the request host so widgets on subdomains still work.
    return (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function wa_origin(): string
{
    $host = wa_rp_id();
    return (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $host;
}

// ---------- registration ----------
/**
 * Store a freshly-registered credential.
 *
 * @param string $publicKeyB64url  base64url of SPKI DER bytes from
 *                                 credential.response.getPublicKey()
 */
function wa_register_credential(int $userId, string $credentialIdB64url,
                                 string $publicKeyB64url, ?string $deviceName = null): bool
{
    $spkiDer = wa_b64url_decode($publicKeyB64url);
    if ($spkiDer === '') return false;

    // Convert to PEM so openssl_pkey_get_public() accepts it directly at
    // verify time. Chunk to 64 chars per RFC.
    $pem = "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spkiDer), 64, "\n")
         . "-----END PUBLIC KEY-----\n";

    // Basic sanity check — reject if OpenSSL can't parse.
    if (!openssl_pkey_get_public($pem)) return false;

    try {
        aiserve_db()->prepare(
            'INSERT INTO user_webauthn_credentials
                (user_id, credential_id_b64, public_key_pem, device_name)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                public_key_pem = VALUES(public_key_pem),
                device_name = COALESCE(VALUES(device_name), device_name)'
        )->execute([$userId, $credentialIdB64url, $pem, $deviceName ?: null]);
        return true;
    } catch (Throwable $e) {
        error_log('[AiServe wa_register_credential] ' . $e->getMessage());
        return false;
    }
}

// ---------- authentication ----------
/**
 * Return every credential-id (base64url) registered for this user, so
 * the client can present allowCredentials to the authenticator.
 */
function wa_credentials_for_user(int $userId): array
{
    try {
        $s = aiserve_db()->prepare('SELECT credential_id_b64 FROM user_webauthn_credentials WHERE user_id = ?');
        $s->execute([$userId]);
        return array_column($s->fetchAll(), 'credential_id_b64');
    } catch (Throwable $e) { return []; }
}

/**
 * Verify a WebAuthn assertion. Inputs are base64url as posted by the
 * browser's assertion response. Returns true and updates sign_count on
 * success; returns false on any failure.
 */
function wa_verify_assertion(int $userId, string $credentialIdB64url,
                              string $clientDataJsonB64url,
                              string $authenticatorDataB64url,
                              string $signatureB64url): bool
{
    try {
        // Fetch stored credential — must belong to this user.
        $s = aiserve_db()->prepare(
            'SELECT id, public_key_pem, sign_count FROM user_webauthn_credentials
             WHERE user_id = ? AND credential_id_b64 = ? LIMIT 1'
        );
        $s->execute([$userId, $credentialIdB64url]);
        $row = $s->fetch();
        if (!$row) return false;

        $clientDataJson  = wa_b64url_decode($clientDataJsonB64url);
        $authData        = wa_b64url_decode($authenticatorDataB64url);
        $sig             = wa_b64url_decode($signatureB64url);
        if ($clientDataJson === '' || $authData === '' || $sig === '') return false;

        // 1. Client data checks.
        $cd = json_decode($clientDataJson, true);
        if (!is_array($cd)
            || ($cd['type'] ?? '') !== 'webauthn.get'
            || !isset($cd['challenge'], $cd['origin'])) return false;
        if (!wa_consume_challenge((string)$cd['challenge'])) return false;
        if ((string)$cd['origin'] !== wa_origin()) return false;

        // 2. authenticator data: RP ID hash matches.
        if (strlen($authData) < 37) return false;
        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals(hash('sha256', wa_rp_id(), true), $rpIdHash)) return false;
        // Flags byte at offset 32: bit 0 = UP (user present) must be set.
        $flags = ord($authData[32]);
        if (($flags & 0x01) !== 0x01) return false;

        // 3. Sign count monotonic (cloned-credential guard).
        $count = unpack('N', substr($authData, 33, 4))[1] ?? 0;
        if ($count !== 0 && $count <= (int)$row['sign_count']) {
            error_log('[AiServe wa_verify_assertion] sign_count regression for cred ' . $credentialIdB64url);
            return false;
        }

        // 4. Signature check.
        $signedData = $authData . hash('sha256', $clientDataJson, true);
        $pub = openssl_pkey_get_public((string)$row['public_key_pem']);
        if (!$pub) return false;
        $ok = openssl_verify($signedData, $sig, $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return false;

        // 5. Persist sign count + last-used.
        aiserve_db()->prepare(
            'UPDATE user_webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?'
        )->execute([$count, (int)$row['id']]);
        return true;
    } catch (Throwable $e) {
        error_log('[AiServe wa_verify_assertion] ' . $e->getMessage());
        return false;
    }
}

/**
 * Does this user have any registered biometric credential?
 */
function wa_user_has_credential(int $userId): bool
{
    try {
        $s = aiserve_db()->prepare('SELECT 1 FROM user_webauthn_credentials WHERE user_id = ? LIMIT 1');
        $s->execute([$userId]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * Delete all biometric credentials for a user — used by "Remove
 * biometric" button and by password change.
 */
function wa_revoke_all(int $userId): void
{
    try {
        aiserve_db()->prepare('DELETE FROM user_webauthn_credentials WHERE user_id = ?')
                    ->execute([$userId]);
    } catch (Throwable $e) { /* ok */ }
}
