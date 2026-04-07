<?php
declare(strict_types=1);

/**
 * Trim, normalize whitespace, block null bytes. No external APIs.
 */
function kits_sanitize_address_line(string $s): string
{
    $s = str_replace(["\0", "\r"], '', $s);
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return $s;
}

function kits_line_looks_like_spam_or_garbage(string $s): bool
{
    if ($s === '') {
        return true;
    }
    // One repeated character (e.g. "aaaaaaa", "1111111")
    if (preg_match('/^(.)\1{7,}$/us', $s)) {
        return true;
    }
    // Keyboard mashing / no real letters (place names need letters)
    if (!preg_match('/\p{L}/u', $s)) {
        return true;
    }
    return false;
}

/** Obvious injection / HTML tricks (we store plain text; still reject). */
function kits_address_contains_dodgy_markup(string $s): bool
{
    $l = mb_strtolower($s);
    return str_contains($l, '<script')
        || str_contains($l, 'javascript:')
        || str_contains($l, 'onerror=')
        || str_contains($l, 'onload=')
        || str_contains($l, 'data:text/html');
}

/**
 * @param array<string,mixed> $data
 * @return string|null Error message, or null if OK (mutates $data for trimmed fields)
 */
function kits_validate_checkout_address_fields(array &$data): ?string
{
    $allowedCountries = ['nl', 'be', 'de', 'fr'];
    $country = strtolower(trim((string)($data['country'] ?? 'nl')));
    if (!in_array($country, $allowedCountries, true)) {
        return 'Kies een geldig land.';
    }
    $data['country'] = strtoupper($country);

    $street = kits_sanitize_address_line((string)($data['street'] ?? ''));
    $zip    = kits_sanitize_address_line((string)($data['zip'] ?? ''));
    $city   = kits_sanitize_address_line((string)($data['city'] ?? ''));

    if (kits_address_contains_dodgy_markup($street) || kits_address_contains_dodgy_markup($zip)
        || kits_address_contains_dodgy_markup($city)) {
        return 'Ongeldige tekens in adres.';
    }

    // Street: need letters + at least one digit (huisnummer)
    if (mb_strlen($street) < 3 || mb_strlen($street) > 200) {
        return 'Straat en huisnummer ontbreken of zijn te lang.';
    }
    if (!preg_match('/\p{L}/u', $street) || !preg_match('/\p{N}/u', $street)) {
        return 'Vul straat en huisnummer in (met cijfer).';
    }
    if (kits_line_looks_like_spam_or_garbage($street)) {
        return 'Straat en huisnummer lijken ongeldig.';
    }

    // Postcode: letters, cijfers, spaties, koppelteken (internationaal vriendelijk)
    if (mb_strlen($zip) < 2 || mb_strlen($zip) > 20) {
        return 'Postcode ongeldig of te lang.';
    }
    if (!preg_match('/^[\p{L}\p{N}\s\-.]+$/u', $zip)) {
        return 'Postcode bevat ongeldige tekens.';
    }
    if (!preg_match('/\p{N}/u', $zip)) {
        return 'Postcode moet minstens één cijfer bevatten.';
    }
    $zipCompact = preg_replace('/\s+/u', '', $zip);
    if (preg_match('/^(.)\1{7,}$/us', $zipCompact)) {
        return 'Postcode lijkt ongeldig.';
    }

    if (mb_strlen($city) < 2 || mb_strlen($city) > 100) {
        return 'Plaats ontbreekt of is te lang.';
    }
    if (kits_line_looks_like_spam_or_garbage($city)) {
        return 'Plaats lijkt ongeldig.';
    }

    $data['street'] = $street;
    $data['zip'] = $zip;
    $data['city'] = $city;

    return null;
}
