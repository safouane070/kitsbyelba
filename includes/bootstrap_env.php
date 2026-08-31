<?php
declare(strict_types=1);

/**
 * Load KITS_* variables from a local .env file (not committed). Lines: KITS_DB_HOST=value
 * File is denied by web server via .htaccess; still prefer real environment variables on production.
 */
function kits_load_dotenv(string $baseDir): void
{
    $path = $baseDir . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^KITS_[A-Z0-9_]+=/', $line)) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = substr($line, 0, $eq);
        $val = substr($line, $eq + 1);
        $val = trim($val, " \t\"'");
        if ($key === '') {
            continue;
        }
        $cur = getenv($key);
        // Non-empty real environment wins. Empty string or unset → .env may fill (some hosts predefine empty KITS_*).
        if ($cur !== false && $cur !== '') {
            continue;
        }
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
}
