<?php
declare(strict_types=1);

// HTTP-rooktest: elke pagina en elk endpoint tegen een draaiende site (lokaal XAMPP of staging).
//   KITS_TEST_BASE_URL=http://localhost/kitsbyelba php tests/run.php
// Zonder bereikbare site (zoals in CI) worden deze tests overgeslagen, niet als fout geteld.

$base = rtrim((string)(getenv('KITS_TEST_BASE_URL') ?: 'http://localhost/kitsbyelba'), '/');

/** @return array{0:int,1:string,2:string} status, content-type, body */
function kits_http(string $url, string $method = 'GET'): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
        'header' => "User-Agent: kits-smoke-test\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    $status = 0;
    $type = '';
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; }
        if (stripos($h, 'content-type:') === 0) { $type = strtolower(trim(substr($h, 13))); }
    }
    return [$status, $type, $body === false ? '' : $body];
}

function assert_no_php_errors(string $body, string $where): void
{
    if (preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)/', $body, $m)) {
        throw new AssertionError("PHP-fout '{$m[1]}' in $where");
    }
}

[$probe] = kits_http($base . '/api/healthz.php');
if ($probe === 0) {
    echo "  (http-rooktests overgeslagen: $base niet bereikbaar — zet KITS_TEST_BASE_URL)\n";
    return;
}

// ── Pagina's ──────────────────────────────────────────────────────────────
$pages = [
    '/' => 'Op voorraad',
    '/index.html' => 'Op voorraad',
    '/shop.html' => 'id="grid"',
    '/shirts' => 'id="grid"',
    '/sets' => 'id="grid"',
    '/hemdsetjes' => 'id="grid"',
    '/retro' => 'id="grid"',
    '/kids' => 'id="grid"',
    '/voorraad' => 'id="grid"',
    '/wishlist.php' => 'id="grid"',
    '/account.php' => '<h1',
    '/faq.php' => '<details class="faq-item">',
    '/maattabel.php' => 'class="sg-table',
];
foreach ($pages as $path => $marker) {
    test("pagina $path: 200, HTML, geen PHP-fouten", function () use ($base, $path, $marker): void {
        [$status, $type, $body] = kits_http($base . $path);
        assert_eq(200, $status, $path);
        assert_true(str_contains($type, 'text/html'), "$path content-type $type");
        assert_no_php_errors($body, $path);
        assert_true(str_contains($body, $marker), "$path mist '$marker'");
        assert_true(str_contains($body, 'class="announce-promo"'), "$path mist announce-bar");
        assert_false(str_contains($body, '<?'), "$path lekt ruwe PHP (SetHandler uit?)");
    });
}

test('homepage kort: maattabel en FAQ staan op eigen pagina\'s, footer linkt ernaar', function () use ($base): void {
    $body = kits_http($base . '/')[2];
    assert_false(str_contains($body, 'id="sizeguide"'), 'maattabel staat nog op de homepage');
    assert_false(str_contains($body, 'id="faq"'), 'FAQ staat nog op de homepage');
    assert_true(str_contains($body, 'href="maattabel.php"') && str_contains($body, 'href="faq.php"'), 'footer-links ontbreken');
});

test('faq.php heeft FAQPage-schema met evenveel vragen als op de pagina', function () use ($base): void {
    $body = kits_http($base . '/faq.php')[2];
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m);
    $ld = json_decode($m[1] ?? '', true);
    assert_eq('FAQPage', $ld['@type'] ?? null);
    assert_eq(substr_count($body, '<details class="faq-item">'), count($ld['mainEntity'] ?? []));
});

test('categoriepagina\'s hebben elk een eigen <title>', function () use ($base): void {
    $titles = [];
    foreach (['/shirts', '/sets', '/hemdsetjes', '/retro', '/kids', '/voorraad'] as $p) {
        preg_match('#<title>(.*?)</title>#s', kits_http($base . $p)[2], $m);
        $titles[$p] = trim($m[1] ?? '');
        assert_true($titles[$p] !== '', "$p zonder title");
    }
    assert_eq(count($titles), count(array_unique($titles)), 'dubbele titles: ' . json_encode($titles));
});

// ── API ───────────────────────────────────────────────────────────────────
test('api/products.php: JSON-lijst met verplichte velden', function () use ($base): void {
    [$status, $type, $body] = kits_http($base . '/api/products.php');
    assert_eq(200, $status);
    assert_true(str_contains($type, 'application/json'), $type);
    $list = json_decode($body, true);
    assert_true(is_array($list) && array_values($list) === $list, 'geen JSON-lijst');
    foreach ($list as $p) {
        foreach (['id', 'name', 'price', 'cat', 'league', 'stock', 'in_voorraad'] as $k) {
            assert_true(array_key_exists($k, $p), "product mist '$k'");
        }
        assert_true(is_int($p['id']) && $p['id'] > 0, 'id');
        assert_true(is_numeric($p['price']) && $p['price'] >= 0, "prijs van #{$p['id']}");
        assert_true(in_array($p['in_voorraad'], [0, 1], true), "in_voorraad van #{$p['id']}");
    }
});

test('api/products.php?view=detail: zelfde producten, met extra velden', function () use ($base): void {
    $short = json_decode(kits_http($base . '/api/products.php')[2], true);
    $detail = json_decode(kits_http($base . '/api/products.php?view=detail')[2], true);
    assert_true(is_array($detail), 'detail geen JSON');
    assert_eq(array_column($short, 'id'), array_column($detail, 'id'), 'detail- en lijstweergave tonen andere producten');
});

test('api/config.php: publieke config zonder geheimen', function () use ($base): void {
    [$status, , $body] = kits_http($base . '/api/config.php');
    assert_eq(200, $status);
    $cfg = json_decode($body, true);
    foreach (['whatsapp', 'freeShippingFrom', 'shippingCost', 'promoBanner'] as $k) {
        assert_true(array_key_exists($k, $cfg), "config mist '$k'");
    }
    foreach (['db_pass', 'db_user', 'admin', 'password', 'secret'] as $bad) {
        assert_false(stripos($body, $bad) !== false, "config lekt '$bad'");
    }
});

test('api/healthz.php geeft 200 JSON', function () use ($base): void {
    [$status, $type] = kits_http($base . '/api/healthz.php');
    assert_eq(200, $status);
    assert_true(str_contains($type, 'json'), $type);
});

foreach (['/api/coupon_validate.php', '/api/stock_notify.php', '/api/order_confirm.php'] as $ep) {
    test("$ep weigert GET (alleen POST)", function () use ($base, $ep): void {
        assert_eq(405, kits_http($base . $ep)[0]);
    });
}

test('place-order.php zonder data → 400, geen PHP-fout', function () use ($base): void {
    [$status, , $body] = kits_http($base . '/place-order.php', 'POST');
    assert_true($status >= 400 && $status < 500, "status $status");
    assert_no_php_errors($body, 'place-order.php');
});

// ── Productpagina ─────────────────────────────────────────────────────────
test('product.php: bestaand product 200 met naam, prijs en schema', function () use ($base): void {
    $list = json_decode(kits_http($base . '/api/products.php')[2], true) ?: [];
    if (!$list) { return; } // lege catalogus: niets te openen
    $p = $list[0];
    [$status, , $body] = kits_http($base . '/product.php?id=' . $p['id']);
    assert_eq(200, $status);
    assert_no_php_errors($body, 'product.php');
    assert_true(str_contains($body, htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8')), 'naam ontbreekt in HTML');
    assert_true(str_contains($body, '"@type":"Product"') || str_contains($body, '"@type": "Product"'), 'Product-schema ontbreekt');
    assert_true(str_contains($body, 'id="sizeGuideDlg"') && str_contains($body, 'class="sg-link"'), 'maattabel-link/venster ontbreekt');
});

test('product.php: onbekend product → 404, geen valse "Op voorraad"', function () use ($base): void {
    [$status, , $body] = kits_http($base . '/product.php?id=999999999');
    assert_eq(404, $status);
    assert_true(str_contains($body, 'data-pdp-gone="1"'), 'gone-vlag ontbreekt');
    assert_false((bool)preg_match('#id="pStock">\s*Op voorraad#', $body), '404 toont "Op voorraad"');
});

// ── SEO / afgeschermde paden ──────────────────────────────────────────────
test('sitemap.php en robots.txt', function () use ($base): void {
    [$status, $type, $body] = kits_http($base . '/sitemap.php');
    assert_eq(200, $status);
    assert_true(str_contains($type, 'xml'), $type);
    assert_true(str_contains($body, '<urlset'), 'geen urlset');
    assert_eq(200, kits_http($base . '/robots.txt')[0]);
});

foreach (['/config.php', '/includes/db.php', '/tools/', '/.env', '/database.sql', '/.git/config'] as $blocked) {
    test("afgeschermd: $blocked niet publiek", function () use ($base, $blocked): void {
        $status = kits_http($base . $blocked)[0];
        assert_true(in_array($status, [403, 404], true), "$blocked gaf $status");
    });
}
