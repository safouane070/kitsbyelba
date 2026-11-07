<?php
declare(strict_types=1);

/**
 * Shirts voor de hero-waaier. Bron: images/hero/manifest.json (uitgeknipte rugfoto's, in volgorde van voorkeur).
 * Alleen shirts waarvan het product nog actief (= te koop) is komen erin; staat een product uit of is het
 * verwijderd, dan schuift automatisch het volgende uit de pool door. Elk shirt linkt naar zijn productpagina.
 *
 * Valt de database weg, dan tonen we de pool zoals hij is (liever een volle hero dan een lege).
 *
 * @return list<array{id:int,file:string,label:string,href:string}>
 */
function kits_hero_kits(?PDO $pdo, string $manifestPath, int $max = 12): array
{
    $pool = json_decode((string)@file_get_contents($manifestPath), true);
    if (!is_array($pool)) return [];
    $pool = array_values(array_filter($pool, static fn($k) =>
        is_array($k) && (int)($k['id'] ?? 0) > 0 && preg_match('/^[a-z0-9-]+$/', (string)($k['file'] ?? ''))
    ));
    if (!$pool) return [];

    $names = null; // id => productnaam van actieve producten
    if ($pdo) {
        try {
            $ids = array_map(static fn($k) => (int)$k['id'], $pool);
            $st = $pdo->prepare('SELECT id, name FROM products WHERE active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            $names = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $names[(int)$row['id']] = (string)$row['name'];
        } catch (Throwable $e) {
            error_log('[hero_kits] ' . $e->getMessage());
            $names = null;
        }
    }

    $out = [];
    foreach ($pool as $k) {
        $id = (int)$k['id'];
        if ($names !== null && !isset($names[$id])) continue; // niet (meer) te koop
        // Zelfde slug als product.php gebruikt voor zijn canonical-URL
        $slug = $id . (isset($names[$id]) ? '-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($names[$id])), '-') : '');
        $out[] = [
            'id'    => $id,
            'file'  => (string)$k['file'],
            'label' => (string)($k['label'] ?? ''),
            'href'  => 'product.php?slug=' . rawurlencode($slug),
        ];
        if (count($out) >= $max) break;
    }
    return $out;
}

/**
 * Plek in de waaier per shirt. Bij 8+ shirts: plek 0–6 zichtbaar, de rest wacht rechts ("in") en schuift
 * later binnen. Bij minder (te weinig te koop) staat de waaier stil en vullen we vanuit het midden.
 *
 * @return list<string>
 */
function kits_hero_slots(int $count): array
{
    if ($count >= 8) {
        $slots = [];
        for ($i = 0; $i < $count; $i++) $slots[] = $i < 7 ? (string)$i : 'in';
        return $slots;
    }
    $centerFirst = ['3', '2', '4', '1', '5', '0', '6'];
    $slots = array_slice($centerFirst, 0, $count);
    sort($slots); // DOM-volgorde links→rechts, gelijk aan de stapelvolgorde
    return $slots;
}
