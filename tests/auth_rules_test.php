<?php
declare(strict_types=1);

// Auth-validatieregels: e-mailnormalisatie en wachtwoordbeleid.
require_once __DIR__ . '/../includes/auth_rules.php';

test('e-mail: lowercase + trim', function (): void {
    assert_eq('jan@voorbeeld.nl', kits_normalize_email('  Jan@Voorbeeld.NL  '));
});

test('e-mail: al genormaliseerd blijft gelijk', function (): void {
    assert_eq('a@b.nl', kits_normalize_email('a@b.nl'));
});

test('wachtwoord: 8 tekens is toegestaan', function (): void {
    assert_null(kits_password_policy_error('12345678'));
});

test('wachtwoord: korter dan 8 wordt afgewezen', function (): void {
    assert_eq('Wachtwoord moet minimaal 8 tekens zijn', kits_password_policy_error('1234567'));
});

test('wachtwoord: langer dan 128 wordt afgewezen', function (): void {
    assert_eq('Wachtwoord is te lang', kits_password_policy_error(str_repeat('a', 129)));
});

test('wachtwoord: precies 128 is toegestaan', function (): void {
    assert_null(kits_password_policy_error(str_repeat('a', 128)));
});
