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
 */
declare(strict_types=1);

$__kbeFooterHideAlleTenues = !empty($footerHideAlleTenues);
?>
<!-- FOOTER (via includes/footer.php) -->
<footer>
  <div class="foot-inner">
    <div class="foot-brand">
      <div class="foot-logo">KitsByElbaa</div>
      <p>Voetbalshirts van topclubs en landenteams, met jouw naam en nummer.</p>
    </div>
    <div class="foot-col"><h2>Shop</h2><?php if (!$__kbeFooterHideAlleTenues): ?><a href="shop.html">Alle tenues</a><?php endif; ?><a href="shirts">Shirts</a><a href="sets">Sets</a><a href="retro">Retro</a><a href="voorraad">Op voorraad</a></div>
    <div class="foot-col"><h2>Info</h2><a href="maattabel.php">Maattabel</a><a href="faq.php">Veelgestelde vragen</a><a href="wishlist.php">Wishlist</a></div>
    <div class="foot-col"><h2>Contact</h2><a href="https://wa.me/31684446255" target="_blank" rel="noopener noreferrer">WhatsApp</a><a href="mailto:KitsByElbaa@outlook.com">E-mail</a><a href="https://www.snapchat.com/add/kitsbyelbaa" target="_blank" rel="noopener noreferrer">Snapchat</a><a href="https://www.tiktok.com/@kitsbyelba" target="_blank" rel="noopener noreferrer">TikTok</a></div>
  </div>
  <div class="foot-bottom">
    <span>&copy; 2026 KitsByElbaa</span>
    <span>Betalen via Tikkie &middot; Verzending met PostNL</span>
  </div>
</footer>
<?php
unset($__kbeFooterHideAlleTenues);
