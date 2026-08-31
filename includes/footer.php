<?php
/**
 * Shared site footer for KitsByElbaa.
 *
 * SINGLE source of truth for the 4-column dark footer — used everywhere.
 * PHP pages include it directly; index.html and shop.html also include it
 * (they run as PHP via the SetHandler rule in .htaccess), and the category
 * pages (shirts/sets/... .php) include shop.html, so this file is the ONLY
 * place the footer markup exists. Edit here and it changes on every page.
 *
 * Optional parameters (set as local variables before including):
 *   $footerHideAlleTenues (bool)  — true on shop.html-equivalent pages to hide the
 *                                   "Alle tenues" link. Default false.
 *   $footerAnchorPrefix   (string) — prefix for in-page anchors (#sizeguide, #faq,
 *                                    #how-it-works). Use '' on index.html for local
 *                                    anchors, 'index.html' on every other page.
 *                                    Default 'index.html'.
 */
declare(strict_types=1);

$__kbeFooterHideAlleTenues = !empty($footerHideAlleTenues);
$__kbeFooterAnchorPrefix   = isset($footerAnchorPrefix) ? (string)$footerAnchorPrefix : 'index.html';
$__kbeFooterAnchor         = htmlspecialchars($__kbeFooterAnchorPrefix, ENT_QUOTES, 'UTF-8');
?>
<!-- FOOTER (via includes/footer.php) -->
<footer>
  <div class="foot-inner">
    <div>
      <div class="foot-logo">KitsByElbaa</div>
      <p>Voetbalshirts en tenues van topclubs en landen. Bestel via WhatsApp, betaal met Tikkie.</p>
    </div>
    <div><h4>Shop</h4><?php if (!$__kbeFooterHideAlleTenues): ?><a href="shop.html">Alle tenues</a><?php endif; ?><a href="wishlist.php">Wishlist</a><a href="shirts">Shirts</a><a href="sets">Sets</a><a href="hemdsetjes">Hemdsetjes</a><a href="retro">Retro</a><a href="kids">Kids</a><a href="voorraad">Voorraad</a></div>
    <div><h4>Info</h4><a href="<?= $__kbeFooterAnchor ?>#sizeguide">Maattabel</a><a href="<?= $__kbeFooterAnchor ?>#faq">FAQ</a><a href="<?= $__kbeFooterAnchor ?>#how-it-works">Hoe het werkt</a></div>
    <div><h4>Contact</h4><a href="https://wa.me/31684446255" target="_blank" rel="noopener noreferrer">WhatsApp (+31 6 84446255)</a><a href="mailto:KitsByElbaa@outlook.com">KitsByElbaa@outlook.com</a><a href="https://www.snapchat.com/add/KitsByElbaa" target="_blank" rel="noopener noreferrer">Snapchat: KitsByElbaa</a><a href="https://www.tiktok.com/@kitsbyelba" target="_blank" rel="noopener noreferrer">TikTok: KitsByElbaa</a><a href="<?= $__kbeFooterAnchor ?>#faq">Retour &amp; veelgestelde vragen</a></div>
  </div>
  <div class="foot-bottom">
    <span>&copy; 2026 KitsByElbaa. Alle rechten voorbehouden.</span>
    <span>Betaling via Tikkie &middot; Verzending via PostNL</span>
  </div>
</footer>
<?php
unset($__kbeFooterHideAlleTenues, $__kbeFooterAnchorPrefix, $__kbeFooterAnchor);
