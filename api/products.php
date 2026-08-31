<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cors.php';
kits_emit_cors_headers($cfg, false);

try {
    $view = strtolower(trim((string)($_GET['view'] ?? 'list')));
    $isDetail = $view === 'detail';
    $pdo = new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Ensure player_price / player_stock_sizes (and other) columns exist before selecting them.
    require_once __DIR__ . '/schema_products.php';
    ensure_products_kits_path_column($pdo);
    $select = $isDetail
        ? "id, name, version, league, cat, emoji, price, player_price, badge, cat_cover, image, image2, image3, image_order, stock, stock_sizes, player_stock_sizes, in_voorraad,
           kits_path, description, fit_info, size_advice, material_info, shipping_info, returns_info, personalization_policy, care_instructions"
        : "id, name, version, league, cat, emoji, price, player_price, badge, cat_cover, image, image2, image3, image_order, stock, stock_sizes, player_stock_sizes, in_voorraad";
    $rows = $pdo->query(
        "SELECT {$select} FROM products WHERE active = 1 ORDER BY sort_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    // Cast types so JS receives numbers, not strings
    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['price']     = (float)$r['price'];
        $r['stock']     = (int)$r['stock'];
        $r['cat_cover']   = (int)$r['cat_cover'];
        $r['in_voorraad'] = (int)$r['in_voorraad'];
        // Decode stock_sizes JSON into an object (null if not set)
        $r['stock_sizes'] = $r['stock_sizes'] ? json_decode($r['stock_sizes'], true) : null;
        // Player-versie: prijs (null = zelfde als fan) + per-maat voorraad
        $r['player_price'] = isset($r['player_price']) && $r['player_price'] !== null ? (float)$r['player_price'] : null;
        $r['player_stock_sizes'] = !empty($r['player_stock_sizes']) ? json_decode((string)$r['player_stock_sizes'], true) : null;
    }
    echo json_encode(array_values($rows));
} catch (Throwable $e) {
    error_log('[products] ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 120');
    echo json_encode([]);
}
