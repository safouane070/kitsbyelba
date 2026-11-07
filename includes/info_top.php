<?php
/**
 * Bovenkant van een eenvoudige infopagina (faq.php, maattabel.php): <head>, announce, nav.
 * Verwacht: $cfg (config.php), $infoTitle, $infoDesc, $infoPath (bv. 'faq.php').
 * Optioneel: $infoLd (array → JSON-LD). Sluit af met een open <main class="info-page">;
 * includes/info_bottom.php sluit 'm weer.
 */
require_once __DIR__ . '/asset.php';
$__infoBase = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
$__infoCanon = $__infoBase !== '' ? $__infoBase . '/' . $infoPath : '';
$__e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="images/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="images/apple-touch-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f9faf7">
<title><?= $__e($infoTitle) ?></title>
<meta name="description" content="<?= $__e($infoDesc) ?>">
<?php if ($__infoCanon !== ''): ?>
<link rel="canonical" href="<?= $__e($__infoCanon) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $__e($infoTitle) ?>">
<meta property="og:description" content="<?= $__e($infoDesc) ?>">
<meta property="og:url" content="<?= $__e($__infoCanon) ?>">
<?php endif; ?>
<?php if (!empty($infoLd)): ?>
<script type="application/ld+json"><?= json_encode($infoLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endif; ?>
<link rel="preload" href="fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/space-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= kits_asset('css/fonts.css') ?>">
<link rel="stylesheet" href="<?= kits_asset('css/footer.css') ?>">
<link rel="stylesheet" href="<?= kits_asset('css/app.css') ?>">
<link rel="stylesheet" href="<?= kits_asset('css/responsive-global.css') ?>">
<script defer src="<?= kits_asset('js/kbe-ios-helpers.js') ?>"></script>
</head>
<body>
<?php include __DIR__ . '/announce.php'; ?>
<?php $navCartOnclick = "window.location.href='index.html?openCart=1'"; $navSearch = true; include __DIR__ . '/nav.php'; ?>
<?php include __DIR__ . '/nav-search.php'; ?>
<main class="info-page" id="main-content">
<?php unset($__infoBase, $__infoCanon); ?>
