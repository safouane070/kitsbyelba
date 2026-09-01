/* shop-core.js — gedeelde, byte-identieke functies uit index.html + shop.html.
   Gegenereerd door dedup (finding A). Page-specifieke/divergerende functies blijven inline. */
function kbeWaDigits() {
  const d = String(CONFIG.whatsapp || '31684446255').replace(/\D/g, '');
  return d || '31684446255';
}

function setShopCardView(view) {
  currentShopCardView = (view === 'list') ? 'list' : 'grid';
  const grid = document.getElementById('grid');
  if (grid) grid.classList.toggle('pgrid-view-list', currentShopCardView === 'list');
  const g = document.getElementById('shopViewGridBtn');
  const l = document.getElementById('shopViewListBtn');
  if (g) g.classList.toggle('on', currentShopCardView === 'grid');
  if (l) l.classList.toggle('on', currentShopCardView === 'list');
}

function openNavSearch() {
  const bg = document.getElementById('navSearchBg');
  const modal = document.getElementById('navSearchModal');
  const input = document.getElementById('navSearchInput');
  if (bg) bg.classList.add('on');
  if (modal) modal.classList.add('on');
  if (input) {
    input.value = currentSearchTerm || '';
    setTimeout(() => input.focus(), 50);
  }
  renderNavSearchSuggestions((input && input.value) || '');
}

function closeNavSearch() {
  const bg = document.getElementById('navSearchBg');
  const modal = document.getElementById('navSearchModal');
  if (bg) bg.classList.remove('on');
  if (modal) modal.classList.remove('on');
  navSearchActiveIndex = -1;
}

function getNavSearchSuggestions(queryRaw, limit = 8) {
  const q = String(queryRaw || '').trim();
  if (!Array.isArray(PRODUCTS) || !PRODUCTS.length) return [];
  if (!q) {
    return PRODUCTS
      .filter((p) => (p.image || p.image2 || p.image3))
      .slice(0, limit);
  }
  return PRODUCTS
    .map((p) => ({ p, score: getProductSearchScore(p, q) }))
    .filter((x) => x.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map((x) => x.p);
}

function applyPopularNavSearch(term) {
  const input = document.getElementById('navSearchInput');
  if (!input) return;
  input.value = term;
  renderNavSearchSuggestions(term);
  input.focus();
}

function openProductFromNavSearch(id) {
  closeNavSearch();
  goToProductPage(id);
}

function setNavSearchActiveIndex(nextIdx) {
  navSearchActiveIndex = nextIdx;
  const items = Array.from(document.querySelectorAll('#navSearchResults .nav-search-item'));
  items.forEach((el, idx) => el.classList.toggle('is-active', idx === navSearchActiveIndex));
}

function handleNavSearchKeydown(event) {
  const input = document.getElementById('navSearchInput');
  const count = navSearchSuggestions.length;
  if (event.key === 'Escape') {
    closeNavSearch();
    return;
  }
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    if (!count) return;
    const next = navSearchActiveIndex < count - 1 ? navSearchActiveIndex + 1 : 0;
    setNavSearchActiveIndex(next);
    return;
  }
  if (event.key === 'ArrowUp') {
    event.preventDefault();
    if (!count) return;
    const next = navSearchActiveIndex > 0 ? navSearchActiveIndex - 1 : count - 1;
    setNavSearchActiveIndex(next);
    return;
  }
  if (event.key === 'Enter') {
    event.preventDefault();
    applyNavSearch();
    return;
  }
  if (input) {
    setTimeout(() => renderNavSearchSuggestions(input.value), 0);
  }
}

function renderNavSearchSuggestions(queryRaw) {
  const box = document.getElementById('navSearchResults');
  if (!box) return;
  const q = String(queryRaw || '').trim();
  const items = getNavSearchSuggestions(q, 8);
  navSearchSuggestions = items;
  navSearchActiveIndex = -1;
  if (!items.length) {
    box.innerHTML = `<div class="nav-search-empty">${q ? 'Geen resultaten. Probeer een andere club of competitie.' : 'Type om direct producten te zien.'}</div>`;
    return;
  }
  const popular = !q ? `<div class="nav-search-popular">${NAV_SEARCH_POPULAR.map(t => `<button type="button" class="nav-search-chip" data-popular-term="${esc(t)}">${esc(t)}</button>`).join('')}</div>` : '';
  box.innerHTML = popular + items.map((p, idx) => {
    const img = productImgSrc(p.image || p.image2 || p.image3);
    return `<button type="button" class="nav-search-item" onmouseenter="setNavSearchActiveIndex(${idx})" onclick="openProductFromNavSearch(${Number(p.id)})">
      ${img ? `<img src="${img}" alt="${esc(p.name || '')}" width="48" height="58" loading="lazy" decoding="async">` : `<div></div>`}
      <div>
        <div class="nav-search-item-name">${esc(p.name || '')}</div>
        <div class="nav-search-item-meta">${esc(p.league || '')}</div>
      </div>
      <div class="nav-search-item-price">€${Number(p.price || 0).toFixed(2)}</div>
    </button>`;
  }).join('');
  box.querySelectorAll('.nav-search-chip').forEach((btn) => {
    btn.addEventListener('click', () => {
      const term = btn.getAttribute('data-popular-term') || btn.textContent || '';
      applyPopularNavSearch(term);
    });
  });
}

function unitPrintingExtra() {
  const v = Number(CONFIG.customPrintingPrice);
  return Number.isFinite(v) && v >= 0 ? v : 5;
}

function unitBadgeExtra() {
  const v = Number(CONFIG.badgeExtraPrice);
  return Number.isFinite(v) && v >= 0 ? v : 3;
}

function linePrintingExtraForCart(i) {
  if ((i.printing_option || 'none') !== 'custom') return 0;
  const pn = String(i.print_name || '').trim();
  const num = String(i.print_number || '').trim();
  return pn || num ? unitPrintingExtra() : 0;
}

function lineBadgeExtraForCart(i) {
  if ((i.printing_option || 'none') !== 'custom') return 0;
  return String(i.print_badges || '').trim() ? unitBadgeExtra() : 0;
}

function recalcCartPrintingPrices() {
  if (!Array.isArray(cart) || !PRODUCTS.length) return;
  let changed = false;
  cart.forEach(i => {
    const p = PRODUCTS.find(x => x.id === i.id);
    if (!p) return;
    const expected = Number(p.price) + linePrintingExtraForCart(i) + lineBadgeExtraForCart(i);
    if (Math.abs(Number(i.price) - expected) > 0.005) {
      i.price = expected;
      changed = true;
    }
  });
  if (changed) persistCartState();
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function jss(v) {
  return JSON.stringify(String(v ?? ''));
}

function productImgSrc(file) {
  if (!file) return '';
  let s = String(file).trim().replace(/\\/g, '/').replace(/^\/+/, '');
  if (/^https?:\/\//i.test(s)) return s;
  const enc = (seg) => encodeURIComponent(seg);
  const prefix = 'uploads/products/';
  while (s.toLowerCase().startsWith(prefix)) {
    s = s.slice(prefix.length);
  }
  if (!s) return '';
  if (s.indexOf('/') !== -1) return prefix + s.split('/').filter(Boolean).map(enc).join('/');
  return prefix + enc(s);
}

function getPublicSiteBase() {
  const fromCfg = (CONFIG.publicSiteUrl || '').trim().replace(/\/$/, '');
  if (fromCfg) return fromCfg;
  try {
    const { origin, pathname } = window.location;
    let p = pathname;
    if (/\.html?$/i.test(p)) p = p.replace(/\/[^/]+$/, '');
    if (p.length > 1) p = p.replace(/\/$/, '');
    return origin + (p || '');
  } catch (_) {
    return '';
  }
}

function primaryImageFileForCartLine(line) {
  const f = line.image || line.image2 || line.image3;
  if (f) return f;
  const p = PRODUCTS.find(x => x.id === line.id);
  return p ? (p.image || p.image2 || p.image3) : '';
}

function absoluteProductImgUrl(file) {
  const rel = productImgSrc(file);
  if (!rel) return '';
  if (/^https?:\/\//i.test(rel)) return rel;
  const base = getPublicSiteBase().replace(/\/$/, '');
  if (!base) return '';
  return base + '/' + rel.replace(/^\//, '');
}

function kbeEnsureEmailjsInit(pk) {
  pk = typeof pk === 'string' ? pk.trim() : pk;
  if (!pk) return;
  let n = 0;
  (function tick() {
    if (typeof emailjs !== 'undefined') {
      try { emailjs.init({ publicKey: pk }); } catch (_) {}
      return;
    }
    if (++n < 150) setTimeout(tick, 20);
  })();
}

function kbeEmailJsSendOpts() {
  const pk = (typeof CONFIG !== 'undefined' && CONFIG.emailjsPk) ? String(CONFIG.emailjsPk).trim() : '';
  return pk ? { publicKey: pk } : {};
}

function maybeShowPromoPopup() {
  try {
    if (localStorage.getItem(PROMO_POPUP_KEY) === '1') return;
  } catch (_) {}
  const el = document.getElementById('promoPopup');
  if (el) el.classList.add('on');
}

function closePromoPopup() {
  const el = document.getElementById('promoPopup');
  if (el) el.classList.remove('on');
  try { localStorage.setItem(PROMO_POPUP_KEY, '1'); } catch (_) {}
}

function persistCartState() {
  try { localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart)); } catch (_) {}
}

function persistWishlistState() {
  try { localStorage.setItem(WISHLIST_STORAGE_KEY, JSON.stringify(wishlist)); } catch (_) {}
}

function persistCouponState() {
  try {
    if (appliedCoupon) localStorage.setItem(COUPON_STORAGE_KEY, JSON.stringify(appliedCoupon));
    else localStorage.removeItem(COUPON_STORAGE_KEY);
  } catch (_) {}
}

function restoreCartState() {
  try {
    const raw = localStorage.getItem(CART_STORAGE_KEY);
    if (!raw) return;
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) cart = parsed;
  } catch (_) {}
}

function restoreWishlistState() {
  try {
    const raw = localStorage.getItem(WISHLIST_STORAGE_KEY);
    if (!raw) return;
    const parsed = JSON.parse(raw);
    wishlist = Array.isArray(parsed) ? parsed.map(Number).filter(Number.isFinite) : [];
  } catch (_) { wishlist = []; }
}

function productHaystack(p) {
  const kp = String(p.kits_path || '').replace(/\\/g, '/');
  return `${p.name || ''} ${p.description || ''} ${p.league || ''} ${kp}`.toLowerCase();
}

function normalizeSearchText(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9\s/.-]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function detectKitVariant(p) {
  const h = productHaystack(p);
  const path = String(p.kits_path || '').toLowerCase().replace(/\\/g, '/');
  const s = h + ' | ' + path;
  if (/\bgoalkeeper\b|\bgoalie\b|\bgk\b|(?:^|[\\/])gk(?:[\\/]|$)|keeper kit|keeper shirt/.test(s)) return 'goalkeeper';
  if (/\bthird\b|\b3rd\b|(?:^|[\\/])third(?:[\\/]|$)|\bthird kit\b/.test(s)) return 'third';
  if (/\baway\b|(?:^|[\\/])away(?:[\\/]|$)|\baway kit\b/.test(s)) return 'away';
  if (/\bhome\b|(?:^|[\\/])home(?:[\\/]|$)|\bhome kit\b/.test(s)) return 'home';
  return 'other';
}

function detectProductType(p) {
  const catDb = String(p.cat || '').toLowerCase();
  if (catDb === 'hemsetjes' || catDb === 'hemdsetjes') return 'hemdsetjes';
  if (catDb === 'training') return 'shirts';
  if (catDb === 'retro') return 'retro';
  if (catDb === 'kids') return 'kids';
  const name = String(p.name || '').toLowerCase();
  const desc = String(p.description || '').toLowerCase();
  if (name.includes('retro kids')) return 'kids';
  if (name.includes('kids kit') || name.includes(' kids ') || /\bkids\b/.test(name)) return 'kids';
  if (/\bretro\b|\bvintage\b/.test(name) || /\bretro\b|\bvintage\b/.test(desc)) return 'retro';
  if (/\bhem\b|\bhemdje\b|\bhemset|\bhemdsetjes\b/.test(name)) return 'hemdsetjes';
  if (name.includes('full kit set') || name.includes(' kit set') || /\bset\b/.test(name)) return 'sets';
  return 'shirts';
}

function detectProductVersion(p) {
  if (!p) return 'fan';
  const v = String(p.version || '').trim().toLowerCase();
  if (v === 'player' || v === 'fan') return v;
  const hay = `${p.name || ''} ${p.description || ''} ${p.fit_info || ''} ${p.size_advice || ''}`.toLowerCase();
  if (
    hay.includes('player version') ||
    hay.includes('player fit') ||
    hay.includes('players version') ||
    /\bplayer\b/.test(hay)
  ) {
    return 'player';
  }
  if (
    hay.includes('spelersversie') ||
    hay.includes('spelerversie') ||
    hay.includes('spelers versie') ||
    hay.includes('speler versie') ||
    hay.includes('spelers kit') ||
    hay.includes('spelerseditie') ||
    hay.includes('spelers editie')
  ) {
    return 'player';
  }
  if (hay.includes('fan version') || hay.includes('fan fit') || /\bfan\b/.test(hay)) return 'fan';
  if (hay.includes('fanversie') || hay.includes('fan versie') || hay.includes('fansversie')) return 'fan';
  return 'fan';
}

function updateShopVoorraadLede() {
  const el = document.getElementById('shopVoorraadLede');
  if (!el) return;
  if (currentVoorraadFilter === 'in_stock') {
    el.hidden = false;
    el.className = 'shop-voorraad-lede';
    el.innerHTML = '<div class="shop-voorraad-inner"><span class="shop-voorraad-ico" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2d5a27" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m21 8-9-5-9 5v8l9 5 9-5z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/></svg></span><div><p class="shop-voorraad-lede-p"><strong>Op voorraad</strong> — wat je hier ziet hebben we nu liggen. <strong>Levering 1–2 werkdagen</strong> (kan per week wisselen).</p>' +
      '<p class="shop-voorraad-lede-sub">Geen voorraad elders op de site: <strong>7–12 werkdagen</strong>. Filter “Niet op voorraad” of “Voorraad — alle” toont de rest.</p></div></div>';
  } else if (currentVoorraadFilter === 'out_of_stock') {
    el.hidden = false;
    el.className = 'shop-voorraad-lede shop-voorraad-lede--out';
    el.innerHTML = '<div class="shop-voorraad-inner"><span class="shop-voorraad-ico" aria-hidden="true">⏳</span><div><p class="shop-voorraad-lede-p"><strong>Niet op voorraad</strong> — <strong>7–12 werkdagen</strong> levering.</p>' +
      '<p class="shop-voorraad-lede-sub">Wel snel? Ga naar tab of filter <strong>Alleen op voorraad</strong> — daar geldt <strong>1–2 werkdagen</strong>.</p></div></div>';
  } else {
    el.hidden = true;
    el.className = 'shop-voorraad-lede';
    el.innerHTML = '';
  }
}

function buildNoResultsActions() {
  return `<div class="no-results-actions">
    <button type="button" onclick="resetShopFilters()">Reset alles</button>
    <button type="button" onclick="doFilter('all')">Alle competities</button>
    <button type="button" onclick="setTypeFilter('shirts')">Shirts</button>
    <button type="button" onclick="setTypeFilter('kids')">Kids</button>
  </div>`;
}

function updateShopHeroLede() {
  const el = document.getElementById('shopHeroLede');
  if (!el) return;
  const typeMap = {
    shirts: 'Shirts van topclubs en nationale teams, met fan- en player-opties.',
    sets: 'Complete sets voor een volledige look, direct gefilterd op competitie.',
    hemdsetjes: 'Lichte hemdsetjes voor training en warm weer, snel te filteren op league.',
    retro: 'Retro klassiekers van legendarische seizoenen.',
    kids: 'Kids tenues met passende maten en snelle selectie op club of competitie.',
    all: 'Shop per categorie en gebruik filters om snel de juiste club, competitie en maat te vinden.'
  };
  const typeKey = typeMap[currentTypeFilter] ? currentTypeFilter : 'all';
  const leagueMap = { premier:'Premier League', laliga:'La Liga', bundesliga:'Bundesliga', seriea:'Serie A', ligue1:'Ligue 1', eredivisie:'Eredivisie', national:'Nationale teams' };
  const league = currentFilterCat !== 'all' ? ` Gefilterd op ${leagueMap[currentFilterCat] || 'competitie'}.` : '';
  el.textContent = typeMap[typeKey] + league;
}

function isWishlisted(id) {
  return wishlist.includes(Number(id));
}

function toggleWishlist(id, btn) {
  const pid = Number(id);
  if (!Number.isFinite(pid)) return;
  const i = wishlist.indexOf(pid);
  const on = i === -1;
  if (on) wishlist.push(pid); else wishlist.splice(i, 1);
  persistWishlistState();
  if (btn) btn.classList.toggle('on', on);
  if (typeof showToast === 'function') showToast(on ? '♥ Toegevoegd aan wishlist' : '♡ Verwijderd uit wishlist');
}

function doFilter(cat, btn) {
  document.querySelectorAll('#leaguePills .fpill').forEach(b => b.classList.remove('on'));
  if (btn) btn.classList.add('on');
  renderGrid(cat);
}

function setVersionFromUi(v) {
  currentVersionFilter = v || 'all';
  renderGrid();
}

function setKitVariantFromUi(v) {
  currentKitVariantFilter = v || 'all';
  renderGrid();
}

function setActiveTypeNav(type) {
  document.querySelectorAll('[id^="navType-"]').forEach(a => a.classList.remove('active'));
  const link = document.getElementById('navType-' + type);
  if (link) link.classList.add('active');
}

function setSortMode(value) {
  currentSortMode = value || 'featured';
  renderGrid();
}

function slugifyProductName(name) {
  return (name || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function getProductShareParam(p) {
  return `${p.id}-${slugifyProductName(p.name)}`;
}

function getCartItemSlug(item) {
  if (!item || item.id == null || item.id === '') return '';
  return `${item.id}-${slugifyProductName(item.name)}`;
}

function openProductFromUrl() {
  const url = new URL(window.location.href);
  const productParam = url.searchParams.get('product');
  if (!productParam) return;
  const idPart = String(productParam).split('-')[0];
  const id = parseInt(idPart, 10);
  const p = PRODUCTS.find(x => x.id === id);
  if (p) openDetail(p.id, false);
}

function goToProductPage(id) {
  const p = PRODUCTS.find(x => x.id === id);
  if (!p) return;
  window.location.href = 'product.php?slug=' + getProductShareParam(p);
}

function setGalleryMainImageByIndex(index) {
  if (!currentGalleryImages.length) return;
  currentGalleryIndex = Math.max(0, Math.min(index, currentGalleryImages.length - 1));
  const thumbs = document.querySelectorAll('#dThumbs .d-thumb');
  setGalleryMainImage(currentGalleryImages[currentGalleryIndex], thumbs[currentGalleryIndex] || null);
}

function goGallery(dir) {
  if (!currentGalleryImages.length) return;
  const next = currentGalleryIndex + dir;
  if (next < 0 || next >= currentGalleryImages.length) return;
  setGalleryMainImageByIndex(next);
}

function updateGalleryArrows() {
  const prevBtn = document.getElementById('dPrevBtn');
  const nextBtn = document.getElementById('dNextBtn');
  if (!prevBtn || !nextBtn) return;
  if (currentGalleryImages.length <= 1) {
    prevBtn.disabled = true;
    nextBtn.disabled = true;
    return;
  }
  prevBtn.disabled = currentGalleryIndex <= 0;
  nextBtn.disabled = currentGalleryIndex >= currentGalleryImages.length - 1;
}

function getDrawerPolicyHTML() {
  // Keep content short enough for the drawer; users can still rely on the site footer links.
  return `
    <div class="policy">
      <h3>Verzending</h3>
      <div>
        <strong>Op voorraad:</strong> 1–2 werkdagen · <strong>Nabestelling:</strong> 7–12 werkdagen.
        Gratis verzending vanaf <strong>€${Number(CONFIG.freeShippingFrom || 90).toFixed(0)}</strong>.
      </div>
      <h3 style="margin-top:14px">Retour</h3>
      <div>
        Wij accepteren geen retouren, tenzij er een fout aan onze kant is — zie ook de FAQ.
        <strong>Gepersonaliseerde items</strong> zijn uitgesloten van retour.
      </div>
      <h3 style="margin-top:14px">Disclaimer</h3>
      <div>
        Dit is een <strong>replica</strong> en is <strong>niet gelieerd aan</strong> of <strong>goedgekeurd door</strong> officiële clubs, competities of spelers.
      </div>
    </div>
  `;
}

function setPrintingOption(option, btnEl) {
  currentPrinting = option;
  const noneBtn = document.getElementById('print-none-btn');
  const customBtn = document.getElementById('print-custom-btn');
  const fields = document.getElementById('printFields');

  noneBtn.classList.toggle('on', option === 'none');
  customBtn.classList.toggle('on', option === 'custom');
  fields.style.display = option === 'custom' ? 'block' : 'none';

  refreshDetailDrawerPrice();

  // If size is already selected, update add button label.
  const addBtn = document.getElementById('dAddBtn');
  if (addBtn && !addBtn.disabled && currentSz) {
    const extraLbl = option === 'custom' ? ' · Bedrukking' : '';
    addBtn.textContent = '+ In winkelwagen — Maat ' + currentSz + extraLbl;
  }
}

function closeDetail() {
  document.getElementById('dbg').classList.remove('on');
  document.getElementById('drawer').classList.remove('on');
  currentDetailId = null;
  currentSz = null;
  currentPrinting = 'none';
  currentGalleryImages = [];
  currentGalleryIndex = 0;
  const url = new URL(window.location.href);
  url.searchParams.delete('product');
  window.history.replaceState({}, '', url.toString());
}

function pickSz(btn) {
  const p = PRODUCTS.find(p => p.id === currentDetailId);
  if (!p || !productHasSellableStock(p)) return;
  document.querySelectorAll('.sz').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  currentSz = btn.dataset.size || btn.textContent.trim();

  // Enable add button
  const addBtn = document.getElementById('dAddBtn');
  addBtn.disabled = false;
  const extraLbl = currentPrinting === 'custom' ? ' · Bedrukking' : '';
  addBtn.textContent = '+ In winkelwagen — Maat ' + currentSz + extraLbl;

  // Hide hint
  document.getElementById('szHint').style.display = 'none';

  // Wire up button to current product + size
  if (p) {
    addBtn.onclick = () => {
      addToCart(p, currentSz);
      closeDetail();
    };
  }
}

function importPendingCartFromProductPage() {
  const key = 'kbe_cart';
  let items = [];
  try { items = JSON.parse(localStorage.getItem(key) || '[]'); } catch (_) { items = []; }
  if (!Array.isArray(items) || !items.length) return;
  items.forEach(it => {
    if (!it || !it.id || !it.size) return;
    mergeCartItem(it);
  });
  localStorage.removeItem(key);
  persistCartState();
  updateCount();
}

function updateCount() {
  const count = cart.reduce((s, i) => s + i.qty, 0);
  document.getElementById('cartN').textContent = count;
}

function openCart() {
  const cp = document.getElementById('cpanel');
  const alreadyOpen = cp.classList.contains('on');
  document.getElementById('cbg').classList.add('on');
  cp.classList.add('on');
  document.body.style.overflow = 'hidden';
  if (!alreadyOpen) {
    try {
      history.pushState({ kbeCart: 1 }, '');
      __kbeCartHistory++;
    } catch (_) {}
  }
  renderCart();
}

function closeCart() {
  const cp = document.getElementById('cpanel');
  if (!cp.classList.contains('on')) return;
  document.getElementById('cbg').classList.remove('on');
  cp.classList.remove('on');
  document.body.style.overflow = '';
  if (__kbeCartHistory > 0) {
    __kbeCartHistory--;
    try {
      history.back();
    } catch (_) {}
  }
}

function clearCart() {
  if (!cart.length) return;
  if (!confirm('Winkelwagen leegmaken?')) return;
  cart = [];
  appliedCoupon = null;
  persistCouponState();
  persistCartState();
  updateCount();
  renderCart();
}

function rmItem(i) {
  cart.splice(i, 1);
  persistCartState();
  updateCount();
  renderCart();
}

function removeAppliedCoupon() {
  appliedCoupon = null;
  persistCouponState();
  const cartIn = document.getElementById('cartCouponInput');
  if (cartIn) { cartIn.value = ''; cartIn.disabled = false; }
  const modalIn = document.getElementById('couponInput');
  if (modalIn) { modalIn.value = ''; modalIn.disabled = false; }
  const cfb = document.getElementById('cartCouponFeedback');
  if (cfb) { cfb.className = 'cart-coupon-feedback'; cfb.textContent = ''; }
  const mfb = document.getElementById('couponFeedback');
  if (mfb) { mfb.className = 'coupon-feedback'; mfb.textContent = ''; }
  renderCart();
  updateModalRecap();
}

function prefillCheckoutFromProfile() {
  fetch('api/auth.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'me' }),
    credentials: 'same-origin'
  })
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok || !j.user) return;
      const u = j.user;
      const set = (id, v) => {
        const el = document.getElementById(id);
        if (el && v != null && String(v).trim() !== '') el.value = String(v);
      };
      set('fName', u.name);
      set('fPhone', u.phone);
      set('fStreet', u.street);
      set('fZip', u.zip);
      set('fCity', u.city);
      set('fEmail', u.email);
    })
    .catch(() => {});
}

function openModal() {
  closeCart();
  const cIn = document.getElementById('couponInput');
  if (cIn) {
    cIn.disabled = false;
    cIn.value = appliedCoupon ? appliedCoupon.code : '';
  }
  updateModalRecap();
  renderUpsell();
  document.getElementById('mbg').classList.add('on');
  prefillCheckoutFromProfile();
}

function updateModalRecap() {
  const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const discount = calcDiscount(sub);
  const discountedSub = sub - discount;
  const ship = discountedSub >= CONFIG.freeShippingFrom ? 0 : CONFIG.shippingCost;
  const total = discountedSub + ship;

  let html = `<p class="mrecap-lbl">Overzicht bestelling</p>`;
  cart.forEach(i => {
    html += `<div class="mrecap-row"><span>${i.qty}× ${i.name} (${i.size}${i.printing_option==='custom' ? ' · ' + (i.print_name||'') + (i.print_number ? ' #' + i.print_number : '') : ''})</span><span>€${(i.price * i.qty).toFixed(2)}</span></div>`;
  });
  if (discount > 0) {
    html += `<div class="mrecap-row discount"><span>Korting (${appliedCoupon.type === 'percent' ? appliedCoupon.value + '%' : '€' + Number(appliedCoupon.value).toFixed(2)})</span><span>-€${discount.toFixed(2)}</span></div>`;
  }
  html += `<div class="mrecap-row"><span>Verzending</span><span>${ship === 0 ? 'GRATIS' : '€' + ship.toFixed(2)}</span></div>`;
  html += `<div class="mrecap-tot"><span>Totaal</span><span>€${total.toFixed(2)}</span></div>`;
  document.getElementById('mrecap').innerHTML = html;
}

function closeModal() {
  document.getElementById('mbg').classList.remove('on');
}

function renderUpsell() {
  const cartIds = cart.map(i => i.id);
  const cartCats = [...new Set(cart.map(i => i.cat))];
  const suggestions = PRODUCTS
    .filter(p => !cartIds.includes(p.id) && cartCats.includes(p.cat))
    .slice(0, 3);

  const section = document.getElementById('upsellSection');
  if (!suggestions.length) { section.style.display = 'none'; return; }

  section.style.display = 'block';
  document.getElementById('upsellGrid').innerHTML = suggestions.map(p => `
    <div class="upsell-card" onclick="window.location.href='product.php?slug='+getProductShareParam(p)">
      <div class="upsell-img">
        ${(p.image || p.image2 || p.image3) ? `<img src="${productImgSrc(p.image || p.image2 || p.image3)}" alt="${esc(p.name)}" onerror="this.style.display='none'">` : `<span class="pcard-noimg" aria-hidden="true"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.2" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span>`}
      </div>
      <div class="upsell-info">
        <p class="upsell-name">${esc(p.name)}</p>
        <p class="upsell-price">€${p.price.toFixed(2)}</p>
      </div>
    </div>`).join('');
}

function checkoutIdempotencyKey() {
  if (!window.__checkoutIdempotencyKey) {
    window.__checkoutIdempotencyKey = (typeof crypto !== 'undefined' && crypto.randomUUID)
      ? crypto.randomUUID()
      : ('idem-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12));
  }
  return window.__checkoutIdempotencyKey;
}

function backShop() {
  document.getElementById('cscreen').classList.remove('on');
}

function showToast(msg, type) {
  const t = document.getElementById('toast');
  msg = String(msg);
  if (!type) {
    if (/^\s*(⚠️|❌|✗)/.test(msg)) type = 'error';
    else if (/^\s*(✓|✅)/.test(msg)) type = 'success';
    else if (/^\s*(♥|♡)/.test(msg)) type = 'wish';
  }
  msg = msg.replace(/^\s*(⚠️|❌|✗|✓|✅|♥|♡)\s*/, '');
  const icons = {
    error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.4v.01"></path></svg>',
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>',
    wish:    '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7.5-4.6-10-9C.5 8.5 2 5 5.5 5 8 5 9.3 6.7 12 9c2.7-2.3 4-4 6.5-4C22 5 23.5 8.5 22 12c-2.5 4.4-10 9-10 9z"></path></svg>'
  };
  t.className = 'toast' + (type ? ' toast--' + type : '');
  t.innerHTML = (icons[type] ? `<span class="toast-ico">${icons[type]}</span>` : '') + '<span class="toast-msg"></span>';
  t.querySelector('.toast-msg').textContent = msg;
  t.classList.add('on');
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => t.classList.remove('on'), 2800);
}

function initKbeNavToggleAria() {
  const cb = document.getElementById('kbeNavToggle');
  const ham = document.getElementById('ham');
  const nav = document.getElementById('navLinks');
  if (!cb || !ham || !nav) return;
  const sync = () => ham.setAttribute('aria-expanded', cb.checked ? 'true' : 'false');
  cb.addEventListener('change', sync);
  nav.querySelectorAll('a').forEach((a) => {
    a.addEventListener('click', () => {
      cb.checked = false;
      sync();
    });
  });
  sync();
}

function toggleFi(id) {
  const el = document.getElementById(id);
  const isOpen = el.classList.contains('on');
  document.querySelectorAll('.faqitem').forEach(f => {
    f.classList.remove('on');
    f.querySelector('.faq-ico').textContent = '+';
  });
  if (!isOpen) {
    el.classList.add('on');
    el.querySelector('.faq-ico').textContent = '−';
  }
}

function getCheckoutCountry() {
  const el = document.getElementById('fCountry');
  return (el && el.value) ? String(el.value).toLowerCase() : 'nl';
}

function checkoutAddressClientOk(street, zip, city) {
  const st = String(street || '').trim();
  const zp = String(zip || '').trim();
  const ct = String(city || '').trim();
  if (zp.length < 2 || zp.length > 20) return 'Vul een geldige postcode in.';
  if (!/\d/.test(zp)) return 'Postcode moet minstens één cijfer bevatten.';
  if (!/^[\p{L}\p{N}\s\-.]+$/u.test(zp)) return 'Postcode bevat ongeldige tekens.';
  if (ct.length < 2 || ct.length > 100) return 'Vul een plaats in.';
  if (st.length < 3 || st.length > 200) return 'Vul straat en huisnummer in.';
  if (!/\p{L}/u.test(st) || !/\d/u.test(st)) return 'Vul straat en huisnummer in (met cijfer).';
  if (!/\p{L}/u.test(ct)) return 'Vul een geldige plaatsnaam in.';
  return '';
}

function syncCheckoutPlaceholders() {
  const cc = getCheckoutCountry();
  const z = document.getElementById('fZip');
  const c = document.getElementById('fCity');
  if (!z || !c) return;
  const ph = { nl: ['1234 AB', 'Amsterdam'], be: ['1000', 'Brussel'], de: ['10115', 'Berlijn'], fr: ['75001', 'Parijs'] };
  const p = ph[cc] || ph.nl;
  z.placeholder = p[0];
  c.placeholder = p[1];
}
