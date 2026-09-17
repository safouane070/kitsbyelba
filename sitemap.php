<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');

$cfg = require __DIR__ . '/config.php';
$base = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
if ($base === '') {
    $base = '';
}

function kits_sitemap_slugify(string $name): string
{
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    $s = trim($s, '-');

    return $s !== '' ? $s : 'kit';
}

$urls = [];
if ($base !== '') {
    // Homepage + shop + de per-categorie landingspagina's (schone URLs uit .htaccess).
    // account.php bewust NIET: login-pagina, geen SEO-waarde (staat op noindex-niveau).
    $static = [
        ['index.html',  '1.0', 'daily'],
        ['shop.html',   '0.9', 'daily'],
        ['shirts',      '0.9', 'daily'],
        ['sets',        '0.8', 'daily'],
        ['hemdsetjes',  '0.7', 'weekly'],
        ['retro',       '0.8', 'weekly'],
        ['kids',        '0.7', 'weekly'],
        ['voorraad',    '0.8', 'daily'],
    ];
    foreach ($static as [$path, $prio, $freq]) {
        $urls[] = ['loc' => $base . '/' . $path, 'changefreq' => $freq, 'priority' => $prio];
    }
}

try {
    $pdo = kits_pdo($cfg);
    $rows = $pdo->query('SELECT id, name FROM products WHERE active = 1 ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($base === '') {
            continue;
        }
        $slug = (int)$r['id'] . '-' . kits_sitemap_slugify((string)$r['name']);
        $urls[] = [
            'loc' => $base . '/product.php?' . http_build_query(['slug' => $slug]),
            'changefreq' => 'weekly',
            'priority' => '0.6',
        ];
    }
} catch (Throwable $e) {
    // still output static URLs if any
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if (!empty($u['changefreq'])) {
        echo '<changefreq>' . htmlspecialchars($u['changefreq'], ENT_XML1, 'UTF-8') . '</changefreq>';
    }
    if (!empty($u['priority'])) {
        echo '<priority>' . htmlspecialchars($u['priority'], ENT_XML1, 'UTF-8') . '</priority>';
    }
    echo "</url>\n";
}
echo '</urlset>';
