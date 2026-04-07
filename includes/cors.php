<?php
declare(strict_types=1);

/**
 * Trusted browser origins for CORS. Never use * with credentials.
 *
 * @return list<string>
 */
function kits_trusted_origin_list(array $cfg): array
{
    $list = [];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $list[] = $scheme . '://' . $host;
    }
    $base = trim((string)($cfg['public_site_url'] ?? ''));
    if ($base !== '') {
        $p = parse_url($base);
        if (!empty($p['scheme']) && !empty($p['host'])) {
            $port = isset($p['port']) ? ':' . $p['port'] : '';
            $list[] = $p['scheme'] . '://' . $p['host'] . $port;
        }
    }
    foreach ((array)($cfg['trusted_origins'] ?? []) as $t) {
        $t = trim((string)$t);
        if ($t !== '') {
            $list[] = rtrim($t, '/');
        }
    }
    $list = array_values(array_unique($list));
    return $list;
}

function kits_origin_is_trusted(string $origin, array $cfg): bool
{
    if ($origin === '') {
        return false;
    }
    $origin = rtrim($origin, '/');
    foreach (kits_trusted_origin_list($cfg) as $allowed) {
        if ($origin === $allowed) {
            return true;
        }
    }
    return false;
}

/**
 * Emit Access-Control-Allow-* only when Origin matches a trusted URL.
 */
function kits_emit_cors_headers(array $cfg, bool $withCredentials = false): void
{
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($requestOrigin === '' || !kits_origin_is_trusted($requestOrigin, $cfg)) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
    if ($withCredentials) {
        header('Access-Control-Allow-Credentials: true');
    }
}
