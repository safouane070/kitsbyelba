<?php
declare(strict_types=1);

/**
 * Simple file-based rate limiter (per PHP temp dir). Not distributed-safe; sufficient for single-server XAMPP/production.
 *
 * @param string $bucket Stable key, e.g. 'place_order_' . hash('sha256', $ip)
 */
function kits_rate_limit_allow(string $bucket, int $maxAttempts, int $windowSeconds): bool
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kitsbyelba_rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // Fail-closed: kunnen we de teller niet opslaan, dan weigeren we i.p.v. de
    // limiet stil te laten wegvallen (anders = ongelimiteerde login/checkout).
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $bucket);
    $now = time();
    $data = ['window_start' => $now, 'count' => 0];
    if (is_readable($file)) {
        $raw = @file_get_contents($file);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
            $data = $decoded;
        }
    }
    if ($now - (int)$data['window_start'] >= $windowSeconds) {
        $data = ['window_start' => $now, 'count' => 0];
    }
    $data['count'] = (int)$data['count'] + 1;
    if (@file_put_contents($file, json_encode($data), LOCK_EX) === false) {
        // Schrijffout → teller niet vastgelegd → fail-closed weigeren.
        return false;
    }
    return $data['count'] <= $maxAttempts;
}
