<?php
/**
 * CLI only: generate a bcrypt hash for the admin panel.
 *
 * Usage: php tools/set_admin_password.php "YourLongRandomPasswordHere"
 * Paste the output into config.php as admin_password_hash, or set env KITS_ADMIN_PASSWORD_HASH.
 */
declare(strict_types=1);

if (PHP_SAPI_NAME() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$pw = $argv[1] ?? '';
if (strlen($pw) < 12) {
    fwrite(STDERR, "Error: use a password with at least 12 characters.\n");
    exit(1);
}

echo password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;
