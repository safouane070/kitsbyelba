# Overdracht: admin.php opsplitsen

> Dit document is bedoeld om in een **nieuwe chat** te plakken/aanwijzen. Het is
> zelfstandig: een koude sessie kan hiermee direct aan de slag. Branch:
> `redesign/ux-cleanup`. Repo draait op XAMPP/Apache/Windows, `php` = `C:\xampp\php\php.exe`.

## Waarom
`admin.php` is ±3.625 regels in één bestand: PHP-bootstrap + een 22-cases AJAX-router
+ alle HTML + ±1.830 regels inline JS. Het werkt en is veilig, maar is zwaar te
onderhouden en de inline JS is niet cachebaar (laadt bij elke adminpagina opnieuw).
Doel: opsplitsen **zonder gedragsverandering**, in fases die elk los te testen zijn.

## Structuurkaart (regelnummers bij aanvang — verschuiven tijdens het werk)

| Regels        | Wat                                                                 |
|---------------|---------------------------------------------------------------------|
| 1–110         | Bootstrap: `kits_session_start`, security headers, `$cfg`, EmailJS-defines, admin-IP-guard, logout, brute-force, login, `kits_pdo` + schema-ensures, AJAX-body parse + **CSRF-gate** |
| 111–±627      | `switch ($act)` — **22 AJAX-cases** (de router). ±516 regels PHP    |
| 629–±1731     | HTML: login-form + dashboard-markup; mini-inline-`<script>` op 1147–1153 en 1160 |
| ±1732–3610    | Eén grote inline `<script>` — **alle admin-JS** (±1.878 regels)     |
| 3610–einde    | Afsluitende HTML                                                    |

De 22 cases (regel-ankers bij aanvang): `stats`(113), `orders`(122), `order_detail`(132),
`update_status`(150), `low_stock`(189), `products`(228), `save_product`(234),
`duplicate_product`(341), `set_cat_cover`(393), `save_tracking`(407), `quick_restock`(417),
`toggle_voorraad`(449), `get_stock_notifications`(457), `clear_stock_notifications`(470),
`clear_stock_notification_row`(481), `save_order_note`(494), `delete_product`(508),
`coupons`(534), `save_coupon`(549), `delete_coupon`(577), `site_settings`(583),
`save_site_settings`(588).

## Belangrijke koppelingen (hier zit het risico)
- **Frontend → backend** loopt via `api()` op regel ±1789:
  `fetch('admin.php', { headers: {'X-Action': action, 'X-CSRF-Token': CSRF_TOKEN}, body: JSON })`.
  De actie zit in de **`X-Action`-header** (fallback: `body.action`). Zolang de router
  in `admin.php` blijft antwoorden, hoeft deze URL **niet** te wijzigen.
- **Upload** gaat apart naar `api/upload.php` (regel ±3262) — buiten scope, niet aanraken.
- **9 server→JS-injecties** bovenaan het JS-blok (regels ±1733–1781), allemaal
  `<?= json_encode(...) ?>`: `KBE_EMAILJS_PUBLIC_KEY`, `EJSVC_ORDER`, `EJSVC_RESTOCK`,
  `EJTPL`, `CSRF_TOKEN`, `productsCache` (`$_allProducts`), `ADMIN_NOTIFY_EMAIL`,
  `EMAIL_PUBLIC_BASE`, `EMAILJS_RESTOCK_TPL`. **Dit is de enige plek waar PHP in de JS zit.**
  Alles ná ±1782 is zuivere JS zonder PHP-tags.
- CSRF-gate (`hash_equals($_SESSION['csrf_token'], X-CSRF-Token)`) staat vóór de switch
  (regel ±105) en moet dat blijven, waar de router ook heen gaat.

---

## Fase A — inline JS naar `js/admin.js` (laag risico, doe dit eerst)
Grootste winst, kleinste kans op stukgaan. **Geen URL-wijziging nodig.**

1. Houd bovenaan een klein inline-`<script>` met **alleen** de 9 server-injecties
   (de `<?= json_encode ?>`-regels). Zet ze bij voorkeur op één object om het net te
   houden, bv. `window.KITS_ADMIN = { csrf: <?=…?>, publicKey: <?=…?>, products: <?=…?>, … }`;
   of laat ze als top-level `const`/`let` staan als je in `admin.js` niet wilt herschrijven.
2. Verplaats de rest van het JS-blok (±1782–3610) naar `js/admin.js`.
   Als je stap 1 als losse consts liet: `admin.js` gebruikt die globals gewoon (ze staan
   eerder in de global scope). Koos je het object: vervang de verwijzingen in `admin.js`.
3. Laad met cache-busting, ná de bootstrap-consts:
   `<script defer src="<?= kits_asset('js/admin.js') ?>"></script>`
   (`kits_asset()` zit al in `includes/asset.php`; `admin.php` laadt dat via `config.php`/nav —
   check even of het beschikbaar is, anders `require_once __DIR__.'/includes/asset.php';`).
4. **Let op `defer` + volgorde:** de bootstrap-consts moeten vóór `admin.js` in de DOM
   staan én `admin.js` mag pas draaien als de DOM er is — `defer` regelt het tweede.
   EmailJS (regel 635) staat al met `defer` erboven; behoud die volgorde.

**Verificatie A:** open `admin.php`, log in, en klik door élke tab (orders, producten,
voorraad, coupons, instellingen). Test minstens één van elk: order-status wijzigen,
product opslaan, quick-restock (mail!), coupon opslaan, site-settings opslaan. Console
moet leeg zijn (0 errors). `curl` de acties niet nodig — het is dezelfde endpoint.

## Fase B — router naar `includes/admin_router.php` (midden risico)
Nu `admin.php` puur bootstrap + HTML is, verhuist de `switch`.

1. Knip het blok `if ($auth && $__adminAction !== '') { … switch($act){…} }` (regels
   ±99–627) naar `includes/admin_router.php`. Roep het aan op exact dezelfde plek:
   `require __DIR__ . '/includes/admin_router.php';` — die file gebruikt `$pdo`, `$cfg`,
   `$d`/`$act`, `$_SESSION`. **URL blijft `admin.php`**, dus de frontend `api()` verandert niet.
2. (Optioneel, later) splits de 22 cases naar `api/admin/{action}.php` met een dun
   dispatch-tabel (`$handlers = ['stats' => …]`). Alleen doen als B1 stabiel is; elke
   case heeft eigen SQL en soms eigen validatie — één voor één verplaatsen + testen.

**Verificatie B:** zelfde klik-door-alles als A, plus `git stash`-diff-check dat de
CSRF-gate nog vóór dispatch draait. Test bewust een **fout CSRF-token** (moet 403 geven)
en een **niet-ingelogde** AJAX-call (moet niets doen / login tonen).

## Fase C — HTML-template (optioneel, laagste prioriteit)
Dashboard-markup (629–1731) naar `includes/admin_view.php`. Puur cosmetisch voor de
bestandsgrootte; alleen doen als A+B bevallen.

---

## Harde regels tijdens het werk
- **CRLF behouden.** `admin.php` staat als CRLF in git. Bewerk met Edit-tool (regel-exact),
  niet met `sed`/Python die EOL flippen — anders krijg je een 3.600-regel-nepdiff.
  Nieuwe files (`js/admin.js`, `includes/admin_router.php`) mogen LF zijn (rest van de
  codebase is LF); check `.gitattributes`/`core.autocrlf` gedrag met een kleine testdiff.
- **`php -l` na elke stap:** `C:\xampp\php\php.exe -l admin.php` (+ nieuwe files).
- **Audits draaien:** `node tools/audit-syntax.mjs` en de andere `tools/audit-*.mjs` moeten
  groen blijven. Voeg eventueel een admin-size-audit toe.
- **Niks aan gedrag veranderen.** Dit is een verhuizing, geen herontwerp. Als je een bug
  in een case ziet: noteer 'm, fix apart, niet meesmokkelen in de split.
- **Commit-conventie:** commits op naam `safouane070`, **geen** Co-Authored-By/Claude-trailer
  (`git commit -F <bestand> --no-verify`). Kleine logische commits per fase.
- Werk eventueel in een git worktree zodat de winkel-branch ongemoeid blijft.

## Kickoff-prompt voor de nieuwe chat
> Lees `docs/admin-split-plan.md`. Voer **Fase A** uit (inline JS uit admin.php naar
> js/admin.js, met de 9 server-injecties als inline bootstrap ervoor). Houd CRLF op
> admin.php, `php -l` + `tools/audit-*.mjs` groen, geen gedragsverandering. Commit op
> mijn naam zonder trailer. Daarna stoppen en mij laten testen vóór Fase B.
