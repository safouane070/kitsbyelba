<?php
/**
 * Move kit folders into: kits / {League} / {Club} / {Original product folder} /
 * Fewer items at the root; leagues grouped with their clubs.
 *
 * Run (POST only): /api/reorganize_kits_folders.php
 * Auth: admin session + X-CSRF-Token OR X-Import-Secret header.
 * Options:
 *   dry_run=1     — show planned moves only (default try without this = execute)
 *   update_db=1   — update products.kits_path and image paths (default 1)
 *
 * Skips folders already at League/Club/Product (3+ segments, first = known league).
 * After a real run, run sync_kits_from_folder.php once if you use include_orphans or need text refresh.
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
$updateDb = !isset($payload['update_db']) || (string)$payload['update_db'] !== '0';

$kitsRoot = realpath(__DIR__ . '/../kits');
if (!$kitsRoot || !is_dir($kitsRoot)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'kits/ folder not found']);
    exit;
}

$imgExt = ['jpg' => 1, 'jpeg' => 1, 'png' => 1, 'webp' => 1, 'gif' => 1];

/** Remove empty directories under kits (bottom-up), never delete kits root. */
function reorganize_pruneEmptyDirs(string $root): void
{
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $path = $item->getRealPath();
        if ($path === false || $path === $root) {
            continue;
        }
        @rmdir($path);
    }
}

$pdo = null;
if ($updateDb && !$dryRun) {
    try {
        $pdo = kits_pdo($cfg);
        ensure_products_kits_path_column($pdo);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB connection failed — set update_db=0 to filesystem only']);
        exit;
    }
}

$leafDirs = [];
kits_collectLeafProductDirs($kitsRoot, $imgExt, function (string $dir, array $imageFiles) use (&$leafDirs): void {
    $leafDirs[] = [$dir, $imageFiles];
});

usort($leafDirs, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));

$moves = [];
$skipped = [];
$errors = [];

foreach ($leafDirs as [$dir, $imageFiles]) {
    $rel = kits_relPathFromRoot($dir, $kitsRoot);
    $parts = $rel === '' ? [] : explode('/', $rel);
    $depth = count($parts);
    $folderName = $depth ? $parts[$depth - 1] : basename($dir);

    if ($depth >= 3 && isset($parts[0]) && kits_isKnownLeagueSegment($parts[0])) {
        $skipped[] = ['reason' => 'already_league_club_product', 'path' => $rel];
        continue;
    }

    $haystack = $rel . ' ' . $folderName;
    $info = kits_inferLeagueClubFromHaystack($haystack);
    $leagueFolder = $info['league'] ?? '_Unsorted';
    $clubFolder = $info['club'] ?? 'Other';

    $productFolder = $folderName;
    foreach (['\\', '/', ':', '*', '?', '"', '<', '>', '|'] as $bad) {
        if (str_contains($productFolder, $bad)) {
            $productFolder = kits_safeFolderName($folderName);
            break;
        }
    }

    $target = $kitsRoot . DIRECTORY_SEPARATOR . $leagueFolder . DIRECTORY_SEPARATOR . $clubFolder . DIRECTORY_SEPARATOR . $productFolder;

    if (realpath($dir) && realpath($target) && realpath($dir) === realpath($target)) {
        $skipped[] = ['reason' => 'same_path', 'path' => $rel];
        continue;
    }

    $origTarget = $target;
    $n = 2;
    while (is_dir($target) && realpath($target) !== realpath($dir)) {
        $target = $origTarget . '_' . $n;
        $n++;
    }

    $plannedRel = kits_relPathFromRoot($target, $kitsRoot);

    $moves[] = [
        'from' => $rel,
        'to' => $plannedRel,
        'league' => $leagueFolder,
        'club' => $clubFolder,
    ];

    if ($dryRun) {
        continue;
    }

    if (!is_dir($dir)) {
        $errors[] = ['path' => $rel, 'error' => 'Source missing'];
        continue;
    }

    $parentTarget = dirname($target);
    if (!is_dir($parentTarget) && !@mkdir($parentTarget, 0775, true)) {
        $errors[] = ['path' => $rel, 'error' => 'Could not mkdir ' . $parentTarget];
        continue;
    }

    if (!@rename($dir, $target)) {
        $errors[] = ['path' => $rel, 'error' => 'rename() failed to ' . $target];
        continue;
    }

    $newRel = kits_relPathFromRoot($target, $kitsRoot);

    if ($pdo) {
        $oldKey = kits_pathKeyFromRel($rel);
        $newKey = kits_pathKeyFromRel($newRel);
        $w1 = $imageFiles[0] ?? null;
        $w2 = $imageFiles[1] ?? null;
        $w3 = $imageFiles[2] ?? null;
        $i1 = $w1 ? kits_webPath($newRel, $w1) : null;
        $i2 = $w2 ? kits_webPath($newRel, $w2) : null;
        $i3 = $w3 ? kits_webPath($newRel, $w3) : null;

        $stmt = $pdo->prepare('SELECT id FROM products WHERE kits_path = ? LIMIT 1');
        $stmt->execute([$oldKey]);
        $pid = $stmt->fetchColumn();
        if ($pid) {
            $up = $pdo->prepare(
                'UPDATE products SET kits_path = ?, image = ?, image2 = ?, image3 = ? WHERE id = ?'
            );
            $up->execute([$newKey, $i1, $i2, $i3, (int)$pid]);
        }
    }
}

if (!$dryRun) {
    reorganize_pruneEmptyDirs($kitsRoot);
}

echo json_encode([
    'ok' => count($errors) === 0,
    'dry_run' => $dryRun,
    'update_db' => $updateDb && !$dryRun,
    'leaf_count' => count($leafDirs),
    'moves' => count($moves),
    'skipped' => count($skipped),
    'skipped_sample' => array_slice($skipped, 0, 25),
    'moves_sample' => array_slice($moves, 0, 50),
    'errors' => $errors,
    'hint' => $dryRun
        ? 'Remove dry_run=1 to execute. Backup kits/ first on Windows if unsure.'
        : 'Run sync_kits_from_folder.php if product titles need refreshing.',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
