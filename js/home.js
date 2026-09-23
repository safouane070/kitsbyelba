/* dedup-compat: globals + shims voor gedeelde core-functies (homepage = fan-versie, geen seizoen/versie-UI) */
let currentVersion = 'fan';
let currentSeason = null;
let bannerIdx = 0, bannerTimer = null;
function renderDrawerVersion(){}          /* geen versie-rij in homepage quick-view */
function applyLeagueFromUrl(){}           /* geen league-pills op homepage */
function applyCollectionPageMode(){}      /* homepage is geen collectie-pagina */
function updateShopPageHeaderByType(){}   /* homepage heeft eigen header */

// ── CONFIG filled from api/config.php (no EmailJS keys in page source) ──
let CONFIG = {
  whatsapp:         '31684446255',
  season:           '',
  freeShippingFrom: 0,
  shippingCost:     0,
  customPrintingPrice: 5,
  badgeExtraPrice: 3,
  emailjsPk:        '',
  emailjsService:   '',
  emailjsTemplate:  '',
  adminEmail:       '',
  publicSiteUrl:    '',
};

let PRODUCTS = [];
/** Geen ingebouwde productgrid op de homepage — #shop wijst naar categoriekaarten. */
const HOME_SKIP_EMBEDDED_SHOP = true;
let cart = [];
let wishlist = [];
/** Browser-terug (mobiel) sluit winkelwagen i.p.v. pagina te verlaten */
let __kbeCartHistory = 0;
let currentDetailId = null;
let currentSz = null; // no default — user must explicitly pick
let currentPrinting = 'none'; // none|custom
let currentDetailEmoji = '👕';
let currentGalleryImages = [];
let currentGalleryIndex = 0;
let currentFilterCat = 'all';
let currentSearchTerm = '';
let currentSortMode = 'featured';
let currentShopCardView = 'grid';
let navSearchSuggestions = [];
let navSearchActiveIndex = -1;
const NAV_SEARCH_POPULAR = ['Real Madrid', 'Barcelona', 'PSG', 'Retro', 'Kids', 'Manchester'];

// Vaste site-promo (banner/FAQ): 10% met KITSBYELBA. Extra acties in admin → tabel coupons + api/coupon_validate.php
let appliedCoupon = null;

function applyPromoTextsFromConfig() {
  if (CONFIG.promoBanner) {
    document.querySelectorAll('.announce-promo').forEach(el => { el.innerHTML = CONFIG.promoBanner; });
  }
  const faq = document.getElementById('faqPromoAnswer');
  if (faq && CONFIG.promoFaqAnswer) faq.innerHTML = CONFIG.promoFaqAnswer;
}
const CART_STORAGE_KEY = 'kbe_cart_main';
const COUPON_STORAGE_KEY = 'kbe_coupon_main';
const WISHLIST_STORAGE_KEY = 'kbe_wishlist_main';

// ── INIT ──
Promise.all([
  fetch('api/config.php').then(r => r.json()).catch(() => ({})),
  fetch('api/products.php').then(async r => {
    const ok = r.ok;
    const j = await r.json().catch(() => []);
    const arr = Array.isArray(j) ? j : [];
    window.__kbeProductsApiFailed = !ok;
    return arr;
  }).catch(() => { window.__kbeProductsApiFailed = true; return []; })
]).then(async ([cfg, products]) => {
  Object.assign(CONFIG, cfg);
  applyPromoTextsFromConfig();
  applySeoFromConfig('index.html');
  kbeEnsureEmailjsInit(CONFIG.emailjsPk);
  PRODUCTS = products;
  if (window.__kbeProductsApiFailed) {
    showToast('Producten laden lukt niet (server/database). Controleer de verbinding of probeer het zo opnieuw.');
  }
  restoreCartState();
  restoreWishlistState();
  recalcCartPrintingPrices();
  clampCartToStock();
  await restoreCouponState();
  applyDynamicStoreTexts();
  applyBannerImagesFromProducts();
  const pPrint = unitPrintingExtra();
  const pBadge = unitBadgeExtra();
  const printPriceEl = document.getElementById('printCustomPrice');
  const printHintEl = document.getElementById('printCustomPriceHint');
  const printBadgeHintEl = document.getElementById('printBadgePriceHint');
  const faqPrintEl = document.getElementById('faqPrintPrice');
  const faqBadgeEl = document.getElementById('faqBadgePrice');
  const priceStr = pPrint.toFixed(2).replace('.', ',');
  const badgeStr = pBadge.toFixed(2).replace('.', ',');
  if (printPriceEl) printPriceEl.textContent = priceStr;
  if (printHintEl) printHintEl.textContent = priceStr;
  if (printBadgeHintEl) printBadgeHintEl.textContent = badgeStr;
  if (faqPrintEl) faqPrintEl.textContent = priceStr;
  if (faqBadgeEl) faqBadgeEl.textContent = badgeStr;
  if (currentDetailId && currentPrinting === 'custom') {
    refreshDetailDrawerPrice();
  }
  const waF = document.getElementById('waFloat');
  if (waF) waF.href = 'https://wa.me/' + kbeWaDigits();
  renderHeroShots();
  renderHero();
  initShopFromUrl();
  populateCategoryImages();
  importPendingCartFromProductPage();
  updateCount();
  openProductFromUrl();
  const __qs = new URLSearchParams(window.location.search);
  if (__qs.get('checkout') === '1' && cart.length) {
    openModal();
    try { history.replaceState({}, '', location.pathname); } catch (_) {}
  } else if (__qs.get('openCart') === '1' && cart.length) {
    openCart();
  }
});

// Run immediately — does NOT need product data
initRevealAnimations();

// ── BACK TO TOP ──
(function(){
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'back-to-top';
  btn.title = 'Back to top';
  btn.innerHTML = '↑';
  btn.onclick = () => kbeScrollToTop();
  document.body.appendChild(btn);
  window.addEventListener('scroll', () => btn.classList.toggle('on', window.scrollY > 400), {passive:true});
})();

// ── HOME PRODUCT ALLOCATION ──
// One shared set so the same kit never shows twice on the homepage
// (hero shots, "Populaire tenues" grid, and category covers all draw from it).
const HOME_USED = new Set();
const homeHasImg = p => !!(p && (p.image || p.image2 || p.image3));
const homeIsStar = p => /#\s*\d/.test(String(p && p.name || '')); // player number = recognisable shirt
const homeMarkUsed = p => { if (p) HOME_USED.add(Number(p.id)); };
const homeUnused = p => p && !HOME_USED.has(Number(p.id));
const homeCountry = p => String(p && p.name || '').trim().split(/\s+/)[0].toLowerCase();
// Pick n items, preferring a different country/team each, topping up if needed.
function pickHomeDiverse(pool, n) {
  const out = [], seen = new Set();
  for (const p of pool) { const c = homeCountry(p); if (!seen.has(c)) { out.push(p); seen.add(c); } if (out.length >= n) break; }
  if (out.length < n) for (const p of pool) { if (!out.includes(p)) { out.push(p); if (out.length >= n) break; } }
  return out;
}

// ── HERO SHOTS (recognisable star shirts from the live catalogue) ──
function renderHeroShots() {
  const wrap = document.getElementById('heroShots');
  if (!wrap) return;
  const shirts = PRODUCTS.filter(p => homeHasImg(p) && detectProductType(p) === 'shirts');
  const stars = shirts.filter(homeIsStar);
  const pool = stars.length >= 4 ? stars : shirts;
  // Curated feature kits first (Brazil beige, Argentinië away, Zuid-Korea, Barcelona roze),
  // then fill any gap with diverse unused shirts so it still works if a pinned id is gone.
  const HERO_PIN = [513, 524, 528, 531];
  let pick = HERO_PIN.map(id => PRODUCTS.find(p => Number(p.id) === id && homeHasImg(p))).filter(Boolean);
  if (pick.length < 4) pick = pick.concat(pickHomeDiverse(pool.filter(p => homeUnused(p) && !pick.includes(p)), 4 - pick.length));
  pick = pick.slice(0, 4);
  if (!pick.length) { wrap.style.display = 'none'; return; }
  pick.forEach(homeMarkUsed);
  wrap.style.display = '';
  wrap.innerHTML = pick.map(p => {
    // Decoratieve showcase-tegels: geen badge en geen prijs — de "Populaire tenues"-grid hieronder draagt die info.
    return `<a class="hero-shot" href="${productHref(p)}" title="${esc(p.name)}" aria-label="${esc(p.name)}">
      <img src="${productThumbSrc(p.image || p.image2 || p.image3)}" data-full="${productImgSrc(p.image || p.image2 || p.image3)}" alt="${esc(p.name)}" loading="eager" fetchpriority="high" decoding="async" onerror="if(!this.dataset.fb){this.dataset.fb=1;this.src=this.getAttribute('data-full');}else{this.closest('.hero-shot').style.display='none';}">
      <span class="hero-shot-tag">${esc(p.name)}</span>
    </a>`;
  }).join('');
}

// Legacy banner-slider was replaced by the single hero; keep no-op stubs in case
// an old inline handler still references these.
function goBanner() {}
function moveBanner() {}

// ── RENDER HERO PRODUCT CARDS ──
function renderHero() {
  const el = document.getElementById('hero-grid');
  if (!el) return;
  // Prefer recognisable star shirts that aren't already shown elsewhere on the homepage.
  const fresh = PRODUCTS.filter(p => homeHasImg(p) && homeUnused(p));
  const freshStars = fresh.filter(homeIsStar);
  let featured = pickHomeDiverse(freshStars.length >= 4 ? freshStars : fresh, 4);
  if (!featured.length) featured = PRODUCTS.slice(0, 4);
  featured.forEach(homeMarkUsed);
  if (!featured.length) {
    const failed = !!window.__kbeProductsApiFailed;
    const msg = failed
      ? 'Uitgelichte tenues konden niet worden geladen (server of database). Vernieuw de pagina over een moment, of scroll naar de collectie hieronder.'
      : 'Er zijn nog geen actieve producten om hier te tonen. Controleer of de catalogus in de database staat en of producten op “actief” staan.';
    el.innerHTML = `<div class="hero-grid-empty"><p>${msg}</p><button type="button" class="btn-primary" onclick="location.href='shirts'">Naar shirts</button></div>`;
    return;
  }
  el.innerHTML = featured.map(p => `
    <a class="hcard" href="${productHref(p)}">
      <div class="hcard-img">
        ${(p.image || p.image2 || p.image3)
          ? `<img src="${productThumbSrc(p.image || p.image2 || p.image3)}" data-full="${productImgSrc(p.image || p.image2 || p.image3)}" alt="${esc(p.name)}" width="320" height="405" decoding="async" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover" onerror="thumbErr(this)">`
          : `<div class="pnoimg"><span class="icon" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5L5 20"/></svg></span><span>Foto volgt</span></div>`}
        ${['new','hot'].includes(String(p.badge || '').toLowerCase()) ? `<span class="hcard-badge ${String(p.badge).toLowerCase()}">${String(p.badge).toLowerCase() === 'new' ? 'Nieuw' : 'Populair'}</span>` : ''}
      </div>
      <div class="hcard-info">
        <div class="hcard-name">${esc(p.name)}</div>
        <div class="hcard-price">${eur(p.price)}</div>
      </div>
    </a>`).join('');
}

// ── RENDER PRODUCT GRID ──
let currentTypeFilter = 'shirts';
let currentVersionFilter = 'all';
let currentKitVariantFilter = 'all';
let currentVoorraadFilter = 'all'; // 'all', 'in_stock', 'out_of_stock'

function renderGrid(cat = currentFilterCat) {
  if (HOME_SKIP_EMBEDDED_SHOP) return;
  currentFilterCat = cat;
  const gridEl = document.getElementById('grid');
  if (!gridEl) return;
  gridEl.classList.add('fading');
  setTimeout(() => { _doRenderGrid(cat); gridEl.classList.remove('fading'); }, 150);
}

/** Tab-rail: Voorraad is actief bij “alle types + alleen op voorraad”. */
function syncShopCategoryTabsFromState() {
  const vTab = document.getElementById('ftab-voorraad');
  if (!vTab) return;
  const voorraadTabActive = currentVoorraadFilter === 'in_stock' && currentTypeFilter === 'all';
  document.querySelectorAll('#catTabs .ftab:not(.ftab-player)').forEach(b => b.classList.remove('on'));
  if (voorraadTabActive) {
    vTab.classList.add('on');
  } else {
    const labels = { all:'Alles', shirts:'Shirts', sets:'Sets', hemdsetjes:'Hemdsetjes', retro:'Retro', kids:'Kids' };
    const label = labels[currentTypeFilter] || 'Shirts';
    const btn = [...document.querySelectorAll('#catTabs .ftab')].find(b => b.textContent.trim() === label);
    if (btn) btn.classList.add('on');
  }
  const pv = document.getElementById('ftab-player');
  if (pv) pv.classList.toggle('on', currentVersionFilter === 'player');
}

function buildShopNoResultsTips(cat) {
  const tips = [];
  if (currentSearchTerm) tips.push(`Zoekterm <strong>“${esc(currentSearchTerm)}”</strong> wissen of aanpassen.`);
  if (currentTypeFilter !== 'all') tips.push('Probeer een andere <strong>type-tab</strong> (bijv. Alle tenues).');
  if (currentVersionFilter !== 'all') tips.push('Zet Fan/Player op <strong>alle</strong>.');
  if (cat !== 'all') tips.push('Kies <strong>Alle competities</strong> of een andere competitie.');
  if (currentKitVariantFilter !== 'all') tips.push('Zet kit-variant (thuis/uit) op <strong>alle</strong>.');
  if (currentVoorraadFilter !== 'all') tips.push('Zet het voorraadfilter op <strong>alle artikelen</strong> (of wissel tussen op/niet op voorraad).');
  if (!tips.length) tips.push('Gebruik <strong>Filters resetten</strong> om alles terug te zetten.');
  return '<ul class="no-results-tips">' + tips.map(t => `<li>${t}</li>`).join('') + '</ul>';
}

function updateLeaguePillCounts() {
  const leagueKeywords = {
    all: '',
    premier: 'premier',
    laliga: 'la liga',
    bundesliga: 'bundesliga',
    seriea: 'serie',
    ligue1: 'ligue',
    eredivisie: 'eredivisie',
    national: 'national'
  };
  let base = [...PRODUCTS];
  if (currentTypeFilter !== 'all') base = base.filter(p => detectProductType(p) === currentTypeFilter);
  if (currentVersionFilter !== 'all') base = base.filter(p => detectProductVersion(p) === currentVersionFilter);
  if (currentKitVariantFilter !== 'all') base = base.filter(p => detectKitVariant(p) === currentKitVariantFilter);
  if (currentVoorraadFilter === 'in_stock') base = base.filter(p => parseInt(p.in_voorraad, 10) === 1);
  else if (currentVoorraadFilter === 'out_of_stock') base = base.filter(p => parseInt(p.in_voorraad, 10) !== 1);
  if (currentSearchTerm) {
    base = base
      .map((p) => ({ p, score: getProductSearchScore(p, currentSearchTerm) }))
      .filter((x) => x.score > 0)
      .map((x) => x.p);
  }
  document.querySelectorAll('#leaguePills .fpill').forEach((pill) => {
    const oc = pill.getAttribute('onclick') || '';
    const m = oc.match(/doFilter\('([^']+)'/);
    const key = ((m && m[1]) || 'all').toLowerCase();
    const kw = leagueKeywords[key] || key;
    const count = key === 'all'
      ? base.length
      : base.filter(p => (p.cat || '').toLowerCase() === key || (p.league || '').toLowerCase().includes(kw)).length;
    if (!pill.dataset.baseLabel) pill.dataset.baseLabel = pill.textContent.replace(/\s\(\d+\)\s*$/, '').trim();
    pill.innerHTML = `${esc(pill.dataset.baseLabel)} <span class="fpill-count">(${count})</span>`;
  });
}

function _doRenderGrid(cat) {
  if (HOME_SKIP_EMBEDDED_SHOP) return;
  let list = [...PRODUCTS];

  // League keyword map for matching against p.league text
  const leagueKeywords = {
    premier:'premier', laliga:'la liga', bundesliga:'bundesliga',
    seriea:'serie', ligue1:'ligue', eredivisie:'eredivisie', national:'national'
  };

  // Apply type filter (shirts/sets/kids/…) from product naming.
  if (currentTypeFilter !== 'all') {
    list = list.filter(p => detectProductType(p) === currentTypeFilter);
  }

  // Apply version filter (fan/player)
  if (currentVersionFilter !== 'all') {
    list = list.filter(p => detectProductVersion(p) === currentVersionFilter);
  }

  // Apply league filter - matches p.cat OR p.league text
  if (cat !== 'all') {
    const kw = leagueKeywords[cat] || cat;
    list = list.filter(p =>
      (p.cat || '').toLowerCase() === cat ||
      (p.league || '').toLowerCase().includes(kw)
    );
  }

  if (currentKitVariantFilter !== 'all') {
    list = list.filter(p => detectKitVariant(p) === currentKitVariantFilter);
  }

  if (currentVoorraadFilter === 'in_stock') {
    list = list.filter(p => parseInt(p.in_voorraad, 10) === 1);
  } else if (currentVoorraadFilter === 'out_of_stock') {
    list = list.filter(p => parseInt(p.in_voorraad, 10) !== 1);
  }

  if (currentSearchTerm) {
    list = list
      .map((p) => ({ p, score: getProductSearchScore(p, currentSearchTerm) }))
      .filter((x) => x.score > 0)
      .sort((a, b) => b.score - a.score)
      .map((x) => x.p);
  }
  if (currentSortMode === 'price_asc') list.sort((a,b) => a.price - b.price);
  if (currentSortMode === 'price_desc') list.sort((a,b) => b.price - a.price);
  if (currentSortMode === 'name_asc') list.sort((a,b) => a.name.localeCompare(b.name));
  document.getElementById('shopCount').textContent = list.length + (list.length === 1 ? ' artikel' : ' artikelen');
  const titleEl = document.getElementById('shopTitle');
  if (titleEl) {
    const typeLabels = {all:'Alle tenues',shirts:'Shirts',sets:'Sets',hemdsetjes:'Hemdsetjes',retro:'Retro',kids:'Kids'};
    if (currentVoorraadFilter === 'in_stock') {
      titleEl.textContent = currentTypeFilter === 'all' ? 'Op voorraad' : (typeLabels[currentTypeFilter] + ' · op voorraad');
    } else if (currentVoorraadFilter === 'out_of_stock') {
      titleEl.textContent = currentTypeFilter === 'all' ? 'Niet op voorraad' : (typeLabels[currentTypeFilter] + ' · niet op voorraad');
    } else {
      titleEl.textContent = typeLabels[currentTypeFilter] || 'Alle tenues';
    }
  }
  if (!list.length) {
    const hasSearch = !!currentSearchTerm;
    document.getElementById('grid').innerHTML = `
      <div class="no-results">
        <strong>${hasSearch ? `Geen resultaten voor “${esc(currentSearchTerm)}”` : 'Geen tenues gevonden'}</strong>
        <p>${hasSearch ? 'Probeer een andere club, land of competitie.' : 'Pas je filters aan — tips hieronder.'}</p>
        ${buildShopNoResultsTips(cat)}
        ${hasSearch ? navSearchEmptyHtml(currentSearchTerm, kbeWaDigits()) : ''}${buildNoResultsActions()}
        <button type="button" class="btn-sec" onclick="resetShopFilters()">Filters resetten</button>
      </div>`;
    updateShopVoorraadLede();
    syncShopCategoryTabsFromState();
    updateLeaguePillCounts();
    updateShopHeroLede();
    return;
  }
  document.getElementById('grid').innerHTML = list.map((p, idx) => {
    const stock = getStockState(p.stock);
    return `
    <div class="pcard card-enter" style="animation-delay:${Math.min(idx * 35, 280)}ms">
      <div class="pimg-wrap">
        ${(p.image || p.image2 || p.image3)
          ? `<img src="${productThumbSrc(p.image || p.image2 || p.image3)}" data-full="${productImgSrc(p.image || p.image2 || p.image3)}" alt="${esc(p.name)}" loading="lazy" decoding="async" width="320" height="400" onerror="thumbErr(this)">`
          : `<div class="pnoimg"><span class="icon" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5L5 20"/></svg></span><span>Foto volgt</span></div>`}
        <button type="button" class="pwish-btn ${isWishlisted(p.id) ? 'on' : ''}" onclick="event.stopPropagation();toggleWishlist(${p.id},this)" aria-label="Wishlist"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 20.3l-1.45-1.32C5.4 14.24 2 11.16 2 7.5 2 4.42 4.42 2 7.5 2c1.74 0 3.41.81 4.5 2.09C13.09 2.81 14.76 2 16.5 2 19.58 2 22 4.42 22 7.5c0 3.66-3.4 6.74-8.55 11.48L12 20.3z"/></svg></button>
        ${['new','hot'].includes(String(p.badge || '').toLowerCase())
          ? `<span class="pbadge ${String(p.badge).toLowerCase()}">${String(p.badge).toLowerCase() === 'new' ? 'Nieuw' : 'Populair'}</span>`
          : (!currentSearchTerm && currentSortMode === 'featured' && idx < 3 ? `<span class="pbadge bestseller">Bestseller</span>` : '')}
      </div>
      <div class="pbody">
        <p class="pleague">${esc(p.league)}</p>
        <p class="pname"><a class="pcard-link" href="${productHref(p)}">${esc(p.name)}</a></p>
        <span class="stock-pill ${stock.key}">${stock.label}</span>
        <div class="pfooter">
          <span class="pprice">${eur(p.price)}</span>
          <button type="button" class="padd-btn" onclick="event.stopPropagation();openDetail(${p.id})" title="Snel bekijken &amp; toevoegen" aria-label="Snel toevoegen">+</button>
        </div>
      </div>
    </div>`;
  }).join('');
  updateShopVoorraadLede();
  updateShopRefineUI();
  syncShopCategoryTabsFromState();
  updateLeaguePillCounts();
  updateShopHeroLede();
}

function toggleShopRefinePanel() {
  const panel = document.getElementById('shopRefinePanel');
  const btn = document.getElementById('shopRefineToggle');
  const wrap = document.getElementById('shopRefineWrap');
  const bg = document.getElementById('shopFilterBg');
  if (!panel || !btn) return;
  const next = !panel.classList.contains('is-open');
  panel.classList.toggle('is-open', next);
  btn.setAttribute('aria-expanded', next ? 'true' : 'false');
  if (wrap) wrap.classList.toggle('on', next);
  if (bg) bg.classList.toggle('on', next);
}

function closeShopRefinePanel() {
  const panel = document.getElementById('shopRefinePanel');
  const btn = document.getElementById('shopRefineToggle');
  const wrap = document.getElementById('shopRefineWrap');
  const bg = document.getElementById('shopFilterBg');
  if (panel) panel.classList.remove('is-open');
  if (btn) btn.setAttribute('aria-expanded', 'false');
  if (wrap) wrap.classList.remove('on');
  if (bg) bg.classList.remove('on');
}

function updateShopRefineUI() {
  const badge = document.getElementById('shopRefineBadge');
  const panel = document.getElementById('shopRefinePanel');
  const btn = document.getElementById('shopRefineToggle');
  if (!badge) return;
  let n = 0;
  if (currentVersionFilter !== 'all') n++;
  if (currentKitVariantFilter !== 'all') n++;
  if (currentVoorraadFilter !== 'all') n++;
  if (n > 0) {
    badge.hidden = false;
    badge.textContent = String(n);
    if (panel && btn && !panel.classList.contains('is-open')) {
      panel.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
    }
  } else {
    badge.hidden = true;
  }
}

function setVoorraadFilter(v) {
  currentVoorraadFilter = v || 'all';
  renderGrid();
}

// Categorie-kaart: ~500px-thumbnail (net als de productgrid) i.p.v. de volle foto (was ~1,1 MB
// voor 4 kaarten). Oudere producten zonder thumb vallen terug op de volle foto.
function setCardBg(el, file) {
  const thumb = productThumbSrc(file), full = productImgSrc(file);
  const probe = new Image();
  probe.onload = () => { el.style.backgroundImage = `url('${thumb}')`; };
  probe.onerror = () => { el.style.backgroundImage = `url('${full}')`; };
  probe.src = thumb;
}

function populateCategoryImages() {
  const cats =['shirts','sets','hemdsetjes','retro','kids','voorraad'];
  cats.forEach(cat => {
    const el = document.getElementById('catimg-' + cat);
    if (!el) return;
    let inCat;
    if (cat === 'voorraad') {
      inCat = PRODUCTS.filter(p => parseInt(p.in_voorraad, 10) === 1 && (p.image||p.image2||p.image3));
    } else {
      inCat = PRODUCTS.filter(p => detectProductType(p) === cat && (p.image||p.image2||p.image3));
    }
    // Prefer an unused product so the same kit never repeats across the homepage.
    let p = inCat.find(pp => pp.cat_cover && homeUnused(pp))
         || inCat.find(homeUnused)
         || inCat.find(pp => pp.cat_cover)
         || inCat[0];
    // Categories with no own products yet (e.g. kids): show an unused recognisable shirt.
    if (!p && cat === 'kids') {
      const shirts = PRODUCTS.filter(x => homeHasImg(x) && detectProductType(x) === 'shirts');
      p = shirts.filter(homeIsStar).find(homeUnused) || shirts.find(homeUnused) || shirts.filter(homeIsStar)[0];
    }
    if (p) { homeMarkUsed(p); setCardBg(el, p.image||p.image2||p.image3); }
  });
  // Player Version tile
  const playerEl = document.getElementById('catimg-player');
  if (playerEl) {
    const playerProds = PRODUCTS.filter(p => detectProductVersion(p) === 'player' && (p.image||p.image2||p.image3));
    const p = playerProds.find(p => p.cat_cover) || playerProds[0];
    if (p) setCardBg(playerEl, p.image||p.image2||p.image3);
  }
}

function goToShopPlayer() {
  if (HOME_SKIP_EMBEDDED_SHOP) {
    window.location.href = 'shirts';
    return;
  }
  // Reset type filter, activate player version
  currentTypeFilter = 'all';
  currentVoorraadFilter = 'all';
  const _sv = document.getElementById('shopVoorraad');
  if (_sv) _sv.value = 'all';
  currentVersionFilter = 'player';
  currentFilterCat = 'all';
  _doRenderGrid('all');
  kbeScrollIntoView(document.getElementById('shop'));
}

function setTypeFilter(type, btn) {
  currentTypeFilter = type || 'all';
  currentVoorraadFilter = 'all';
  const _sv = document.getElementById('shopVoorraad');
  if (_sv) _sv.value = 'all';
  renderGrid();
}

/** CTA “Alle tenues”: op homepage → volledige shop (alle types) via shop.html. */
function scrollToAlleTenues() {
  if (HOME_SKIP_EMBEDDED_SHOP) {
    window.location.href = 'shop.html';
    return;
  }
  const shop = document.getElementById('shop');
  document.querySelectorAll('[id^="navType-"]').forEach(a => a.classList.remove('active'));
  currentFilterCat = 'all';
  setTypeFilter('all');
  kbeScrollIntoView(shop);
}

function goToShopVoorraad() {
  if (HOME_SKIP_EMBEDDED_SHOP) {
    window.location.href = 'voorraad';
    return;
  }
  currentTypeFilter = 'all';
  currentVoorraadFilter = 'in_stock';
  currentFilterCat = 'all';
  currentVersionFilter = 'all';
  const shopVer = document.getElementById('shopVersion');
  if (shopVer) shopVer.value = 'all';
  const vs = document.getElementById('shopVoorraad');
  if (vs) vs.value = 'in_stock';
  document.querySelectorAll('[id^="navType-"]').forEach(a => a.classList.remove('active'));
  renderGrid('all');
  kbeScrollIntoView(document.getElementById('shop'));
}

function goToShop(type) {
  if (HOME_SKIP_EMBEDDED_SHOP) {
    const t = (type || 'shirts').toLowerCase();
    window.location.href = t === 'all' ? 'shop.html' : t;
    return;
  }
  currentTypeFilter = type || 'all';
  currentVoorraadFilter = 'all';
  const vs = document.getElementById('shopVoorraad');
  if (vs) vs.value = 'all';
  currentFilterCat = 'all';
  setActiveTypeNav(type || 'shirts');
  renderGrid('all');
  kbeScrollIntoView(document.getElementById('shop'));
}

function initShopFromUrl() {
  const params = new URLSearchParams(window.location.search);
  let t = (params.get('type') || '').toLowerCase();
  if (t === 'hemsetjes') t = 'hemdsetjes';
  if (t === 'training') t = 'shirts';
  if (HOME_SKIP_EMBEDDED_SHOP) {
    if (params.get('voorraad') === '1' || t === 'voorraad') {
      window.location.replace('voorraad');
      return;
    }
    const allowed = ['shirts', 'sets', 'hemdsetjes', 'retro', 'kids'];
    if (t && allowed.includes(t)) {
      window.location.replace(t);
      return;
    }
    return;
  }
  if (params.get('voorraad') === '1' || t === 'voorraad') {
    goToShopVoorraad();
    return;
  }
  const allowed = ['shirts', 'sets', 'hemdsetjes', 'retro', 'kids'];
  if (t && allowed.includes(t)) {
    goToShop(t);
    return;
  }
  currentTypeFilter = 'shirts';
  setActiveTypeNav('shirts');
  renderGrid('all');
}

function resetShopFilters() {
  currentSearchTerm = '';
  currentSortMode = 'featured';
  currentFilterCat = 'all';
  currentTypeFilter = 'shirts';
  currentVersionFilter = 'all';
  currentKitVariantFilter = 'all';
  const searchInput = document.getElementById('shopSearch');
  if (searchInput) searchInput.value = '';
  const _rc = document.getElementById('searchClear');
  if (_rc) _rc.classList.remove('visible');
  const sortInput = document.getElementById('shopSort');
  if (sortInput) sortInput.value = 'featured';

  const _pv = document.getElementById('ftab-player');
  if (_pv) _pv.classList.remove('on');

  document.querySelectorAll('#leaguePills .fpill').forEach(b => b.classList.remove('on'));
  const allLeague = document.querySelector('#leaguePills .fpill');
  if (allLeague) allLeague.classList.add('on');

  const sv = document.getElementById('shopVersion');
  if (sv) sv.value = 'all';
  const skt = document.getElementById('shopKitType');
  if (skt) skt.value = 'all';
  currentVoorraadFilter = 'all';
  const svoor = document.getElementById('shopVoorraad');
  if (svoor) svoor.value = 'all';

  const panel = document.getElementById('shopRefinePanel');
  const rt = document.getElementById('shopRefineToggle');
  if (panel) panel.classList.remove('is-open');
  if (rt) rt.setAttribute('aria-expanded', 'false');
  const rb = document.getElementById('shopRefineBadge');
  if (rb) rb.hidden = true;

  setActiveTypeNav('shirts');
  renderGrid('all');
}
let _searchDebounce = null;
function togglePlayerVersion() {
  if (HOME_SKIP_EMBEDDED_SHOP) {
    window.location.href = 'shirts';
    return;
  }
  const pv = document.getElementById('ftab-player');
  const isOn = pv && pv.classList.contains('on');
  currentVersionFilter = isOn ? 'all' : 'player';
  _doRenderGrid(currentFilterCat);
}

function clearSearch() {
  const inp = document.getElementById('shopSearch');
  const clearBtn = document.getElementById('searchClear');
  if (inp) inp.value = '';
  if (clearBtn) clearBtn.classList.remove('visible');
  currentSearchTerm = '';
  _doRenderGrid(currentFilterCat);
}

function applyDynamicStoreTexts() {
  const free = Number(CONFIG.freeShippingFrom || 40).toFixed(0);
  const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
  set('announceFreeShipA', `Gratis verzending vanaf €${free}`);
  set('announceFreeShipB', `Gratis verzending vanaf €${free}`);
  set('bannerFreeShipText', `Vanaf €${free}.`);
  set('heroFreeShipText', `Gratis verzending vanaf €${free}`);
  set('trustBarFreeShipText', `Gratis verzending vanaf €${free}`);
}

function applyBannerImagesFromProducts() {
  const slides = [
    document.querySelector('.banner-slide-1'),
    document.querySelector('.banner-slide-2')
  ];
  if (!slides[0] || !slides[1]) return;

  // Optioneel: zet eigen banners in images/hero-1.jpg en images/hero-2.jpg (één probe per slide;
  // meerdere extensies/paden gaven onnodig veel 404’s in de console).
  // Bump ?v= whenever you replace a hero image, so cached copies refresh for everyone.
  const fixedHeroCandidates = [
    ['images/hero-1.webp?v=3', 'images/hero-1.jpg?v=3'],
    ['images/hero-2.webp?v=3', 'images/hero-2.jpg?v=3']
  ];

  // Fallback: uploaded product images (max one unique image per slide).
  const candidates = [];
  PRODUCTS.forEach(p => {
    const img = p.image || p.image2 || p.image3;
    if (img && !candidates.includes(img)) candidates.push(img);
  });

  const tryUrls = (urls, onOk, onFail) => {
    if (!urls.length) { onFail(); return; }
    const [first, ...rest] = urls;
    const probe = new Image();
    probe.onload = () => onOk(first);
    probe.onerror = () => tryUrls(rest, onOk, onFail);
    probe.src = first;
  };

  slides.forEach((slide, idx) => {
    const slideImg = slide.querySelector('.banner-photo');
    const slideBlur = slide.querySelector('.banner-bg-blur');
    const productFile = candidates[idx];
    const fallbackUrl = productFile ? productImgSrc(productFile) : '';
    const heroTryList = fixedHeroCandidates[idx] || [];

    const useUrl = (url) => {
      slide.classList.add('has-photo');
      if (slideImg) {
        if (idx === 0) {
          slideImg.setAttribute('fetchpriority', 'high');
          slideImg.loading = 'eager';
        } else {
          slideImg.setAttribute('fetchpriority', 'low');
        }
        slideImg.src = url;
        slideImg.classList.remove('hide');
      }
      if (slideBlur) {
        slideBlur.style.backgroundImage = `url('${url}')`;
      }
    };
    const useGradient = () => {
      slide.classList.remove('has-photo');
      if (slideImg) {
        slideImg.removeAttribute('fetchpriority');
        slideImg.removeAttribute('loading');
        slideImg.src = '';
        slideImg.classList.add('hide');
      }
      if (slideBlur) {
        slideBlur.style.backgroundImage = '';
      }
    };

    tryUrls(heroTryList, useUrl, () => {
      if (!fallbackUrl) { useGradient(); return; }
      tryUrls([fallbackUrl], useUrl, useGradient);
    });
  });
}

/** Align canonical + Open Graph URLs with config publicSiteUrl (set in config.php / .env). */
function applySeoFromConfig(pageFile) {
  const base = (CONFIG.publicSiteUrl || '').trim().replace(/\/$/, '');
  if (!base) return;
  const url = base + '/' + pageFile;
  const canon = document.querySelector('link[rel="canonical"]');
  if (canon) canon.setAttribute('href', url);
  const ogUrl = document.querySelector('meta[property="og:url"]');
  if (ogUrl) ogUrl.setAttribute('content', url);
}

// ── DETAIL DRAWER ──

document.getElementById('printName').addEventListener('input', function() {
  this.value = this.value.slice(0, 15);
  if (currentPrinting === 'custom') refreshDetailDrawerPrice();
});
document.getElementById('printNumber').addEventListener('input', function() {
  this.value = this.value.replace(/\D/g, '').slice(0, 4);
  if (currentPrinting === 'custom') refreshDetailDrawerPrice();
});
document.getElementById('printBadges').addEventListener('input', function() {
  this.value = this.value.slice(0, 80);
  if (currentPrinting === 'custom') refreshDetailDrawerPrice();
});

// ── CART ──

window.addEventListener('popstate', function () {
  const cp = document.getElementById('cpanel');
  if (cp && cp.classList.contains('on')) {
    document.getElementById('cbg').classList.remove('on');
    cp.classList.remove('on');
    document.body.style.overflow = '';
    __kbeCartHistory = Math.max(0, __kbeCartHistory - 1);
  }
});

/** Hamburger = checkbox #kbeNavToggle + label (werkt op iOS zonder click/touch-bugs). */

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initKbeMobileTapFixes);
} else {
  initKbeMobileTapFixes();
}

// Gallery keyboard navigation while drawer is open.
document.addEventListener('keydown', (e) => {
  const drawerOpen = document.getElementById('drawer').classList.contains('on');
  if (!drawerOpen) return;
  if (e.key === 'ArrowLeft') goGallery(-1);
  if (e.key === 'ArrowRight') goGallery(1);
});

// ── Adres: lichte client-check (server doet de echte validatie) ──
const fCountryEl = document.getElementById('fCountry');
if (fCountryEl) fCountryEl.addEventListener('change', syncCheckoutPlaceholders);
syncCheckoutPlaceholders();
