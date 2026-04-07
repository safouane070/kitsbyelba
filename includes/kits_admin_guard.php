<?php
declare(strict_types=1);

/**
 * Admin IP allowlist (optional). Used by admin.php only.
 */

function kits_admin_client_ip(array $cfg): string
{
    if (!empty($cfg['trust_proxy_for_ip'])) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && $xff !== '') {
            return trim(explode(',', $xff)[0]);
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function kits_admin_ip_allowed(array $cfg): bool
{
    /** @var list<string> $allowed */
    $allowed = $cfg['admin_allowed_ips'] ?? [];
    if ($allowed === []) {
        return true;
    }
    $ip = kits_admin_client_ip($cfg);
    foreach ($allowed as $rule) {
        $rule = trim($rule);
        if ($rule === '' || $rule === $ip) {
            if ($rule === $ip) {
                return true;
            }
            continue;
        }
        if (str_ends_with($rule, '*')) {
            $prefix = substr($rule, 0, -1);
            if ($prefix !== '' && str_starts_with($ip, $prefix)) {
                return true;
            }
        }
    }
    return false;
}
