<?php
/**
 * Shared marquee announce bar — SINGLE source of truth.
 * Included by index.html, shop.html, product.php, account.php (and, via
 * shop.html, every category page). Edit here → changes on every page.
 *
 * Free-shipping amount comes from $cfg['free_shipping_from'] when the page
 * loaded config.php (product/account); the two static entry pages fall back
 * to 40. De promotekst komt server-side uit site_settings (zelfde bron als
 * api/config.php), zodat er bij het laden geen verouderde code flitst.
 */
$__kbeShip = isset($cfg['free_shipping_from']) ? (int)$cfg['free_shipping_from'] : 40;
require_once __DIR__ . '/site_settings.php';
$__kbePromo = kits_site_settings_defaults()['promo_banner'];
try {
    // Elke includer zet $cfg al (config.php niet opnieuw requiren: kits_env() zou dubbel gedeclareerd worden)
    if (isset($cfg) && is_array($cfg) && function_exists('kits_pdo')) {
        $__kbePromo = kits_load_site_settings(kits_pdo($cfg))['promo_banner'] ?: $__kbePromo;
    }
} catch (Throwable $e) {
    // DB onbereikbaar → standaardtekst
}
$__kbePromo = kits_sanitize_promo_html((string)$__kbePromo);
?>
<!-- ANNOUNCEMENT BAR (via includes/announce.php) -->
<div class="announce">
  <div class="announce-inner">
    <span class="announce-promo" id="announcePromoA"><?= $__kbePromo ?></span>
    <span>Op voorraad: 1–2 werkdagen · Nabestelling: 7–12 werkdagen</span>
    <span id="announceFreeShipA">Gratis verzending vanaf €<?= $__kbeShip ?></span>
    <span>Betaal veilig met Tikkie</span>
    <span>Snel antwoord via WhatsApp</span>
    <span class="announce-promo" id="announcePromoB"><?= $__kbePromo ?></span>
    <span>Op voorraad: 1–2 werkdagen · Nabestelling: 7–12 werkdagen</span>
    <span id="announceFreeShipB">Gratis verzending vanaf €<?= $__kbeShip ?></span>
    <span>Betaal veilig met Tikkie</span>
    <span>Snel antwoord via WhatsApp</span>
  </div>
</div>
<?php unset($__kbeShip, $__kbePromo); ?>
