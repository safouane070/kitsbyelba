<?php
declare(strict_types=1);

// HTTPS-detectie bepaalt de Secure-vlag op de sessiecookie.
// (session.php definieert alleen functies bij include — geen sessie wordt gestart.)
require_once __DIR__ . '/../includes/session.php';

/** Reset de relevante $_SERVER-sleutels tussen cases. */
function kits_reset_https_server(): void
{
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
}

test('https: geen enkele indicator → false', function (): void {
    kits_reset_https_server();
    assert_false(kits_request_is_https());
});

test('https: HTTPS=on → true', function (): void {
    kits_reset_https_server();
    $_SERVER['HTTPS'] = 'on';
    assert_true(kits_request_is_https());
});

test('https: HTTPS=off telt niet als https', function (): void {
    kits_reset_https_server();
    $_SERVER['HTTPS'] = 'off';
    assert_false(kits_request_is_https(), 'off mag de Secure-cookie niet forceren');
});

test('https: X-Forwarded-Proto=https (proxy) → true', function (): void {
    kits_reset_https_server();
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    assert_true(kits_request_is_https());
});

test('https: X-Forwarded-Proto met meerdere waarden neemt de eerste', function (): void {
    kits_reset_https_server();
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
    assert_true(kits_request_is_https());
});
