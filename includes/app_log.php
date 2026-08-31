<?php
declare(strict_types=1);

/**
 * Structured JSON logging helper for production troubleshooting.
 */
function kits_log(string $level, string $event, array $context = []): void
{
    $lvl = strtoupper(trim($level));
    if ($lvl === '') {
        $lvl = 'INFO';
    }
    $entry = [
        'ts' => gmdate('c'),
        'level' => $lvl,
        'event' => $event,
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'context' => $context,
    ];
    error_log(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
