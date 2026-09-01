<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$cfg = require $root . '/config.php';

// ── Authorization ─────────────────────────────────────────────
// Detailed diagnostics (DB host/name/user, config presence, error codes) are
// infrastructure info and must not be exposed to anonymous visitors. Require an
// admin session OR the import secret (header or ?secret=). Everyone else gets a
// bare liveness probe only.
require_once $root . '/includes/session.php';
kits_session_start('Lax');
$importSecret   = (string)($cfg['import_secret'] ?? '');
$secretProvided = (string)($_SERVER['HTTP_X_IMPORT_SECRET'] ?? $_GET['secret'] ?? '');
$authorized = !empty($_SESSION['admin'])
    || ($importSecret !== '' && hash_equals($importSecret, $secretProvided));

$checks = [];
$ok = true;

$checks['dotenv_readable'] = is_readable($root . DIRECTORY_SEPARATOR . '.env');

$checks['config_admin_hash'] = !empty($cfg['admin_password_hash']);
$checks['config_import_secret'] = !empty($cfg['import_secret']);
if (!$checks['config_admin_hash'] || !$checks['config_import_secret']) {
    $ok = false;
}

$dbErr = null;
$host = (string)($cfg['db_host'] ?? '');
$user = (string)($cfg['db_user'] ?? '');
$pass = (string)($cfg['db_pass'] ?? '');
$dbn = (string)($cfg['db_name'] ?? '');

// Server + login (zonder databasenaam): scheidt "verkeerde DB-naam" van "host/user/pass fout"
$checks['db_server_login'] = false;
if ($host !== '' && $user !== '') {
    try {
        $pdoBare = kits_pdo($cfg, false); // false = zonder dbname: test server/login los van DB-naam
        $pdoBare->query('SELECT 1');
        $checks['db_server_login'] = true;
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $info = $e->errorInfo;
            $checks['db_server_login_mysql_code'] = isset($info[1]) ? (int)$info[1] : null;
        }
    }
}

try {
    $pdo = kits_pdo($cfg);
    $pdo->query('SELECT 1');
    $checks['db'] = true;
} catch (Throwable $e) {
    $checks['db'] = false;
    $ok = false;
    $dbErr = $e->getMessage();
    if ($e instanceof PDOException) {
        $info = $e->errorInfo;
        // MySQL driver codes (no password leaked): 1045=auth, 1049=unknown DB, 2002=host/unreachable
        $checks['db_mysql_code'] = isset($info[1]) ? (int)$info[1] : null;
        $checks['db_sqlstate'] = (string)($info[0] ?? '');
    }
}

// Safe hints (no password): helps verify .env is read and names match Hostinger/phpMyAdmin.
$checks['db_config'] = [
    'host' => $host,
    'name' => $dbn,
    'user' => $user,
    'pass_configured' => trim($pass) !== '',
];

$out = [
    'ok' => $ok,
    'ts' => gmdate('c'),
];

// Only expose infrastructure diagnostics to an authorized caller.
if ($authorized) {
    $out['checks'] = $checks;
    // Temporary troubleshooting: set KITS_DEBUG_DB=1 in .env, then reload. Remove after fixing.
    if ($dbErr !== null && !empty($cfg['debug_db'])) {
        $out['db_error'] = $dbErr;
    }
}

http_response_code($ok ? 200 : 503);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
