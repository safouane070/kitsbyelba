<?php
/**
 * Issue CSRF token for guest checkout (place-order.php). Same session cookie as other site APIs.
 */
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
ini_set('session.gc_maxlifetime', (string)(60 * 120));

header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cors.php';
kits_emit_cors_headers($cfg, true);
header('Access-Control-Allow-Headers: Content-Type, Cookie, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(204);
    exit;
}

if (empty($_SESSION['checkout_csrf']) || !is_string($_SESSION['checkout_csrf'])) {
    $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
echo json_encode(['ok' => true, 'csrf' => $_SESSION['checkout_csrf']], JSON_UNESCAPED_UNICODE);
