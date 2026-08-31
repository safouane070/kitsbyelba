<?php
declare(strict_types=1);

/**
 * Manual schema migration runner.
 * Run before deployment or after restore:
 *   php tools/migrate_schema.php
 */

// CLI only: never runnable over HTTP (this file lives under the web root).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/schema_products.php';
require_once __DIR__ . '/../api/schema_admin_features.php';

try {
    $pdo = new PDO(
        "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['db_user'],
        $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    ensure_products_kits_path_column($pdo);
    ensure_admin_features_schema($pdo);
    echo "Schema migrations complete.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Schema migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
