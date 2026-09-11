<?php
/**
 * inc/push.php — Web Push (RFC 8291 aes128gcm) in pure PHP.
 *
 * Sends a browser push notification to every registered subscription
 * of a target user_id. Used by webhook/evolution.php, api/widget_send.php
 * and any other inbound-message ingest to notify the assigned agent
 * (or, for unassigned conversations, every workspace agent).
 *
 * Why pure PHP:
 *   The stock composer choice (minishlink/web-push) works fine but this
 *   repo isn't composer-managed. Pure PHP keeps the deploy story to
 *   "git pull, run the migration" — no vendor/ tree to ship.
 *
 * Cryptography reference:
 *   - RFC 8291 §3.4:  message encryption payload = salt(16) | rs(4) |
 *                     idlen(1) | as_public(65) | ciphertext
 *   - RFC 8188 §2.2:  aes128gcm content-encoding, HKDF-SHA256 derives
 *                     CEK + nonce from an IKM per-message
 *   - RFC 8292:       VAPID JWT (ES256) authenticates the AS to the
 *                     push service so anonymous browsers can safely
 *                     hand out endpoints
 *
 * Public API:
 *   push_get_vapid_keys(): array      — VAPID keypair (auto-generates on first call)
 *   push_send_to_user(int, ...): int  — fan out to every subscription for a user
 *   push_subscribe(int, string, string, string, string): void
 *   push_delete_by_endpoint(string): void  — 410/404 cleanup
 */

require_once __DIR__ . '/helpers.php';

// -----------------------------------------------------------
// VAPID keys — auto-generated on first use, persisted in
// platform_settings so a redeploy doesn't invalidate every
// subscription. Rotating them invalidates every subscription
// on every browser — do it only when you have to.
// -----------------------------------------------------------

function push_get_vapid_keys(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $priv = platform_setting('vapid_private_key_pem', '');
    $pub  = platform_setting('vapid_public_key_b64u', '');
    if ($priv !== '' && $pub !== '') {
        $cache = ['private_pem' => $priv, 'public_b64u' => $pub];
        return $cache;
    }

    // First-run generation. Prime256v1 (aka P-256, aka secp256r1) —
    // the ONLY curve Web Push allows.
    $pkey = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($pkey === false) {
        error_log('[AiServe push] openssl_pkey_new failed: ' . openssl_error_string());
        return ['private_pem' => '', 'public_b64u' => ''];
    }
    openssl_pkey_export($pkey, $pem);
    $det = openssl_pkey_get_details($pkey);
    // Uncompressed EC point: 0x04 | X(32) | Y(32). VAPID public key
    // must be exactly 65 bytes in this shape.
    $pub65 = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT)
                    . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $pubB64u = push_b64u_encode($pub65);

    aiserve_db()->prepare(
        'INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    )->execute(['vapid_private_key_pem', $pem]);
    aiserve_db()->prepare(
        'INSERT INTO platform_settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    )->execute(['vapid_public_key_b64u', $pubB64u]);

    // Invalidate the platform_setting() cache so the next reader sees
    // the just-written values.
    return $cache = ['private_pem' => $pem, 'public_b64u' => $pubB64u];
}

// -----------------------------------------------------------
// Subscribe / unsubscribe bookkeeping
// -----------------------------------------------------------

function push_subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $ua = ''): void
{
    if ($userId <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') return;
    aiserve_db()->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             user_id = VALUES(user_id),
             p256dh  = VALUES(p256dh),
             auth    = VALUES(auth),
             last_seen_at = NOW()'
    )->execute([$userId, $endpoint, $p256dh, $auth, mb_substr($ua, 0, 255)]);
}

function push_delete_by_endpoint(string $endpoint): void
{
    aiserve_db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')
                ->execute([$endpoint]);
}

// -----------------------------------------------------------
// Fan-out to a user
// -----------------------------------------------------------

/**
 * Deliver a push to every subscription belonging to $userId.
 *
 * $data is a small assoc array — anything you'd want to render in the
 * notification. Keep it under ~3 KB (browsers cap the encrypted payload
 * at 4 KB and the AES-GCM tag + framing eats a chunk).
 *
 * Returns the number of endpoints the notification was accepted for.
 * Dead endpoints (HTTP 404/410) are deleted so we don't push to them
 * again.
 */
function push_send_to_user(int $userId, array $data, int $ttl = 3600): int
{
    if ($userId <= 0) return 0;

    $db = aiserve_db();
    $stmt = $db->prepare('SELECT * FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $subs = $stmt->fetchAll();
    if (!$subs) return 0;

    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) return 0;

    $vapid = push_get_vapid_keys();
    if ($vapid['private_pem'] === '') return 0;

    $ok = 0;
    foreach ($subs as $s) {
        $r = push_send_one($s, $payload, $vapid, $ttl);
        if ($r['ok']) {
            $ok++;
            $db->prepare('UPDATE push_subscriptions SET last_seen_at = NOW() WHERE id = ?')
               ->execute([(int)$s['id']]);
        } elseif (in_array((int)$r['status'], [404, 410], true)) {
            // Endpoint permanently gone — the browser uninstalled the
            // PWA, cleared site data, or the push service rotated us.
            push_delete_by_endpoint((string)$s['endpoint']);
        } else {
            error_log('[AiServe push] subscription ' . $s['id'] . ' → HTTP '
                      . $r['status'] . ' ' . $r['body']);
        }
    }
    return $ok;
}

// -----------------------------------------------------------
// The wire: encrypt + sign + POST one subscription
// -----------------------------------------------------------

function push_send_one(array $sub, string $payload, array $vapid, int $ttl): array
{
    $endpoint = (string)$sub['endpoint'];
    $p256dh   = push_b64u_decode((string)$sub['p256dh']);
    $authKey  = push_b64u_decode((string)$sub['auth']);

    // 1. Ephemeral ECDH keypair (per-message).
    $eph = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($eph === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'ephemeral key gen failed'];
    }
    $ephDet = openssl_pkey_get_details($eph);
    $asPublic = "\x04" . str_pad($ephDet['ec']['x'], 32, "\0", STR_PAD_LEFT)
                       . str_pad($ephDet['ec']['y'], 32, "\0", STR_PAD_LEFT);

    // 2. ECDH shared secret with the subscription's public key.
    //    openssl_pkey_derive wants the peer as a PEM-wrapped public key.
    $peerPem = push_ec_public_to_pem($p256dh);
    $peer = openssl_pkey_get_public($peerPem);
    if ($peer === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'bad peer key'];
    }
    $shared = openssl_pkey_derive($peer, $eph, 32);
    if ($shared === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'ECDH failed: ' . openssl_error_string()];
    }

    // 3. RFC 8291 §3.4 key/nonce derivation.
    $salt = random_bytes(16);
    $info = "WebPush: info\x00" . $p256dh . $asPublic;
    $ikm  = hash_hkdf('sha256', $shared, 32, $info, $authKey);

    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00",     $salt);

    // 4. Encrypt payload + 0x02 padding-delimiter (per aes128gcm rules).
    $tag = '';
    $plaintext = $payload . "\x02";
    $ciphertext = openssl_encrypt(
        $plaintext, 'aes-128-gcm', $cek,
        OPENSSL_RAW_DATA, $nonce, $tag
    );
    if ($ciphertext === false) {
        return ['ok' => false, 'status' => 0, 'body' => 'encrypt failed'];
    }

    // 5. Assemble aes128gcm block: salt | rs(4) | idlen(1) | as_public(65) | ct|tag
    $rs    = "\x00\x00\x10\x00";        // record size = 4096
    $idlen = chr(strlen($asPublic));    // 65
    $body  = $salt . $rs . $idlen . $asPublic . $ciphertext . $tag;

    // 6. VAPID JWT — one JWT per host (audience). Reuse-cache within
    // a single fanout so 50 pushes to Google FCM don't sign 50 JWTs.
    static $jwtCache = [];
    $u = parse_url($endpoint);
    $aud = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '');
    if (!isset($jwtCache[$aud])) {
        $jwtCache[$aud] = push_vapid_jwt($aud, $vapid);
    }
    $jwt = $jwtCache[$aud];

    $headers = [
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'Content-Length: ' . strlen($body),
        'TTL: ' . (int)$ttl,
        'Urgency: high',
        'Authorization: vapid t=' . $jwt . ',k=' . $vapid['public_b64u'],
    ];

    // 7. POST it.
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FAILONERROR    => false,
    ]);
    $respBody = (string)curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errMsg   = curl_error($ch);
    curl_close($ch);

    if ($status === 0) {
        return ['ok' => false, 'status' => 0, 'body' => 'curl: ' . $errMsg];
    }
    return [
        'ok'     => ($status >= 200 && $status < 300),
        'status' => $status,
        'body'   => mb_substr($respBody, 0, 500),
    ];
}

// -----------------------------------------------------------
// VAPID JWT — ES256 (ECDSA with P-256 and SHA-256)
// -----------------------------------------------------------

function push_vapid_jwt(string $audience, array $vapid): string
{
    $header  = ['typ' => 'JWT', 'alg' => 'ES256'];
    $sub     = platform_setting('vapid_contact_mailto', '')
             ?: ('mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'aiserve.my'));
    if (!str_starts_with($sub, 'mailto:') && !str_starts_with($sub, 'https://')) {
        $sub = 'mailto:' . $sub;
    }
    $claims  = [
        'aud' => $audience,
        'exp' => time() + 12 * 3600, // 12h — the max some push services accept
        'sub' => $sub,
    ];
    $seg = push_b64u_encode(json_encode($header))
         . '.' . push_b64u_encode(json_encode($claims));

    $priv = openssl_pkey_get_private($vapid['private_pem']);
    if ($priv === false) return '';
    // openssl_sign emits an ASN.1 DER-encoded ECDSA sig; VAPID expects
    // the raw R || S (64 bytes). Convert.
    $der = '';
    openssl_sign($seg, $der, $priv, OPENSSL_ALGO_SHA256);
    $raw = push_ecdsa_der_to_raw($der, 32);
    if ($raw === '') return '';

    return $seg . '.' . push_b64u_encode($raw);
}

/**
 * Convert an ASN.1 DER-encoded ECDSA signature (as emitted by
 * openssl_sign) into the raw R||S concat form JWS requires.
 * $sizeBytes is 32 for P-256.
 */
function push_ecdsa_der_to_raw(string $der, int $sizeBytes): string
{
    // SEQUENCE (30 len) INTEGER (02 rlen R) INTEGER (02 slen S)
    if (strlen($der) < 8 || $der[0] !== "\x30") return '';
    $offset = 2;
    // Handle long-form length byte on the outer SEQUENCE (rare for P-256
    // since combined length fits in 1 byte, but be safe).
    if ((ord($der[1]) & 0x80) !== 0) {
        $offset = 2 + (ord($der[1]) & 0x7F);
    }
    if ($der[$offset] !== "\x02") return '';
    $rLen = ord($der[$offset + 1]);
    $r    = substr($der, $offset + 2, $rLen);
    $offset += 2 + $rLen;
    if ($der[$offset] !== "\x02") return '';
    $sLen = ord($der[$offset + 1]);
    $s    = substr($der, $offset + 2, $sLen);

    // Strip a leading 0x00 sign byte (added by DER when high bit is set).
    if (strlen($r) > $sizeBytes && $r[0] === "\x00") $r = substr($r, 1);
    if (strlen($s) > $sizeBytes && $s[0] === "\x00") $s = substr($s, 1);
    // Left-pad short integers.
    $r = str_pad($r, $sizeBytes, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $sizeBytes, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

// -----------------------------------------------------------
// Small helpers
// -----------------------------------------------------------

function push_b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function push_b64u_decode(string $b64u): string
{
    $pad = 4 - (strlen($b64u) % 4);
    if ($pad < 4) $b64u .= str_repeat('=', $pad);
    return (string)base64_decode(strtr($b64u, '-_', '+/'));
}

/**
 * Wrap a raw uncompressed EC point (65 bytes, 0x04 X Y) in a
 * minimal ASN.1 SubjectPublicKeyInfo so openssl_pkey_get_public
 * will accept it. Prevents having to shell out.
 *
 * The DER prefix is the constant SPKI header for id-ecPublicKey +
 * prime256v1 followed by the BIT STRING wrapping the raw point.
 */
function push_ec_public_to_pem(string $rawPoint): string
{
    // 65 bytes uncompressed point.
    $prefix = hex2bin(
        '3059' .                         // SEQUENCE (89)
        '3013' .                         //   SEQUENCE (19)
        '0607' . '2a8648ce3d0201' .      //     OID id-ecPublicKey
        '0608' . '2a8648ce3d030107' .    //     OID prime256v1
        '0342' .                         //   BIT STRING (66)
        '00'                             //     unused-bits = 0
    );
    $der = $prefix . $rawPoint;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}
