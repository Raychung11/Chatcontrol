<?php
/**
 * DNS resolver that bypasses Hostinger's poisoned OS-level resolver.
 *
 * When the partner gateway's server restarts and gets a new IP, global DNS
 * updates within minutes but Hostinger's local resolver can keep returning
 * the old answer for hours, breaking every outbound send. To work around
 * that, this helper asks Google's public DNS-over-HTTPS endpoint directly,
 * which is uncached and always fresh.
 *
 * Result is memoised per request so we don't pay the ~50 ms DoH round-trip
 * more than once per host within a single PHP invocation.
 */

function fresh_dns_resolve(string $host): ?string
{
    static $cache = [];
    $host = strtolower(trim($host));
    if ($host === '') return null;
    if (isset($cache[$host])) return $cache[$host];

    $url = 'https://dns.google/resolve?name=' . urlencode($host) . '&type=A';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_TIMEOUT           => 4,
        CURLOPT_SSL_VERIFYPEER    => true,
        CURLOPT_SSL_VERIFYHOST    => 2,
        CURLOPT_HTTPHEADER        => ['Accept: application/dns-json'],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code !== 200) {
        // Fall back to the system resolver if DoH itself is unreachable.
        $sys = @gethostbyname($host);
        $cache[$host] = ($sys && $sys !== $host) ? $sys : null;
        return $cache[$host];
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data) || empty($data['Answer'])) {
        $cache[$host] = null;
        return null;
    }
    foreach ($data['Answer'] as $ans) {
        // type 1 = A record
        if ((int)($ans['type'] ?? 0) === 1 && !empty($ans['data'])) {
            $cache[$host] = (string)$ans['data'];
            return $cache[$host];
        }
    }
    $cache[$host] = null;
    return null;
}

/**
 * Build the CURLOPT_RESOLVE array entry to pin $host:$port to a fresh IP.
 * Returns null if we can't resolve fresh - caller should then proceed
 * with whatever the OS resolver hands back.
 */
function fresh_dns_resolve_entry(string $url, int $defaultPort = 443): ?array
{
    $parts = parse_url($url);
    $host  = $parts['host'] ?? '';
    if ($host === '') return null;
    $port  = (int)($parts['port'] ?? $defaultPort);
    $ip    = fresh_dns_resolve($host);
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
    return [
        'entry' => sprintf('%s:%d:%s', $host, $port, $ip),
        'ip'    => $ip,
        'host'  => $host,
        'port'  => $port,
    ];
}
