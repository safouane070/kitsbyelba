<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cors.php';
kits_emit_cors_headers($cfg, true);
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode niet toegestaan']);
    exit;
}

require_once __DIR__ . '/../includes/rate_limit.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!kits_rate_limit_allow('coupon_validate_' . $ip, 120, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Te veel verzoeken. Probeer later opnieuw.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
$code = strtoupper(trim((string)($data['code'] ?? '')));
if ($code === '' || mb_strlen($code) > 50) {
    echo json_encode(['ok' => false, 'error' => 'Ongeldige code']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
        $cfg['db_user'],
        $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    error_log('[coupon_validate] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Serverfout']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT code, type, value FROM coupons WHERE code = ? AND active = 1 LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[coupon_validate] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Serverfout']);
    exit;
}

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Ongeldige of inactieve code']);
    exit;
}

echo json_encode([
    'ok' => true,
    'code' => $row['code'],
    'type' => $row['type'],
    'value' => (float) $row['value'],
]);
