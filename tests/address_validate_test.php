<?php
declare(strict_types=1);

// Checkout-adresvalidatie (place-order.php gebruikt dezelfde functies).
require_once __DIR__ . '/../includes/address_input_validate.php';

test('geldig NL-adres wordt geaccepteerd en genormaliseerd', function (): void {
    $data = ['country' => 'nl', 'street' => '  Kerkstraat   12 ', 'zip' => '1234 AB', 'city' => 'Den Haag'];
    assert_null(kits_validate_checkout_address_fields($data));
    assert_eq('NL', $data['country'], 'landcode wordt hoofdletters');
    assert_eq('Kerkstraat 12', $data['street'], 'dubbele spaties samengevouwen');
});

test('land buiten de allowlist wordt geweigerd', function (): void {
    $data = ['country' => 'us', 'street' => 'Main 1', 'zip' => '1000', 'city' => 'NYC'];
    assert_eq('Kies een geldig land.', kits_validate_checkout_address_fields($data));
});

test('straat zonder huisnummer (geen cijfer) wordt geweigerd', function (): void {
    $data = ['country' => 'nl', 'street' => 'Kerkstraat', 'zip' => '1234 AB', 'city' => 'Den Haag'];
    assert_eq('Vul straat en huisnummer in (met cijfer).', kits_validate_checkout_address_fields($data));
});

test('postcode zonder cijfer wordt geweigerd', function (): void {
    $data = ['country' => 'nl', 'street' => 'Kerkstraat 12', 'zip' => 'ABCDE', 'city' => 'Den Haag'];
    assert_eq('Postcode moet minstens één cijfer bevatten.', kits_validate_checkout_address_fields($data));
});

test('script-injectie in een adresregel wordt geweigerd', function (): void {
    $data = ['country' => 'nl', 'street' => '<script>alert(1)</script> 12', 'zip' => '1234 AB', 'city' => 'Den Haag'];
    assert_eq('Ongeldige tekens in adres.', kits_validate_checkout_address_fields($data));
});

test('plaats van herhaalde tekens (spam) wordt geweigerd', function (): void {
    $data = ['country' => 'nl', 'street' => 'Kerkstraat 12', 'zip' => '1234 AB', 'city' => 'aaaaaaaa'];
    assert_eq('Plaats lijkt ongeldig.', kits_validate_checkout_address_fields($data));
});

test('sanitize verwijdert null-bytes en vouwt witruimte samen', function (): void {
    assert_eq('Den Haag', kits_sanitize_address_line("Den\0   Haag"));
});

test('markup-detectie herkent javascript: en onerror=', function (): void {
    assert_true(kits_address_contains_dodgy_markup('javascript:alert(1)'));
    assert_true(kits_address_contains_dodgy_markup('x onerror=alert(1)'));
    assert_false(kits_address_contains_dodgy_markup('Gewone Straat 12'));
});
