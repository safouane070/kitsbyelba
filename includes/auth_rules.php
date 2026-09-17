<?php
declare(strict_types=1);

/**
 * Pure auth-validatieregels — geen DB, geen sessie, geen I/O.
 * Uit auth.php getrokken zodat de regels los te testen zijn.
 */

/** Normaliseer een e-mailadres zoals de auth-API dat overal doet. */
function kits_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Wachtwoordbeleid bij registreren: minimaal 8, maximaal 128 tekens.
 * @return string|null Foutmelding, of null als het wachtwoord voldoet.
 */
function kits_password_policy_error(string $password): ?string
{
    if (mb_strlen($password) < 8) {
        return 'Wachtwoord moet minimaal 8 tekens zijn';
    }
    if (mb_strlen($password) > 128) {
        return 'Wachtwoord is te lang';
    }
    return null;
}
