<?php
declare(strict_types=1);

/**
 * Public promo copy (banner, popup, FAQ). Coupon logic stays in `coupons` + admin Kortingscodes.
 */
function kits_site_settings_defaults(): array
{
    return [
        'promo_banner' => '10% KORTING — code <strong>KITSBYELBA</strong> · <strong>KitsByElbaa</strong>',
        'promo_popup_title' => '10% KORTING',
        'promo_popup_sub' => 'Gebruik deze code bij het afrekenen:',
        'promo_popup_code' => 'kitsbyelba',
        'promo_faq_answer' => 'Ja! Gebruik code <strong>KITSBYELBA</strong> bij het afrekenen voor <strong>10% korting</strong> op je bestelling.',
    ];
}

function kits_sanitize_promo_html(string $s): string
{
    $clean = strip_tags($s, '<strong><b><em><i><span><br>');
    // Allow only plain formatting tags; strip all attributes from allowed tags.
    $clean = preg_replace('/<(strong|b|em|i|span)\b[^>]*>/i', '<$1>', $clean ?? '') ?? '';
    $clean = preg_replace('/<br\b[^>]*>/i', '<br>', $clean) ?? '';
    return $clean;
}

function ensure_site_settings_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_settings (
            `key` VARCHAR(64) NOT NULL PRIMARY KEY,
            `value` MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * @return array<string, string>
 */
function kits_load_site_settings(PDO $pdo): array
{
    ensure_site_settings_schema($pdo);
    $defaults = kits_site_settings_defaults();
    $stmt = $pdo->query('SELECT `key`, `value` FROM site_settings');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $out = $defaults;
    foreach ($defaults as $k => $def) {
        if (!isset($rows[$k]) || (string) $rows[$k] === '') {
            $pdo->prepare('INSERT INTO site_settings (`key`, `value`) VALUES (?, ?)')
                ->execute([$k, $def]);
            $out[$k] = $def;
        } else {
            $out[$k] = (string) $rows[$k];
        }
    }
    foreach ($rows as $k => $v) {
        if (array_key_exists($k, $defaults)) {
            $out[$k] = (string) $v;
        }
    }
    return $out;
}
