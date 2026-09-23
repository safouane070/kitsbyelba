<?php
declare(strict_types=1);
$cfg = require __DIR__ . '/config.php';
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$publicBase = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
$seoTitle = 'Voetbaltenue | KitsByElbaa';
$seoDesc = 'Voetbalshirts en tenues bij KitsByElbaa. Snelle levering, veilig afrekenen en bedrukking met naam & nummer.';
$seoImage = '';
$seoCanonical = $publicBase !== '' ? $publicBase . '/product.php' : '';
$seoPrice = null;
$seoName = '';
$slugQ = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
$pdpId = 0;
if ($slugQ !== '' && preg_match('/^(\d+)/', $slugQ, $m)) { $pdpId = (int)$m[1]; }
elseif (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) { $pdpId = (int)$_GET['id']; }
$contactEmail = 'KitsByElbaa@outlook.com';
$pdpWaDigits = preg_replace('/\D/', '', (string)($cfg['whatsapp'] ?? '31684446255')) ?: '31684446255';
$pdpWaDisplay = '+31 6 84446255';
$pdpWaHref = $pdpWaDigits !== '' ? 'https://wa.me/' . $pdpWaDigits : '';

if ($pdpId > 0) {
    try {
        $pdoSeo = kits_pdo($cfg);
        $st = $pdoSeo->prepare('SELECT id, name, description, image, price FROM products WHERE id = ? AND active = 1 LIMIT 1');
        $st->execute([$pdpId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $seoName = (string)$row['name'];
            $seoTitle = $seoName . ' | KitsByElbaa';
            $plain = trim(strip_tags((string)($row['description'] ?? '')));
            if ($plain !== '') {
                $seoDesc = mb_strlen($plain) > 160 ? mb_substr($plain, 0, 157) . '…' : $plain;
            }
            $img = trim((string)($row['image'] ?? ''));
            if ($img !== '') {
                if (preg_match('#^https?://#i', $img)) {
                    $seoImage = $img;
                } elseif ($publicBase !== '') {
                    $seoImage = $publicBase . '/' . ltrim(str_replace('\\', '/', $img), '/');
                }
            }
            if ($publicBase !== '') {
                // Canonical altijd de nette slug-URL (ook als men via ?id= binnenkomt) → geen dubbele URL's voor 1 product.
                $__pslug = $pdpId . '-' . trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($seoName)), '-');
                $seoCanonical = $publicBase . '/product.php?slug=' . rawurlencode($__pslug);
            }
            $seoPrice = (float)$row['price'];
        }
    } catch (Throwable $e) {
        $pdpDbError = true; // DB tijdelijk weg: geen 404 geven, pagina probeert het client-side
    }
}
// Onbekend of uitgeschakeld product: echte 404 + noindex, zodat Google oude links opruimt
// (voorheen 200 met het eerste product uit de lijst → verkeerd shirt + dubbele content).
$pdpGone = $seoName === '' && empty($pdpDbError);
if ($pdpGone) {
    http_response_code(404);
    $seoTitle = 'Shirt niet meer beschikbaar | KitsByElbaa';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="images/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="images/apple-touch-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f9faf7">
<title><?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?></title>
<?php if ($pdpGone): ?><meta name="robots" content="noindex"><?php endif; ?>
<meta name="description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($seoCanonical !== ''): ?>
<link rel="canonical" href="<?= htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="product">
<meta property="og:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($seoImage !== ''): ?><meta property="og:image" content="<?= htmlspecialchars($seoImage, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php if ($seoName !== '' && $seoPrice !== null && $seoCanonical !== ''): ?>
<?php
$ldProduct = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $seoName,
    'description' => $seoDesc,
    'brand' => ['@type' => 'Brand', 'name' => 'KitsByElbaa'],
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => 'EUR',
        'price' => round($seoPrice, 2),
        'availability' => 'https://schema.org/InStock',
        'url' => $seoCanonical,
    ],
];
if ($seoImage !== '') {
    $ldProduct['image'] = [$seoImage];
}
?>
<script type="application/ld+json"><?= json_encode($ldProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<link rel="preload" href="fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/space-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
<?php require_once __DIR__ . '/includes/asset.php'; ?><link rel="stylesheet" href="<?= kits_asset('css/fonts.css') ?>">
<?php require_once __DIR__ . '/includes/asset.php'; ?>
<link rel="stylesheet" href="<?= kits_asset('css/product.css') ?>">
<link rel="stylesheet" href="<?= kits_asset('css/app.css') ?>">
<link rel="stylesheet" href="<?= kits_asset('css/responsive-global.css') ?>">
<script defer src="<?= kits_asset('js/kbe-ios-helpers.js') ?>"></script>
</head>
<body>
<?php include __DIR__ . '/includes/announce.php'; ?>
<?php $navCartOnclick = 'openPdpCart()'; $navCartNumId = 'cartNProduct'; $navSearch = true; include __DIR__ . '/includes/nav.php'; ?>
<main id="main">
<?php include __DIR__ . '/includes/nav-search.php'; ?>
<div class="wrap">
  <div class="top"><a class="back" id="backLink" href="index.html#shop">← Terug naar shop</a></div>

  <div class="grid">
    <div class="gallery">
      <div class="main-img-box" id="mainBox">
        <button type="button" class="garr prev" onclick="shiftImg(-1)" aria-label="Vorige productafbeelding">‹</button>
        <img class="main-img" id="mainImg" alt="<?= htmlspecialchars($seoName !== '' ? $seoName . ' — voetbalshirt' : 'Voetbalshirt', ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="garr next" onclick="shiftImg(1)" aria-label="Volgende productafbeelding">›</button>
        <button type="button" class="pdp-wish-btn" id="pdpWishBtn" onclick="togglePdpWishlist()" aria-pressed="false" aria-label="Toevoegen aan wishlist" title="Wishlist">♥</button>
      </div>
      <p class="pdp-wish-fb" id="pdpWishFb" hidden></p>
      <div class="thumbs" id="thumbs"></div>
    </div>
    <div class="info">
      <h1 id="pName">Laden…</h1>
      <div class="price" id="pPrice">€0,00</div>
      <p class="stock-note" id="pStock">Op voorraad</p>
      <p id="pdpLeverNote" class="pdp-lever-note" hidden></p>
      <div class="pdp-trust-row" aria-label="Levering en service">
        <span>Verzending vanaf €<?= (int)$cfg['free_shipping_from'] ?> gratis (NL)</span>
        <span>·</span>
        <a href="index.html#faq">Veelgestelde vragen</a>
        <?php if ($pdpWaHref !== ''): ?>
        <span>·</span>
        <a href="<?php echo htmlspecialchars($pdpWaHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">WhatsApp</a>
        <?php endif; ?>
      </div>

      <div class="lbl" id="versieLbl" hidden>Versie</div>
      <div class="versie-row" id="versieRow" hidden></div>
      <div class="lbl">Maat</div>
      <div class="sizes" id="sizes"></div>
      <div id="kidsJerseyChartPdp" class="kids-jersey-pdp" hidden>
        <div class="maten-table-wrap">
          <div class="kids-maten-banner">
            <h3 class="kids-maten-title">Maattabel kids jersey</h3>
            <p class="kids-maten-remark">Let op: door rek in de stof kan de afwijking ongeveer 1–2 cm zijn.</p>
          </div>
          <div class="kids-maten-scroll">
            <table class="maten-table" aria-label="Maattabel">
              <thead>
                <tr>
                  <th>Maat</th>
                  <th>16</th><th>18</th><th>20</th><th>22</th><th>24</th><th>26</th><th>28</th>
                </tr>
              </thead>
              <tbody>
                <tr><td>Leeftijd</td><td>2-3</td><td>4-5</td><td>5-6</td><td>7-8</td><td>8-9</td><td>10-11</td><td>12-13</td></tr>
                <tr><td>Lichaamslengte (cm)</td><td>95-105</td><td>105-115</td><td>115-125</td><td>125-135</td><td>135-145</td><td>145-155</td><td>155-165</td></tr>
                <tr><td>Lengte shirt (cm)</td><td>44</td><td>47</td><td>50</td><td>53</td><td>56</td><td>59</td><td>62</td></tr>
                <tr><td>½ borst (cm)</td><td>35</td><td>37</td><td>39</td><td>41</td><td>43</td><td>45</td><td>47</td></tr>
                <tr><td>Lengte short (cm)</td><td>32</td><td>34</td><td>36</td><td>38</td><td>39</td><td>40</td><td>43</td></tr>
                <tr><td>½ taille (cm)</td><td>20-37</td><td>21-39</td><td>22-41</td><td>23-42</td><td>24-44</td><td>25-47</td><td>26-50</td></tr>
              </tbody>
            </table>
            <p class="maten-scroll-hint" aria-hidden="true">← Veeg voor alle maten →</p>
          </div>
        </div>
      </div>
      <div class="stock-notify-panel" id="stockNotifyPanel">
        <div class="stock-notify-hint" id="stockNotifyHint"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="email" class="inp" id="stockNotifyEmail" placeholder="jij@voorbeeld.nl" autocomplete="email" style="flex:1;min-width:200px">
          <button type="button" class="cta" style="padding:10px 18px;font-size:12px" onclick="submitStockNotify()">Melding sturen</button>
        </div>
        <p class="stock-notify-fb" id="stockNotifyFb"></p>
      </div>

      <label class="lbl" for="printName">Naam op de rug (max. 15 tekens)</label>
      <input class="inp" id="printName" placeholder="bijv. Palmer" maxlength="15" autocomplete="off">
      <label class="lbl" for="printNumber">Nummer (max. 4 cijfers)</label>
      <input class="inp" id="printNumber" placeholder="bijv. 10" maxlength="4" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
      <label class="lbl" for="printBadges">Badges (optioneel)</label>
      <select class="inp" id="printBadges">
        <option value="">Geen badge</option>
      </select>

      <div class="qtyrow">
        <div class="qty"><button type="button" onclick="chgQty(-1)" aria-label="Aantal verlagen">−</button><span id="qtyV">1</span><button type="button" onclick="chgQty(1)" aria-label="Aantal verhogen">+</button></div>
        <button type="button" class="cta" id="addBtn" onclick="addToCart()">In winkelwagen</button>
      </div>
      <div class="small" id="addMsg">Bedrukking (naam en/of nummer): <strong>één</strong> meerprijs van €<?= number_format((float)$cfg['custom_printing_price'],2,',','') ?> per shirt. Badge/patch (indien gekozen): +€<?= number_format((float)($cfg['badge_extra_price'] ?? 3),2,',','') ?> per shirt. Gratis verzending vanaf €<?= (int)$cfg['free_shipping_from'] ?>.</div>
    </div>
  </div>

  <div class="sections">
    <div class="sec"><h3>Productomschrijving</h3><p id="sDesc">—</p></div>
    <div class="sec"><h3>Pasvorm</h3><p id="sFit">—</p></div>
    <div class="sec"><h3>Maatadvies</h3><p id="sSizeAdvice">—</p></div>
    <div class="sec"><h3>Materiaal</h3><p id="sMaterial">—</p></div>
    <div class="sec"><h3>Verzending</h3><p id="sShipping">—</p></div>
    <div class="sec"><h3>Verzorging</h3><p id="sCare">—</p></div>
  </div>

  <div class="reco">
    <h2 class="reco-h">Misschien ook leuk</h2>
    <div class="reco-strip">
      <button type="button" class="reco-arr reco-arr-prev" id="recoPrev" aria-label="Vorige producten">‹</button>
      <div class="reco-viewport" id="recoViewport">
        <div class="reco-track" id="reco"></div>
      </div>
      <button type="button" class="reco-arr reco-arr-next" id="recoNext" aria-label="Volgende producten">›</button>
    </div>
  </div>
</div>

<div class="sticky">
  <button type="button" class="cta" id="stickyAddBtn" onclick="document.getElementById('addBtn').click()">In winkelwagen</button>
  <button type="button" class="cta cta-wa" id="stickyWaBtn" onclick="openStickyWhatsApp()"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>WhatsApp</button>
</div>

<div class="pdp-cart-bg" id="pcbg" onclick="closePdpCart()"></div>
<aside class="pdp-cart" id="pcart">
  <div class="pdp-cart-head">
    <span class="pdp-cart-title" id="pcartTitle">Mijn artikelen • 0</span>
    <button type="button" class="pdp-cart-close" onclick="closePdpCart()" aria-label="Sluiten">✕</button>
  </div>
  <div class="pdp-cart-body" id="pcartBody"><div class="pdp-empty">Je winkelwagen is leeg.<br><button type="button" class="pdp-empty-cta" onclick="closePdpCart()">Terug naar winkelen</button></div></div>
  <div class="pdp-cart-foot">
    <div class="pdp-coupon">
      <div class="pdp-coupon-row">
        <input id="pcCouponInput" placeholder="Kortingscode">
        <button type="button" onclick="applyPdpCoupon()">Toepassen</button>
      </div>
      <div class="pdp-coupon-fb" id="pcCouponFb"></div>
      <button type="button" id="pcCouponRemoveBtn" class="pdp-coupon-remove" onclick="removePdpCoupon()">Korting wijzigen of verwijderen</button>
    </div>
    <div class="pdp-discount" id="pcDiscount" style="display:none"><span id="pcDiscountLabel">Korting</span><span id="pcDiscountAmt">-€0,00</span></div>
    <div class="pdp-total"><span>Totaal</span><span id="pcartTotal">€0,00</span></div>
    <button type="button" class="pdp-check" onclick="goToCart()" style="display:inline-flex;align-items:center;justify-content:center;gap:8px"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>Naar afrekenen</button>
    <button type="button" class="pdp-note" onclick="closePdpCart()" style="background:none;border:none;cursor:pointer;width:100%;padding:10px 0;font-family:inherit;font-size:12px;color:#888;font-weight:700">← Verder winkelen</button>
  </div>
</aside>

<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button type="button" class="lb-arr prev" onclick="event.stopPropagation();shiftImg(-1);openLightbox()" aria-label="Vorige afbeelding">‹</button>
  <button type="button" class="lb-arr next" onclick="event.stopPropagation();shiftImg(1);openLightbox()" aria-label="Volgende afbeelding">›</button>
  <button type="button" class="close" onclick="closeLightbox();event.stopPropagation()" aria-label="Lightbox sluiten">✕</button>
  <img id="lbImg" alt="Vergrote afbeelding">
</div>

</main>
<?php include __DIR__ . '/includes/footer.php'; // shared 4-column footer, identical to the rest of the site ?>

<script>
let CONFIG = {
  whatsapp:'<?= htmlspecialchars($pdpWaDigits, ENT_QUOTES, 'UTF-8') ?>',
  customPrintingPrice: <?= (float)$cfg['custom_printing_price'] ?>,
  badgeExtraPrice: <?= (float)($cfg['badge_extra_price'] ?? 3) ?>
};
</script>
<script src="<?= kits_asset('js/product-page.js') ?>"></script>
<script src="<?= kits_asset('js/nav-search.js') ?>"></script>
</body>
</html>









