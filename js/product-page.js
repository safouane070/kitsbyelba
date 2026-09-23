function kbeWaDigits() {
  const d = String(CONFIG.whatsapp || '31684446255').replace(/\D/g, '');
  return d || '31684446255';
}
let PRODUCTS = [];
let P = null;
let gallery = [];
let gi = 0;
let qty = 1;
let pickedSize = '';
let pickedVersion = 'fan';
let pdpCart = [];
let __kbePdpCartHistory = 0;
const CART_STORAGE_KEY = 'kbe_cart_main';
/** Zelfde sleutel als index.html / shop.html — array van product-id’s */
const WISHLIST_STORAGE_KEY = 'kbe_wishlist_main';
let wishlist = [];

function isWishlisted(id) {
  return wishlist.includes(Number(id));
}
function saveWishlistState() {
  try { localStorage.setItem(WISHLIST_STORAGE_KEY, JSON.stringify(wishlist)); } catch (_) {}
}
function syncPdpWishlistButton() {
  const btn = document.getElementById('pdpWishBtn');
  if (!btn || !P) return;
  const on = isWishlisted(P.id);
  btn.classList.toggle('on', on);
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.setAttribute('aria-label', on ? 'Verwijderen van wishlist' : 'Toevoegen aan wishlist');
}
function togglePdpWishlist() {
  if (!P) return;
  const pid = Number(P.id);
  const i = wishlist.indexOf(pid);
  const on = i < 0;
  if (on) wishlist.push(pid);
  else wishlist.splice(i, 1);
  saveWishlistState();
  syncPdpWishlistButton();
  const fb = document.getElementById('pdpWishFb');
  if (fb) {
    fb.textContent = on
      ? 'Toegevoegd aan je wishlist. Open Wishlist in het menu om alles te zien.'
      : 'Verwijderd uit je wishlist.';
    fb.hidden = false;
    clearTimeout(window.__pdpWishFbT);
    window.__pdpWishFbT = setTimeout(() => {
      fb.hidden = true;
      fb.textContent = '';
    }, 2600);
  }
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
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initKbeNavToggleAria);
} else {
  initKbeNavToggleAria();
}

// Vaste site-promo: 10% / KITSBYELBA in banner; extra codes via admin + api/coupon_validate.php
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

function couponAppliedLabel(c) {
  if (!c) return '';
  const bit = c.type === 'percent' ? c.value + '%' : eur(Number(c.value)).replace(/,00$/, '');
  return 'Kortingscode toegepast — ' + bit + ' korting';
}

function calcPdpDiscount(sub) {
  if (!pdpCoupon) return 0;
  if (pdpCoupon.type === 'percent') return sub * pdpCoupon.value / 100;
  return Math.min(pdpCoupon.value, sub);
}
const COUPON_STORAGE_KEY = 'kbe_coupon_main';
let pdpCoupon = null;

function getStockState(stock){
  const s = Number(stock || 0);
  if (s <= 0) return { key:'out', label:'Niet op voorraad' };
  if (s <= 5) return { key:'low', label:`Nog maar ${s} stuks!` };
  return { key:'ok', label:'Op voorraad' };
}
/** Zelfde `in_voorraad`-logica als shop: alleen bij snelle voorraad groene “op voorraad”-tekst; anders nabestelling 7–12 dagen. */
function getPdpStockState(p){
  if (!p) return { key:'out', label:'Niet op voorraad' };
  const ss = versionStockSizes(p, pickedVersion);
  const units = ss ? Object.values(ss).reduce((a, q) => a + Math.max(0, Number(q) || 0), 0) : Number(p.stock || 0);
  if (units <= 0) return { key:'out', label:'Niet op voorraad' };
  // Op voorraad → snelle levering 1–2 werkdagen; anders nabestelling 7–12 werkdagen.
  if (parseInt(p.in_voorraad, 10) === 1) return getStockState(units);
  return { key:'slow', label:'Nabestelling — levering 7–12 werkdagen' };
}
/** Zelfde logica als shop / index: kids-tenues tonen de KIDS Jersey Size Chart bij maat. */
function isKidsProduct(p){
  if (!p) return false;
  const name = String(p.name || '').toLowerCase();
  const desc = String(p.description || '').toLowerCase();
  if (name.includes('retro kids')) return true;
  if (name.includes('kids kit') || name.includes(' kids ') || /\bkids\b/.test(name)) return true;
  if (/\bkids\b/.test(desc)) return true;
  return false;
}
/**
 * Fan vs player — gelijk aan shop/index: eerst `version` uit admin, daarna tekst (EN/NL).
 * Spelerskits: geen 3XL/4XL op de PDP (zie renderProduct → allSizes).
 */
function detectPdpProductVersion(p) {
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
/* Fan = basis (price / stock_sizes); Player = aparte prijs + aparte per-maat voorraad. */
function versionStockSizes(p, version){
  if (!p) return null;
  if (version === 'player') {
    const ps = p.player_stock_sizes;
    // Aparte player-voorraad indien ingesteld; anders deelt Player de gewone voorraad.
    if (ps && typeof ps === 'object' && Object.keys(ps).length) return ps;
  }
  return (p.stock_sizes && typeof p.stock_sizes === 'object') ? p.stock_sizes : null;
}
function versionPrice(p, version){
  if (version === 'player' && p && p.player_price != null && p.player_price !== '') return Number(p.player_price);
  return Number((p && p.price) || 0);
}
function productHasPlayer(p){
  if (!p) return false;
  if (p.player_price != null && p.player_price !== '') return true;
  const ps = p.player_stock_sizes;
  return !!(ps && typeof ps === 'object' && Object.values(ps).some(q => Number(q) > 0));
}
function maxQtyForProductSize(p, sizeStr){
  if (!p || !sizeStr) return 0;
  const ss = versionStockSizes(p, pickedVersion);
  if (ss && typeof ss === 'object') {
    if (sizeStr === 'XXL') {
      return Math.max(0, Math.floor(Number(ss.XXL || 0) + Number(ss['2XL'] || 0)));
    }
    if (Object.prototype.hasOwnProperty.call(ss, sizeStr)) {
      return Math.max(0, Math.floor(Number(ss[sizeStr]) || 0));
    }
  }
  return Math.max(0, Math.floor(Number(p.stock) || 0));
}
function productHasSellableStock(p){
  if (!p) return false;
  const ss = versionStockSizes(p, pickedVersion);
  if (ss && typeof ss === 'object' && Object.keys(ss).length) {
    return Object.values(ss).some(q => Number(q) > 0);
  }
  return Number(p.stock || 0) > 0;
}
function pdpPrintingMatch(i, po, pn, pnum, pb){
  return (i.printing_option || 'none') === po &&
    (i.print_name || '') === pn &&
    (i.print_number || '') === pnum &&
    (i.print_badges || '') === pb;
}
function pdpQtyUsedForVariant(pId, sizeStr, po, pn, pnum, pb, version){
  const ver = version || 'fan';
  return pdpCart.reduce((s, i) => {
    if (i.id !== pId || i.size !== sizeStr || (i.version || 'fan') !== ver || !pdpPrintingMatch(i, po, pn, pnum, pb)) return s;
    return s + Number(i.qty || 0);
  }, 0);
}
function clampPdpCartToStock(){
  let changed = false;
  for (let i = pdpCart.length - 1; i >= 0; i--) {
    const item = pdpCart[i];
    const p = PRODUCTS.find(x => x.id === item.id);
    if (!p) continue;
    const max = maxQtyForProductSize(p, item.size);
    if (item.qty > max) {
      item.qty = max;
      changed = true;
    }
    if (item.qty <= 0) {
      pdpCart.splice(i, 1);
      changed = true;
    }
  }
  if (changed) {
    try { localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart)); } catch (_) {}
  }
}
function unitBadgeExtraPdp() {
  const v = Number(CONFIG.badgeExtraPrice);
  return Number.isFinite(v) && v >= 0 ? v : 3;
}
/** Stukprijs incl. bedrukking/badge — zelfde regels als addToCart en place-order.php. */
function pdpUnitPrice(f){
  const printAdd = f.printing_option === 'custom' && (f.print_name || f.print_number) ? Number(CONFIG.customPrintingPrice || 5) : 0;
  const badgeAdd = f.printing_option === 'custom' && f.print_badges ? unitBadgeExtraPdp() : 0;
  return { unit: versionPrice(P, pickedVersion) + printAdd + badgeAdd, extra: printAdd + badgeAdd };
}
/** Prijs live bijwerken zodra de klant naam/nummer/badge invult: geen verrassing in de winkelwagen. */
function updatePdpPrice(){
  const el = document.getElementById('pPrice');
  if (!el || !P) return;
  const f = getPendingPrintFields();
  const { unit, extra } = pdpUnitPrice(f);
  el.textContent = eur(unit);
  if (extra > 0) {
    const note = document.createElement('span');
    note.className = 'price-extra';
    note.textContent = 'incl. ' + eur(extra) + ((f.print_name || f.print_number) ? (f.print_badges ? ' bedrukking & badge' : ' bedrukking') : ' badge');
    el.appendChild(note);
  }
}
function getPendingPrintFields(){
  const pn = (document.getElementById('printName').value || '').trim().slice(0,15);
  const num = (document.getElementById('printNumber').value || '').trim().replace(/\D/g,'').slice(0,4);
  const badges = (document.getElementById('printBadges').value || '').trim();
  const custom = !!(pn || num || badges);
  return {
    printing_option: custom ? 'custom' : 'none',
    print_name: custom ? pn : '',
    print_number: custom ? num : '',
    print_badges: custom ? badges : ''
  };
}
function getLeagueBadgeForProduct(p){
  const league = String(p?.league || '').toLowerCase();
  if (league.includes('premier')) return 'Premier League';
  if (league.includes('la liga')) return 'La Liga';
  if (league.includes('serie a')) return 'Serie A';
  if (league.includes('bundesliga')) return 'Bundesliga';
  if (league.includes('erediv')) return 'Eredivisie';
  if (league.includes('ligue 1') || league.includes('ligue1')) return 'Ligue 1';
  return '';
}
function renderBadgeOptions(p){
  const sel = document.getElementById('printBadges');
  if (!sel) return;
  const current = (sel.value || '').trim();
  const options = [''];
  const leagueBadge = getLeagueBadgeForProduct(p);
  // Champions League alleen bij clubs uit een grote competitie — niet bij landenteams (Curaçao, WK) of overig.
  if (leagueBadge) options.push(leagueBadge, 'Champions League');
  sel.innerHTML = options.map(v => `<option value="${v}">${v || 'Geen badge'}</option>`).join('');
  sel.value = options.includes(current) ? current : '';
  const noChoice = options.length === 1;
  sel.hidden = noChoice;
  const lbl = document.querySelector('label[for="printBadges"]');
  if (lbl) lbl.hidden = noChoice;
}
function productImgSrc(file){
  if(!file) return '';
  let s = String(file).trim().replace(/\\/g,'/').replace(/^\/+/,'');
  if(/^https?:\/\//i.test(s)) return s;
  const enc = (seg) => encodeURIComponent(seg);
  const prefix = 'uploads/products/';
  while(s.toLowerCase().startsWith(prefix)) s = s.slice(prefix.length);
  if(!s) return '';
  if(s.indexOf('/') !== -1) return prefix + s.split('/').filter(Boolean).map(enc).join('/');
  return prefix + enc(s);
}

function slugify(name){ return (name||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
function shareSlug(p){ return `${p.id}-${slugify(p.name)}`; }
function escHtml(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function parseIdFromQuery(){
  const q = new URLSearchParams(location.search);
  const s = q.get('slug') || '';
  const fromSlug = parseInt(s.split('-')[0],10);
  if (Number.isFinite(fromSlug)) return fromSlug;
  const fromId = parseInt(q.get('id') || '',10);
  return Number.isFinite(fromId) ? fromId : null;
}

// ── BACK TO TOP ──
(function(){
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'back-to-top';
  btn.title = 'Terug naar boven';
  btn.innerHTML = '↑';
  btn.onclick = () => (typeof kbeScrollToTop === 'function' ? kbeScrollToTop() : window.scrollTo(0, 0));
  document.body.appendChild(btn);
  window.addEventListener('scroll', () => btn.classList.toggle('on', window.scrollY > 300), {passive:true});
})();

Promise.all([
  fetch('api/config.php').then(r=>r.json()).catch(()=>({})),
  fetch('api/products.php?view=detail').then(async r => {
    const j = await r.json().catch(() => []);
    return Array.isArray(j) ? j : [];
  }).catch(() => [])
]).then(async ([cfg, prods])=>{
  Object.assign(CONFIG,cfg||{});
  PRODUCTS = prods || [];
  const id = parseIdFromQuery();
  P = PRODUCTS.find(x=>x.id===id) || PRODUCTS[0] || null;
  if(!P){ document.getElementById('pName').textContent='Product niet gevonden'; return; }
  // Default versie: Fan, tenzij Fan geen voorraad heeft maar Player wel.
  pickedVersion = (function(){
    const fanOk = (P.stock_sizes && typeof P.stock_sizes === 'object')
      ? Object.values(P.stock_sizes).some(q => Number(q) > 0)
      : Number(P.stock || 0) > 0;
    return (!fanOk && productHasPlayer(P)) ? 'player' : 'fan';
  })();
  // Shared cart key with index.html (with fallback migration from legacy key)
  try {
    pdpCart = JSON.parse(localStorage.getItem(CART_STORAGE_KEY) || '[]');
  } catch (_) {
    pdpCart = [];
  }
  if (!Array.isArray(pdpCart) || !pdpCart.length) {
    try {
      const legacy = JSON.parse(localStorage.getItem('kbe_cart') || '[]');
      if (Array.isArray(legacy) && legacy.length) {
        pdpCart = legacy;
        localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
        localStorage.removeItem('kbe_cart');
      }
    } catch (_) {}
  }
  try {
    const wr = localStorage.getItem(WISHLIST_STORAGE_KEY);
    const parsed = wr ? JSON.parse(wr) : [];
    wishlist = Array.isArray(parsed) ? parsed.map(Number).filter(Number.isFinite) : [];
  } catch (_) {
    wishlist = [];
  }
  await restoreCouponState();
  recalcPdpCartLinePrices();
  clampPdpCartToStock();
  renderProduct();
  renderPdpCart();
});

/** Zelfde prijslogica als place-order.php: bedrukking (naam/nummer) + optionele badge. */
function recalcPdpCartLinePrices() {
  if (!Array.isArray(pdpCart) || !PRODUCTS.length) return;
  let changed = false;
  pdpCart.forEach(i => {
    const p = PRODUCTS.find(x => x.id === i.id);
    if (!p) return;
    const opt = (i.printing_option || 'none') === 'custom' ? 'custom' : 'none';
    const pn = String(i.print_name || '').trim();
    const num = String(i.print_number || '').trim();
    const bd = String(i.print_badges || '').trim();
    const printAdd = opt === 'custom' && (pn || num) ? Number(CONFIG.customPrintingPrice || 5) : 0;
    const badgeAdd = opt === 'custom' && bd ? unitBadgeExtraPdp() : 0;
    const expected = Number(p.price) + printAdd + badgeAdd;
    if (Math.abs(Number(i.price) - expected) > 0.005) {
      i.price = expected;
      changed = true;
    }
  });
  if (changed) {
    try { localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart)); } catch (_) {}
  }
}

async function restoreCouponState(){
  try{
    const raw = localStorage.getItem(COUPON_STORAGE_KEY);
    if(!raw) return;
    const parsed = JSON.parse(raw);
    if(!parsed || !parsed.code) return;
    const res = await validateCouponCode(parsed.code);
    if(res.ok){
      pdpCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    } else {
      localStorage.removeItem(COUPON_STORAGE_KEY);
    }
  }catch(_){}
}
function persistCouponState(){
  try{
    if(pdpCoupon) localStorage.setItem(COUPON_STORAGE_KEY, JSON.stringify(pdpCoupon));
    else localStorage.removeItem(COUPON_STORAGE_KEY);
  }catch(_){}
}

function orderedImages(p){
  const map = {1:p.image,2:p.image2,3:p.image3};
  const order = String(p.image_order || '1,2,3').split(',').map(n=>parseInt(n,10)).filter(n=>[1,2,3].includes(n));
  const imgs = order.map(n=>map[n]).filter(Boolean);
  if(!imgs.length){ const fb=[p.image,p.image2,p.image3].filter(Boolean); return fb; }
  return imgs;
}

let stockNotifySize = '';
let stockNotifyCsrf = '';

function openStockNotify(sz) {
  stockNotifySize = sz;
  const panel = document.getElementById('stockNotifyPanel');
  const hint = document.getElementById('stockNotifyHint');
  const fb = document.getElementById('stockNotifyFb');
  if (fb) {
    fb.textContent = '';
    fb.style.color = '';
  }
  if (hint) {
    hint.textContent = 'We mailen je wanneer maat ' + sz + ' weer op voorraad is:';
  }
  if (panel) {
    panel.classList.add('on');
  }
  const em = document.getElementById('stockNotifyEmail');
  if (em) {
    em.focus();
  }
}

async function submitStockNotify() {
  const email = (document.getElementById('stockNotifyEmail').value || '').trim();
  const fb = document.getElementById('stockNotifyFb');
  if (!email || !email.includes('@')) {
    if (fb) {
      fb.style.color = '#991b1b';
      fb.textContent = 'Voer een geldig e-mailadres in.';
    }
    return;
  }
  if (!P || !stockNotifySize) return;
  try {
    const csrf = await ensureStockNotifyCsrf();
    const r = await fetch('api/stock_notify.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ email, product_id: P.id, size: stockNotifySize, csrf_token: csrf }),
      credentials: 'same-origin'
    });
    const j = await r.json();
    if (j.ok) {
      if (fb) {
        fb.style.color = '#065f46';
        fb.textContent = 'Je staat op de lijst — we mailen zodra deze maat terug is.';
      }
    } else {
      if (fb) {
        fb.style.color = '#991b1b';
        fb.textContent = j.error || 'Kon niet opslaan.';
      }
    }
  } catch (e) {
    if (fb) {
      fb.style.color = '#991b1b';
      fb.textContent = 'Netwerkfout — probeer opnieuw.';
    }
  }
}

async function ensureStockNotifyCsrf() {
  if (stockNotifyCsrf) return stockNotifyCsrf;
  const r = await fetch('api/checkout_csrf.php', { credentials: 'same-origin' });
  const j = await r.json();
  if (!r.ok || !j || !j.ok || !j.csrf) throw new Error('csrf');
  stockNotifyCsrf = String(j.csrf);
  return stockNotifyCsrf;
}

function syncPdpAddButtons() {
  if (!P) return;
  const ss = versionStockSizes(P, pickedVersion);
  const totalOut = ss ? Object.values(ss).every(q => Number(q) <= 0) : Number(P.stock || 0) <= 0;
  const addBtn = document.getElementById('addBtn');
  const stickyBtn = document.getElementById('stickyAddBtn');
  addBtn.disabled = totalOut;
  addBtn.textContent = totalOut ? 'Niet op voorraad' : 'In winkelwagen';
  addBtn.style.background = '';
  if (stickyBtn) {
    stickyBtn.disabled = totalOut;
    stickyBtn.textContent = addBtn.textContent;
    stickyBtn.style.background = '';
  }
  const levNote = document.getElementById('pdpLeverNote');
  if (levNote) {
    const quick = parseInt(P.in_voorraad, 10) === 1;
    if (totalOut || !quick) {
      levNote.classList.remove('show');
      levNote.hidden = true;
      levNote.textContent = '';
    } else {
      levNote.textContent = 'Levering meestal binnen 1 à 2 werkdagen (kan per week wisselen).';
      levNote.classList.add('show');
      levNote.hidden = false;
    }
  }
}

function renderVersionToggle(){
  const row = document.getElementById('versieRow');
  const lbl = document.getElementById('versieLbl');
  if (!row || !lbl) return;
  // Fan/Player is overal beschikbaar: toggle altijd tonen.
  lbl.hidden = false; row.hidden = false;
  const opts = [{ key: 'fan', label: 'Fan' }, { key: 'player', label: 'Player' }];
  row.innerHTML = opts.map(o => {
    const on = pickedVersion === o.key ? ' on' : '';
    const price = versionPrice(P, o.key);
    return `<button type="button" class="versie-btn${on}" onclick="pickVersion('${o.key}')">`
      + `<span class="versie-name">${o.label}</span>`
      + `<span class="versie-price">${eur(price)}</span>`
      + `</button>`;
  }).join('');
}
function pickVersion(v){
  const nv = (v === 'player') ? 'player' : 'fan';
  if (nv === pickedVersion) return;
  pickedVersion = nv;
  pickedSize = '';        // maat resetten: maten en voorraad verschillen per versie
  qty = 1;
  const qv = document.getElementById('qtyV'); if (qv) qv.textContent = '1';
  renderProduct();
}
function renderProduct(){
  const snp = document.getElementById('stockNotifyPanel');
  if (snp) {
    snp.classList.remove('on');
  }
  stockNotifySize = '';
  const snFb = document.getElementById('stockNotifyFb');
  if (snFb) {
    snFb.textContent = '';
  }

  document.title = `${P.name} — KitsByElbaa`;
  document.getElementById('pName').textContent = P.name;
  document.getElementById('mainImg').alt = P.name;
  updatePdpPrice();
  renderVersionToggle();
  const stock = getPdpStockState(P);
  const stockEl = document.getElementById('pStock');
  stockEl.className = 'stock-note ' + stock.key;
  stockEl.textContent = stock.label;
  const ss = versionStockSizes(P, pickedVersion);
  const baseSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
  /* 2XL = zelfde maat als XXL in voorraad-JSON; één knop, voorraad optellen */
  const fanOnlyLarge = ['3XL', '4XL'];
  const isPlayer = (pickedVersion === 'player');
  const allSizes = ss && !isPlayer
    ? [...baseSizes, ...fanOnlyLarge]
    : baseSizes;
  syncPdpAddButtons();
  renderBadgeOptions(P);
  const kChart = document.getElementById('kidsJerseyChartPdp');
  if (kChart) kChart.hidden = !isKidsProduct(P);

  let anyOut = false;
  document.getElementById('sizes').innerHTML = allSizes.map(sz => {
    const qty = ss
      ? (sz === 'XXL' ? (Number(ss.XXL ?? 0) + Number(ss['2XL'] ?? 0)) : Number(ss[sz] ?? 0))
      : (isPlayer ? 0 : (Number(P.stock||0) > 0 ? 99 : 0));
    const isOut = qty <= 0;
    if (isOut) anyOut = true;
    const isLow = !isOut && qty <= 5;
    const stockLabel = isOut ? 'Uit' : isLow ? `${qty} over` : '';
    return `<div class="sz-wrap">
      <button type="button" class="sz${isOut?' out':isLow?' low':''}" onclick="${isOut?`openStockNotify('${sz}')`:`pickSize(this,'${sz}')`}"
      data-size="${sz}" data-qty="${qty}"${isOut?` title="Uitverkocht — klik en we mailen je zodra maat ${sz} terug is"`:''}>
      ${sz}${stockLabel ? `<span class="sz-stock">${stockLabel}</span>` : ''}
    </button>
    </div>`;
  }).join('') + (anyOut ? `<p class="sz-help">Grijs = uitverkocht. Klik zo'n maat aan &rarr; we mailen je zodra 'ie terug is.</p>` : '');
  gallery = orderedImages(P);
  gi = 0;
  renderGallery();
  document.getElementById('sDesc').textContent = P.description || 'Nog geen productomschrijving.';
  document.getElementById('sFit').textContent = P.fit_info || 'Standaard pasvorm.';
  document.getElementById('sSizeAdvice').textContent = P.size_advice || 'Bekijk de maattabel op de site. Twijfel je over je maat? App ons gerust via WhatsApp, dan helpen we je persoonlijk de juiste maat te kiezen.';
  document.getElementById('sMaterial').textContent = P.material_info || 'Ademende performance-stof.';
  document.getElementById('sShipping').textContent = P.shipping_info || 'Op voorraad: levering 1–2 werkdagen. Nabestelling: 7–12 werkdagen.';
  document.getElementById('sCare').textContent = P.care_instructions || 'Wassen op 30°C, binnenstebuiten.';
  renderReco();
  syncPdpWishlistButton();
}

function renderGallery(animate){
  const main = document.getElementById('mainImg');
  const t = document.getElementById('thumbs');
  if(!gallery.length){
    main.src = '';
    main.alt = 'Geen afbeelding';
    t.innerHTML = '';
    return;
  }
  const doSwap = () => {
    main.src = productImgSrc(gallery[gi]);
    main.onclick = ()=>openLightbox();
    t.innerHTML = gallery.map((g,idx)=>`<button type="button" class="thumb ${idx===gi?'on':''}" onclick="setImg(${idx})"><img src="${productImgSrc(g)}" alt="View ${idx+1}"></button>`).join('');
    if(animate) { main.classList.remove('switching'); }
  };
  if(animate){ main.classList.add('switching'); setTimeout(doSwap, 220); }
  else doSwap();
}
function setImg(i){ gi=i; renderGallery(true); }
function shiftImg(d){ if(!gallery.length) return; gi = (gi + d + gallery.length) % gallery.length; renderGallery(true); }
function openLightbox(){
  if(!gallery.length) return;
  const lb = document.getElementById('lightbox');
  document.getElementById('lbImg').src = productImgSrc(gallery[gi]);
  lb.classList.add('on');
  document.body.style.overflow = 'hidden';
}
function closeLightbox(){
  document.getElementById('lightbox').classList.remove('on');
  const cartOpen = document.getElementById('pcart')?.classList.contains('on');
  document.body.style.overflow = cartOpen ? 'hidden' : '';
}
function pickSize(el,s){
  if(!P) return;
  const qAvail = Number(el.dataset.qty ?? 0);
  if(qAvail <= 0) return;
  document.querySelectorAll('.sz').forEach(x=>x.classList.remove('on'));
  el.classList.add('on');
  pickedSize = el.dataset.size || s;
  qty = 1;
  document.getElementById('qtyV').textContent = String(qty);
}
function chgQty(d){
  if(!P || !pickedSize) return;
  const pr = getPendingPrintFields();
  const max = maxQtyForProductSize(P, pickedSize);
  const used = pdpQtyUsedForVariant(P.id, pickedSize, pr.printing_option, pr.print_name, pr.print_number, pr.print_badges, pickedVersion);
  const remaining = Math.max(0, max - used);
  if (remaining <= 0) {
    qty = 1;
    document.getElementById('qtyV').textContent = String(qty);
    return;
  }
  let next = qty + d;
  if (next < 1) next = 1;
  if (next > remaining) next = remaining;
  qty = next;
  document.getElementById('qtyV').textContent = String(qty);
}

function pdpToast(msg, type){
  let t = document.getElementById('pdpToastEl');
  if (!t) { t = document.createElement('div'); t.id = 'pdpToastEl'; document.body.appendChild(t); }
  const icons = {
    error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.4v.01"></path></svg>',
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>'
  };
  t.className = 'toast' + (type ? ' toast--' + type : '');
  t.innerHTML = (icons[type] ? `<span class="toast-ico">${icons[type]}</span>` : '') + '<span class="toast-msg"></span>';
  t.querySelector('.toast-msg').textContent = String(msg);
  void t.offsetWidth;
  t.classList.add('on');
  clearTimeout(pdpToast._t);
  pdpToast._t = setTimeout(() => t.classList.remove('on'), 2800);
}
function addToCart(){
  if(!P) return;
  if(!productHasSellableStock(P)){ pdpToast('Dit product is niet op voorraad.', 'error'); return; }
  if(!pickedSize){ pdpToast('Kies eerst een maat.', 'error'); return; }
  const nameEl = document.getElementById('printName');
  const numEl = document.getElementById('printNumber');
  let pn = (nameEl.value || '').trim().slice(0,15);
  const numRaw = (numEl.value || '').trim();
  let num = numRaw.replace(/\D/g,'').slice(0,4);
  if(numRaw && numRaw !== num){ pdpToast('Nummer mag alleen cijfers bevatten (max. 4).', 'error'); return; }
  nameEl.value = pn;
  numEl.value = num;
  let badges = (document.getElementById('printBadges').value || '').trim();
  const custom = !!(pn || num || badges);
  if (!custom) { pn = ''; num = ''; badges = ''; }
  const printing_option = custom ? 'custom' : 'none';
  const maxAllowed = maxQtyForProductSize(P, pickedSize);
  const used = pdpQtyUsedForVariant(P.id, pickedSize, printing_option, pn, num, badges, pickedVersion);
  const remaining = maxAllowed - used;
  const addQty = Math.min(Math.max(1, Number(qty) || 1), Math.max(0, remaining));
  if (addQty <= 0 || remaining <= 0) {
    pdpToast('Onvoldoende voorraad voor deze maat (' + maxAllowed + ' beschikbaar voor deze optie).', 'error');
    return;
  }
  const unit = pdpUnitPrice({ printing_option, print_name: pn, print_number: num, print_badges: badges }).unit;
  const cartItem = {
    id: P.id,
    name: P.name,
    league: P.league,
    cat: P.cat,
    emoji: P.emoji || '👕',
    image: P.image || '',
    image2: P.image2 || '',
    image3: P.image3 || '',
    stock: maxAllowed,
    size: pickedSize,
    version: pickedVersion,
    printing_option,
    print_name: pn,
    print_number: num,
    print_badges: badges,
    price: unit,
    qty: addQty
  };
  const sameIdx = pdpCart.findIndex(i =>
    i.id === P.id && i.size === pickedSize && (i.version || 'fan') === pickedVersion && pdpPrintingMatch(i, printing_option, pn, num, badges)
  );
  if (sameIdx >= 0) {
    pdpCart[sameIdx].qty = Math.min(maxAllowed, Number(pdpCart[sameIdx].qty || 0) + addQty);
  } else {
    pdpCart.push(cartItem);
  }
  qty = 1;
  document.getElementById('qtyV').textContent = '1';
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
  renderPdpCart();

  // Button feedback: show "✓ Added!" briefly, then open the cart panel
  const addBtnEl = document.getElementById('addBtn');
  const stickyBtnEl = document.getElementById('stickyAddBtn');
  addBtnEl.textContent = 'Toegevoegd!';
  addBtnEl.style.background = '#3a3d38';
  addBtnEl.disabled = true;
  if (stickyBtnEl) {
    stickyBtnEl.textContent = 'Toegevoegd!';
    stickyBtnEl.style.background = '#3a3d38';
    stickyBtnEl.disabled = true;
  }
  setTimeout(() => {
    syncPdpAddButtons();
    openPdpCart();
  }, 700);
}

function openStickyWhatsApp() {
  const size = document.querySelector('.sz.on')?.textContent?.trim() || '';
  const bits = [
    'Hi! Ik wil deze graag bestellen:',
    P ? P.name : '',
    size ? `(maat ${size})` : ''
  ].filter(Boolean);
  const msg = bits.join(' ');
  const wa = 'https://wa.me/' + kbeWaDigits() + '?text=' + encodeURIComponent(msg);
  window.open(wa, '_blank');
}

document.getElementById('printName').addEventListener('input', function(){
  this.value = this.value.slice(0,15);
  updatePdpPrice();
});
document.getElementById('printNumber').addEventListener('input', function(){
  this.value = this.value.replace(/\D/g,'').slice(0,4);
  updatePdpPrice();
});
document.getElementById('printBadges').addEventListener('change', updatePdpPrice);

function goToCart(){
  // Cart lives in shared storage (kbe_cart_main); the full checkout modal only
  // exists on index/shop. Land the shopper straight in that checkout instead of
  // dropping them on the homepage behind a tiny cart drawer.
  if (!Array.isArray(pdpCart) || !pdpCart.length) {
    pdpToast('Je winkelwagen is leeg — voeg eerst een tenue toe.', 'error');
    return;
  }
  window.location.href = 'index.html?checkout=1';
}

function openPdpCart(){
  const pc = document.getElementById('pcart');
  const alreadyOpen = pc.classList.contains('on');
  document.getElementById('pcbg').classList.add('on');
  pc.classList.add('on');
  document.body.style.overflow = 'hidden';
  if (!alreadyOpen) {
    try {
      history.pushState({ kbePdpCart: 1 }, '');
      __kbePdpCartHistory++;
    } catch (_) {}
  }
}
window.addEventListener('popstate', function () {
  const pc = document.getElementById('pcart');
  if (pc && pc.classList.contains('on')) {
    document.getElementById('pcbg').classList.remove('on');
    pc.classList.remove('on');
    document.body.style.overflow = '';
    __kbePdpCartHistory = Math.max(0, __kbePdpCartHistory - 1);
  }
});
function closePdpCart(){
  const pc = document.getElementById('pcart');
  if (!pc.classList.contains('on')) return;
  document.getElementById('pcbg').classList.remove('on');
  pc.classList.remove('on');
  document.body.style.overflow = '';
  if (__kbePdpCartHistory > 0) {
    __kbePdpCartHistory--;
    try { history.back(); } catch (_) {}
  }
}
function renderPdpCart(){
  const body = document.getElementById('pcartBody');
  const title = document.getElementById('pcartTitle');
  const totalEl = document.getElementById('pcartTotal');
  const navCount = document.getElementById('cartNProduct');
  const count = pdpCart.reduce((s,i)=>s+Number(i.qty||1),0);
  const subtotal = pdpCart.reduce((s,i)=>s+Number(i.price||0)*Number(i.qty||1),0);
  const discount = calcPdpDiscount(subtotal);
  const total = subtotal - discount;
  title.textContent = `Mijn artikelen • ${count}`;
  if (navCount) navCount.textContent = String(count);
  totalEl.textContent = `${eur(total)}`;
  const discRow = document.getElementById('pcDiscount');
  const discAmt = document.getElementById('pcDiscountAmt');
  const cpIn = document.getElementById('pcCouponInput');
  const cpFb = document.getElementById('pcCouponFb');
  if (cpIn) {
    cpIn.value = pdpCoupon ? pdpCoupon.code : '';
    cpIn.disabled = false;
  }
  const pcRm = document.getElementById('pcCouponRemoveBtn');
  if (pcRm) pcRm.style.display = pdpCoupon ? 'inline-block' : 'none';
  if (cpFb) {
    cpFb.className = 'pdp-coupon-fb' + (pdpCoupon ? ' ok' : '');
    cpFb.textContent = pdpCoupon ? couponAppliedLabel(pdpCoupon) : '';
  }
  const discLbl = document.getElementById('pcDiscountLabel');
  if (discLbl) {
    discLbl.textContent = pdpCoupon
      ? ('Korting (' + (pdpCoupon.type === 'percent' ? pdpCoupon.value + '%' : eur(Number(pdpCoupon.value))) + ')')
      : 'Korting';
  }
  if (discRow && discAmt) {
    if (discount > 0) {
      discRow.style.display = 'flex';
      discAmt.textContent = `-${eur(discount)}`;
    } else {
      discRow.style.display = 'none';
    }
  }
  if(!pdpCart.length){
    body.innerHTML = '<div class="pdp-empty">Je winkelwagen is leeg.<br><button type="button" class="pdp-empty-cta" onclick="closePdpCart()">Terug naar winkelen</button></div>';
    return;
  }
  body.innerHTML = pdpCart.map((i, idx) => {
    const img = i.image || i.image2 || i.image3;
    const slug = i.id != null ? shareSlug(i) : '';
    const href = slug ? `product.php?slug=${encodeURIComponent(slug)}` : '';
    const mediaInner = img ? `<img src="${productImgSrc(img)}" alt="${String(i.name||'').replace(/"/g,'&quot;')}">` : `<span class="pdp-line-noimg" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span>`;
    const mediaBlock = href
      ? `<a class="pdp-line-media" href="${href}" title="Product bekijken">${mediaInner}</a>`
      : `<div class="pdp-line-media">${mediaInner}</div>`;
    const nameBlock = href
      ? `<a class="pdp-line-name" href="${href}">${escHtml(i.name)}</a><div><a class="pdp-line-view" href="${href}">Product bekijken</a></div>`
      : `<div class="pdp-line-name">${escHtml(i.name)}</div>`;
    return `<div class="pdp-line">
      ${mediaBlock}
      <div>
        ${nameBlock}
        <div class="pdp-line-meta">${i.version==='player' ? 'Player · ' : ''}Maat: ${i.size}${i.printing_option==='custom' ? ' · ' + (i.print_name||'') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' · ' + escHtml(i.print_badges) : '') : ''}</div>
        <div class="pdp-qty">
          <button type="button" onclick="pdpChQ(${idx},-1)" aria-label="Aantal verlagen">−</button>
          <span>${i.qty}</span>
          <button type="button" onclick="pdpChQ(${idx},1)" aria-label="Aantal verhogen">+</button>
        </div>
        <button type="button" class="pdp-remove" onclick="pdpRemove(${idx})">Verwijderen</button>
      </div>
      <div class="pdp-line-price">${eur((Number(i.price||0)*Number(i.qty||1)))}</div>
    </div>`;
  }).join('');
}

async function applyPdpCoupon(){
  const input = document.getElementById('pcCouponInput');
  const fb = document.getElementById('pcCouponFb');
  const code = (input.value || '').trim();
  if(!code){
    fb.className = 'pdp-coupon-fb err';
    fb.textContent = 'Voer een kortingscode in.';
    return;
  }
  fb.className = 'pdp-coupon-fb';
  fb.textContent = 'Even geduld…';
  const res = await validateCouponCode(code);
  if(res.ok){
    pdpCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    persistCouponState();
    fb.className = 'pdp-coupon-fb ok';
    fb.textContent = couponAppliedLabel(pdpCoupon);
    input.value = res.code;
  } else {
    pdpCoupon = null;
    persistCouponState();
    fb.className = 'pdp-coupon-fb err';
    fb.textContent = (res.error === 'network' ? 'Kon code niet controleren.' : (res.error || 'Ongeldige kortingscode.'));
  }
  renderPdpCart();
}

function removePdpCoupon() {
  pdpCoupon = null;
  persistCouponState();
  const input = document.getElementById('pcCouponInput');
  if (input) { input.value = ''; input.disabled = false; }
  const fb = document.getElementById('pcCouponFb');
  if (fb) { fb.className = 'pdp-coupon-fb'; fb.textContent = ''; }
  renderPdpCart();
}

function pdpChQ(idx,delta){
  if (idx < 0 || idx >= pdpCart.length) return;
  const item = pdpCart[idx];
  const p = PRODUCTS.find(x => x.id === item.id) || P;
  const max = p ? maxQtyForProductSize(p, item.size) : Number(item.stock) || 0;
  let n = Number(item.qty || 1) + delta;
  if (n > max) n = max;
  if (n <= 0) pdpCart.splice(idx, 1);
  else pdpCart[idx].qty = n;
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
  renderPdpCart();
}

function pdpRemove(idx){
  if (idx < 0 || idx >= pdpCart.length) return;
  pdpCart.splice(idx,1);
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
  renderPdpCart();
}

function renderReco(){
  const score = (x) => {
    let s = 0;
    if (x.cat && P.cat && x.cat === P.cat) s += 3;
    if (x.league && P.league && x.league === P.league) s += 2;
    if (x.badge === 'hot') s += 1;
    return s;
  };
  const list = PRODUCTS
    .filter(x=>x.id!==P.id)
    .map(x=>({x,s:score(x)}))
    .sort((a,b)=> b.s - a.s || a.x.price - b.x.price)
    .map(v=>v.x)
    .slice(0, 10);
  const recoEl = document.querySelector('.reco');
  const track = document.getElementById('reco');
  if (!track) return;
  if (!list.length) {
    if (recoEl) recoEl.style.display = 'none';
    return;
  }
  if (recoEl) recoEl.style.display = '';
  const priceNl = (n) => eur(Number(n)).replace('.', ',');
  track.innerHTML = list.map(x=>{
    const safeName = escHtml(x.name);
    const slug = encodeURIComponent(shareSlug(x));
    const hasImg = orderedImages(x).filter(Boolean).length > 0;
    const imgTag = hasImg
      ? `<img class="reco-img" alt="${safeName}" loading="lazy" decoding="async" sizes="(max-width:600px) 72vw, 268px">`
      : '';
    return `<a class="card" href="product.php?slug=${slug}">
      <div class="cimg${hasImg ? '' : ' cimg--empty'}">${imgTag}</div>
      <div class="ctxt"><div class="cn">${safeName}</div><div class="cp">${eur(x.price)}</div></div>
    </a>`;
  }).join('');
  bindRecoCardImages(list);
  track.querySelectorAll('.cimg img.reco-img').forEach(el => {
    el.addEventListener('load', () => { window.requestAnimationFrame(() => { window.requestAnimationFrame(updateRecoArrows); }); }, { once: true });
  });
  initRecoSlider();
}

/** Horizontale strip + lazy: eerste bron faalde vaak; probeer image / image2 / image3. */
function bindRecoCardImages(list) {
  const track = document.getElementById('reco');
  if (!track) return;
  list.forEach((p, i) => {
    const card = track.children[i];
    if (!card) return;
    const cimg = card.querySelector('.cimg');
    const img = cimg && cimg.querySelector('img.reco-img');
    const urls = orderedImages(p).filter(Boolean).map(u => productImgSrc(u)).filter(Boolean);
    if (!cimg) return;
    if (!urls.length) {
      cimg.classList.add('cimg--empty');
      return;
    }
    if (!img) {
      cimg.classList.add('cimg--empty');
      return;
    }
    let idx = 0;
    img.onerror = function recoImgErr() {
      idx += 1;
      if (idx < urls.length) {
        this.src = urls[idx];
        return;
      }
      this.remove();
      cimg.classList.add('cimg--empty');
    };
    img.onload = function() {
      cimg.classList.remove('cimg--empty');
    };
    img.src = urls[0];
  });
}

function updateRecoArrows(){
  const vp = document.getElementById('recoViewport');
  const prev = document.getElementById('recoPrev');
  const next = document.getElementById('recoNext');
  if (!vp || !prev || !next) return;
  const cards = vp.querySelectorAll('.reco-track .card');
  const max = Math.max(0, vp.scrollWidth - vp.clientWidth);
  const left = vp.scrollLeft;
  prev.disabled = left <= 2;
  next.disabled = cards.length ? left >= max - 2 : true;
}

let __recoSliderBound = false;
function initRecoSlider(){
  const vp = document.getElementById('recoViewport');
  const prev = document.getElementById('recoPrev');
  const next = document.getElementById('recoNext');
  if (!vp || !prev || !next) return;

  const gap = 16;

  function go(dir){
    const cards = vp.querySelectorAll('.reco-track .card');
    if (!cards.length) return;
    const maxScroll = Math.max(0, vp.scrollWidth - vp.clientWidth);
    const w = cards[0].getBoundingClientRect().width;
    const step = w + gap;
    let target = vp.scrollLeft + dir * step;
    target = Math.max(0, Math.min(maxScroll, target));
    if (typeof kbeScrollElementTo === 'function') {
      kbeScrollElementTo(vp, target, 0, true);
    } else {
      vp.scrollTo({ left: target, behavior: 'smooth' });
    }
    window.requestAnimationFrame(() => window.requestAnimationFrame(updateRecoArrows));
  }

  if (!__recoSliderBound) {
    __recoSliderBound = true;
    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));
    vp.addEventListener('scroll', updateRecoArrows, { passive: true });
    window.addEventListener('resize', () => { window.requestAnimationFrame(updateRecoArrows); });
  }
  window.requestAnimationFrame(() => {
    vp.scrollLeft = 0;
    window.requestAnimationFrame(() => {
      vp.scrollLeft = 0;
      updateRecoArrows();
    });
  });
}

// Smart back link — goes to previous page if it was our site, otherwise falls back to index.html
(function(){
  const back = document.getElementById('backLink');
  if (!back) return;
  const ref = document.referrer;
  if (ref && ref.includes('index.html')) {
    back.href = ref;
  }
})();

document.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowLeft') shiftImg(-1);
  if (e.key === 'ArrowRight') shiftImg(1);
  if (e.key === 'Escape') closeLightbox();
});

// Bedragen in Nederlandse notatie: €37,50.
function eur(n) {
  return '€' + Number(n || 0).toFixed(2).replace('.', ',');
}
