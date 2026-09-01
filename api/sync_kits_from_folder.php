<?php
/**
 * Scan /kits (recursive) and upsert products. Expects layout:
 *   kits / {League} / {Club} / {product folder with images} /
 * (flat folders at kits root still work; reorganize_kits_folders.php creates the nest above.)
 *
 * Run (POST only): /api/sync_kits_from_folder.php
 * Auth: admin session + X-CSRF-Token OR X-Import-Secret header.
 * Options payload: dry_run=1, include_orphans=0|1
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/kits_library.php';
require_once __DIR__ . '/schema_products.php';
require_once __DIR__ . '/../includes/kits_admin_guard.php';

$cfg = require __DIR__ . '/../config.php';
$secret = (string)($cfg['import_secret'] ?? '');
require_once __DIR__ . '/../includes/session.php';
kits_session_start('Lax');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
if (!kits_admin_ip_allowed($cfg)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}
$csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfSession = (string)($_SESSION['csrf_token'] ?? '');
$secretHeader = (string)($_SERVER['HTTP_X_IMPORT_SECRET'] ?? '');
$adminAuthorized = !empty($_SESSION['admin']) && $csrfHeader !== '' && $csrfSession !== '' && hash_equals($csrfSession, $csrfHeader);
$appEnv = strtolower((string)(getenv('KITS_ENV') ?: ''));
$allowSecretHeader = in_array($appEnv, ['local', 'development'], true);
$secretAuthorized = $allowSecretHeader && $secret !== '' && hash_equals($secret, $secretHeader);
if (!$adminAuthorized && !$secretAuthorized) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$raw = json_decode((string)file_get_contents('php://input'), true);
$payload = is_array($raw) ? $raw : $_POST;
$dryRun = isset($payload['dry_run']) && (string)$payload['dry_run'] !== '0' && (string)$payload['dry_run'] !== '';
$includeOrphans = isset($payload['include_orphans']) && (string)$payload['include_orphans'] === '1';

$kitsRoot = realpath(__DIR__ . '/../kits');
if (!$kitsRoot || !is_dir($kitsRoot)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'kits/ folder not found next to site root']);
    exit;
}

$season = $cfg['season'] ?? '25/26';
$imgExt = ['jpg' => 1, 'jpeg' => 1, 'png' => 1, 'webp' => 1, 'gif' => 1];

try {
    $pdo = kits_pdo($cfg);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

ensure_products_kits_path_column($pdo);

$leafDirs = [];
kits_collectLeafProductDirs($kitsRoot, $imgExt, function (string $dir, array $imageFiles) use (&$leafDirs): void {
    $leafDirs[] = [$dir, $imageFiles];
});

$added = 0;
$updated = 0;
$skipped = 0;
$orphans = [];
$errors = [];

$selectByPath = $pdo->prepare('SELECT id, price, stock, badge, sort_order, active FROM products WHERE kits_path = ? LIMIT 1');
$insert = $pdo->prepare(
    'INSERT INTO products
    (name, league, cat, emoji, description, fit_info, size_advice, material_info, shipping_info, returns_info,
     personalization_policy, care_instructions, image_order, stock, price, badge, image, image2, image3, active, sort_order, kits_path)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$update = $pdo->prepare(
    'UPDATE products SET
        name = ?, league = ?, cat = ?, description = ?,
        image = ?, image2 = ?, image3 = ?,
        kits_path = ?
     WHERE id = ?'
);

$maxSort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM products')->fetchColumn();

foreach ($leafDirs as [$dir, $imageFiles]) {
    $rel = kits_relPathFromRoot($dir, $kitsRoot);
    $folderName = basename($dir);
    $haystack = $rel . ' ' . $folderName;
    $lg = kits_inferLeagueFromHaystack($haystack);
    if (!$lg && !$includeOrphans) {
        $orphans[] = $rel !== '' ? ('kits/' . str_replace('\\', '/', $rel)) : $folderName;
        $skipped++;
        continue;
    }
    if (!$lg) {
        $lg = ['cat' => 'national', 'league' => 'National Teams'];
    }

    $meta = kits_inferProductMeta($folderName, $haystack);
    $name = kits_buildDisplayName($meta['title'], $meta['type'], $meta['version'], $season);
    $verLabel = $meta['version'] === 'player' ? 'Player version' : 'Fan version';
    $desc = kits_buildDescription($meta['title'], $lg['league'], $verLabel);

    $kitsPathKey = kits_pathKeyFromRel(str_replace('\\', '/', $rel));
    $web1 = kits_webPath($rel, $imageFiles[0]);
    $web2 = $imageFiles[1] ?? null;
    $web3 = $imageFiles[2] ?? null;

    $emoji = ['premier' => '🔴', 'laliga' => '🔵', 'bundesliga' => '🟥', 'seriea' => '🖤', 'ligue1' => '🖤', 'eredivisie' => '🟠', 'national' => '🌍'][$lg['cat']] ?? '👕';

    $fit = 'Regular fit. Size up for a looser feel.';
    $sizeAdvice = 'If you are between sizes, choose one size up.';
    $material = 'Lightweight polyester performance fabric.';
    $shipping = 'Delivered in 5-7 business days.';
    $returns = '14-day returns for unused non-personalized items.';
    $policy = 'Custom printed items cannot be returned.';
    $care = 'Wash at 30°C, inside out. Do not tumble dry.';

    try {
        $selectByPath->execute([$kitsPathKey]);
        $row = $selectByPath->fetch(PDO::FETCH_ASSOC);

        if ($dryRun) {
            if ($row) {
                $updated++;
            } else {
                $added++;
            }
            continue;
        }

        if ($row) {
            $update->execute([
                $name,
                $lg['league'],
                $lg['cat'],
                $desc,
                $web1,
                $web2,
                $web3,
                $kitsPathKey,
                (int)$row['id'],
            ]);
            $updated++;
        } else {
            $maxSort++;
            $insert->execute([
                $name,
                $lg['league'],
                $lg['cat'],
                $emoji,
                $desc,
                $fit,
                $sizeAdvice,
                $material,
                $shipping,
                $returns,
                $policy,
                $care,
                '1,2,3',
                10,
                $meta['price'],
                '',
                $web1,
                $web2,
                $web3,
                1,
                $maxSort,
                $kitsPathKey,
            ]);
            $added++;
        }
    } catch (Exception $e) {
        error_log('[sync_kits] ' . $kitsPathKey . ': ' . $e->getMessage());
        $errors[] = ['kits_path' => $kitsPathKey, 'error' => 'Row processing failed'];
    }
}

echo json_encode([
    'ok' => count($errors) === 0,
    'dry_run' => $dryRun,
    'kits_root' => $kitsRoot,
    'leaf_folders' => count($leafDirs),
    'inserted' => $added,
    'updated' => $updated,
    'skipped_no_league' => $skipped,
    'layout_hint' => 'kits/{League}/{Club}/{product}/ — run reorganize_kits_folders.php to sort flat folders.',
    'orphans_sample' => array_slice($orphans, 0, 40),
    'orphans_count' => count($orphans),
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
