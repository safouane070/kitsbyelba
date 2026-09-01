<?php
declare(strict_types=1);

/**
 * True when the current request is served over HTTPS — including behind a
 * TLS-terminating reverse proxy that forwards X-Forwarded-Proto. Correcter dan
 * het oude `isset($_SERVER['HTTPS'])` (dat óók true is bij HTTPS='off' → cookie
 * kreeg dan onterecht Secure over HTTP en de sessie brak).
 */
function kits_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return is_string($xfp) && strtolower(trim(explode(',', $xfp)[0])) === 'https';
}

/**
 * Start de gedeelde PHPSESSID-sessie met de standaard secure-cookie-vlaggen.
 * SINGLE SOURCE OF TRUTH — voorheen 10× gekopieerd en gedrift (3 verschillende
 * `secure`-checks, Strict/Lax door elkaar op dezelfde cookie). Nu overal Lax:
 * blokkeert cross-site POST (CSRF-vector) én houdt login-redirects werkend; de
 * echte CSRF-verdediging zijn de tokens. `secure` via kits_request_is_https().
 *
 * @param string   $sameSite       'Lax' (default) of 'Strict'
 * @param int|null $gcMaxlifetime  server-side sessie-TTL in sec; MOET vóór start
 *                                  gezet worden (daarom hier, niet erna).
 */
function kits_session_start(string $sameSite = 'Lax', ?int $gcMaxlifetime = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if ($gcMaxlifetime !== null) {
        ini_set('session.gc_maxlifetime', (string)$gcMaxlifetime);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => kits_request_is_https(),
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();
}

/**
 * Clear session data, expire the session cookie, and destroy the server-side session.
 */
function kits_destroy_session(): void
{
    $_SESSION = [];
    if (!ini_get('session.use_cookies')) {
        session_destroy();
        return;
    }
    $p = session_get_cookie_params();
    $name = session_name();
    $expire = time() - 42000;
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, '', [
            'expires' => $expire,
            'path' => $p['path'],
            'domain' => $p['domain'] ?? '',
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    } else {
        setcookie($name, '', $expire, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}
