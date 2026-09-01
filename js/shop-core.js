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

/* ── dedup ronde 2 (finding #1): genuinely-shared cart/checkout/search/seo/stock/util.
   Bron van waarheid: shop.html (superset, versie/seizoen-bewust). Page-specifieke
   grid/filter/banner/hero blijven inline per pagina (divergente filter-architectuur). ── */
function addToCart(p, sz) {
  if (!sz) { showToast('⚠️ Kies eerst een maat'); return; }
  if (!productHasSellableStock(p, currentVersion)) { showToast('⚠️ Dit product is niet op voorraad'); return; }

  const printing_option = currentPrinting;
  const nameEl = document.getElementById('printName');
  const numEl = document.getElementById('printNumber');
  const badgesEl = document.getElementById('printBadges');
  let print_name = (nameEl.value || '').trim().slice(0, 15);
  const printNumberRaw = (numEl.value || '').trim();
  let print_number = printNumberRaw.replace(/\D/g, '').slice(0, 4);
  let print_badges = (badgesEl.value || '').trim().slice(0, 80);
  nameEl.value = print_name;
  numEl.value = print_number;
  badgesEl.value = print_badges;

  if (printing_option === 'custom') {
    if (!print_name && !print_number && !print_badges) {
      showToast('⚠️ Vul minstens een naam, nummer of badge in');
      return;
    }
    if (printNumberRaw && printNumberRaw !== print_number) {
      showToast('⚠️ Alleen cijfers (max. 4)');
      return;
    }
    if (print_number && !/^\d{1,4}$/.test(print_number)) {
      showToast('⚠️ Maximaal 4 cijfers');
      return;
    }
  } else {
    print_name = '';
    print_number = '';
    print_badges = '';
  }

  const printExtra = printing_option === 'custom' && (print_name || print_number) ? unitPrintingExtra() : 0;
  const badgeExtra = printing_option === 'custom' && print_badges ? unitBadgeExtra() : 0;
  const unitPrice = versionPrice(p, currentVersion) + printExtra + badgeExtra;
  const existingLine = cart.find(i =>
    i.id === p.id &&
    i.size === sz &&
    (i.version || 'fan') === currentVersion &&
    (i.printing_option || 'none') === printing_option &&
    (i.print_name || '') === print_name &&
    (i.print_number || '') === print_number &&
    (i.print_badges || '') === print_badges
  );
  const currentQty = existingLine ? Number(existingLine.qty || 0) : 0;
  const maxForSize = maxQtyForProductSize(p, sz, currentVersion);
  if (currentQty + 1 > maxForSize) {
    showToast('⚠️ Onvoldoende voorraad voor deze maat (' + maxForSize + ' beschikbaar)');
    return;
  }

  mergeCartItem({
    ...p,
    size: sz,
    version: currentVersion,
    season: currentSeason,
    printing_option,
    print_name,
    print_number,
    print_badges,
    price: unitPrice,
    qty: 1
  });
  persistCartState();
  updateCount();
  showToast('✓ ' + p.name + ' (' + sz + ')' + (printing_option === 'custom' ? ' · Bedrukking' : '') + ' toegevoegd');
  openCart();
}

function renderCart() {
  const body = document.getElementById('cbody');
  const foot = document.getElementById('cfoot');
  if (!cart.length) {
    body.innerHTML = `<div class="cempty"><div class="cempty-ico" aria-hidden="true"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg></div><p>Je winkelwagen is leeg.<br>Voeg tenues toe om te starten.</p><button type="button" class="cart-empty-cta" onclick="closeCart()">Terug naar winkelen</button></div>`;
    foot.style.display = 'none';
    const checkoutBtn = document.getElementById('checkoutBtn');
    if (checkoutBtn) checkoutBtn.textContent = 'Bestellen via WhatsApp';
    return;
  }
  foot.style.display = 'block';

  const sub = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const discount = calcDiscount(sub);
  const discountedSub = sub - discount;
  const ship = discountedSub >= CONFIG.freeShippingFrom ? 0 : CONFIG.shippingCost;
  const total = discountedSub + ship;
  const remaining = Math.max(0, CONFIG.freeShippingFrom - discountedSub);
  const progress = Math.min(100, (discountedSub / CONFIG.freeShippingFrom) * 100);
  const cartCouponInput = document.getElementById('cartCouponInput');
  const cartCouponFeedback = document.getElementById('cartCouponFeedback');
  if (cartCouponInput) {
    cartCouponInput.value = appliedCoupon ? appliedCoupon.code : '';
    cartCouponInput.disabled = false;
  }
  const cartRm = document.getElementById('cartCouponRemoveBtn');
  if (cartRm) cartRm.style.display = appliedCoupon ? 'inline-block' : 'none';
  if (cartCouponFeedback) {
    if (appliedCoupon) {
      cartCouponFeedback.className = 'cart-coupon-feedback ok';
      cartCouponFeedback.textContent = couponAppliedLabel(appliedCoupon);
    } else {
      cartCouponFeedback.className = 'cart-coupon-feedback';
      cartCouponFeedback.textContent = '';
    }
  }

  // Shipping progress bar
  document.getElementById('ship-progress-wrap').innerHTML = discountedSub < CONFIG.freeShippingFrom
    ? `<div class="ship-progress">
        <div class="sp-text">Nog <strong>€${remaining.toFixed(2)}</strong> voor gratis verzending</div>
        <div class="sp-bar"><div class="sp-fill" style="width:${progress}%"></div></div>
       </div>`
    : `<div class="ship-free-msg"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px"><path d="M20 6L9 17l-5-5"></path></svg>Gratis verzending bereikt</div>`;

  // Cart items — thumbnail + title link to product page for quick return to that kit
  body.innerHTML = cart.map((item, i) => {
    const cslug = getCartItemSlug(item);
    const phref = cslug ? `product.php?slug=${encodeURIComponent(cslug)}` : '';
    const thumbInner = (item.image || item.image2 || item.image3)
      ? `<img src="${productImgSrc(item.image || item.image2 || item.image3)}" alt="${esc(item.name)}" onerror="this.style.display='none'">`
      : '<span class="citem-noimg" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span>';
    const thumbBlock = cslug
      ? `<a class="citem-img citem-thumb-link" href="${phref}" title="Product bekijken">${thumbInner}</a>`
      : `<div class="citem-img">${thumbInner}</div>`;
    const nameBlock = cslug
      ? `<a class="citem-name citem-name-link" href="${phref}" title="Product bekijken">${esc(item.name)}</a>`
      : `<p class="citem-name">${esc(item.name)}</p>`;
    const viewLink = cslug
      ? `<a class="citem-view-link" href="${phref}">Product bekijken</a>`
      : '';
    return `
    <div class="citem">
      ${thumbBlock}
      <div class="citem-info">
        ${nameBlock}
        ${viewLink}
        <p class="citem-meta">Maat:</p>
        <select class="citem-size-select" data-cart-index="${i}" onchange="setCartItemSize(${i}, this.value)">
          ${['XS','S','M','L','XL','XXL','2XL','3XL'].map(s => `<option value="${s}" ${item.size===s?'selected':''}>${s}</option>`).join('')}
        </select>
        <p class="citem-meta" style="margin-top:8px">${item.version==='player' ? 'Player · ' : ''}${esc(item.league)}${item.season ? ' · ' + esc(item.season) : ''}${item.printing_option==='custom' ? ' · ' + esc(item.print_name||'') + (item.print_number ? ' #' + esc(item.print_number) : '') : ''}</p>
        <div class="citem-row">
          <div class="qrow">
            <button type="button" class="qbtn" data-cart-index="${i}" data-cart-delta="-1" onclick="chQ(${i},-1)" aria-label="Aantal verlagen">−</button>
            <span class="qval">${item.qty}</span>
            <button type="button" class="qbtn" data-cart-index="${i}" data-cart-delta="1" onclick="chQ(${i},1)" aria-label="Aantal verhogen">+</button>
          </div>
          <span class="citem-price">€${(item.price * item.qty).toFixed(2)}</span>
        </div>
        <button type="button" class="crm" data-cart-remove="${i}" onclick="rmItem(${i})">Verwijderen</button>
      </div>
    </div>`;
  }).join('');

  // Totals
  document.getElementById('tSub').textContent = '€' + sub.toFixed(2);
  const discRow = document.getElementById('discountCartRow');
  if (discount > 0) {
    discRow.style.display = 'flex';
    document.getElementById('discountCartLabel').textContent = 'Korting (' + (appliedCoupon.type === 'percent' ? appliedCoupon.value + '%' : '€' + Number(appliedCoupon.value).toFixed(2)) + ')';
    document.getElementById('discountCartAmt').textContent = '-€' + discount.toFixed(2);
  } else {
    discRow.style.display = 'none';
  }
  document.getElementById('tShip').textContent = ship === 0 ? 'GRATIS' : '€' + ship.toFixed(2);
  document.getElementById('tTot').textContent = '€' + total.toFixed(2);
}

function mergeCartItem(line) {
  const ex = cart.find(i =>
    i.id === line.id &&
    i.size === line.size &&
    (i.version || 'fan') === (line.version || 'fan') &&
    (i.printing_option || 'none') === (line.printing_option || 'none') &&
    (i.print_name || '') === (line.print_name || '') &&
    (i.print_number || '') === (line.print_number || '') &&
    (i.print_badges || '') === (line.print_badges || '')
  );
  const p = PRODUCTS.find(x => x.id === line.id);
  const max = p ? maxQtyForProductSize(p, line.size, line.version) : Math.max(0, Math.floor(Number(line.stock) || 0));
  const add = Number(line.qty || 1);
  if (ex) {
    ex.qty = Math.min(max, Number(ex.qty || 0) + add);
  } else {
    const q = Math.min(max, add);
    if (q <= 0) return;
    cart.push({ ...line, qty: q });
  }
}

function clampCartToStock() {
  let changed = false;
  for (let i = cart.length - 1; i >= 0; i--) {
    const item = cart[i];
    const p = PRODUCTS.find(x => x.id === item.id);
    if (!p) continue;
    const max = maxQtyForProductSize(p, item.size, item.version);
    if (item.qty > max) {
      item.qty = max;
      changed = true;
    }
    if (item.qty <= 0) {
      cart.splice(i, 1);
      changed = true;
    }
  }
  if (changed) persistCartState();
}

function getMaxQtyForCartItem(item) {
  const p = PRODUCTS.find(x => x.id === item.id);
  if (!p) return Math.max(0, Math.floor(Number(item.stock) || 0));
  return maxQtyForProductSize(p, item.size, item.version);
}

function maxQtyForProductSize(p, sizeStr, version) {
  if (!p || !sizeStr) return 0;
  const ver = version || 'fan';
  const ss = versionStockSizes(p, ver);
  if (ss && Object.prototype.hasOwnProperty.call(ss, sizeStr)) {
    return Math.max(0, Math.floor(Number(ss[sizeStr]) || 0));
  }
  if (ver === 'player') return 0;
  return Math.max(0, Math.floor(Number(p.stock) || 0));
}

function getStockState(stock) {
  const s = Number(stock || 0);
  if (s <= 0) return { key: 'out', label: 'Niet op voorraad' };
  if (s <= 5) return { key: 'low', label: `Nog ${s} op voorraad!` };
  return { key: 'ok', label: 'Op voorraad' };
}

function productHasSellableStock(p, version) {
  if (!p) return false;
  const ver = version || 'fan';
  const ss = versionStockSizes(p, ver);
  if (ss && Object.keys(ss).length) {
    return Object.values(ss).some(q => Number(q) > 0);
  }
  if (ver === 'player') return false;
  return Number(p.stock || 0) > 0;
}

function calcDiscount(subtotal) {
  if (!appliedCoupon) return 0;
  const d = appliedCoupon.type === 'percent'
    ? subtotal * Math.min(100, Math.max(0, Number(appliedCoupon.value))) / 100
    : Number(appliedCoupon.value);
  // One coupon per order, capped so it can never exceed the order value.
  return Math.max(0, Math.min(d, subtotal));
}

function couponAppliedLabel(c) {
  if (!c) return '';
  const bit = c.type === 'percent' ? c.value + '%' : '€' + Number(c.value).toFixed(2).replace(/\.00$/, '');
  return '✓ Kortingscode toegepast — ' + bit + ' korting';
}

function setCartItemSize(i, size) {
  if (!cart[i]) return;
  cart[i].size = size;
  const p = PRODUCTS.find(x => x.id === cart[i].id);
  if (p) {
    const max = maxQtyForProductSize(p, size, cart[i].version);
    if (cart[i].qty > max) {
      cart[i].qty = max;
      if (max <= 0) {
        cart.splice(i, 1);
        showToast('⚠️ Die maat is uitverkocht — regel verwijderd');
        persistCartState();
        updateCount();
        renderCart();
        return;
      }
      showToast('⚠️ Aantal aangepast aan de voorraad voor deze maat');
    }
  }
  persistCartState();
  renderCart();
}

function chQ(i, d) {
  if (d > 0) {
    const maxStock = getMaxQtyForCartItem(cart[i]);
    if (cart[i].qty >= maxStock) {
      showToast('⚠️ Maximum voorraad voor deze maat bereikt');
      return;
    }
  }
  cart[i].qty += d;
  if (cart[i].qty <= 0) cart.splice(i, 1);
  persistCartState();
  updateCount();
  renderCart();
}

function showConfirm(orderId, email, total, ship, items, discount, waUrl, emailOk) {
  document.getElementById('waConfirmBtn').href = waUrl;

  // Order reference in card header
  document.getElementById('cord-id-val').textContent = '#' + orderId;

  // Subtitle (emailOk: true = sent, false = failed, undefined = still sending in background)
  const csub = document.getElementById('csub');
  csub.className = 'csub' + (emailOk === false && email ? ' err' : '');
  if (email && emailOk === true) {
    csub.textContent = 'Bevestigingsmail verstuurd naar ' + email + '. Je ontvangt straks een Tikkie.';
  } else if (email && emailOk === false) {
    csub.textContent = 'Bestelling geplaatst! E-mail niet verstuurd — neem zonodig contact op via WhatsApp.';
  } else if (email && emailOk === undefined) {
    csub.textContent = 'WhatsApp geopend. Bevestigingsmail naar ' + email + ' wordt verstuurd…';
  } else {
    csub.textContent = 'Je bestelling is verstuurd. We bevestigen via WhatsApp en sturen een Tikkie.';
  }

  // Order items in card body
  const ccard = document.getElementById('ccard');
  let html = '';
  items.forEach(i => {
    const print = i.printing_option === 'custom'
      ? (i.print_name || '') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' · ' + i.print_badges : '')
      : '';
    html += `<div class="cord-item">
      <div class="cord-item-name">
        ${i.qty > 1 ? '<strong>' + i.qty + '×</strong> ' : ''}${i.name}
        <small>${i.version==='player'?'Player · ':''}Maat: ${i.size}${print ? ' · ' + print : ''}</small>
      </div>
      <span style="white-space:nowrap;font-weight:600">€${(i.price * i.qty).toFixed(2)}</span>
    </div>`;
  });
  if (discount > 0) {
    html += `<div class="cord-meta" style="color:var(--accent);font-weight:600"><span>Korting</span><span>−€${discount.toFixed(2)}</span></div>`;
  }
  html += `<div class="cord-meta"><span>Verzending</span><span>${ship === 0 ? '<strong style="color:var(--accent)">GRATIS</strong>' : '€' + ship.toFixed(2)}</span></div>`;
  ccard.innerHTML = html;

  // Total row
  document.getElementById('cord-total-row').innerHTML =
    `<span>Totaal</span><span>€${total.toFixed(2)}</span>`;

  // Show screen, scroll to top
  const screen = document.getElementById('cscreen');
  screen.classList.add('on');
  screen.scrollTop = 0;
}

function getProductSearchScore(p, queryRaw) {
  const q = normalizeSearchText(queryRaw);
  if (!q) return 0;

  const name = normalizeSearchText(p.name || '');
  const league = normalizeSearchText(p.league || '');
  const cat = normalizeSearchText(p.cat || '');
  const path = normalizeSearchText(String(p.kits_path || '').replace(/\\/g, '/'));
  const season = normalizeSearchText(CONFIG.season || '');
  const hay = `${name} ${league} ${cat} ${path} ${season}`.trim();
  const tokens = q.split(' ').filter(Boolean);
  if (!tokens.length) return 0;

  // Every token must match strongly (word-start or full token inside the text).
  const allTokensMatch = tokens.every((t) => {
    if (t.length <= 1) return false;
    return new RegExp(`\\b${t}`).test(hay) || hay.includes(t);
  });
  if (!allTokensMatch) return 0;

  let score = 0;
  if (name === q) score += 300;
  if (name.startsWith(q)) score += 220;
  if (name.includes(q)) score += 170;
  if (league.includes(q)) score += 130;
  if (cat.includes(q)) score += 70;
  if (path.includes(q)) score += 40;

  for (const t of tokens) {
    if (name.startsWith(t)) score += 40;
    if (new RegExp(`\\b${t}`).test(name)) score += 26;
    if (new RegExp(`\\b${t}`).test(league)) score += 18;
    if (new RegExp(`\\b${t}`).test(cat)) score += 10;
  }

  return score;
}

function applyNavSearch() {
  const input = document.getElementById('navSearchInput');
  const next = ((input && input.value) || '').trim();
  if (navSearchActiveIndex >= 0 && navSearchSuggestions[navSearchActiveIndex]) {
    openProductFromNavSearch(navSearchSuggestions[navSearchActiveIndex].id);
    return;
  }
  currentSearchTerm = next;
  if (next) {
    const top = getNavSearchSuggestions(next, 1);
    if (top.length === 1) {
      closeNavSearch();
      goToProductPage(top[0].id);
      return;
    }
  }
  closeNavSearch();
  renderGrid(currentFilterCat);
}

function setSearchTerm(value) {
  currentSearchTerm = (value || '').trim();
  renderGrid();
}

function initRevealAnimations() {
  const io = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) entry.target.classList.add('on');
    });
  }, { threshold: 0.12 });
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));
}

function initKbeMobileTapFixes() {
  initKbeNavToggleAria();
  const catTabs = document.getElementById('catTabs');
  if (catTabs && !catTabs.dataset.kbeDelegated) {
    catTabs.dataset.kbeDelegated = '1';
    catTabs.addEventListener('click', function (e) {
      const btn = e.target && e.target.closest ? e.target.closest('button.ftab') : null;
      if (!btn || !catTabs.contains(btn)) return;
      if (btn.id === 'ftab-voorraad') {
        goToShopVoorraad();
        return;
      }
      const filter = btn.getAttribute('data-cat-filter');
      if (filter != null && filter !== '') setTypeFilter(filter, btn);
    });
  }
}

function setGalleryMainImage(filename, thumbEl) {
  const dMainImg = document.getElementById('dMainImg');
  if (filename) {
    dMainImg.innerHTML = `
      <button type="button" class="d-arrow prev" id="dPrevBtn" onclick="goGallery(-1)" aria-label="Vorige afbeelding">‹</button>
      <img src="${productImgSrc(filename)}" alt="Productafbeelding" onerror="this.style.display='none'">
      <button type="button" class="d-arrow next" id="dNextBtn" onclick="goGallery(1)" aria-label="Volgende afbeelding">›</button>`;
  } else {
    dMainImg.innerHTML = `
      <button type="button" class="d-arrow prev" id="dPrevBtn" onclick="goGallery(-1)" aria-label="Vorige afbeelding" disabled>‹</button>
      <span class="d-noimg" aria-hidden="true"><svg width="96" height="96" viewBox="0 0 24 24" fill="none" stroke="#c3c6bd" stroke-width="1.1" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span>
      <button type="button" class="d-arrow next" id="dNextBtn" onclick="goGallery(1)" aria-label="Volgende afbeelding" disabled>›</button>`;
  }
  const thumbs = document.querySelectorAll('#dThumbs .d-thumb');
  thumbs.forEach(t => t.classList.remove('on'));
  if (thumbEl) thumbEl.classList.add('on');
  else if (thumbs[0]) thumbs[0].classList.add('on');
  updateGalleryArrows();
}

function switchMaten(v) {
  ['fan','player'].forEach(t => {
    document.getElementById('mt-'+t).classList.toggle('on', t===v);
    document.getElementById('mp-'+t).classList.toggle('on', t===v);
  });
}

function refreshDetailDrawerPrice() {
  const p = PRODUCTS.find(pr => pr.id === currentDetailId);
  const el = document.getElementById('dPrice');
  if (!p || !el) return;
  let total = versionPrice(p, currentVersion);
  if (currentPrinting === 'custom') {
    const pn = (document.getElementById('printName').value || '').trim();
    const num = (document.getElementById('printNumber').value || '').trim();
    const bd = (document.getElementById('printBadges').value || '').trim();
    if (pn || num) total += unitPrintingExtra();
    if (bd) total += unitBadgeExtra();
  }
  el.textContent = '€' + total.toFixed(2);
}

function openDetail(id, updateUrl = true) {
  const p = PRODUCTS.find(p => p.id === id);
  if (!p) return;
  currentDetailId = id;
  currentSz = null; // always reset — no default size
  currentVersion = (!productHasSellableStock(p, 'fan') && productHasPlayer(p)) ? 'player' : 'fan';

  // Reset printing option (drawer)
  currentPrinting = 'none';
  document.getElementById('print-none-btn').classList.add('on');
  document.getElementById('print-custom-btn').classList.remove('on');
  document.getElementById('printFields').style.display = 'none';
  document.getElementById('printName').value = '';
  document.getElementById('printNumber').value = '';
  document.getElementById('printBadges').value = '';

  // Gallery (up to 3 images)
  currentDetailEmoji = p.emoji || '👕';
  const imgs = [p.image, p.image2, p.image3].filter(Boolean);
  const dMainImg = document.getElementById('dMainImg');
  const dThumbs = document.getElementById('dThumbs');
  currentGalleryImages = imgs;
  currentGalleryIndex = 0;
  if (imgs.length) {
    dThumbs.innerHTML = imgs.map((img, idx) => `
      <div class="d-thumb${idx === 0 ? ' on' : ''}" onclick="setGalleryMainImageByIndex(${idx})">
        <img src="${productImgSrc(img)}" alt="Galerij ${idx + 1}" onerror="this.style.display='none'">
      </div>`).join('');
    setGalleryMainImageByIndex(0);
  } else {
    dThumbs.innerHTML = '';
    dMainImg.innerHTML = `
      <button type="button" class="d-arrow prev" id="dPrevBtn" onclick="goGallery(-1)" aria-label="Vorige afbeelding" disabled>‹</button>
      <span class="d-noimg" aria-hidden="true"><svg width="96" height="96" viewBox="0 0 24 24" fill="none" stroke="#c3c6bd" stroke-width="1.1" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span>
      <button type="button" class="d-arrow next" id="dNextBtn" onclick="goGallery(1)" aria-label="Volgende afbeelding" disabled>›</button>`;
    updateGalleryArrows();
  }

  document.getElementById('dLeague').textContent = p.league;
  document.getElementById('dName').textContent = p.name;
  document.getElementById('dDesc').textContent = (p.description && p.description.trim())
    ? p.description
    : 'Licht, ademend polyester. Zelfde design als op het veld. Wasbaar op 30°C.';

  document.getElementById('dPrice').textContent = '€' + versionPrice(p, currentVersion).toFixed(2);
  document.getElementById('dPolicy').innerHTML = getDrawerPolicyHTML();
  renderDrawerVersion(p);

  const vss = versionStockSizes(p, currentVersion);
  const vUnits = vss ? Object.values(vss).reduce((a, q) => a + Math.max(0, Number(q) || 0), 0) : (currentVersion === 'player' ? 0 : Number(p.stock || 0));
  const stock = getStockState(vUnits);
  const stockMsg = document.getElementById('dStockMsg');
  stockMsg.className = 'stock-msg ' + stock.key;
  stockMsg.textContent = stock.label;

  // Voorraad info
  const voorraadEl = document.getElementById('dVoorraadText');
  if (voorraadEl) {
    voorraadEl.textContent = parseInt(p.in_voorraad) === 1
      ? 'Op voorraad: levering binnen 1–2 werkdagen'
      : 'Niet op voorraad: 7–12 werkdagen';
  }

  // Reset season to default 25/26
  currentSeason = '25/26';
  document.querySelectorAll('#seasonRow .sz').forEach(b => {
    b.classList.toggle('on', b.textContent.trim() === '25/26');
  });

  // Reset sizes — no pre-selection (excluding season row)
  document.querySelectorAll('.sz-row .sz').forEach(b => b.classList.remove('on'));

  const kjd = document.getElementById('kidsJerseyDrawerChart');
  if (kjd) kjd.hidden = detectProductType(p) !== 'kids';

  // Reset add button to disabled
  const addBtn = document.getElementById('dAddBtn');
  const soldOut = !productHasSellableStock(p, currentVersion);
  addBtn.disabled = soldOut;
  addBtn.textContent = soldOut ? 'Niet op voorraad' : 'Kies eerst een maat';

  // Show hint
  const hint = document.getElementById('szHint');
  hint.textContent = 'Kies een maat om verder te gaan';
  hint.className = 'sz-hint';

  document.getElementById('dbg').classList.add('on');
  document.getElementById('drawer').classList.add('on');
  if (updateUrl) {
    const url = new URL(window.location.href);
    url.searchParams.set('product', getProductShareParam(p));
    window.history.replaceState({}, '', url.toString());
  }
}

function versionPrice(p, version) {
  if ((version || 'fan') === 'player' && p && p.player_price != null && p.player_price !== '') return Number(p.player_price);
  return Number((p && p.price) || 0);
}

function productHasPlayer(p) {
  if (!p) return false;
  if (p.player_price != null && p.player_price !== '') return true;
  const ps = p.player_stock_sizes;
  return !!(ps && typeof ps === 'object' && Object.values(ps).some(q => Number(q) > 0));
}

function productHasSize(p, sizeKey) {
  if (!p || !sizeKey || sizeKey === 'all') return true;
  const ss = p.stock_sizes;
  if (ss && typeof ss === 'object') {
    if (sizeKey === 'XXL' || sizeKey === '2XL') {
      return Number(ss.XXL || 0) + Number(ss['2XL'] || 0) > 0;
    }
    if (Object.prototype.hasOwnProperty.call(ss, sizeKey)) {
      return Number(ss[sizeKey] || 0) > 0;
    }
  }
  const hay = `${p.name || ''} ${p.description || ''}`.toUpperCase();
  return new RegExp(`\\b${sizeKey}\\b`).test(hay);
}

function versionStockSizes(p, version) {
  if (!p) return null;
  if ((version || 'fan') === 'player') return (p.player_stock_sizes && typeof p.player_stock_sizes === 'object') ? p.player_stock_sizes : null;
  return (p.stock_sizes && typeof p.stock_sizes === 'object') ? p.stock_sizes : null;
}

async function applyCoupon() {
  const input = document.getElementById('couponInput');
  const feedback = document.getElementById('couponFeedback');
  const code = input.value.trim();

  if (!code) { feedback.className = 'coupon-feedback err'; feedback.textContent = 'Voer een kortingscode in.'; return; }

  feedback.className = 'coupon-feedback';
  feedback.textContent = 'Even geduld…';
  const res = await validateCouponCode(code);
  if (res.ok) {
    appliedCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    persistCouponState();
    feedback.className = 'coupon-feedback ok';
    feedback.textContent = couponAppliedLabel(appliedCoupon);
    input.value = res.code;
    renderCart();
    updateModalRecap();
  } else {
    appliedCoupon = null;
    persistCouponState();
    feedback.className = 'coupon-feedback err';
    feedback.textContent = '✗ ' + (res.error === 'network' ? 'Kon code niet controleren.' : (res.error || 'Ongeldige kortingscode.'));
    renderCart();
  }
}

async function applyCouponFromCart() {
  const input = document.getElementById('cartCouponInput');
  const feedback = document.getElementById('cartCouponFeedback');
  const code = (input.value || '').trim();
  if (!code) {
    feedback.className = 'cart-coupon-feedback err';
    feedback.textContent = 'Voer een kortingscode in.';
    return;
  }
  feedback.className = 'cart-coupon-feedback';
  feedback.textContent = 'Even geduld…';
  const res = await validateCouponCode(code);
  if (res.ok) {
    appliedCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    persistCouponState();
    feedback.className = 'cart-coupon-feedback ok';
    feedback.textContent = couponAppliedLabel(appliedCoupon);
    input.value = res.code;
  } else {
    appliedCoupon = null;
    persistCouponState();
    feedback.className = 'cart-coupon-feedback err';
    feedback.textContent = '✗ ' + (res.error === 'network' ? 'Kon code niet controleren.' : (res.error || 'Ongeldige kortingscode.'));
  }
  renderCart();
  updateModalRecap();
}

async function ensureCheckoutCsrf() {
  if (window.__checkoutCsrf) return window.__checkoutCsrf;
  const r = await fetch('api/checkout_csrf.php', { credentials: 'same-origin' });
  const j = await r.json();
  if (!r.ok || !j || !j.ok || !j.csrf) throw new Error('csrf');
  window.__checkoutCsrf = j.csrf;
  return j.csrf;
}

async function placeOrder() {
  if (window.__checkoutOrderInFlight) return;
  const name   = document.getElementById('fName').value.trim();
  const phone  = document.getElementById('fPhone').value.trim();
  const street = document.getElementById('fStreet').value.trim();
  const email  = document.getElementById('fEmail').value.trim();
  const notes  = document.getElementById('fNotes').value.trim();

  if (!name || !phone || !street) {
    showToast('⚠️ Vul alle verplichte velden in');
    return;
  }
  if (!email || !email.includes('@')) {
    showToast('⚠️ Voer een geldig e-mailadres in');
    return;
  }

  const country = getCheckoutCountry();
  const zip  = document.getElementById('fZip').value.trim();
  const city = document.getElementById('fCity').value.trim();
  const addrErr = checkoutAddressClientOk(street, zip, city);
  if (addrErr) {
    showToast('⚠️ ' + addrErr);
    return;
  }

  const sub      = cart.reduce((s, i) => s + i.price * i.qty, 0);
  const discount = calcDiscount(sub);
  const discSub  = sub - discount;
  const ship     = discSub >= CONFIG.freeShippingFrom ? 0 : CONFIG.shippingCost;
  const total    = discSub + ship;

  const btn = document.getElementById('msub');
  const btnSvg = `<svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> Verstuur naar WhatsApp`;
  btn.disabled = true;
  btn.textContent = 'Bestelling plaatsen…';

  let orderRes = null;
  try {
    window.__checkoutOrderInFlight = true;
    const csrf = await ensureCheckoutCsrf();
    const hpEl = document.getElementById('checkoutHpWebsite');
    const website = hpEl ? String(hpEl.value || '') : '';
    const req = await fetch('place-order.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify({
        name, email, phone, street, zip, city, country, notes,
        csrf_token: csrf,
        idempotency_key: checkoutIdempotencyKey(),
        website,
        coupon_code: appliedCoupon ? appliedCoupon.code : null,
        items: cart.map(i => ({
          product_id: i.id,
          name: i.name,
          size: i.size,
          qty: i.qty,
          price: i.price,
          printing_option: i.printing_option || 'none',
          print_name: i.print_name || null,
          print_number: i.print_number || null,
          print_badges: i.print_badges || null
        }))
      })
    });
    orderRes = await req.json();
    if (!req.ok || !orderRes.success) {
      if (req.status === 403) window.__checkoutCsrf = null;
      showToast('⚠️ ' + (orderRes.error || 'Bestelling mislukt'));
      btn.disabled = false;
      btn.innerHTML = btnSvg;
      return;
    }
    window.__checkoutIdempotencyKey = null;
  } catch (_) {
    showToast('⚠️ Kan nu geen bestelling plaatsen');
    btn.disabled = false;
    btn.innerHTML = btnSvg;
    return;
  } finally {
    window.__checkoutOrderInFlight = false;
  }

  const snap = JSON.parse(JSON.stringify(cart));

  // Use server-verified totals (server recalculates everything — never trust client totals)
  const orderId     = orderRes.order_id || ('KD-' + Date.now().toString().slice(-6));
  const verDiscount = orderRes.discount  ?? discount;
  const verShip     = orderRes.shipping  ?? ship;
  const verTotal    = orderRes.total     ?? total;

  // WhatsApp message (uses snap = cart before clearing)
  const countryLabels = { nl: 'Nederland', be: 'België', de: 'Duitsland', fr: 'Frankrijk' };
  const addrLine = country !== 'nl'
    ? `${street}, ${zip} ${city} (${countryLabels[country] || country})`
    : `${street}, ${zip} ${city}`;
  let msg = `🛒 *NIEUWE BESTELLING — KitsByElbaa*\nBestelling: *${orderId}*\n\n*Klant*\nNaam: ${name}\nTel: ${phone}\nAdres: ${addrLine}\nE-mail: ${email}`;
  if (notes) msg += `\nOpmerkingen: ${notes}`;
  msg += `\n\n*Artikelen*\n`;
  snap.forEach(i => {
    const extra = i.printing_option==='custom'
      ? ' · ' + (i.print_name||'') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' (' + i.print_badges + ')' : '')
      : '';
    const seasonStr = i.season ? ` · Seizoen ${i.season}` : '';
    msg += `• ${i.qty}× ${i.name} (${i.size}${extra}${seasonStr}) — €${(i.price * i.qty).toFixed(2)}\n`;
  });
  if (verDiscount > 0) msg += `\nKorting (${orderRes.coupon_applied?.toUpperCase() || 'code'}): -€${verDiscount.toFixed(2)}`;
  msg += `\nVerzending: ${verShip === 0 ? 'GRATIS' : '€' + verShip.toFixed(2)}\n*Totaal: €${verTotal.toFixed(2)}*\n\n_Stuur een Tikkie. Bedankt!_`;

  // Confirmation email (sent after WhatsApp opens — EmailJS must not block the WA redirect)
  const itemsText = snap.map(i => {
    const extra = i.printing_option==='custom'
      ? ' · ' + (i.print_name||'') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' (' + i.print_badges + ')' : '')
      : '';
    return `${i.qty}x ${i.name} (${i.version==='player'?'Player · ':''}Maat: ${i.size}${extra}) — €${(i.price * i.qty).toFixed(2)}`;
  }).join('\n');
  const ordersForEmail = snap.map(i => {
    const extra = i.printing_option==='custom'
      ? ' · ' + (i.print_name||'') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' (' + i.print_badges + ')' : '')
      : '';
    return {
      name: i.name + ' (' + i.size + extra + ')',
      units: i.qty,
      price: (i.price * i.qty).toFixed(2),
      image_url: absoluteProductImgUrl(primaryImageFileForCartLine(i)),
      league: i.league || '',
      size: i.size || '',
    };
  });
  const emailSiteBase = getPublicSiteBase().replace(/\/$/, '');
  const params = {
    new_order: 1,
    order_id: orderId,
    email,
    customer_name: name,
    customer_email: email,
    order_items: itemsText,
    shipping_cost: verShip === 0 ? 'GRATIS' : '€' + verShip.toFixed(2),
    order_total: '€' + verTotal.toFixed(2),
    order_discount: verDiscount > 0 ? ('€' + verDiscount.toFixed(2)) : '',
    coupon_code: orderRes.coupon_applied || '',
    customer_address: `${street}, ${zip} ${city}`,
    customer_phone: phone,
    customer_notes: notes || '',
    orders: ordersForEmail,
    cost: {
      shipping: verShip === 0 ? 'GRATIS' : '€' + verShip.toFixed(2),
      total: '€' + verTotal.toFixed(2),
    },
    order_date: new Date().toLocaleDateString('nl-NL', { day: '2-digit', month: 'short', year: 'numeric' }),
    site_url: emailSiteBase,
    logo_url: emailSiteBase ? (emailSiteBase + '/images/logo.png') : '',
  };
  const emailPayload = { ...params, to_email: email, to_name: name };

  const waUrl = 'https://wa.me/' + kbeWaDigits() + '?text=' + encodeURIComponent(msg);
  window.open(waUrl, '_blank');

  // Clear cart only after server confirms success
  cart = [];
  appliedCoupon = null;
  persistCartState();
  updateCount();
  closeModal();
  showConfirm(orderId, email, verTotal, verShip, snap, verDiscount, waUrl, undefined);

  const kbeOrderEmailFailMsg = (function () {
    const h = orderRes && orderRes.order_email_hint;
    if (h === 'emailjs_allow_nonbrowser') {
      return 'Bestelling geplaatst. Geen mail: EmailJS → Account → Security → zet “Allow EmailJS API for non-browser applications” aan (nodig voor mail vanaf de server).';
    }
    if (h === 'emailjs_private_key') {
      return 'Bestelling geplaatst. Geen mail: zet in .env KITS_EMAILJS_ACCESS_TOKEN (private key uit EmailJS Security), of schakel die verplichting uit.';
    }
    return 'Bestelling geplaatst! E-mail niet verstuurd — neem zonodig contact op via WhatsApp.';
  })();

  if (orderRes.order_email_sent) {
    const el = document.getElementById('csub');
    if (el && email) {
      el.className = 'csub';
      el.textContent = 'Bevestigingsmail verstuurd naar ' + email + '. Je ontvangt een Tikkie (meestal binnen 24 uur).';
    }
  } else if (typeof emailjs !== 'undefined' && CONFIG.emailjsPk && CONFIG.emailjsService && CONFIG.emailjsTemplate) {
    emailjs.send(CONFIG.emailjsService, CONFIG.emailjsTemplate, emailPayload, kbeEmailJsSendOpts())
      .then(() => {
        const el = document.getElementById('csub');
        if (!el || !email) return;
        el.className = 'csub';
        el.textContent = 'Bevestigingsmail verstuurd naar ' + email + '. Je ontvangt een Tikkie (meestal binnen 24 uur).';
      })
      .catch(() => {
        const el = document.getElementById('csub');
        if (!el || !email) return;
        el.className = 'csub err';
        el.textContent = kbeOrderEmailFailMsg;
      });
  } else {
    const el = document.getElementById('csub');
    if (el && email) {
      el.className = 'csub err';
      el.textContent = kbeOrderEmailFailMsg;
    }
  }

  btn.disabled = false;
  btn.innerHTML = `<svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> Verstuur naar WhatsApp`;
}

async function restoreCouponState() {
  try {
    const raw = localStorage.getItem(COUPON_STORAGE_KEY);
    if (!raw) return;
    const parsed = JSON.parse(raw);
    if (!parsed || !parsed.code) return;
    const res = await validateCouponCode(parsed.code);
    if (res.ok) {
      appliedCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    } else {
      localStorage.removeItem(COUPON_STORAGE_KEY);
    }
  } catch (_) {}
}

async function validateCouponCode(raw) {
  const code = (raw || '').trim();
  if (!code) return { ok: false };
  try {
    const r = await fetch('api/coupon_validate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code }),
      credentials: 'same-origin'
    });
    return await r.json();
  } catch (_) {
    return { ok: false, error: 'network' };
  }
}
