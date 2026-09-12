<?php
declare(strict_types=1);

/**
 * Order ↔ voorraad-helpers. Gedeeld door admin.php (annuleren) en de
 * automatische opschoning van afgehaakte bestellingen.
 *
 * Voorraad wordt afgeboekt bij het AANMAKEN van een order (place-order.php),
 * dus reserveert een klik het shirt meteen. Wordt een order geannuleerd of
 * verloopt hij (nooit bevestigd/betaald), dan boeken we de voorraad terug.
 */

/**
 * Boek de voorraad van één order terug naar de producten.
 * Idempotent bedoeld op order-niveau: roep dit één keer aan per annulering.
 */
if (!function_exists('kits_restock_order_items')) {
    function kits_restock_order_items(PDO $pdo, int $orderDbId): void
    {
        $items = $pdo->prepare("SELECT product_id, size, quantity FROM order_items WHERE order_id=?");
        $items->execute([$orderDbId]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $pid  = (int) $item['product_id'];
            $qty  = (int) $item['quantity'];
            $size = strtoupper(trim((string) ($item['size'] ?? '')));

            $prod = $pdo->prepare("SELECT stock_sizes FROM products WHERE id=?");
            $prod->execute([$pid]);
            $row = $prod->fetch(PDO::FETCH_ASSOC);
            $ss  = ($row && $row['stock_sizes']) ? json_decode($row['stock_sizes'], true) : null;

            if ($ss && $size && array_key_exists($size, $ss)) {
                $ss[$size] = (int) $ss[$size] + $qty;
                $pdo->prepare("UPDATE products SET stock_sizes=?, stock=stock+? WHERE id=?")
                    ->execute([json_encode($ss), $qty, $pid]);
            } else {
                $pdo->prepare("UPDATE products SET stock=stock+? WHERE id=?")
                    ->execute([$qty, $pid]);
            }
        }
    }
}

/**
 * Ruim afgehaakte bestellingen op: orders die na $ttlHours nog steeds op
 * 'pending' staan (dus nooit door de klant bevestigd of door admin verwerkt)
 * worden geannuleerd en hun voorraad wordt teruggeboekt. Alles wat al verder
 * staat (confirmed/paid/shipped/…) blijft met rust.
 *
 * Opportunistisch bedoeld (bij admin-laden), zodat er geen cron nodig is.
 * Geeft het aantal opgeschoonde orders terug.
 */
if (!function_exists('kits_expire_stale_pending_orders')) {
    function kits_expire_stale_pending_orders(PDO $pdo, int $ttlHours = 96): int
    {
        if ($ttlHours <= 0) {
            return 0;
        }

        // DB-klok gebruiken (created_at = CURRENT_TIMESTAMP), $ttlHours is een int → veilig te interpoleren.
        $stmt = $pdo->prepare(
            "SELECT id FROM orders
             WHERE status='pending' AND created_at < (NOW() - INTERVAL " . (int) $ttlHours . " HOUR)"
        );
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return 0;
        }

        $cleaned = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $pdo->beginTransaction();
                // Her-check binnen de transactie: admin kan de order net hebben aangeraakt.
                $chk = $pdo->prepare("SELECT status FROM orders WHERE id=? FOR UPDATE");
                $chk->execute([$id]);
                if ($chk->fetchColumn() === 'pending') {
                    kits_restock_order_items($pdo, $id);
                    $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$id]);
                    $cleaned++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[expire_stale_pending] order ' . $id . ': ' . $e->getMessage());
            }
        }
        return $cleaned;
    }
}
