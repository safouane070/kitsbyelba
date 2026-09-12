<?php
/**
 * Klant bevestigt dat hij zijn bestelling in WhatsApp heeft verstuurd.
 * Zet de order van 'pending' → 'confirmed', zodat admin een echte bestelling
 * (wacht op Tikkie) kan onderscheiden van een afgehaakte klik, en de order
 * niet meer automatisch verloopt.
 *
 * Gated met dezelfde checkout-CSRF als place-order.php. Verandert alleen een
 * label (nooit betaling/voorraad), dus laag risico, maar we houden 't netjes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_guard.php';
kits_json_guard();

require_once __DIR__ . '/../includes/session.php';
kits_session_start('Lax', 60 * 120);

header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/rate_limit.php';
kits_emit_cors_headers($cfg, true);
header('Access-Control-Allow-Headers: Content-Type, Cookie, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode niet toegestaan']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!kits_rate_limit_allow('order_confirm_' . $ip, 60, 3600)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Te veel verzoeken. Probeer later opnieuw.']);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ongeldige gegevens']);
    exit;
}

// CSRF: zelfde token als place-order.php
$csrfIn      = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data['csrf_token'] ?? '');
$csrfSession = (string) ($_SESSION['checkout_csrf'] ?? '');
if ($csrfSession === '' || $csrfIn === '' || !hash_equals($csrfSession, $csrfIn)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Beveiligingssessie verlopen.']);
    exit;
}

$orderId = strtoupper(trim((string) ($data['order_id'] ?? '')));
if ($orderId === '' || !preg_match('/^KD-[0-9]{4,12}$/', $orderId)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ongeldig bestelnummer']);
    exit;
}

// Eigendom: alleen de sessie die dit order plaatste (place-order.php) mag 't
// bevestigen. Zo kan niemand een gegokt/afgekeken order_id van een ander op
// 'confirmed' zetten. Geen match → stil no-op (geen foutmelding, geen signaal).
$sessionOrderId = strtoupper((string) ($_SESSION['last_order_id'] ?? ''));
if ($sessionOrderId === '' || $sessionOrderId !== $orderId) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode(['ok' => true, 'confirmed' => false]);
    exit;
}

try {
    $pdo  = kits_pdo($cfg);
    $stmt = $pdo->prepare("UPDATE orders SET status='confirmed' WHERE order_id=? AND status='pending'");
    $stmt->execute([$orderId]);
    $changed = $stmt->rowCount() > 0;
} catch (Throwable $e) {
    error_log('[order_confirm] ' . $e->getMessage());
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Serverfout']);
    exit;
}

while (ob_get_level() > 0) { ob_end_clean(); }
// 'changed' false = order bestond niet meer als 'pending' (al bevestigd/betaald) — ook prima voor de klant.
echo json_encode(['ok' => true, 'confirmed' => $changed]);
