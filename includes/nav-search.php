<?php
/**
 * Gedeelde zoek-modal (nav-vergrootglas). Markup + CSS staan in css/app.css.
 * Gedrag: js/nav-search.js (product.php, account.php) of shop-core.js (index/shop).
 * Vereist $navSearch=true op de nav zodat de zoekknop rendert.
 */
?>
<div class="nav-search-modal-bg" id="navSearchBg" onclick="closeNavSearch()"></div>
<div class="nav-search-modal" id="navSearchModal" role="dialog" aria-modal="true" aria-label="Zoek producten">
  <div class="nav-search-modal-head">
    <strong>Zoeken</strong>
    <button type="button" class="nav-search-close" onclick="closeNavSearch()" aria-label="Zoekvenster sluiten">&times;</button>
  </div>
  <input id="navSearchInput" class="nav-search-field" aria-label="Zoek producten" placeholder="Zoek op club, land of competitie" autocomplete="off" oninput="renderNavSearchSuggestions(this.value)" onkeydown="handleNavSearchKeydown(event)">
  <div class="nav-search-results" id="navSearchResults"></div>
</div>
