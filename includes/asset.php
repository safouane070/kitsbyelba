<?php
declare(strict_types=1);

/**
 * Cache-busting asset-URL helper.
 *
 * Vervangt handmatige ?v=N (die je moet onthouden te bumpen na elke edit) door
 * de bestandsmtime: verandert automatisch zodra het bestand wijzigt, en is
 * identiek over alle pagina's die hetzelfde asset laden — dus nooit meer stale
 * CSS/JS na een deploy, en geen drift tussen index en shop.
 *
 * Gebruik in <head>:  <link rel="stylesheet" href="<?= kits_asset('css/app.css') ?>">
 */
if (!function_exists('kits_asset')) {
    function kits_asset(string $rel): string {
        $rel = ltrim($rel, '/');
        $full = __DIR__ . '/../' . $rel;
        $v = @filemtime($full);
        return $rel . ($v ? '?v=' . $v : '');
    }
}
