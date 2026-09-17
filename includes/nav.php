<?php
/**
 * Shared top nav + league mega-menu — SINGLE source of truth.
 * Included by index.html, shop.html, product.php, account.php (and, via
 * shop.html, every category page). Edit here → changes on every page.
 *
 * Set these before including (all optional):
 *   $navActive      string  active item: '' | shirts|sets|hemdsetjes|retro|kids|voorraad|wishlist|account
 *   $navHomePrefix  string  '' on the homepage (local #anchors, logo -> '#');
 *                           'index.html' everywhere else. Default 'index.html'.
 *   $navSearch      bool    render the search button (page must also have the
 *                           search modal markup + JS). Default false.
 *   $navCartOnclick string  cart-button onclick. Default 'openCart()'.
 *   $navCartNumId   string  id of the cart-count span. Default 'cartN'.
 *   $navClass       string  extra class on <nav> (e.g. 'site-nav'). Default ''.
 */
$__hp        = isset($navHomePrefix) ? (string)$navHomePrefix : 'index.html';
$__active    = isset($navActive) ? (string)$navActive : '';
$__logoHref  = $__hp === '' ? '#' : $__hp;              // homepage logo scrolls to top
$__cartClick = isset($navCartOnclick) ? (string)$navCartOnclick : 'openCart()';
$__cartNum   = isset($navCartNumId) ? (string)$navCartNumId : 'cartN';
// Default naar 'site-nav' zodat de responsive nav-regels (mobiele hamburger in
// responsive-global.css: .site-nav .ham/.nav-links) OVERAL werken — ook op pagina's die
// vergeten $navClass te zetten (was de bug op product.php: geen hamburger op mobiel).
$__navClass  = (isset($navClass) && trim((string)$navClass) !== '') ? trim((string)$navClass) : 'site-nav';
$__showSearch = !empty($navSearch);
$__esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$__cats = [
  'shirts'     => 'Shirts',
  'sets'       => 'Sets',
  'hemdsetjes' => 'Hemdsetjes',
  'retro'      => 'Retro',
  'kids'       => 'Kids',
];
$__leagues = [
  'laliga'     => 'La Liga',
  'premier'    => 'Premier League',
  'bundesliga' => 'Bundesliga',
  'seriea'     => 'Serie A',
  'eredivisie' => 'Eredivisie',
  'ligue1'     => 'Ligue 1',
  'national'   => 'Landen',
  'overig'     => 'Overig',
];
?>
<!-- NAV (via includes/nav.php) -->
<nav<?= $__navClass !== '' ? ' class="' . $__esc($__navClass) . '"' : '' ?>>
  <div class="nav-top">
    <input type="checkbox" id="kbeNavToggle" class="kbe-nav-toggle-input" autocomplete="off" tabindex="-1">
    <a class="logo" href="<?= $__esc($__logoHref) ?>">
      <img class="logo-img" src="images/logo.jpeg" alt="KitsByElbaa logo" width="200" height="64" loading="eager" decoding="async">
      <span class="logo-text">KitsByElbaa</span>
    </a>
    <ul class="nav-links" id="navLinks">
<?php foreach ($__cats as $__slug => $__label): ?>
      <li class="has-mega"><a class="top-link<?= $__active === $__slug ? ' active' : '' ?>" href="<?= $__slug ?>" id="navType-<?= $__slug ?>"><?= $__label ?></a>
        <div class="nav-mega">
          <?php foreach ($__leagues as $__lslug => $__llabel): ?><a href="<?= $__slug ?>?league=<?= $__lslug ?>"><?= $__llabel ?></a><?php endforeach; ?>
        </div>
      </li>
<?php endforeach; ?>
      <li><a href="voorraad" id="navType-voorraad"<?= $__active === 'voorraad' ? ' class="active"' : '' ?>>Voorraad</a></li>
      <li><a href="wishlist.php"<?= $__active === 'wishlist' ? ' class="active"' : '' ?>>Wishlist</a></li>
      <li><a href="<?= $__hp ?>#sizeguide">Maattabel</a></li>
      <li><a href="<?= $__hp ?>#faq">FAQ</a></li>
      <li><a href="account.php" id="nav-account-link"<?= $__active === 'account' ? ' class="active"' : '' ?>>Inloggen / registreren</a></li>
    </ul>
    <div class="nav-right">
      <label for="kbeNavToggle" class="ham" id="ham" aria-label="Menu" aria-expanded="false" aria-controls="navLinks"><span></span><span></span><span></span></label>
<?php if ($__showSearch): ?>
      <button type="button" class="nav-search" onclick="openNavSearch()" aria-label="Zoeken"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/></svg></button>
<?php endif; ?>
      <button type="button" class="nav-cart" onclick="<?= $__esc($__cartClick) ?>" aria-label="Winkelwagen" title="Winkelwagen — aantal artikelen">
        <span class="nav-cart-ico" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></span><span class="nav-cart-label">Winkelwagen</span><span class="nav-cart-num" id="<?= $__esc($__cartNum) ?>" title="Aantal in winkelwagen">0</span>
      </button>
    </div>
  </div>
</nav>
<!-- Verbergt league-links zonder producten uit de mega-menu (voorkomt lege pagina's). -->
<?php require_once __DIR__ . '/asset.php'; ?><script src="<?= kits_asset('js/product-classify.js') ?>"></script><script defer src="<?= kits_asset('js/nav-mega.js') ?>"></script>
<?php
unset(
  $navActive, $navHomePrefix, $navSearch, $navCartOnclick, $navCartNumId, $navClass,
  $__hp, $__active, $__logoHref, $__cartClick, $__cartNum, $__navClass, $__showSearch,
  $__esc, $__cats, $__leagues, $__slug, $__label, $__lslug, $__llabel
);
?>
