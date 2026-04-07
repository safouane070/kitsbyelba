<?php
declare(strict_types=1);

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
