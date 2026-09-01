# Ontwikkelnotities — architectuur & beslissingen

Korte, eerlijke vastlegging van niet-vanzelfsprekende keuzes in deze codebase.
Bedoeld voor de volgende ontwikkelaar (of mijzelf over 6 maanden).

## ⚠️ index.html en shop.html draaien PHP (SetHandler)

`index.html` en `shop.html` zijn `.html`-bestanden maar bevatten PHP-includes
(`includes/nav.php`, `includes/footer.php`, `includes/announce.php`). Dit werkt
**alleen** dankzij deze regel in `.htaccess`:

```apache
<FilesMatch "^(index|shop)\.html$">
    SetHandler application/x-httpd-php
</FilesMatch>
```

Gevolgen / valkuilen:
- Verhuis je naar een host zonder deze `.htaccess`, of gaat `mod_php` uit, dan
  verdwijnen nav/footer/announce **stil** (de PHP-tags worden dan platte tekst
  of niets). Er is geen foutmelding.
- Open je deze bestanden direct via `file://` of een static server, idem.
- De categorie-stubs (`shirts.php`, `sets.php`, `kids.php`, `retro.php`,
  `voorraad.php`, `hemdsetjes.php`) `include` shop.html juist zodat de PHP
  gegarandeerd draait; die zijn dus wél robuust.

Netter zou zijn: hernoem naar `index.php` / `shop.php`. Niet gedaan omdat de
canonieke URL's en externe links naar `/index.html` / `/shop.html` wijzen; een
rename vraagt 301-redirects. Bewust uitgesteld — hier gedocumenteerd zodat het
geen stille verrassing is.

## css/responsive-global.css — audit (finding #3)

Onderzocht of dit bestand (april, vóór de redesign, 126× `!important`) dood of
strijdig is met het nieuwe systeem. **Conclusie: behouden.**

- Het is een bewuste *responsive override-laag* die ná de pagina-CSS laadt
  (media-queries: safe-area/notch, hamburger-menu, 44px-tikzones (WCAG),
  horizontaal-scroll-preventie, iOS/WebKit-quirks).
- Geen echte base-duplicatie: `.nav-mega`/`.nav-links`/`.site-nav` staan als
  base alleen in `app.css`; in `responsive-global.css` en `pages-shared.css`
  komen ze uitsluitend binnen `@media` voor (legitieme overrides, geen dubbel).
- De `!important`-dichtheid is inherent aan een retrofit-override-sheet (moet
  inline-styles verslaan). Ze veilig verwijderen vraagt per-regel
  specificity-werk mét apparaat-tests; hoog risico, lage opbrengst → niet nu.

De oorspronkelijke verdenking ("dode/vechtende regels") bleek dus grotendeels
een vals alarm. Baseline vastgelegd: **126 `!important`**, 729 regels.

## js/shop-core.js — dedup ronde 2 (finding #1)

`index.html` en `shop.html` deelden ~32 functies die als kopie inline in BEIDE
stonden en uit elkaar waren gedreven (o.a. de winkelwagen: shop kende
player/fan-versie + seizoen, index niet → ander gedrag bij toevoegen vanaf de
homepage). Die zijn nu verplaatst naar `js/shop-core.js` (bron van waarheid =
shop-versie, de superset). Resultaat: inline JS per pagina ~50% kleiner en
cachebaar (was `no-cache` inline HTML).

**Wél gedeeld (in core):** cart/checkout/coupon/afrekenen (addToCart, renderCart,
mergeCartItem, clampCartToStock, placeOrder, applyCoupon, validateCouponCode, …),
zoeken (getProductSearchScore, applyNavSearch), stock-helpers
(getStockState, maxQtyForProductSize, productHasSellableStock, versionPrice,
versionStockSizes, productHasPlayer/Size), quick-view (openDetail, switchMaten,
setGalleryMainImage, refreshDetailDrawerPrice), util (chQ, showConfirm,
calcDiscount, initRevealAnimations, initKbeMobileTapFixes).

**Bewust NIET gedeeld (blijft inline per pagina):**
- **grid/filter/banner/hero** (renderGrid, renderHero, updateLeaguePillCounts,
  setTypeFilter, resetShopFilters, initShopFromUrl, goBanner, …): index en shop
  hebben *divergente filter-architecturen* (index: `currentVersionFilter` +
  `detectProductVersion`; shop: `currentVersion` + kleur/maat-filters +
  `productMatchesLeague`). Samenvoegen zou de homepage-filters HERONTWERPEN.
- **SEO/tekst-appliers** (applySeoFromConfig, applyPromoTextsFromConfig,
  applyDynamicStoreTexts): per pagina anders — shop overschrijft title/desc per
  categorie; de homepage houdt z'n eigen SEO en promo-popup-teksten.

**Compat op index:** een core-functie mag een page-specifieke inline-functie bij
naam aanroepen (resolvet per pagina). Waar de homepage geen shop-DOM heeft, staan
bovenaan het inline-script kleine no-op shims + defaults
(`currentVersion='fan'`, `currentSeason=null`, `renderDrawerVersion(){}`, enz.).
De homepage-winkelwagen gedraagt zich nu identiek aan de shop (versie 'fan').

## css/app.css — z-index schaal (finding #4)

Alle globale overlay-`z-index`-waarden lopen nu via semantische tokens in
`:root` (`--z-nav`, `--z-cart`, `--z-modal`, `--z-toast`, …). De waarden
behouden de bestaande stapelvolgorde. Kleine component-interne stacking (0–25)
blijft bewust rauw. Eén normalisatie: de toast op `product.php` stond op 9000
en is teruggebracht naar `--z-toast` (700, boven alle overlays).
