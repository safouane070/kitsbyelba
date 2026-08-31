<?php
/**
 * Shared marquee announce bar — SINGLE source of truth.
 * Included by index.html, shop.html, product.php, account.php (and, via
 * shop.html, every category page). Edit here → changes on every page.
 *
 * Free-shipping amount comes from $cfg['free_shipping_from'] when the page
 * loaded config.php (product/account); the two static entry pages fall back
 * to 40. The A/B span ids let the on-page JS refresh the promo/shipping text;
 * pages without that JS simply keep the static text.
 */
$__kbeShip = isset($cfg['free_shipping_from']) ? (int)$cfg['free_shipping_from'] : 40;
?>
<!-- ANNOUNCEMENT BAR (via includes/announce.php) -->
<div class="announce">
  <div class="announce-inner">
    <span class="announce-promo" id="announcePromoA">10% korting met code <strong>KITSBYELBA</strong></span>
    <span>Op voorraad: 1–2 werkdagen · Nabestelling: 7–12 werkdagen</span>
    <span id="announceFreeShipA">Gratis verzending vanaf €<?= $__kbeShip ?></span>
    <span>Betaal veilig met Tikkie</span>
    <span>Snel antwoord via WhatsApp</span>
    <span class="announce-promo" id="announcePromoB">10% korting met code <strong>KITSBYELBA</strong></span>
    <span>Op voorraad: 1–2 werkdagen · Nabestelling: 7–12 werkdagen</span>
    <span id="announceFreeShipB">Gratis verzending vanaf €<?= $__kbeShip ?></span>
    <span>Betaal veilig met Tikkie</span>
    <span>Snel antwoord via WhatsApp</span>
  </div>
</div>
<?php unset($__kbeShip); ?>
