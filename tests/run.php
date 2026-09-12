<?php
declare(strict_types=1);

/**
 * Piepkleine, dependency-vrije test-runner. Geen Composer/PHPUnit nodig —
 * past bij de "geen framework"-opzet van dit project.
 *
 *   php tests/run.php
 *
 * Elk bestand tests/*_test.php registreert cases via test('naam', fn).
 * De helpers gooien een AssertionError bij een mismatch; de runner vangt
 * die per case op, telt door, en eindigt met exitcode 1 als er iets faalt.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

/** @var array<int,array{0:string,1:callable}> */
$GLOBALS['__kits_tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__kits_tests'][] = [$name, $fn];
}

function assert_true(bool $cond, string $msg = ''): void
{
    if ($cond !== true) {
        throw new AssertionError('verwacht true' . ($msg !== '' ? " — $msg" : ''));
    }
}

function assert_false(bool $cond, string $msg = ''): void
{
    if ($cond !== false) {
        throw new AssertionError('verwacht false' . ($msg !== '' ? " — $msg" : ''));
    }
}

/** Strikte gelijkheid (===). */
function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(sprintf(
            'verwacht %s, kreeg %s%s',
            var_export($expected, true),
            var_export($actual, true),
            $msg !== '' ? " — $msg" : ''
        ));
    }
}

function assert_null(mixed $value, string $msg = ''): void
{
    if ($value !== null) {
        throw new AssertionError('verwacht null, kreeg ' . var_export($value, true) . ($msg !== '' ? " — $msg" : ''));
    }
}

// ── Laad alle testbestanden ─────────────────────────────────────────────
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

// ── Draai ze ────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
$failures = [];

foreach ($GLOBALS['__kits_tests'] as [$name, $fn]) {
    try {
        $fn();
        $pass++;
        echo "  \u{2713} $name\n";
    } catch (Throwable $e) {
        $fail++;
        $failures[] = [$name, $e->getMessage()];
        echo "  \u{2717} $name\n";
    }
}

echo "\n";
if ($failures) {
    echo "Mislukt:\n";
    foreach ($failures as [$name, $why]) {
        echo "  - $name: $why\n";
    }
    echo "\n";
}

echo sprintf("%d geslaagd, %d mislukt (%d totaal)\n", $pass, $fail, $pass + $fail);
exit($fail > 0 ? 1 : 0);
