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

## Inline-CSS index vs shop — GEEN dedup (finding #8)

Onderzocht of de ~39 selectors die inline in ZOWEL `index.html` als `shop.html`
voorkomen (`.hero`, `.banner-*`, `.catcard`, `.cats-grid`, `.how-step`, …) naar
`pages-shared.css` gehoist konden worden. **Conclusie: nee, bewust laten.**

Alle 39 hebben *verschillende* regel-bodies per pagina — het is geen toevallige
drift maar **echte per-pagina divergentie onder gedeelde classnamen**:
- `.hero` = op de homepage een donkere radial-gradient-achtergrond; op shop een
  tweekoloms grid-layout. Totaal andere component, zelfde naam.
- `.cats-grid` = index `flex/wrap` (carousel), shop `grid 3-col`.
- `.banner-content` = index links uitgelijnd, shop gecentreerd.
- `.hero-trust` / `.hs-icon` / `.hero-sub` = index licht-op-donker, shop
  donker-op-licht.

Samenvoegen zou óf beide pagina's identiek forceren (breekt het bedoelde
per-pagina ontwerp) óf per-pagina overrides vereisen (heft de dedup weer op).
Dit is dezelfde situatie als de JS grid/filter-architectuur (zie dedup-notitie
hierboven): gedeeld van naam, niet van gedrag. De inline-CSS blijft dus per
pagina. Vergelijkingsscript-logica: elke top-level selector-body genormaliseerd
en gediff't — 0 identieke, 39 verschillend.

## Cache-busting via filemtime — includes/asset.php (finding #9)

Handmatige `?v=N` op CSS/JS (die je moest onthouden te bumpen na elke edit, en
die tussen pagina's kon gaan driften) is vervangen door `kbe_asset('css/app.css')`
in `includes/asset.php`: hangt `?v=<filemtime>` aan, verandert automatisch bij
elke bestandswijziging, identiek over alle pagina's. Gebruikt in `index.html`,
`shop.html` (+ category-stubs via include) en `includes/nav.php` (nav-mega.js →
werkt zo ook op product.php/account.php). Guard `function_exists` maakt
dubbel-include veilig. Let op: dit is weer inline-PHP in de `.html`-pagina's, dus
het valt onder dezelfde SetHandler-afhankelijkheid als nav/footer (zie boven).

## EmailJS via CDN — SRI-pinning (finding #10)

`@emailjs/browser@4/dist/email.min.js` (range-versie, geen integrity) is gepind
op `@4.4.1` mét `integrity="sha384-…"` + `crossorigin` + `referrerpolicy`, in
`index.html`, `shop.html` en `admin.php`. Reden: het script draait op de
checkout-pagina; zonder SRI zou een gecompromitteerde jsdelivr vreemde JS met
zicht op besteldata kunnen injecteren. LET OP bij upgraden: de exacte versie in
de URL én de hash moeten samen mee — een kale `@4` mét integrity breekt zodra
jsdelivr een nieuwe 4.x publiceert (hash-mismatch → script geblokkeerd → checkout
stuk). Nieuwe hash: `curl -sL <url> | openssl dgst -sha384 -binary | openssl base64 -A`.

## Sessie-start gecentraliseerd — includes/session.php (finding #11)

Het `session_set_cookie_params([...]) + session_start()`-blok stond **10× gekopieerd**
(admin, account, place-order, api/auth, checkout_csrf, healthz, upload, stock_notify,
sync, reorganize) en was gedrift: 3 verschillende `secure`-checks (o.a. het foute
`isset($_SERVER['HTTPS'])` → true bij `HTTPS='off'` → Secure over HTTP → sessie stuk
achter een TLS-proxy) en `samesite` Strict/Lax door elkaar op dezelfde `PHPSESSID`.
Nu één `kits_session_start(string $sameSite='Lax', ?int $gcMaxlifetime=null)`:
- `secure` via `kits_request_is_https()` — checkt `$_SERVER['HTTPS']` én
  `X-Forwarded-Proto` (reverse-proxy TLS, spiegelt de .htaccess-proxyregel).
- **SameSite overal `Lax`** (was mixed): blokkeert cross-site POST (CSRF-vector) én
  houdt login-redirects werkend; de echte CSRF-verdediging blijft de tokens. Admin/
  healthz/upload/sync gingen van Strict → Lax zodat één gedeelde cookie één policy heeft.
- `gc_maxlifetime` wordt nu **vóór** `session_start()` gezet; in place-order.php en
  checkout_csrf.php stond het erná = no-op (stille bug), nu gefixt.
Live geverifieerd: Set-Cookie op alle endpoints = `HttpOnly; SameSite=Lax`, geen Secure
over HTTP; login/checkout-CSRF/upload-403 werken.

## PDO-verbinding gecentraliseerd — includes/db.php (finding #12)

`new PDO(...)` stond in **14 bestanden** en was gedrift: 6 misten
`PDO::ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC` (kregen stil `FETCH_BOTH`). Geverifieerd
dat geen van die 6 numerieke rij-indexen gebruikt vóór het gelijktrekken. Nu één
`kits_pdo(array $cfg, bool $withDb=true)` (DSN + `ERRMODE_EXCEPTION` + `FETCH_ASSOC`);
`$withDb=false` voor healthz' bare server/login-probe zonder dbname. `db.php` wordt
via `config.php` geladen (elk endpoint laadt config toch), dus geen 14 losse requires.
Aanroepers houden hun eigen try/catch. admin.php's dode `$DB`-array verwijderd.

## Cookies & toestemming — geen banner, wél transparantie (finding #13)

Onderzocht wat de site opslaat: `PHPSESSID` (login/cart/CSRF = strikt noodzakelijk) +
localStorage (`kbe_cart_main`, wishlist, coupon, promo-popup-flag = functioneel). Geen
analytics/pixels; Snapchat/TikTok in de footer zijn gewone profiel-links. Onder
ePrivacy 5.3 + NL-cookiewet zijn functionele cookies/opslag vrijgesteld → **geen
toestemmingsbanner vereist**. Openstaand (aanbevolen, nog te doen): (A) Google Fonts
**self-hosten** (nu 3rd-party IP-verzending naar Google — LG München 2022), (B) korte
**privacyverklaring** in de footer (AVG art. 13 transparantie). Pas bij toekomstige
analytics/marketing is een echte opt-in-banner (scripts laden ná consent) nodig.

## Naamconventie: kits_asset (finding #9 vervolg)

De asset-helper heet nu `kits_asset()` (was `kbe_asset()`) — consistent met de rest
van de codebase (`kits_pdo`, `kits_session_start`, `kits_env`, `kits_log`, …).

## JSON-endpoints: error-guard gecentraliseerd — includes/json_guard.php (finding #14)

Het blok `ini_set('display_errors','0') + display_startup_errors + error_reporting(E_ALL) + ob_start()`
stond identiek bovenaan `place-order.php`, `api/auth.php` en `api/checkout_csrf.php`
(display_errors uit zodat PHP-warnings de JSON niet corrumperen; error_log blijft
vol). Nu één `kits_json_guard()` in `includes/json_guard.php`, als állereerste
require aangeroepen (vóór andere requires, want een require die warnt zou anders de
JSON al breken). Wil je een endpoint lokaal debuggen: zet `display_errors` op `'1'`
op die ene plek. `admin.php` (regel ~100) houdt z'n eigen inline-variant — die zit
in een andere context (de AJAX-tak van de monoliet) en wordt meegenomen bij de
admin-split, niet nu. Live geverifieerd: checkout_csrf/auth/place-order geven alle
drie geldige JSON, geen PHP-output gelekt.

## admin.php: restock-debuglogs verwijderd (finding #15)

`sendRestockNotifications()` had 6 achtergebleven `console.log('[restock] …')`-regels
van tijdens het debuggen; één logde klant-e-mailadressen (`n.email`) naar de
browserconsole. Admin-only (achter login) dus geen datalek, maar rommelig + PII in
console → verwijderd. De `console.error`-regels in de catch-blokken blijven: dat is
echte foutdiagnostiek voor de admin als een mail faalt.

## Canonical-domein: nooit hardcoden (finding #16)

`index.html` + `shop.html` hadden het domein 8× hardcoded als
`https://kitsbyelba.com` (één a, .com) in `<link canonical>`, `og:url` en de
JSON-LD `url`/`target`-velden — terwijl de live site en de per-categorie
canonicals van shop.html uit `config.php` (`public_site_url` ← `.env`) komen:
`https://kitsbyelbaa.nl` (twee a's, .nl). Crawlers zonder JS kregen dus een
canonical naar een verkeerd domein. Nu haalt óók index.html z'n base uit
`$__base = rtrim($cfg['public_site_url'], '/')` (zelfde patroon als shop.html) en
staat het domein nergens meer hardcoded. De client-side `applySeoFromConfig()`
gebruikte al `CONFIG.publicSiteUrl` (uit `api/config.php`, r.38) → server en
client komen nu overeen (was voorheen server=.com, client=.nl = mismatch/flash).
Geverifieerd: `curl` op index/shop/shirts geeft overal `kitsbyelbaa.nl`, `php -l`
schoon, `audit-seo.mjs` = SEO_DISTINCT.

Aanpalend opgeruimd: root `hero-1.jpg`/`hero-2.jpg` waren byte-identieke,
nergens gerefereerde duplicaten van `images/hero-*.jpg` (de code laadt alleen de
`images/`-versies) → verwijderd met `git rm`.

## SEO: sitemap + robots + Product-brand (finding #17)

- **`sitemap.xml` verwijderd**: stale, hardcoded oude `.com`-domein, maar 3 URLs.
  Botste met de dynamische `sitemap.php` (config-driven, incl. producten uit DB).
- **`sitemap.php`**: categorie-landingspagina's toegevoegd (shirts/sets/hemdsetjes/
  retro/kids/voorraad) — dé SEO-landingspagina's, stonden er niet in. `account.php`
  eruit (login, geen SEO-waarde). `<priority>` + realistische `changefreq` per URL.
- **`robots.txt`**: `Sitemap:` nu absolute URL (Google-eis) i.p.v. relatief pad.
- **`product.php`**: Product-JSON-LD heeft nu ook `brand` (KitsByElbaa) naast
  price/availability/image → sterker signaal voor rich results.

Nog OPEN (grotere klussen, bewust niet nu): categorie-grids renderen producten
pas via JS (`fetch api/products.php` → leeg `#grid` in kale HTML) = crawler ziet
lege categorie; `product.php` H1 = "Laden…" tot JS draait; categorie-pagina's
hebben geen beschrijvende introtekst (dunne content). Zie chat-actieplan.

## Pre-deploy: security-review + keyword-content (finding #18)

Vóór de eerste echte deploy naar Hostinger (kitsbyelbaa.nl) een gestructureerde
pass gedaan met een gates-ledger (`GATES.md`, 12 gates, allemaal MET met bewijs).

**Security (G1 handmatig + G2 scanner).** Gevoelige endpoints herbeoordeeld —
`place-order.php` (prijzen server-side, prepared statements, CSRF, rate-limit,
honeypot, idempotency, coupon geclamped, voorraad FOR UPDATE), `api/auth.php`
(CSRF, lockout, timing-safe, bcrypt 12, session_regenerate_id, GDPR-erasure),
`api/upload.php` (admin+CSRF, finfo-MIME, random naam, GD re-encode),
`api/coupon_validate.php`. Geen exploiteerbare issue. `tools/audit-security-scan.mjs`
scant op eval/shell/SQL-concat/echo-superglobal mét positieve controle → 0.
Niet-blokkerend: coupons zonder max_uses/expiry (korting is gecapt).

**Content/SEO (G3–G9).** Server-side keyword-tekst toegevoegd zodat crawlers
(zonder JS) echte inhoud zien i.p.v. een leeg JS-grid:
- `shop.html`: `$__seoMap` uitgebreid met per-categorie lede (#shopHeroLede) +
  tekstblok onder het grid (>=120 woorden/categorie), plus keyword-titels/-descriptions.
  JS-`pageMap` gespiegeld (finding #6: server==client, anders overschrijft JS).
- `index.html`: keyword-tekstsectie + titel/desc/og/twitter aangescherpt op
  "voetbalshirts met naam & nummer".
- `.seo-content` styling in `css/pages-shared.css` (gedeeld, on-brand).
- Alt-teksten: product-hoofdfoto (`product.php`) + cart-thumb; card-template
  gebruikte al productnaam. League-logo's houden bewust leeg alt (zichtbaar label
  ernaast → WCAG: anders dubbel voorgelezen).

**Verificatie.** `tools/audit-seo-content.mjs <check>` (sitemap/robots/categories/
meta/contentblock/home/alt/phplint/http/nodotcom) draait tegen de lokale render.
Alle bestaande audits (syntax/responsive/seo/zindex/dedup/inline) blijven groen.

LET OP: dit staat nu alleen LOKAAL. Live (kitsbyelbaa.nl) draaide bij deze commit
nog de oude build (titel "Premium voetbaltenues online", canonical .com). Moet
gedeployed worden naar Hostinger voordat Google er iets van ziet.

## Hero-achtergrond herzien (finding #19)

De homepage-hero had een vage grijzige spookfoto (`images/hero-1.jpg` op
opacity .18 + `mix-blend-mode:luminosity` + grayscale) → modderig/"slop"-gevoel.
Vervangen door een **zichtbare shirt-muur**: dezelfde foto scherp (geen blend/
grayscale) op opacity .55, `background-position:center right`, met een donkere
`linear-gradient`-sluier links (`.hero::before`, 90deg) zodat kop+CTA leesbaar
blijven, plus de bestaande gouden gloed (`.hero::after`). Mobiel (`@media
max-width:960px`) krijgt een sterkere verticale sluier zodat de gestapelde tekst
boven de foto leesbaar blijft. Alleen inline-CSS in `index.html` (.hero-blok);
`images/hero-1.jpg` blijft de bron (root-duplicaten waren al weg, finding #16).

## css/app.css — z-index schaal (finding #4)

Alle globale overlay-`z-index`-waarden lopen nu via semantische tokens in
`:root` (`--z-nav`, `--z-cart`, `--z-modal`, `--z-toast`, …). De waarden
behouden de bestaande stapelvolgorde. Kleine component-interne stacking (0–25)
blijft bewust rauw. Eén normalisatie: de toast op `product.php` stond op 9000
en is teruggebracht naar `--z-toast` (700, boven alle overlays).
