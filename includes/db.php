<?php
declare(strict_types=1);

/**
 * Eén plek voor de PDO-verbinding. Voorheen bouwde elk endpoint zijn eigen
 * `new PDO(...)` (14×) — en dat was gedrift: 6 van de 14 misten
 * PDO::FETCH_ASSOC (kregen stil FETCH_BOTH). Deze helper is de single source of
 * truth voor DSN + opties, zodat álle endpoints identiek gedrag hebben.
 *
 * Gooit PDOException bij een verbindingsfout (ERRMODE_EXCEPTION) — de aanroeper
 * houdt zijn eigen try/catch voor pagina-specifieke foutafhandeling.
 *
 * @param array<string,mixed> $cfg  config.php-array (db_host/db_name/db_user/db_pass)
 * @param bool $withDb  false = verbind zonder database te selecteren (healthz:
 *                      test of de server leeft vóór de db bestaat).
 */
function kits_pdo(array $cfg, bool $withDb = true): PDO
{
    $host = (string)($cfg['db_host'] ?? '127.0.0.1');
    $name = (string)($cfg['db_name'] ?? '');
    $dsn  = 'mysql:host=' . $host
          . ($withDb && $name !== '' ? ';dbname=' . $name : '')
          . ';charset=utf8mb4';
    return new PDO(
        $dsn,
        (string)($cfg['db_user'] ?? ''),
        (string)($cfg['db_pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}
