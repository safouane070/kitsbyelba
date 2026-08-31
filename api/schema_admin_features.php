<?php
/**
 * Ensure orders.admin_note and stock_notifications table (idempotent).
 */
function ensure_admin_features_schema(PDO $pdo): void
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
    $ensureOrderColumn = static function (string $column, string $ddl) use ($chk, $schema, $pdo): void {
        $chk->execute([$schema, 'orders', $column]);
        if ((int)$chk->fetchColumn() === 0) {
            try {
                $pdo->exec('ALTER TABLE orders ADD COLUMN ' . $ddl);
            } catch (Throwable $e) {
                // ignore if race / already exists
            }
        }
    };

    $chk->execute([$schema, 'orders', 'admin_note']);
    if ((int)$chk->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN admin_note TEXT NULL');
        } catch (Throwable $e) {
            // ignore if race
        }
    }

    $chk->execute([$schema, 'orders', 'country']);
    if ((int)$chk->fetchColumn() === 0) {
        try {
            $pdo->exec("ALTER TABLE orders ADD COLUMN country CHAR(2) NOT NULL DEFAULT 'NL' AFTER city");
        } catch (Throwable $e) {
            // ignore if race
        }
    }
    $ensureOrderColumn('tracking_number', "tracking_number VARCHAR(64) NULL AFTER admin_note");
    $ensureOrderColumn('coupon_code', "coupon_code VARCHAR(50) NULL AFTER notes");
    $ensureOrderColumn('discount', "discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER coupon_code");
    $ensureOrderColumn('user_id', "user_id INT UNSIGNED NULL FIRST");

    // order_items: welke versie (fan/player) is besteld — nodig voor fulfilment.
    $chk->execute([$schema, 'order_items', 'version']);
    if ((int)$chk->fetchColumn() === 0) {
        try {
            $pdo->exec("ALTER TABLE order_items ADD COLUMN version VARCHAR(10) NOT NULL DEFAULT 'fan' AFTER size");
        } catch (Throwable $e) {
            // ignore if race / already exists
        }
    }

    $tbl = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $tbl->execute([$schema, 'stock_notifications']);
    if ((int)$tbl->fetchColumn() === 0) {
        $pdo->exec(
            'CREATE TABLE stock_notifications (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              email VARCHAR(254) NOT NULL,
              product_id INT UNSIGNED NOT NULL,
              size VARCHAR(10) NOT NULL DEFAULT \'\',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_stock_notify (email, product_id, size),
              KEY idx_stock_notify_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    // Ensure coupons table exists
    $tbl->execute([$schema, 'coupons']);
    if ((int)$tbl->fetchColumn() === 0) {
        $pdo->exec(
            'CREATE TABLE coupons (
              id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code       VARCHAR(50)  NOT NULL UNIQUE,
              type       ENUM(\'percent\',\'fixed\') NOT NULL DEFAULT \'percent\',
              value      DECIMAL(10,2) NOT NULL DEFAULT 10.00,
              active     TINYINT(1)   NOT NULL DEFAULT 1,
              uses_count INT UNSIGNED NOT NULL DEFAULT 0,
              created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // Seed the default coupon
        try {
            $pdo->exec("INSERT IGNORE INTO coupons (code,type,value,active) VALUES ('KITSBYELBA','percent',10.00,1)");
        } catch (Throwable $e) { /* ignore */ }
    }

    require_once __DIR__ . '/../includes/site_settings.php';
    kits_load_site_settings($pdo);
}
