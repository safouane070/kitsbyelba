<?php
/**
 * Auto-migrate: ensure products.kits_path exists (stores created before this column).
 * Safe to call on every request; no-ops when already applied.
 */
function ensure_products_kits_path_column(PDO $pdo): void
{
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db === false || $db === null) {
        return;
    }
    $schema = (string)$db;

    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $chk->execute([$schema, 'products', 'kits_path']);
    if ((int)$chk->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN kits_path VARCHAR(512) DEFAULT NULL');
    }

    $idx = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $idx->execute([$schema, 'products', 'uq_products_kits_path']);
    if ((int)$idx->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_kits_path (kits_path)');
        } catch (Throwable $e) {
            // Non-null duplicate kits_path values must be resolved before the index can be added.
        }
    }

    // Migrate: per-size stock JSON column
    $chk->execute([$schema, 'products', 'stock_sizes']);
    if ((int)$chk->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE products ADD COLUMN stock_sizes TEXT DEFAULT NULL COMMENT \'JSON per-size stock: {"XS":0,"S":5,"M":3,"L":0,"XL":8,"XXL":2}\'');
    }

    // Migrate: manual voorraad flag (snelle levering 1-2 dagen)
    $chk->execute([$schema, 'products', 'in_voorraad']);
    if ((int)$chk->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE products ADD COLUMN in_voorraad TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // Column already exists or cannot be added
        }
    }
}
