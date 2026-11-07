<?php
declare(strict_types=1);

// Automatische "Populair"-labels en de hero-waaier die zichzelf aanvult met shirts die te koop zijn.
require_once __DIR__ . '/../includes/catalog_signals.php';
require_once __DIR__ . '/../includes/hero_kits.php';

test('populair: handmatig "hot" verdwijnt, alleen bestverkochte producten krijgen het label', function (): void {
    $rows = [
        ['id' => 1, 'badge' => 'hot'],
        ['id' => 2, 'badge' => 'hot'],
        ['id' => 3, 'badge' => null],
    ];
    $out = kits_apply_popular_badges($rows, [2, 3]);
    assert_null($out[0]['badge'], 'handmatig hot zonder verkoop');
    assert_eq('hot', $out[1]['badge']);
    assert_eq('hot', $out[2]['badge'], 'verkocht maar niet handmatig gezet');
});

test('populair: zonder verkoopcijfers krijgt niemand het label', function (): void {
    $out = kits_apply_popular_badges([['id' => 1, 'badge' => 'hot'], ['id' => 2, 'badge' => 'hot']], []);
    assert_null($out[0]['badge']);
    assert_null($out[1]['badge']);
});

test('populair: "Nieuw" blijft handmatig en gaat voor', function (): void {
    $out = kits_apply_popular_badges([['id' => 5, 'badge' => 'new']], [5]);
    assert_eq('new', $out[0]['badge']);
});

test('hero-plekken: 8+ shirts = 7 zichtbaar, de rest wacht rechts', function (): void {
    assert_eq(['0', '1', '2', '3', '4', '5', '6', 'in', 'in', 'in'], kits_hero_slots(10));
});

test('hero-plekken: te weinig shirts vult vanuit het midden', function (): void {
    assert_eq(['2', '3', '4'], kits_hero_slots(3));
    assert_eq(['3'], kits_hero_slots(1));
    assert_eq([], kits_hero_slots(0));
});

/** Tijdelijke manifest + SQLite-catalogus. */
function hero_fixture(array $pool, array $products): array
{
    $file = tempnam(sys_get_temp_dir(), 'kbe-hero');
    file_put_contents($file, json_encode($pool));
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)');
    $st = $pdo->prepare('INSERT INTO products (id, name, active) VALUES (?, ?, ?)');
    foreach ($products as $p) $st->execute($p);
    return [$file, $pdo];
}

test('hero: een product dat uit staat wordt automatisch overgeslagen', function (): void {
    [$file, $pdo] = hero_fixture(
        [['id' => 1, 'file' => 'a', 'label' => 'A'], ['id' => 2, 'file' => 'b', 'label' => 'B'], ['id' => 3, 'file' => 'c', 'label' => 'C']],
        [[1, 'Shirt A', 1], [2, 'Shirt B', 0], [3, 'Brazilië Special #10', 1]]
    );
    $kits = kits_hero_kits($pdo, $file);
    unlink($file);
    assert_eq(['a', 'c'], array_column($kits, 'file'), 'B staat uit en mag er niet in');
    assert_eq('product.php?slug=3-brazili-special-10', $kits[1]['href'], 'zelfde slug als product.php');
});

test('hero: verwijderd product (niet in catalogus) valt weg, maximum wordt gerespecteerd', function (): void {
    [$file, $pdo] = hero_fixture(
        [['id' => 1, 'file' => 'a'], ['id' => 9, 'file' => 'weg'], ['id' => 2, 'file' => 'b'], ['id' => 3, 'file' => 'c']],
        [[1, 'A', 1], [2, 'B', 1], [3, 'C', 1]]
    );
    $kits = kits_hero_kits($pdo, $file, 2);
    unlink($file);
    assert_eq(['a', 'b'], array_column($kits, 'file'));
});

test('hero: staat geen enkel pool-product aan, dan toch de waaier, gelinkt naar het overzicht', function (): void {
    [$file, $pdo] = hero_fixture([['id' => 1, 'file' => 'a'], ['id' => 2, 'file' => 'b']], [[1, 'A', 0]]);
    $kits = kits_hero_kits($pdo, $file);
    unlink($file);
    assert_eq(['a', 'b'], array_column($kits, 'file'));
    assert_eq(['shirts', 'shirts'], array_column($kits, 'href'), 'nooit naar een 404-productpagina');
});

test('hero: zonder database toont hij de pool (liever vol dan leeg), onveilige bestandsnamen vallen weg', function (): void {
    [$file] = hero_fixture([['id' => 1, 'file' => 'a'], ['id' => 2, 'file' => '../x'], ['id' => 0, 'file' => 'c']], []);
    $kits = kits_hero_kits(null, $file);
    unlink($file);
    assert_eq(['a'], array_column($kits, 'file'));
    assert_eq('product.php?slug=1', $kits[0]['href']);
});

test('hero: het echte manifest verwijst alleen naar bestaande afbeeldingen', function (): void {
    $pool = json_decode((string)file_get_contents(__DIR__ . '/../images/hero/manifest.json'), true);
    assert_true(is_array($pool) && count($pool) >= 8, 'minstens 8 shirts in de pool');
    foreach ($pool as $k) {
        assert_true(is_file(__DIR__ . '/../images/hero/' . $k['file'] . '.webp'), 'ontbreekt: ' . $k['file']);
    }
});
