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
if (
    !kits_rate_limit_allow('stock_notify_' . $ip, 40, 3600) ||
    !kits_rate_limit_allow('stock_notify_burst_' . $ip, 10, 600)
) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Te veel verzoeken. Probeer later opnieuw.']);
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
    error_log('[stock_notify] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Serverfout']);
    exit;
}

require_once __DIR__ . '/schema_admin_features.php';
ensure_admin_features_schema($pdo);

$input = json_decode((string)file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim((string)($input['email'] ?? '')));
$productId = (int)($input['product_id'] ?? 0);
$size = strtoupper(trim((string)($input['size'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Voer een geldig e-mailadres in.']);
    exit;
}
if (!kits_rate_limit_allow('stock_notify_email_' . hash('sha256', $email), 6, 86400)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Te veel meldingen voor dit e-mailadres vandaag.']);
    exit;
}
if (mb_strlen($email) > 254) {
    echo json_encode(['ok' => false, 'error' => 'E-mailadres is te lang.']);
    exit;
}
if ($productId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Ongeldig product.']);
    exit;
}

// Must match maatlabels op productpagina (XS/XXL) én eventuele 2XL–4XL in voorraadtabel
$validSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL', '4XL'];
if (!in_array($size, $validSizes, true)) {
    echo json_encode(['ok' => false, 'error' => 'Ongeldige maat.']);
    exit;
}

$st = $pdo->prepare('SELECT id, stock, stock_sizes FROM products WHERE id = ? AND active = 1');
$st->execute([$productId]);
$row = $st->fetch();
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Product niet gevonden.']);
    exit;
}

$total = (int)$row['stock'];
$ss = !empty($row['stock_sizes']) ? json_decode((string)$row['stock_sizes'], true) : null;
if (is_array($ss) && array_key_exists($size, $ss)) {
    $qty = (int)$ss[$size];
} else {
    $qty = $total > 0 ? 999 : 0;
}

if ($qty > 0) {
    echo json_encode(['ok' => false, 'error' => 'Deze maat is beschikbaar — geen voorraadmelding nodig.']);
    exit;
}

try {
    $ins = $pdo->prepare('INSERT IGNORE INTO stock_notifications (email, product_id, size) VALUES (?,?,?)');
    $ins->execute([$email, $productId, $size]);
} catch (Throwable $e) {
    error_log('[stock_notify] insert: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Kon niet opslaan. Probeer opnieuw.']);
    exit;
}

echo json_encode(['ok' => true]);
