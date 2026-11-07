<?php
declare(strict_types=1);

/**
 * Automatische winkelsignalen, afgeleid van echte verkoop i.p.v. handmatig per product.
 *
 * "Populair" = de best verkochte producten van de laatste $days dagen (geannuleerde orders tellen niet),
 * met een minimum aantal verkochte stuks. Zonder genoeg verkoop krijgt niets het label: liever geen
 * label dan een label op alles.
 *
 * @return int[] product-id's, best verkocht eerst
 */
function kits_popular_product_ids(PDO $pdo, int $limit = 4, int $minSold = 2, int $days = 90): array
{
    $limit = max(1, $limit);
    try {
        $st = $pdo->prepare(
            "SELECT oi.product_id, SUM(oi.quantity) AS sold
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              WHERE o.status <> 'cancelled'
                AND o.created_at >= (NOW() - INTERVAL ? DAY)
                AND oi.product_id > 0
           GROUP BY oi.product_id
             HAVING sold >= ?
           ORDER BY sold DESC, oi.product_id ASC
              LIMIT {$limit}"
        );
        $st->execute([$days, $minSold]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[catalog_signals] ' . $e->getMessage());
        return [];
    }
}

/**
 * Zet het badge-veld van productrijen goed: "hot" (Populair) alleen voor $popularIds;
 * een handmatig "hot" wordt genegeerd. "new" (Nieuw) blijft handmatig en gaat voor.
 */
function kits_apply_popular_badges(array $rows, array $popularIds): array
{
    $hot = array_flip(array_map('intval', $popularIds));
    foreach ($rows as &$r) {
        $badge = strtolower((string)($r['badge'] ?? ''));
        if ($badge === 'new') continue;
        $r['badge'] = isset($hot[(int)$r['id']]) ? 'hot' : null;
    }
    unset($r);
    return $rows;
}
