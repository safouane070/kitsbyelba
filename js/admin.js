/**
 * Admin dashboard JS — verhuisd uit admin.php (Fase A van docs/admin-split-plan.md).
 * Server-injecties (CSRF_TOKEN, EmailJS-keys, productsCache, ...) staan als globals
 * in een inline <script> in admin.php, vóór dit bestand geladen wordt.
 */
/** Wacht op EmailJS (`defer` in head), roept init één keer aan — ook vlak vóór send() betrouwbaar. */
function ensureEmailJsInitialized() {
  return new Promise((resolve, reject) => {
    if (!KBE_EMAILJS_PUBLIC_KEY) {
      reject(new Error('Geen KITS_EMAILJS_PK in .env (EmailJS public key).'));
      return;
    }
    let n = 0;
    (function tick() {
      if (typeof emailjs !== 'undefined') {
        try {
          if (!window.__kbeEmailJsInited) {
            emailjs.init({ publicKey: KBE_EMAILJS_PUBLIC_KEY });
            window.__kbeEmailJsInited = true;
          }
        } catch (e) {
          reject(e);
          return;
        }
        resolve();
        return;
      }
      if (++n >= 150) {
        reject(new Error('EmailJS-script niet geladen (netwerk of geblokkeerd).'));
        return;
      }
      setTimeout(tick, 20);
    })();
  });
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    ensureEmailJsInitialized().catch((e) => console.warn('[emailjs] vroege init', e));
  });
} else {
  ensureEmailJsInitialized().catch((e) => console.warn('[emailjs] vroege init', e));
}

/** EmailJS v4: publicKey op elke send() — zelfde prioriteit als in de officiële docs; vangt init-races af. */
function kbeEmailJsSendOpts() {
  return KBE_EMAILJS_PUBLIC_KEY ? { publicKey: KBE_EMAILJS_PUBLIC_KEY } : {};
}

// ── UTILS ─────────────────────────────────────────────
function api(action, body={}) {
  const payload = { ...body, action, csrf: CSRF_TOKEN };
  return fetch('admin.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-Action':action,'X-CSRF-Token':CSRF_TOKEN},
    body: JSON.stringify(payload)
  }).then(async r => {
    const text = await r.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (_) {
      if (r.status === 401 || r.status === 403) {
        throw new Error('Sessie verlopen of geen toegang — log opnieuw in.');
      }
      throw new Error(`API ${action} gaf geen geldige JSON terug (HTTP ${r.status})`);
    }
    return data;
  });
}

function esc(v) {
  return String(v ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}
function jss(v) {
  return JSON.stringify(String(v ?? ''));
}
/** Public storefront URL for a product (same slug as index.html / product.php). */
function storeProductSlug(id, name) {
  const tail = String(name || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'kit';
  return id + '-' + tail;
}
function storeProductUrl(r) {
  const slug = encodeURIComponent(storeProductSlug(r.id, r.name));
  const base = (window.__PUBLIC_SITE__ || '').replace(/\/$/, '');
  if (base) return base + '/product.php?slug=' + slug;
  return 'product.php?slug=' + slug;
}
function openStoreProduct(id, name) {
  window.open(storeProductUrl({ id, name }), '_blank', 'noopener,noreferrer');
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

function toast(msg, dur=2800) {
  const t = document.getElementById('toast');
  msg = String(msg);
  let type = '';
  if (/^\s*(⚠️|❌|✗)/.test(msg)) type = 'error';
  else if (/^\s*(✓|✅)/.test(msg)) type = 'success';
  msg = msg.replace(/^\s*(⚠️|❌|✗|✓|✅)\s*/, '');
  const icons = {
    error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.4v.01"></path></svg>',
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>'
  };
  t.className = 'toast' + (type ? ' toast--' + type : '');
  t.innerHTML = (icons[type] ? `<span class="toast-ico">${icons[type]}</span>` : '') + '<span class="toast-msg"></span>';
  t.querySelector('.toast-msg').textContent = msg;
  t.classList.add('on');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => t.classList.remove('on'), dur);
}

function openAdminSidebar() {
  const side = document.getElementById('adminSidebar');
  const bd = document.getElementById('sidebarBackdrop');
  if (side) side.classList.add('open');
  if (bd) bd.classList.add('on');
  document.body.classList.add('admin-sidebar-open');
  const btn = document.getElementById('adminMenuToggle');
  if (btn) btn.setAttribute('aria-expanded', 'true');
}

function closeAdminSidebar() {
  const side = document.getElementById('adminSidebar');
  const bd = document.getElementById('sidebarBackdrop');
  if (side) side.classList.remove('open');
  if (bd) bd.classList.remove('on');
  document.body.classList.remove('admin-sidebar-open');
  const btn = document.getElementById('adminMenuToggle');
  if (btn) btn.setAttribute('aria-expanded', 'false');
}

function toggleAdminSidebar() {
  const side = document.getElementById('adminSidebar');
  if (side && side.classList.contains('open')) closeAdminSidebar();
  else openAdminSidebar();
}

window.addEventListener('resize', () => {
  if (window.innerWidth > 1024) closeAdminSidebar();
});
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const om = document.getElementById('order-modal');
  const pm = document.getElementById('product-modal');
  if (om && om.classList.contains('on')) {
    closeOrderModal();
    e.preventDefault();
    return;
  }
  if (pm && pm.classList.contains('on')) {
    closeProductModal();
    e.preventDefault();
    return;
  }
  closeAdminSidebar();
});

function statusLabelNl(status) {
  const m = {
    pending: 'In afwachting', confirmed: 'Bevestigd', paid: 'Betaald',
    shipped: 'Verzonden', delivered: 'Bezorgd', cancelled: 'Geannuleerd',
    new: 'Nieuw', hot: 'Populair',
  };
  return m[status] || status;
}
function badge(status) {
  const map = {pending:'b-pending',confirmed:'b-confirmed',paid:'b-paid',
                shipped:'b-shipped',delivered:'b-delivered',cancelled:'b-cancelled',
                new:'b-new',hot:'b-hot'};
  return `<span class="badge ${map[status]||''}">${esc(statusLabelNl(status))}</span>`;
}

function itemPrimaryImageSrc(item) {
  const f = item.product_image || item.product_image2 || item.image || item.image2 || item.image3;
  if (!f) return '';
  const raw = String(f).trim();
  if (/^https?:\/\//i.test(raw)) return raw;
  const base = (typeof EMAIL_PUBLIC_BASE === 'string' && EMAIL_PUBLIC_BASE) ? EMAIL_PUBLIC_BASE.replace(/\/$/, '') : '';
  if (!base) return '';
  const rel = productImgSrc(f);
  return rel ? (base + '/' + rel) : '';
}

function orderItemsEmailText(items) {
  return (items || []).map(i =>
    `${i.quantity}× ${esc(i.name)} (${esc(i.size)}${i.printing_option === 'custom'
      ? ' · ' + esc(i.print_name || '') + (i.print_number ? ' #' + esc(i.print_number) : '') + (i.print_badges ? ' (' + esc(i.print_badges) + ')' : '')
      : ''}) — €${(i.price * i.quantity).toFixed(2)}`
  ).join('\n');
}

function buildPaidEmailParams(o) {
  const items = o.items || [];
  const shipNum = parseFloat(o.shipping);
  const shipStr = shipNum === 0 ? 'GRATIS' : ('€' + shipNum.toFixed(2));
  // Matches EmailJS templates that use {{email}}, {{#orders}} … {{name}} / {{units}} / {{price}}, {{cost.shipping}}
  const disc = parseFloat(o.discount || 0);
  const st = String(o.status || '').trim().toLowerCase();
  const paidLikeStatus = ['paid', 'shipped', 'delivered'].includes(st);
  // EmailJS Mustache: {{#payment_received}} is truthy for number 1/strings inconsistently.
  // Send explicit mutually exclusive flags: 'yes' or empty (falsy in {{#…}} blocks).
  const payment_received_flag = paidLikeStatus ? 'yes' : '';
  const order_confirmed_only_flag = !paidLikeStatus && st === 'confirmed' ? 'yes' : '';

  const orders = items.map(i => {
    const extra = i.printing_option === 'custom'
      ? ' · ' + esc(i.print_name || '') + (i.print_number ? ' #' + esc(i.print_number) : '') + (i.print_badges ? ' (' + esc(String(i.print_badges)) + ')' : '')
      : '';
    return {
      name: String(i.name || '') + ' (' + String(i.size || '') + extra + ')',
      units: i.quantity,
      price: (parseFloat(i.price) * parseInt(i.quantity, 10)).toFixed(2),
      image_url: itemPrimaryImageSrc(i),
      league: i.product_league || '',
      size: i.size || '',
    };
  });
  const base = {
    new_order: false,
    to_email: o.email,
    to_name: o.customer_name || 'Klant',
    email: o.email,
    customer_email: o.email,
    customer_name: o.customer_name || '',
    order_id: o.order_id,
    order_items: orderItemsEmailText(items),
    order_total: '€' + parseFloat(o.total).toFixed(2),
    shipping_cost: shipStr,
    order_date: fmtDate(o.created_at),
    orders,
    cost: {
      shipping: shipStr,
      total: '€' + parseFloat(o.total).toFixed(2),
    },
    payment_received: payment_received_flag,
    order_confirmed_only: order_confirmed_only_flag,
    order_discount: disc > 0 ? ('€' + disc.toFixed(2)) : '',
    coupon_code: o.coupon_code || '',
    customer_notes: o.notes || '',
    customer_phone: o.phone || '',
    customer_address: (function () {
      const base = [o.street, o.zip, o.city].filter(Boolean).join(', ');
      const cc = String(o.country || 'NL').toUpperCase();
      return cc !== 'NL' ? base + ', ' + orderCountryLabel(cc) : base;
    })(),
    site_url: (typeof EMAIL_PUBLIC_BASE === 'string' && EMAIL_PUBLIC_BASE) ? EMAIL_PUBLIC_BASE : '',
    logo_url: (typeof EMAIL_PUBLIC_BASE === 'string' && EMAIL_PUBLIC_BASE) ? (EMAIL_PUBLIC_BASE + '/images/logo.jpeg') : '',
  };
  // Kopie naar admin (alleen als anders dan klant-mail). Zet in EmailJS bij deze template Bcc = {{bcc}}
  if (ADMIN_NOTIFY_EMAIL && String(o.email || '').toLowerCase() !== ADMIN_NOTIFY_EMAIL.toLowerCase()) {
    base.bcc = ADMIN_NOTIFY_EMAIL;
  }
  return base;
}

async function sendPaidConfirmationEmail(o) {
  await ensureEmailJsInitialized();
  await emailjs.send(EJSVC_ORDER, EJTPL, buildPaidEmailParams(o), kbeEmailJsSendOpts());
}

async function sendOrderConfirmationEmailFromId(id) {
  const o = await api('order_detail', {id});
  if (!o) { toast('⚠️ Bestelling niet gevonden'); return; }
  if (!o.email || !String(o.email).includes('@')) { toast('⚠️ Geen geldig e-mailadres'); return; }
  if (!['confirmed', 'paid', 'shipped', 'delivered'].includes(o.status)) {
    toast('⚠️ Zet de bestelling eerst op bevestigd of betaald');
    return;
  }
  try {
    await sendPaidConfirmationEmail(o);
    const paidLike = ['paid', 'shipped', 'delivered'].includes(o.status);
    toast((paidLike ? '✅ Betaalbevestiging verstuurd naar ' : '✅ Update verstuurd naar ') + o.email, 4500);
  } catch (e) {
    toast('⚠️ E-mail mislukt — controleer EmailJS-template en dashboard', 5000);
  }
}

async function sendConfirmationForCurrentOrder() {
  if (!currentOrder) return;
  const o = currentOrder;
  if (!o.email || !String(o.email).includes('@')) { toast('⚠️ Geen geldig e-mailadres'); return; }
  if (!['confirmed', 'paid', 'shipped', 'delivered'].includes(o.status)) {
    toast('⚠️ Zet de bestelling eerst op bevestigd of betaald');
    return;
  }
  let items = o.items;
  if (!items || !items.length) {
    const detail = await api('order_detail', {id: o.id});
    if (detail && detail.items) { items = detail.items; currentOrder.items = items; }
  }
  try {
    await sendPaidConfirmationEmail(o);
    const paidLike = ['paid', 'shipped', 'delivered'].includes(o.status);
    toast((paidLike ? '✅ Betaalbevestiging verstuurd naar ' : '✅ Update verstuurd naar ') + o.email, 4500);
  } catch (e) {
    toast('⚠️ E-mail mislukt — controleer EmailJS-template en dashboard', 5000);
  }
}

function fmtDate(str) {
  let d = new Date(str);
  // MySQL 'YYYY-MM-DD HH:MM:SS' (met spatie) is niet-standaard in sommige engines → Invalid Date.
  if (isNaN(d)) {
    const m = String(str || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (m) d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
  }
  if (isNaN(d)) return String(str || '');
  return d.toLocaleDateString('nl-NL',{day:'2-digit',month:'short',year:'numeric'});
}

// ── TABS ──────────────────────────────────────────────
function tab(name) {
  closeAdminSidebar();
  const mobileTitle = document.getElementById('adminMobileTitle');
  if (mobileTitle) {
    const titles = { dash: 'Overzicht', orders: 'Bestellingen', products: 'Producten', voorraad: 'Voorraad', coupons: 'Kortingscodes', promo: 'Promotie & banner' };
    mobileTitle.textContent = titles[name] || 'Beheer';
  }
  document.querySelectorAll('.view').forEach(v => v.classList.remove('on'));
  document.querySelectorAll('.sn').forEach(b => b.classList.remove('on'));
  const viewEl = document.getElementById('view-'+name);
  const tabEl  = document.getElementById('tab-'+name);
  if (!viewEl || !tabEl) return;
  viewEl.classList.add('on');
  tabEl.classList.add('on');
  if (name === 'dash')     loadDash();
  if (name === 'orders')   loadOrders('all');
  if (name === 'products')  loadProducts(false);
  if (name === 'voorraad')  loadVoorraadManagement();
  if (name === 'coupons')   loadCoupons();
  if (name === 'promo')     loadPromoSettings();
  if (name === 'orders')    pollOrderBadge();
}

// ── VOORRAAD BEHEER ───────────────────────────────────
let _vrCache = [];

function refreshVoorraadTableFromCache() {
  _vrCache = productsCache.filter(p => parseInt(p.in_voorraad, 10) === 1);
  const inp = document.getElementById('vr-search');
  renderVoorraadTable(inp ? inp.value : '');
}

function loadVoorraadManagement() {
  refreshVoorraadTableFromCache();
}

function filterVoorraadRows(q) {
  const needle = (q || '').toLowerCase().trim();
  document.querySelectorAll('#vr-tbody tr[id^="vr-row-"]').forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = (!needle || text.includes(needle)) ? '' : 'none';
  });
}

function renderVoorraadTable(q) {
  const tbody = document.getElementById('vr-tbody');
  const countEl = document.getElementById('vr-count');
  if (!tbody) return;
  const needle = (q || '').toLowerCase().trim();
  const base = _vrCache.filter(p => parseInt(p.in_voorraad, 10) === 1);
  const list = needle
    ? base.filter(p => (p.name + ' ' + (p.league || '')).toLowerCase().includes(needle))
    : base;
  const total = base.length;
  if (countEl) countEl.textContent = total ? `${total} op voorraad` : '';
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:32px;color:var(--ink3)">' +
      (needle ? 'Geen producten gevonden.' : 'Nog geen producten op voorraad. Zet ze aan via <strong>Producten → Snel lever.</strong>') +
      '</td></tr>';
    return;
  }
  tbody.innerHTML = list.map(p => {
    const on = parseInt(p.in_voorraad, 10) === 1;
    const img = p.image || p.image2 || p.image3;
    const shopUrl = storeProductUrl(p);
    const imgCell = img
      ? `<a class="vr-thumb-link" href="${esc(shopUrl)}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" title="Open in winkel"><img src="${productImgSrc(img)}" alt="" loading="lazy" style="width:52px;height:52px;object-fit:cover;border-radius:7px;display:block"></a>`
      : `<div style="width:52px;height:52px;border-radius:7px;background:var(--bg);display:flex;align-items:center;justify-content:center"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></div>`;
    return `<tr id="vr-row-${p.id}" onclick="openProductModal(${p.id})" style="cursor:pointer" title="Klik om te bewerken (foto = winkel)">
      <td onclick="event.stopPropagation()">${imgCell}</td>
      <td style="font-weight:600">${esc(p.name)}</td>
      <td style="color:var(--ink3)">${esc(p.league || '')}</td>
      <td style="text-align:center" onclick="event.stopPropagation()">
        <label style="display:inline-flex;align-items:center;gap:9px;cursor:pointer">
          <div class="vr-toggle${on ? ' vr-toggle--on' : ''}" data-id="${p.id}" onclick="toggleInVoorraad(${p.id},${on ? 0 : 1},this)">
            <div class="vr-toggle-knob"></div>
          </div>
          <span class="vr-lbl" id="vr-lbl-${p.id}" style="font-size:12px;font-weight:600;color:${on ? '#16a34a' : 'var(--ink3)'}">${on ? 'Aan' : 'Uit'}</span>
        </label>
      </td>
    </tr>`;
  }).join('');
}

function toggleInVoorraad(id, newVal, toggleEl) {
  api('toggle_voorraad', {id, in_voorraad: newVal}).then(r => {
    if (!r.ok) { toast('❌ Opslaan mislukt'); return; }
    const on = r.in_voorraad === 1;
    const pc = productsCache.find(x => x.id === id);
    if (pc) pc.in_voorraad = r.in_voorraad;
    refreshVoorraadTableFromCache();
    // Sync toggle in products tab if visible
    const prodToggle = document.querySelector(`#products-body .vr-toggle[data-id="${id}"]`);
    if (prodToggle) {
      prodToggle.classList.toggle('vr-toggle--on', on);
      prodToggle.setAttribute('onclick', `toggleInVoorraadProducts(${id},${on ? 0 : 1},this)`);
    }
    toast(on ? '✅ Op voorraad gezet' : '📦 Uit voorraad gehaald');
  });
}

// ── COUPONS ───────────────────────────────────────────
function loadCoupons() {
  api('coupons').then(rows => {
    const tbody = document.getElementById('coupons-body');
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ink3)">Geen kortingscodes gevonden.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(c => `
      <tr>
        <td><strong>${esc(c.code)}</strong></td>
        <td>${c.type === 'percent' ? 'Procent' : 'Vast bedrag'}</td>
        <td>${c.type === 'percent' ? c.value + '%' : '€' + parseFloat(c.value).toFixed(2)}</td>
        <td>${c.uses_count}</td>
        <td><span style="display:inline-block;padding:2px 10px;border-radius:100px;font-size:11px;font-weight:700;background:${c.active=='1'?'#d1fae5':'#fee2e2'};color:${c.active=='1'?'#065f46':'#991b1b'}">${c.active=='1'?'Actief':'Inactief'}</span></td>
        <td style="white-space:nowrap">${c.created_at ? c.created_at.slice(0,10) : '—'}</td>
        <td>
          <button class="btn btn-sm" style="background:var(--line);color:var(--ink2);margin-right:4px" onclick='editCoupon(${JSON.stringify(c)})'>Bewerken</button>
          <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b" onclick='deleteCoupon(${c.id},${jss(c.code)})'>Verwijder</button>
        </td>
      </tr>`).join('');
  });
}

function openCouponForm() {
  document.getElementById('cf-id').value = '';
  document.getElementById('cf-code').value = '';
  document.getElementById('cf-type').value = 'percent';
  document.getElementById('cf-value').value = '10';
  document.getElementById('cf-active').checked = true;
  document.getElementById('coupon-form-title').textContent = 'Nieuwe kortingscode';
  document.getElementById('coupon-form-err').style.display = 'none';
  document.getElementById('coupon-form-wrap').style.display = 'block';
  document.getElementById('cf-code').disabled = false;
}

function closeCouponForm() {
  document.getElementById('coupon-form-wrap').style.display = 'none';
}

function editCoupon(c) {
  document.getElementById('cf-id').value = c.id;
  document.getElementById('cf-code').value = c.code;
  document.getElementById('cf-code').disabled = true;
  document.getElementById('cf-type').value = c.type;
  document.getElementById('cf-value').value = c.value;
  document.getElementById('cf-active').checked = c.active == '1';
  document.getElementById('coupon-form-title').textContent = 'Bewerk: ' + c.code;
  document.getElementById('coupon-form-err').style.display = 'none';
  document.getElementById('coupon-form-wrap').style.display = 'block';
}

async function saveCoupon() {
  const errEl = document.getElementById('coupon-form-err');
  errEl.style.display = 'none';
  const payload = {
    id:     document.getElementById('cf-id').value || null,
    code:   document.getElementById('cf-code').value.trim().toUpperCase(),
    type:   document.getElementById('cf-type').value,
    value:  parseFloat(document.getElementById('cf-value').value),
    active: document.getElementById('cf-active').checked ? 1 : 0,
  };
  if (!payload.code || payload.code.length < 3) { errEl.textContent = 'Code is verplicht (min. 3 tekens)'; errEl.style.display = 'block'; return; }
  const r = await api('save_coupon', payload);
  if (r.ok) { closeCouponForm(); loadCoupons(); }
  else { errEl.textContent = r.error || 'Opslaan mislukt'; errEl.style.display = 'block'; }
}

async function deleteCoupon(id, code) {
  if (!confirm(`Kortingscode "${code}" verwijderen?`)) return;
  await api('delete_coupon', { id });
  loadCoupons();
}

function loadPromoSettings() {
  const errEl = document.getElementById('sf-err');
  if (errEl) errEl.style.display = 'none';
  api('site_settings').then(s => {
    document.getElementById('sf-banner').value = s.promo_banner || '';
    document.getElementById('sf-faq').value = s.promo_faq_answer || '';
  }).catch(() => {
    if (errEl) { errEl.textContent = 'Kon instellingen niet laden.'; errEl.style.display = 'block'; }
  });
}

async function savePromoSettings() {
  const errEl = document.getElementById('sf-err');
  errEl.style.display = 'none';
  const payload = {
    promo_banner: document.getElementById('sf-banner').value,
    promo_faq_answer: document.getElementById('sf-faq').value,
  };
  const r = await api('save_site_settings', payload);
  if (r.ok) {
    alert('Opgeslagen. Vernieuw de winkel om de teksten te zien.');
  } else {
    errEl.textContent = r.error || 'Opslaan mislukt';
    errEl.style.display = 'block';
  }
}

// ── DASHBOARD ─────────────────────────────────────────
function lowStockChipsHtml(ss) {
  if (!ss || typeof ss !== 'object') return '';
  const order = ['XS','S','M','L','XL','XXL','2XL','3XL','4XL','16','18','20','22','24','26','28'];
  const keys = Object.keys(ss).sort((a, b) => {
    const ia = order.indexOf(a);
    const ib = order.indexOf(b);
    if (ia >= 0 && ib >= 0) return ia - ib;
    if (ia >= 0) return -1;
    if (ib >= 0) return 1;
    return String(a).localeCompare(String(b));
  });
  return keys.map(k => {
    const q = Number(ss[k]) || 0;
    let cls = 'low-stock-chip--ok';
    if (q <= 0) cls = 'low-stock-chip--out';
    else if (q <= 5) cls = 'low-stock-chip--warn';
    return `<span class="low-stock-chip ${cls}"><span class="low-stock-chip-sz">${esc(k)}</span><span class="low-stock-chip-q">${q}</span></span>`;
  }).join('');
}

function loadDash() {
  api('stats').then(s => {
    document.getElementById('s-orders').textContent  = s.orders;
    document.getElementById('s-revenue').textContent = '€' + parseFloat(s.revenue).toFixed(2);
    document.getElementById('s-pending').textContent = s.pending;
    document.getElementById('s-paid').textContent    = s.paid;
  });
  api('orders', {status:'all'}).then(orders => {
    const recent = orders.slice(0,5);
    document.getElementById('dash-recent').innerHTML = recent.length
      ? recent.map(o => `<div class="mini-order" onclick="openOrderModal(${o.id})" style="cursor:pointer">
          <div><div class="mo-id">${esc(o.order_id)}</div><div class="mo-name">${esc(o.customer_name)}</div></div>
          <div style="text-align:right">${badge(o.status)}<div class="mo-name">€${parseFloat(o.total).toFixed(2)}</div></div>
        </div>`).join('')
      : '<div class="empty"><div class="empty-ico">📭</div><p>Nog geen bestellingen</p></div>';

    const attention = orders.filter(o => ['pending','confirmed'].includes(o.status)).slice(0,5);
    document.getElementById('dash-attention').innerHTML = attention.length
      ? attention.map(o => `<div class="mini-order" onclick="openOrderModal(${o.id})" style="cursor:pointer">
          <div><div class="mo-id">${esc(o.order_id)}</div><div class="mo-name">${esc(o.customer_name)} · ${esc(o.city)}</div></div>
          <div>${badge(o.status)}</div>
        </div>`).join('')
      : '<div class="empty"><div class="empty-ico"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></div><p>Je bent helemaal bij!</p></div>';
  });

  api('low_stock').then(rows => {
    document.getElementById('dash-low-stock').innerHTML = rows.length
      ? rows.map(r => {
          const href = esc(storeProductUrl(r));
          const nm = JSON.stringify(r.name);
          const shortSummary = r.stock <= 0 ? 'Uit'
            : (r.worst !== undefined && r.worst < r.stock)
              ? ('Min ' + r.worst)
              : (r.stock + ' over');
          const sub = r.league ? esc(r.league) : '';
          const qtyClass = (r.stock <= 0 || (r.worst !== undefined && r.worst <= 0)) ? 'out' : 'low';
          const chips = lowStockChipsHtml(r.stock_sizes || {});
          return `<div class="low-stock-card low-stock-card--click" role="button" tabindex="0" title="Kit in de winkel openen"
            onclick="openStoreProduct(${r.id}, ${nm})"
            onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openStoreProduct(${r.id}, ${nm});}">
            <div class="low-stock-card-head">
              <div class="low-stock-card-titles">
                <div class="low-stock-title">${esc(r.name)}</div>
                ${sub ? `<div class="low-stock-meta">${sub}</div>` : ''}
              </div>
              <span class="low-stock-summary-badge low-stock-qty ${qtyClass}">${esc(shortSummary)}</span>
            </div>
            ${chips ? `<div class="low-stock-chips">${chips}</div>` : ''}
            <div class="low-stock-card-actions">
              <button type="button" class="low-stock-admin" onclick="event.stopPropagation();openRestockModalById(${r.id})" title="Snel bijvullen">Snel bijvullen</button>
              <a class="low-stock-link" href="${href}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">Winkel ↗</a>
            </div>
          </div>`;
        }).join('')
      : '<div class="empty"><div class="empty-ico">✅</div><p>Alle producten op voorraad</p></div>';
  });
}

// ── ORDERS ────────────────────────────────────────────
let currentFilter = 'all';

function loadOrders(status) {
  currentFilter = status;
  api('orders', {status}).then(orders => {
    const tbody = document.getElementById('orders-body');
    if (!orders.length) {
      tbody.innerHTML = `<tr><td colspan="7"><div class="empty"><div class="empty-ico">📭</div><p>Geen bestellingen</p></div></td></tr>`;
      return;
    }
    tbody.innerHTML = orders.map(o => `
      <tr onclick="openOrderModal(${o.id})">
        <td><strong>${esc(o.order_id)}</strong></td>
        <td>${esc(o.customer_name)}<br><span style="font-size:12px;color:var(--ink3)">${esc(o.email)}</span></td>
        <td>${esc(o.city)}</td>
        <td><strong>€${parseFloat(o.total).toFixed(2)}</strong></td>
        <td>${badge(o.status)}</td>
        <td style="color:var(--ink3);font-size:12px">${fmtDate(o.created_at)}</td>
        <td onclick="event.stopPropagation()">
          <button type="button" class="btn btn-sm" style="background:#dbeafe;color:#1d4ed8;border:none" onclick="quickSetOrderStatus(${o.id}, 'confirmed')">Bevestig</button>
          <button type="button" class="btn btn-sm" style="background:#d1fae5;color:#065f46;border:none;margin-left:6px" onclick="quickSetOrderStatus(${o.id}, 'paid')">Betaald</button>
          <button type="button" class="btn btn-sm" style="background:#ede9fe;color:#5b21b6;border:none;margin-left:6px" onclick="quickSetOrderStatus(${o.id}, 'shipped')">Verzonden</button>
          ${['confirmed','paid','shipped','delivered'].includes(o.status) ? `<button type="button" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border:none;margin-left:6px" title="Klantmail (bevestigd of betaald)" onclick="event.stopPropagation();sendOrderConfirmationEmailFromId(${o.id})">✉️ E-mail</button>` : ''}
        </td>
      </tr>`).join('');
  }).catch(() => {
    const tbody = document.getElementById('orders-body');
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="7"><div class="empty"><div class="empty-ico"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2l9.2 16.3H2.8z"></path><path d="M12 10v4.2M12 17.2v.02"></path></svg></div><p>Kon bestellingen niet laden. Herlaad de pagina.</p></div></td></tr>`;
    }
  });
}

function filterOrders(status, btn) {
  document.querySelectorAll('#view-orders .fpill').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  document.getElementById('order-search').value = '';
  loadOrders(status);
}

function filterOrderSearch() {
  const q = (document.getElementById('order-search').value || '').toLowerCase().trim();
  document.querySelectorAll('#orders-body tr').forEach(tr => {
    tr.style.display = q === '' || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// ── WHATSAPP (order summary) ──────────────────────────
function openWhatsAppForCurrentOrder() {
  if (!currentOrder || !currentOrder.phone) return;
  const o = currentOrder;
  const clean = String(o.phone).replace(/\D/g, '');
  const lines = [];
  lines.push(`Hoi ${o.customer_name},`);
  lines.push(`Over bestelling *${o.order_id}* (${fmtDate(o.created_at)}):`);
  lines.push('');
  const items = o.items || [];
  items.forEach(i => {
    let extra = '';
    if (i.printing_option === 'custom') {
      extra = ' · ' + (i.print_name || '') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' (' + i.print_badges + ')' : '');
    }
    const line = `${i.name} (${i.size}${extra})`;
    const sub = (parseFloat(i.price) * (parseInt(i.quantity, 10) || 0)).toFixed(2);
    lines.push(`• ${i.quantity}× ${line} — €${sub}`);
  });
  lines.push('');
  lines.push(`Subtotaal: €${parseFloat(o.subtotal).toFixed(2)}`);
  lines.push(`Verzendkosten: €${parseFloat(o.shipping).toFixed(2)}`);
  lines.push(`Totaal: €${parseFloat(o.total).toFixed(2)}`);
  const msg = encodeURIComponent(lines.join('\n'));
  window.open(`https://wa.me/${clean}?text=${msg}`, '_blank', 'noopener,noreferrer');
}

async function saveOrderAdminNote() {
  if (!currentOrder) return;
  const el = document.getElementById('om-admin-note');
  const note = el ? el.value : '';
  const r = await api('save_order_note', { id: currentOrder.id, admin_note: note });
  if (r.ok) {
    currentOrder.admin_note = note;
    toast('✓ Interne notitie opgeslagen');
  } else {
    toast('⚠️ Notitie opslaan mislukt');
  }
}

function orderCountryLabel(cc) {
  const c = String(cc || 'NL').toUpperCase();
  const map = { NL: 'Nederland', BE: 'België', DE: 'Duitsland', FR: 'Frankrijk' };
  return map[c] || c;
}

// ── COPY ADDRESS ──────────────────────────────────────
function copyAddress(name, street, zip, city, countryCode) {
  const cc = String(countryCode || 'NL').toUpperCase();
  let line = `${zip} ${city}`;
  if (cc !== 'NL') line += ' — ' + orderCountryLabel(cc);
  const text = `${name}\n${street}\n${line}`;
  navigator.clipboard.writeText(text).then(() => toast('📋 Adres gekopieerd'));
}

// ── ORDER DETAIL MODAL ────────────────────────────────
let currentOrder = null;

function openOrderModal(id) {
  document.getElementById('order-modal').classList.add('on');
  document.getElementById('om-body').innerHTML = '<div style="text-align:center;padding:40px;color:var(--ink3)">Laden…</div>';
  api('order_detail', {id}).then(o => {
    if (!o) { document.getElementById('om-body').innerHTML = 'Bestelling niet gevonden.'; return; }
    currentOrder = o;
    document.getElementById('om-title').textContent = `Bestelling ${esc(o.order_id)}`;

    const sub    = parseFloat(o.subtotal);
    const ship   = parseFloat(o.shipping);
    const total  = parseFloat(o.total);
    const statuses = ['pending','confirmed','paid','shipped','delivered','cancelled'];

    document.getElementById('om-body').innerHTML = `
      <div class="od-section">
        <div class="od-label">Klant</div>
        <div class="od-info">
          <strong>${esc(o.customer_name)}</strong><br>
          📧 ${esc(o.email)}<br>
          📱 ${esc(o.phone)}<br>
          📍 ${esc(o.street)}, ${esc(o.zip)} ${esc(o.city)}${String(o.country || 'NL').toUpperCase() !== 'NL' ? ' · ' + esc(orderCountryLabel(o.country)) : ''}
          ${o.notes ? `<br>📝 ${esc(o.notes)}` : ''}
        </div>
        <div class="od-quick-actions">
          ${o.phone ? `<button type="button" class="od-qa-btn wa" onclick="openWhatsAppForCurrentOrder()">
            <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/><path d="M12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.531 3.5 11.815 11.815 0 0012.05 0z"/></svg>
            WhatsApp
          </button>` : ''}
          <button class="od-qa-btn" onclick='copyAddress(${jss(o.customer_name)},${jss(o.street)},${jss(o.zip)},${jss(o.city)},${jss(o.country || "NL")})'>
            📋 Adres kopiëren
          </button>
        </div>
      </div>
      <div class="od-section">
        <div class="od-label">Track &amp; trace <span style="font-weight:400;color:var(--ink3);font-size:11px">(optioneel — na verzenden)</span></div>
        <div style="display:flex;gap:8px">
          <input class="finput" id="om-tracking" placeholder="bijv. 3SPOST12345678" value="${esc(o.tracking_number||'')}" style="flex:1">
          <button type="button" class="btn btn-sm" onclick="saveTracking()" style="white-space:nowrap;background:var(--accent);color:#fff;border:none;padding:0 14px">Opslaan</button>
        </div>
      </div>
      <div class="od-section">
        <div class="od-label">Interne notitie <span style="font-weight:400;color:var(--ink3);font-size:11px">(niet zichtbaar voor klanten)</span></div>
        <textarea id="om-admin-note" class="finput" style="min-height:88px;width:100%;resize:vertical">${esc(o.admin_note || '')}</textarea>
        <button type="button" class="btn btn-sm" style="margin-top:8px;background:var(--bg);border:1px solid var(--line);color:var(--ink2)" onclick="saveOrderAdminNote()">Interne notitie opslaan</button>
      </div>
      <div class="od-section">
        <div class="od-label">Regels</div>
        <div class="od-items">
          ${(o.items||[]).map(i => `
            <div class="od-item">
              <span>${i.product_id ? `<button type="button" class="od-product-link" onclick="event.stopPropagation();closeOrderModal();openProductModal(${parseInt(i.product_id,10)||0})">Bewerk in catalogus</button>` : ''}${i.quantity}× ${esc(i.name)} <span style="color:var(--ink3)">(${esc(i.size)}${i.printing_option==='custom' ? ' · ' + esc(i.print_name||'') + (i.print_number ? ' #' + esc(i.print_number) : '') + (i.print_badges ? ' (' + esc(i.print_badges) + ')' : '') : ''})</span></span>
              <strong>€${(i.price*i.quantity).toFixed(2)}</strong>
            </div>`).join('')}
          <div class="od-item" style="color:var(--ink3)"><span>Verzendkosten</span><span>${ship===0?'GRATIS':'€'+ship.toFixed(2)}</span></div>
          <div class="od-total"><span>Totaal</span><span>€${total.toFixed(2)}</span></div>
        </div>
      </div>
      <div class="od-section">
        <div class="od-label">Status aanpassen</div>
        <div class="status-row">
          ${statuses.map(s => `<button class="status-btn${o.status===s?' active':''}" onclick="setStatus('${s}',this)">${statusLabelNl(s)}</button>`).join('')}
        </div>
      </div>
      ${o.status === 'cancelled' ? `
      <p class="od-email-hint" style="margin-top:14px;text-align:center">Bestelling geannuleerd — geen klantmail.</p>
      ` : (() => {
        const paidLike = ['paid','shipped','delivered'].includes(o.status);
        const canMail = ['confirmed','paid','shipped','delivered'].includes(o.status);
        const showMarkPaid = o.status === 'pending' || o.status === 'confirmed';
        let html = '';
        // Bevestigd: eerst bevestigingsmail (geen betaling), daarna optie om betaald te zetten + betaalmail
        if (o.status === 'confirmed' && canMail) {
          html += `
      <button type="button" class="pay-btn pay-btn-sec" onclick="sendConfirmationForCurrentOrder()" style="margin-top:0">
        ✉️ Bevestigingsmail versturen
      </button>
      <p class="od-email-hint">Stuurt de mail “<strong>bestelling bevestigd</strong>” (nog geen betaaltekst) — naar <strong>${esc(o.email)}</strong>.</p>`;
        }
        if (showMarkPaid) {
          html += `
      <button type="button" class="pay-btn" id="pay-btn" onclick="markAsPaid()" style="margin-top:${o.status === 'confirmed' ? '14px' : '0'}">
        Markeer als betaald &amp; stuur betaalmail
      </button>
      <p class="od-email-hint">Zet status op <strong>betaald</strong> en stuurt de <strong>betaal</strong>mail (${esc(o.email)}).</p>`;
        }
        if (canMail && paidLike) {
          html += `
      <button type="button" class="pay-btn pay-btn-sec" onclick="sendConfirmationForCurrentOrder()" style="margin-top:12px">
        ✉️ Betaalbevestiging versturen
      </button>
      <p class="od-email-hint">Opnieuw versturen (mail “<strong>betaling ontvangen</strong>”) — naar <strong>${esc(o.email)}</strong>.</p>`;
        }
        return html;
      })()}
      <div style="font-size:11px;color:var(--ink3);margin-top:10px;text-align:center">
        Besteld op: ${fmtDate(o.created_at)}
      </div>
    `;
  });
}

function closeOrderModal() {
  document.getElementById('order-modal').classList.remove('on');
  currentOrder = null;
}

async function saveTracking() {
  if (!currentOrder) return;
  const trk = document.getElementById('om-tracking').value.trim();
  const r = await api('save_tracking', { id: currentOrder.id, tracking_number: trk });
  if (r.ok) { currentOrder.tracking_number = trk; toast(trk ? '📦 Track & trace opgeslagen' : 'Track & trace gewist'); }
  else toast('❌ Track & trace opslaan mislukt');
}

// ── CONFIRM DIALOG ────────────────────────────────────
function showConfirm(title, msg, onOk, okText) {
  const okBtn = document.getElementById('confirm-ok');
  if (okBtn) {
    okBtn.textContent = okText || 'Verwijderen';
    okBtn.style.background = '#ef4444';
    okBtn.onclick = () => { closeConfirm(); onOk(); };
  }
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent   = msg;
  document.getElementById('confirm-modal').classList.add('on');
}
function closeConfirm() {
  document.getElementById('confirm-modal').classList.remove('on');
  const ok = document.getElementById('confirm-ok');
  if (ok) {
    ok.textContent = 'Verwijderen';
    ok.style.background = '#ef4444';
    ok.style.border = 'none';
  }
}

// ── QUICK RESTOCK ─────────────────────────────────────
/** Zelfde maten als api/stock_notify.php + productpagina (anders geen nabestel-match) */
const ADMIN_STOCK_SIZES = ['XS','S','M','L','XL','XXL','2XL','3XL','4XL'];
/** Spelerskits: zelfde maximum als maattabel (3XL/4XL alleen fan). */
const ADMIN_STOCK_SIZES_PLAYER = ['XS','S','M','L','XL','XXL'];
const ADMIN_STOCK_SIZES_FAN_ONLY = ['2XL','3XL','4XL'];
let _restockProductId = null;
let _restockSizes = ADMIN_STOCK_SIZES;
let _restockInitialSizes = null;
function openRestockModalById(id) {
  const p = productsCache.find(x => x.id === id);
  openRestockModal(id, p ? p.name : '', p ? (p.stock_sizes || {}) : {});
}
function openRestockModal(id, name, stockSizesJson) {
  _restockProductId = id;
  // Resolve name + stock_sizes from arguments or productsCache
  let pName = name, current = {};
  if (stockSizesJson) {
    try {
      if (typeof stockSizesJson === 'string') {
        current = JSON.parse(stockSizesJson);
      } else if (typeof stockSizesJson === 'object') {
        current = stockSizesJson || {};
      }
    } catch (e) {}
  }
  if (!pName) {
    const p = productsCache.find(x => x.id === id);
    if (p) {
      pName = p.name;
      current = typeof p.stock_sizes === 'string' ? JSON.parse(p.stock_sizes||'{}') : (p.stock_sizes||{});
    }
  }
  const pMeta = productsCache.find(x => x.id === id);
  const isPlayerRestock = (pMeta && String(pMeta.version || '') === 'player');
  const sizes = isPlayerRestock ? ADMIN_STOCK_SIZES_PLAYER : ADMIN_STOCK_SIZES;
  _restockSizes = sizes;
  _restockInitialSizes = Object.fromEntries(ADMIN_STOCK_SIZES.map(s => [s, Math.max(0, parseInt(current[s] ?? 0, 10) || 0)]));
  document.getElementById('restock-title').textContent = 'Snel bijvullen';
  document.getElementById('restock-name').textContent  = pName || ('Product #' + id);
  document.getElementById('restock-grid').innerHTML = sizes.map(s => `
    <div class="ss-item">
      <div class="ss-lbl">${s}</div>
      <input class="finput ss-input" type="number" min="0" step="1" id="rs-${s}" value="${current[s]??0}"
        oninput="updateRestockTotal()">
    </div>`).join('');
  updateRestockTotal();
  document.getElementById('restock-modal').classList.add('on');
}
function updateRestockTotal() {
  const list = _restockSizes || ADMIN_STOCK_SIZES;
  const total = list.reduce((sum,s)=>sum+(parseInt(document.getElementById('rs-'+s)?.value)||0),0);
  document.getElementById('restock-total').textContent = total;
}
function closeRestockModal() {
  document.getElementById('restock-modal').classList.remove('on');
  _restockProductId = null;
  _restockInitialSizes = null;
  _restockSizes = ADMIN_STOCK_SIZES;
}
async function saveQuickRestock() {
  if (!_restockProductId) return;
  const sizes = _restockSizes || ADMIN_STOCK_SIZES;
  const stock_sizes = Object.fromEntries(ADMIN_STOCK_SIZES.map(s => [
    s,
    sizes.includes(s) ? (parseInt(document.getElementById('rs-'+s)?.value, 10) || 0) : 0
  ]));
  const r = await api('quick_restock', { id: _restockProductId, stock_sizes });
  if (!r.ok) { toast('❌ Voorraad bijwerken mislukt'); return; }
  // Save ID before closing modal (closeRestockModal sets it to null)
  const savedId = _restockProductId;
  // Update cache
  const p = productsCache.find(x => x.id === savedId);
  if (p) { p.stock = r.stock; p.stock_sizes = stock_sizes; }
  toast('✅ Voorraad bijgewerkt');
  closeRestockModal();
  loadDash();
  // Notify sizes that became available (0->>0) OR were increased in this edit.
  const fromApi = Array.isArray(r.restocked_sizes) ? r.restocked_sizes : [];
  const increasedSizes = ADMIN_STOCK_SIZES.filter(s => {
    const before = parseInt((_restockInitialSizes && _restockInitialSizes[s]) ?? 0, 10) || 0;
    const after = parseInt(stock_sizes[s] ?? 0, 10) || 0;
    return after > 0 && after > before;
  });
  const restockedSizes = Array.from(new Set([...fromApi, ...increasedSizes]));
  if (restockedSizes.length > 0 && !r.restock_email_done) {
    await sendRestockNotifications(savedId, restockedSizes);
  }
}

async function applyOrderStatus(status, btn) {
  if (!currentOrder) return;
  const r = await api('update_status', {id: currentOrder.id, status});
  if (!r.ok) { toast('⚠️ Status bijwerken mislukt'); return; }

  currentOrder.status = status;
  document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadOrders(currentFilter);
  loadDash();

  if (status === 'paid') {
    toast('✓ Status: betaald — tik op “Betaalbevestiging versturen” om de klant te mailen.', 4800);
  } else {
    toast('✓ Status: ' + statusLabelNl(status));
  }
  openOrderModal(currentOrder.id);
}

async function setStatus(status, btn) {
  if (!currentOrder) return;
  if (status === 'cancelled') {
    showConfirm(
      'Bestelling annuleren?',
      'De status wordt op geannuleerd gezet. Er wordt geen automatische klantmail verstuurd. Voorraad wordt teruggeboekt wanneer dat van toepassing is.',
      () => { void applyOrderStatus('cancelled', btn); },
      'Ja, annuleren'
    );
    return;
  }
  await applyOrderStatus(status, btn);
}

// ── MARK AS PAID + SEND EMAIL ─────────────────────────
async function markAsPaid() {
  if (!currentOrder) return;
  const btn = document.getElementById('pay-btn');
  const o = currentOrder;
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Bestelling opslaan…';
  }

  const r = await api('update_status', {id: o.id, status: 'paid'});
  if (!r.ok) {
    toast('⚠️ Opslaan als betaald mislukt');
    if (btn) { btn.disabled = false; btn.textContent = 'Markeer als betaald & stuur betaalmail'; }
    return;
  }
  o.status = 'paid';
  if (btn) btn.textContent = 'E-mail versturen…';

  try {
    await sendPaidConfirmationEmail(o);
    toast('✅ Betaald — bevestiging verstuurd naar ' + o.email, 4500);
  } catch (e) {
    toast('⚠️ Opgeslagen als betaald — e-mail mislukt. Gebruik “Betaalbevestiging versturen” om opnieuw te proberen.', 5000);
  }

  loadOrders(currentFilter);
  loadDash();
  openOrderModal(o.id);
}

// ── PRODUCTS ──────────────────────────────────────────
// productsCache is initialized from PHP above
const KNOWN_CATS = ['premier','laliga','bundesliga','seriea','ligue1','eredivisie','national'];

function applyProductAdminFilters(list) {
  const q = (document.getElementById('pf-search')?.value || '').toLowerCase().trim();
  const league = document.getElementById('pf-league')?.value || 'all';
  const active = document.getElementById('pf-active')?.value || 'all';
  const images = document.getElementById('pf-images')?.value || 'all';
  const source = document.getElementById('pf-source')?.value || 'all';
  let view = [...list];
  if (q) {
    view = view.filter(p => {
      const blob = `${p.name || ''} ${p.league || ''} ${p.cat || ''} ${p.id} ${p.kits_path || ''} ${p.description || ''}`.toLowerCase();
      return blob.includes(q);
    });
  }
  if (league !== 'all') {
    if (league === '__other') {
      view = view.filter(p => !KNOWN_CATS.includes(String(p.cat || '').toLowerCase()));
    } else {
      view = view.filter(p => String(p.cat || '').toLowerCase() === league);
    }
  }
  if (active === '1') view = view.filter(p => !!parseInt(p.active, 10));
  if (active === '0') view = view.filter(p => !parseInt(p.active, 10));
  if (images === 'yes') view = view.filter(p => p.image || p.image2 || p.image3);
  if (images === 'no') view = view.filter(p => !(p.image || p.image2 || p.image3));
  if (source === 'kits') view = view.filter(p => p.kits_path);
  if (source === 'manual') view = view.filter(p => !p.kits_path);
  return view;
}

function resetProductFilters() {
  const s = document.getElementById('pf-search');
  if (s) s.value = '';
  ['pf-league','pf-active','pf-images','pf-source'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = 'all';
  });
  applyProductFiltersAndRender();
}

function adminThumbHtml(p) {
  const main = p.image || p.image2 || p.image3;
  const mainSrc = main ? productImgSrc(main) : '';
  const extras = [p.image, p.image2, p.image3].filter(Boolean);
  const uniq = [...new Set(extras)];
  const rest = uniq.length > 1 ? uniq.slice(1) : [];
  const imgPart = main
    ? `<div class="admin-pthumb-wrap"><img class="admin-pthumb" src="${mainSrc}" alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><span class="admin-pthumb-miss" style="display:none;align-items:center;justify-content:center;width:100%;height:100%"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span></div>`
    : `<div class="admin-pthumb-wrap"><span class="admin-pthumb-miss" style="display:flex;align-items:center;justify-content:center;width:100%;height:100%"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span></div>`;
  const mini = rest.length
    ? `<div class="admin-pmini">${rest.map(u => `<img src="${productImgSrc(u)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">`).join('')}</div>`
    : '';
  return imgPart + mini;
}

function renderProductsTableFromCache() {
  const prods = productsCache;
  const view = applyProductAdminFilters(prods);
  const tbody = document.getElementById('products-body');
  const cnt = document.getElementById('products-count');
  if (cnt) {
    cnt.textContent = view.length === prods.length
      ? `${view.length} product${view.length === 1 ? '' : 'en'}`
      : `${view.length} van ${prods.length} getoond`;
  }
  if (!view.length) {
    const emptyMsg = prods.length ? 'Geen producten voor deze filters.' : 'Nog geen producten';
    tbody.innerHTML = `<tr><td colspan="10"><div class="empty"><div class="empty-ico"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"></path><path d="M3 8l9 5 9-5"></path><path d="M12 13v8"></path></svg></div><p>${emptyMsg}</p></div></td></tr>`;
    return;
  }
  tbody.innerHTML = view.map(p => {
    const kpath = p.kits_path ? `<span title="Gesynchroniseerde map">📁 ${esc(p.kits_path)}</span>` : '<span>Handmatig / upload</span>';
    return `
      <tr onclick="openProductModal(${p.id})" title="Klik om te bewerken">
        <td onclick="event.stopPropagation()">
          <input type="checkbox" class="bulk-product-check" value="${p.id}" onchange="syncBulkSelectAll()">
        </td>
        <td>${adminThumbHtml(p)}</td>
        <td class="pname-cell">
          <strong>${esc(p.name)}</strong>
          <div class="pname-meta">ID ${p.id} · ${kpath}</div>
        </td>
        <td>${esc(p.league)}</td>
        <td>${p.cat ? esc(p.cat) : '<span style="color:var(--ink3)">—</span>'}</td>
        <td><strong>€${parseFloat(p.price).toFixed(2)}</strong></td>
        <td>${(parseInt(p.stock,10) || 0) <= 0 ? '<span style="color:var(--red);font-weight:700">Uit</span>' : ((parseInt(p.stock,10) || 0) < 5 ? '<span style="color:#92400e;font-weight:700">Laag (' + (parseInt(p.stock,10) || 0) + ')</span>' : '<span style="color:var(--green);font-weight:700">' + (parseInt(p.stock,10) || 0) + '</span>')}</td>
        <td>${p.badge ? badge(p.badge) : '<span style="color:var(--ink3)">—</span>'}</td>
        <td>${p.active ? '<span style="color:var(--green);font-weight:600">Actief</span>' : '<span style="color:var(--red)">Verborgen</span>'}</td>
        <td onclick="event.stopPropagation()" style="text-align:center">
          <div class="vr-toggle${parseInt(p.in_voorraad)===1?' vr-toggle--on':''}" data-id="${p.id}" onclick="toggleInVoorraadProducts(${p.id},${parseInt(p.in_voorraad)===1?0:1},this)" title="${parseInt(p.in_voorraad)===1?'Op voorraad — klik om uit te zetten':'Niet op voorraad — klik om aan te zetten'}" style="margin:0 auto">
            <div class="vr-toggle-knob"></div>
          </div>
        </td>
        <td onclick="event.stopPropagation()">
          <button class="btn btn-sm" style="background:var(--bg);border:1px solid var(--line);color:var(--ink2)" onclick="openProductModal(${p.id})">Bewerken</button>
          <button class="btn btn-sm" style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;margin-left:6px" onclick="event.stopPropagation();duplicateProductRow(${p.id})">Dupliceren</button>
          <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;margin-left:6px" onclick="event.stopPropagation();deleteProduct(${p.id})">Verwijderen</button>
        </td>
      </tr>`;
  }).join('');
}

function toggleInVoorraadProducts(id, newVal, toggleEl) {
  api('toggle_voorraad', {id, in_voorraad: newVal}).then(r => {
    if (!r.ok) { toast('❌ Opslaan mislukt'); return; }
    const on = r.in_voorraad === 1;
    toggleEl.classList.toggle('vr-toggle--on', on);
    toggleEl.setAttribute('onclick', `toggleInVoorraadProducts(${id},${on?0:1},this)`);
    toggleEl.title = on ? 'Op voorraad — klik om uit te zetten' : 'Niet op voorraad — klik om aan te zetten';
    const p = productsCache.find(x => x.id === id);
    if (p) p.in_voorraad = r.in_voorraad;
    refreshVoorraadTableFromCache();
    toast(on ? '✅ Op voorraad gezet' : '📦 Uit voorraad gehaald');
  });
}

function loadProducts(forceRefresh = false) {
  if (Array.isArray(productsCache)) {
    renderProductsTableFromCache();
    renderCatCoverDropdowns(productsCache);
    refreshVoorraadTableFromCache();
  }
  if (!forceRefresh) {
    return Promise.resolve(productsCache);
  }
  return api('products')
    .then(prods => {
      productsCache = Array.isArray(prods) ? prods : [];
      renderProductsTableFromCache();
      renderCatCoverDropdowns(productsCache);
      refreshVoorraadTableFromCache();
      return productsCache;
    })
    .catch(() => {
      if (Array.isArray(productsCache) && productsCache.length) {
        toast('⚠️ Live verversen mislukt — cache getoond');
        renderProductsTableFromCache();
        renderCatCoverDropdowns(productsCache);
        refreshVoorraadTableFromCache();
        return productsCache;
      }
      const tbody = document.getElementById('products-body');
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="10"><div class="empty"><div class="empty-ico"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2l9.2 16.3H2.8z"></path><path d="M12 10v4.2M12 17.2v.02"></path></svg></div><p>Kon producten niet laden. Herlaad de pagina.</p></div></td></tr>';
      }
      throw new Error('products_load_failed');
    });
}

// detectProductType() en detectProductVersion() staan nu in js/product-classify.js
// (geladen vóór dit script in admin.php). Admin classificeert nu identiek aan de winkel;
// de oude admin-kopie miste de kids-categorie en woog 'set' vóór retro/hemdsetjes.

const catCoverProducts = {};
let _coverPickerCat = null;

function getProdsForCoverCat(prods, cat) {
  if (cat === 'player') return prods.filter(p => detectProductVersion(p) === 'player');
  if (cat === 'voorraad') return prods.filter(p => parseInt(p.in_voorraad, 10) === 1);
  return prods.filter(p => detectProductType(p) === cat);
}

function renderCatCoverDropdowns(prods) {
  const cats = ['shirts','sets','hemdsetjes','retro','kids','voorraad'];
  cats.forEach(cat => {
    catCoverProducts[cat] = getProdsForCoverCat(prods, cat);
    const cover = catCoverProducts[cat].find(p => parseInt(p.cat_cover));
    updateCoverUI(cat, cover || null);
  });
}

function updateCoverUI(cat, p) {
  const thumb = document.getElementById('cover-thumb-' + cat);
  const nameEl = document.getElementById('cover-name-' + cat);
  if (thumb) {
    const img = p && (p.image || p.image2 || p.image3);
    thumb.style.backgroundImage = img ? `url('${productImgSrc(img)}')` : '';
  }
  if (nameEl) nameEl.textContent = p ? p.name : 'Geen cover ingesteld';
}

function openCoverPicker(cat) {
  _coverPickerCat = cat;
  const labels = {shirts:'Shirts',sets:'Sets',hemdsetjes:'Hemdsetjes',retro:'Retro',kids:'Kids',voorraad:'Voorraad'};
  document.getElementById('cover-picker-title').textContent = 'Cover kiezen — ' + labels[cat];
  document.getElementById('cover-picker-search').value = '';
  document.getElementById('cover-picker').style.display = 'block';
  document.getElementById('cover-picker').scrollIntoView({behavior:'smooth', block:'start'});
  renderCoverPickerGrid('');
}

function closeCoverPicker() {
  document.getElementById('cover-picker').style.display = 'none';
  _coverPickerCat = null;
}

function renderCoverPickerGrid(q) {
  const cat   = _coverPickerCat;
  const prods = catCoverProducts[cat] || [];
  const term  = (q || '').toLowerCase().trim();
  const list  = term ? prods.filter(p => p.name.toLowerCase().includes(term)) : prods;
  const grid  = document.getElementById('cover-picker-grid');
  if (!grid) return;
  if (!list.length) {
    grid.innerHTML = '<div style="grid-column:1/-1;color:var(--ink3);font-size:13px;padding:20px 0">Geen producten gevonden</div>';
    return;
  }
  grid.innerHTML = list.map(p => {
    const img = p.image || p.image2 || p.image3;
    const isCover = parseInt(p.cat_cover);
    return `<div onclick="pickCover(${p.id})" style="cursor:pointer;border:2px solid ${isCover ? 'var(--ink)' : 'var(--line)'};border-radius:8px;overflow:hidden;background:var(--bg);transition:border-color .15s" id="cover-pick-${p.id}">
      <div style="width:100%;height:90px;background:var(--bg2);background-size:cover;background-position:center;${img ? `background-image:url('${productImgSrc(img)}')` : ''}"></div>
      <div style="padding:5px 6px;font-size:11px;color:var(--ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.name)}</div>
      ${isCover ? '<div style="padding:0 6px 5px;font-size:10px;font-weight:700;color:var(--ink)">✓ Huidige</div>' : ''}
    </div>`;
  }).join('');
}

async function pickCover(pid) {
  const cat      = _coverPickerCat;
  const clearIds = (catCoverProducts[cat] || []).map(p => p.id);
  const r        = await api('set_cat_cover', { product_id: pid, clear_ids: clearIds });
  if (!r.ok) { toast('❌ Opslaan mislukt'); return; }
  // Update local cache
  (catCoverProducts[cat] || []).forEach(p => { p.cat_cover = p.id === pid ? 1 : 0; });
  productsCache.forEach(p => {
    const inCat = cat === 'voorraad' ? (parseInt(p.in_voorraad, 10) === 1) : (detectProductType(p) === cat);
    if (inCat) p.cat_cover = p.id === pid ? 1 : 0;
  });
  // Refresh card UI
  const chosen = (catCoverProducts[cat] || []).find(p => p.id === pid);
  updateCoverUI(cat, chosen || null);
  toast('Cover opgeslagen');
  closeCoverPicker();
}

function applyProductFiltersAndRender() {
  if (!productsCache.length) {
    loadProducts(true);
    return;
  }
  renderProductsTableFromCache();
}

// ── PRODUCT MODAL — spelersversie: alleen XS–XXL (2XL/3XL/4XL = fan) ──
function isAdminPlayerVersion() {
  return (document.getElementById('p-version')?.value || '') === 'player';
}
function adminStockSizesForProductForm() {
  return isAdminPlayerVersion() ? ADMIN_STOCK_SIZES_PLAYER : ADMIN_STOCK_SIZES;
}
function syncAdminStockGridForVersion() {
  const showFan = !isAdminPlayerVersion();
  document.querySelectorAll('.size-stock-grid .ss-item--fan-only').forEach(el => {
    el.style.display = showFan ? '' : 'none';
  });
}

function openProductModal(id) {
  productFormDirty = false;
  document.getElementById('product-modal').classList.add('on');
  document.getElementById('pm-title').textContent = id ? 'Product bewerken' : 'Product toevoegen';
  document.getElementById('p-id').value = id || '';
  const dupBtn = document.getElementById('pm-dup-btn');
  if (dupBtn) dupBtn.style.display = id ? 'inline-block' : 'none';

  if (!id) {
    ['p-name','p-league','p-emoji','p-price','p-sort'].forEach(x => document.getElementById(x).value = '');
    document.getElementById('p-stock').value = '0';
    document.getElementById('p-cat').value    = '';
    document.getElementById('p-badge').value  = '';
    document.getElementById('p-desc').value    = '';
    ['p-fit','p-size-advice','p-material','p-ship-info','p-returns-info','p-pers-policy','p-care'].forEach(x => document.getElementById(x).value = '');
    document.getElementById('p-img-order').value = '1,2,3';
    document.getElementById('p-active').checked = true;
    document.getElementById('p-in-voorraad').checked = false;
    ADMIN_STOCK_SIZES.forEach(s => { document.getElementById('ss-'+s).value = '0'; });
    ADMIN_STOCK_SIZES_PLAYER.forEach(s => { document.getElementById('ps-'+s).value = '0'; });
    document.getElementById('p-player-price').value = '';
    document.getElementById('p-version').value = '';
    syncAdminStockGridForVersion();
    recalcTotalStock();
    const kpr = document.getElementById('p-kits-path-row');
    const kp = document.getElementById('p-kits-path');
    if (kpr) kpr.style.display = 'none';
    if (kp) kp.value = '';
    resetImageUI();
    return;
  }

  api('products').then(prods => {
    const p = prods.find(x => x.id === id);
    if (!p) return;
    document.getElementById('p-name').value     = p.name;
    document.getElementById('p-league').value   = p.league;
    document.getElementById('p-cat').value      = p.cat;
    document.getElementById('p-emoji').value    = p.emoji;
    document.getElementById('p-desc').value     = p.description || '';
    document.getElementById('p-fit').value      = p.fit_info || '';
    document.getElementById('p-size-advice').value = p.size_advice || '';
    document.getElementById('p-material').value = p.material_info || '';
    document.getElementById('p-ship-info').value = p.shipping_info || '';
    document.getElementById('p-returns-info').value = p.returns_info || '';
    document.getElementById('p-pers-policy').value = p.personalization_policy || '';
    document.getElementById('p-care').value = p.care_instructions || '';
    document.getElementById('p-img-order').value = p.image_order || '1,2,3';
    document.getElementById('p-price').value    = p.price;
    document.getElementById('p-stock').value    = p.stock ?? 0;
    // Per-size stock
    const sizes = p.stock_sizes ? JSON.parse(p.stock_sizes) : {};
    ADMIN_STOCK_SIZES.forEach(s => {
      document.getElementById('ss-'+s).value = sizes[s] ?? 0;
    });
    let psizes = {};
    try { psizes = p.player_stock_sizes ? JSON.parse(p.player_stock_sizes) : {}; } catch (_) { psizes = {}; }
    ADMIN_STOCK_SIZES_PLAYER.forEach(s => {
      document.getElementById('ps-'+s).value = psizes[s] ?? 0;
    });
    document.getElementById('p-player-price').value = (p.player_price != null && p.player_price !== '') ? p.player_price : '';
    document.getElementById('p-version').value = p.version || '';
    syncAdminStockGridForVersion();
    recalcTotalStock();
    document.getElementById('p-badge').value    = p.badge;
    document.getElementById('p-sort').value     = p.sort_order;
    document.getElementById('p-active').checked = !!parseInt(p.active);
    document.getElementById('p-in-voorraad').checked = parseInt(p.in_voorraad) === 1;

    const kpr = document.getElementById('p-kits-path-row');
    const kp = document.getElementById('p-kits-path');
    if (p.kits_path) {
      if (kpr) kpr.style.display = '';
      if (kp) kp.value = p.kits_path;
    } else {
      if (kpr) kpr.style.display = 'none';
      if (kp) kp.value = '';
    }

    // Images
    const imgKeys = {1:'image',2:'image2',3:'image3'};
    [1,2,3].forEach(slot => {
      const key = imgKeys[slot];
      const val = p[key] || '';
      document.getElementById(`p-img-input-${slot}`).value = '';
      document.getElementById(`p-img-current-${slot}`).value = val;
      if (val) {
        document.getElementById(`p-img-preview-${slot}`).innerHTML =
          `<img src="${productImgSrc(val)}" alt="${esc(p.name)}">`;
        document.getElementById(`p-img-remove-${slot}`).style.display = 'block';
      } else {
        resetImageUISlot(slot);
      }
    });
  });
}

function imgPlaceholderHTML() {
  return `
    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:32px;height:32px;opacity:.4"><path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
    <div>Klik om een foto te uploaden</div>
    <div class="img-upload-hint">JPG, PNG of WebP · max. 5 MB</div>`;
}

function resetImageUISlot(slot) {
  document.getElementById(`p-img-current-${slot}`).value = '';
  document.getElementById(`p-img-input-${slot}`).value = '';
  document.getElementById(`p-img-remove-${slot}`).style.display = 'none';
  document.getElementById(`p-img-preview-${slot}`).innerHTML = imgPlaceholderHTML();
}

function resetImageUI() {
  [1,2,3].forEach(s => resetImageUISlot(s));
}

function previewImageSlot(input, slot) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById(`p-img-preview-${slot}`).innerHTML =
      `<img src="${e.target.result}" alt="Voorbeeld">`;
    document.getElementById(`p-img-remove-${slot}`).style.display = 'block';
  };
  reader.readAsDataURL(input.files[0]);
}

function removeImageSlot(slot) {
  resetImageUISlot(slot);
  // Mark image for removal.
  document.getElementById(`p-img-current-${slot}`).value = '__remove__';
}

function closeProductModal() {
  if (productFormDirty) {
    showConfirm('Niet-opgeslagen wijzigingen', 'Je hebt niet-opgeslagen wijzigingen. Toch sluiten?', () => {
      productFormDirty = false;
      document.getElementById('product-modal').classList.remove('on');
    });
    document.getElementById('confirm-ok').textContent = 'Sluiten zonder opslaan';
    document.getElementById('confirm-ok').style.background = '#f59e0b';
    return;
  }
  document.getElementById('product-modal').classList.remove('on');
}

/** Zet elke maat op hetzelfde getal — daarna Product opslaan. Zo verschijnen items met één klik in “op voorraad”. */
function fillStockPreset(qty) {
  const n = Math.max(0, Math.floor(Number(qty)) || 0);
  adminStockSizesForProductForm().forEach(s => {
    const el = document.getElementById('ss-' + s);
    if (el) el.value = String(n);
  });
  if (isAdminPlayerVersion()) {
    ADMIN_STOCK_SIZES_FAN_ONLY.forEach(s => {
      const el = document.getElementById('ss-' + s);
      if (el) el.value = '0';
    });
  }
  recalcTotalStock();
}

function recalcTotalStock() {
  const list = adminStockSizesForProductForm();
  const total = list.reduce((sum, s) => sum + (parseInt(document.getElementById('ss-'+s)?.value, 10) || 0), 0);
  document.getElementById('ss-total-val').textContent = total;
  document.getElementById('p-stock').value = total;
}
document.getElementById('p-version')?.addEventListener('change', () => {
  syncAdminStockGridForVersion();
  recalcTotalStock();
});

async function saveProduct() {
  const data = {
    id:     document.getElementById('p-id').value,
    name:   document.getElementById('p-name').value.trim(),
    league: document.getElementById('p-league').value.trim(),
    cat:    document.getElementById('p-cat').value,
    emoji:  document.getElementById('p-emoji').value.trim() || '👕',
    description: document.getElementById('p-desc').value.trim(),
    fit_info: document.getElementById('p-fit').value.trim(),
    size_advice: document.getElementById('p-size-advice').value.trim(),
    material_info: document.getElementById('p-material').value.trim(),
    shipping_info: document.getElementById('p-ship-info').value.trim(),
    returns_info: document.getElementById('p-returns-info').value.trim(),
    personalization_policy: document.getElementById('p-pers-policy').value.trim(),
    care_instructions: document.getElementById('p-care').value.trim(),
    image_order: document.getElementById('p-img-order').value.trim() || '1,2,3',
    price:  document.getElementById('p-price').value,
    stock:  document.getElementById('p-stock').value,
    version: document.getElementById('p-version').value,
    stock_sizes: Object.fromEntries(ADMIN_STOCK_SIZES.map(s => {
      let q = parseInt(document.getElementById('ss-' + s).value, 10) || 0;
      if (isAdminPlayerVersion() && ADMIN_STOCK_SIZES_FAN_ONLY.includes(s)) q = 0;
      return [s, q];
    })),
    player_price: (document.getElementById('p-player-price').value.trim() === '' ? null : document.getElementById('p-player-price').value),
    player_stock_sizes: Object.fromEntries(ADMIN_STOCK_SIZES_PLAYER.map(s => [s, parseInt(document.getElementById('ps-' + s).value, 10) || 0])),
    badge:  document.getElementById('p-badge').value,
    sort:   document.getElementById('p-sort').value || 0,
    active:       document.getElementById('p-active').checked ? 1 : 0,
    in_voorraad:  document.getElementById('p-in-voorraad').checked ? 1 : 0,
  };
  if (!data.name || !data.league || !data.price) { toast('⚠️ Vul naam, competitie en prijs in'); return; }

  const saveBtn = document.querySelector('#product-modal .save-btn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Opslaan…';

  // Handle images (1..3). Upload only if a new file is selected.
  const imgKeys = {1:'image',2:'image2',3:'image3'};
  for (const slot of [1,2,3]) {
    const fileInput  = document.getElementById(`p-img-input-${slot}`);
    const imgCurrent = document.getElementById(`p-img-current-${slot}`).value;
    const key = imgKeys[slot];

    if (fileInput.files[0]) {
      const formData = new FormData();
      formData.append('image', fileInput.files[0]);
      try {
        const res = await fetch('api/upload.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': CSRF_TOKEN }
        });
        const up  = await res.json();
        if (up.ok) {
          data[key] = up.filename;
        } else {
          toast('⚠️ Upload mislukt: ' + (up.error || 'fout'));
          saveBtn.disabled = false; saveBtn.textContent = 'Product opslaan';
          return;
        }
      } catch (e) {
        toast('⚠️ Upload mislukt — controleer de server');
        saveBtn.disabled = false; saveBtn.textContent = 'Product opslaan';
        return;
      }
    } else if (imgCurrent === '__remove__') {
      data[key] = ''; // will be saved as null
    } else {
      data[key] = imgCurrent; // keep existing (or empty)
    }
  }

  api('save_product', data).then(async (r) => {
    saveBtn.disabled = false; saveBtn.textContent = 'Product opslaan';
    if (r.ok) {
      productFormDirty = false;
      toast(data.id ? '✓ Product bijgewerkt' : '✓ Product toegevoegd');
      const pid = parseInt(data.id, 10) || r.id;
      if (pid && Array.isArray(r.restocked_sizes) && r.restocked_sizes.length > 0 && !r.restock_email_done) {
        await sendRestockNotifications(pid, r.restocked_sizes);
      }
      closeProductModal();
      loadProducts(true);
    } else {
      toast('⚠️ Er ging iets mis');
    }
  });
}

async function duplicateProductRow(id) {
  const r = await api('duplicate_product', { id });
  if (r.ok && r.id) {
    toast('✓ Duplicaat aangemaakt');
    loadProducts(true);
    openProductModal(r.id);
  } else {
    toast('⚠️ Dupliceren mislukt');
  }
}

async function duplicateCurrentProduct() {
  const id = parseInt(document.getElementById('p-id').value, 10);
  if (!id) return;
  await duplicateProductRow(id);
}

function deleteProduct(id) {
  const p = productsCache.find(x => x.id === id);
  const name = p ? `"${p.name}"` : `product #${id}`;
  showConfirm(`${name} verwijderen?`, 'Dit kan niet ongedaan worden gemaakt.', () => {
    api('delete_product', {id}).then(r => {
      if (r.ok) { toast('🗑️ Product verwijderd'); loadProducts(true); }
    });
  });
}

function quickSetOrderStatus(id, status) {
  api('update_status', {id, status}).then(r => {
    if (r.ok) {
      let msg = '✓ Status: ' + statusLabelNl(status);
      if (status === 'paid') {
        msg += '. Gebruik ✉️ E-mail of open de bestelling om de bevestiging te versturen.';
      }
      toast(msg, status === 'paid' ? 4600 : 2800);
      loadOrders(currentFilter);
      loadDash();
    }
  });
}


function toggleBulkSelectAll(checked) {
  document.querySelectorAll('.bulk-product-check').forEach(ch => { ch.checked = checked; });
}

function syncBulkSelectAll() {
  const checks = [...document.querySelectorAll('.bulk-product-check')];
  const all = checks.length && checks.every(ch => ch.checked);
  const selectAll = document.getElementById('bulk-select-all');
  if (selectAll) selectAll.checked = !!all;
}

async function applyBulkAction() {
  const action = document.getElementById('bulk-action').value;
  const ids = [...document.querySelectorAll('.bulk-product-check:checked')].map(ch => parseInt(ch.value, 10));
  if (!action) { toast('⚠️ Kies eerst een bulkbewerking'); return; }
  if (!ids.length) { toast('⚠️ Selecteer minstens één product'); return; }
  if (action === 'delete') {
    showConfirm(
      `${ids.length} geselecteerde producten verwijderen?`,
      'Dit kan niet ongedaan worden gemaakt. Alle geselecteerde producten worden permanent verwijderd.',
      async () => {
        for (const id of ids) {
          await api('delete_product', {id});
        }
        toast(`✓ ${ids.length} product(en) verwijderd`);
        document.getElementById('bulk-select-all').checked = false;
        loadProducts(true);
      },
      'Ja, verwijderen'
    );
    return;
  }

  for (const id of ids) {
    const p = productsCache.find(x => x.id === id);
    if (!p) continue;

    const payload = {
      id: p.id,
      name: p.name,
      league: p.league,
      cat: p.cat,
      emoji: p.emoji,
      description: p.description || '',
      fit_info: p.fit_info || '',
      size_advice: p.size_advice || '',
      material_info: p.material_info || '',
      shipping_info: p.shipping_info || '',
      returns_info: p.returns_info || '',
      personalization_policy: p.personalization_policy || '',
      care_instructions: p.care_instructions || '',
      image_order: p.image_order || '1,2,3',
      price: p.price,
      stock: p.stock ?? 0,
      badge: p.badge,
      sort: p.sort_order || 0,
      active: p.active ? 1 : 0,
      image: p.image || '',
      image2: p.image2 || '',
      image3: p.image3 || ''
    };

    if (action === 'activate') payload.active = 1;
    if (action === 'hide') payload.active = 0;
    if (action === 'badge_new') payload.badge = 'new';
    if (action === 'badge_hot') payload.badge = 'hot';
    if (action === 'badge_none') payload.badge = '';

    await api('save_product', payload);
  }

  toast(`✓ Bulkbewerking toegepast op ${ids.length} product(en)`);
  document.getElementById('bulk-select-all').checked = false;
  loadProducts(false);
}


// ── CLOSE MODALS ON BG CLICK ──────────────────────────
document.getElementById('order-modal').addEventListener('click', function(e) {
  if (e.target === this) closeOrderModal();
});
document.getElementById('product-modal').addEventListener('click', function(e) {
  if (e.target === this) closeProductModal();
});

// ── PRODUCT LIST FILTERS ─────────────────────────────
let productFilterTimer = null;
(function wireProductFilters() {
  const s = document.getElementById('pf-search');
  if (s) {
    s.addEventListener('input', () => {
      clearTimeout(productFilterTimer);
      productFilterTimer = setTimeout(applyProductFiltersAndRender, 220);
    });
  }
  ['pf-league', 'pf-active', 'pf-images', 'pf-source'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyProductFiltersAndRender);
  });
})();

// ── BACK TO TOP ───────────────────────────────────────
(function() {
  const btn = document.createElement('button');
  btn.className = 'back-to-top';
  btn.title = 'Naar boven';
  btn.innerHTML = '↑';
  btn.onclick = () => window.scrollTo({top:0, behavior:'smooth'});
  document.body.appendChild(btn);
  window.addEventListener('scroll', () => btn.classList.toggle('on', window.scrollY > 300), {passive:true});
})();

// ── RESTOCK NOTIFICATIONS ─────────────────────────────
async function sendRestockNotifications(productId, restockedSizes) {
  if (!EMAILJS_RESTOCK_TPL) {
    toast('⚠️ Geen nabestel-mail template-ID ingesteld', 6000);
    console.error('[restock] EMAILJS_RESTOCK_TPL is empty');
    return;
  }

  if (!restockedSizes || restockedSizes.length === 0) {
    console.warn('[restock] No restocked sizes — skipping');
    return;
  }
  try {
    await ensureEmailJsInitialized();
  } catch (e) {
    toast('⚠️ ' + (e && e.message ? e.message : 'EmailJS kon niet starten.'), 8000);
    console.error('[restock] emailjs init', e);
    return;
  }

  let p = productsCache.find(x => Number(x.id) === Number(productId));
  if (!p) {
    try {
      const list = await api('products');
      p = (list || []).find(x => Number(x.id) === Number(productId));
    } catch (_) {}
  }
  const productName = p ? p.name : ('Product #' + productId);
  const productUrl  = storeProductUrl(p || { id: productId, name: '' });
  const productImage = p ? itemPrimaryImageSrc({
    product_image: p.image,
    product_image2: p.image2,
    image: p.image,
    image2: p.image2,
    image3: p.image3,
  }) : '';

  const notifications = await api('get_stock_notifications', { id: productId, sizes: restockedSizes });

  if (!notifications || !notifications.length) {
    console.warn('[restock] No notifications in DB for these sizes');
    return;
  }

  let sent = 0, failed = 0;
  for (const n of notifications) {
    try {
      const restockParams = {
        to_email:     n.email,
        to_name:      (String(n.email).split('@')[0] || 'klant'),
        product_name: productName,
        size:         n.size,
        product_url:  productUrl,
        product_image: productImage,
        site_url:     EMAIL_PUBLIC_BASE || '',
        logo_url:     (typeof EMAIL_PUBLIC_BASE === 'string' && EMAIL_PUBLIC_BASE) ? (EMAIL_PUBLIC_BASE + '/images/logo.jpeg') : '',
      };
      if (ADMIN_NOTIFY_EMAIL && String(n.email || '').toLowerCase() !== String(ADMIN_NOTIFY_EMAIL).toLowerCase()) {
        restockParams.bcc = ADMIN_NOTIFY_EMAIL;
      }
      await emailjs.send(EJSVC_RESTOCK, EMAILJS_RESTOCK_TPL, restockParams, kbeEmailJsSendOpts());
      sent++;
      await api('clear_stock_notification_row', { id: productId, email: n.email, size: n.size });
    } catch(e) {
      failed++;
      console.error('[restock] failed for', n.email, 'service:', EJSVC_RESTOCK, 'template:', EMAILJS_RESTOCK_TPL, e);
    }
  }

  if (sent > 0) toast(`📧 Nabestel-mail naar ${sent} ontvanger(s)`, 4000);
  if (failed > 0) toast(`⚠️ ${failed} e-mail(s) mislukt — zie console`, 4000);
}

// ── UNSAVED CHANGES WARNING ───────────────────────────
let productFormDirty = false;
function markProductDirty() { productFormDirty = true; }

document.getElementById('product-modal').querySelectorAll('input,textarea,select').forEach(el => {
  el.addEventListener('input', markProductDirty);
  el.addEventListener('change', markProductDirty);
});

// ── NEW ORDER BADGE POLLING ───────────────────────────
let _lastPendingCount = null;
function pollOrderBadge() {
  api('stats').then(s => {
    const count = parseInt(s.pending) || 0;
    const badge = document.getElementById('orders-badge');
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
    // If new orders arrived since last poll, flash the tab
    if (_lastPendingCount !== null && count > _lastPendingCount) {
      document.getElementById('tab-orders').style.animation = 'none';
      setTimeout(() => document.getElementById('tab-orders').style.background = 'rgba(239,68,68,.15)', 0);
      setTimeout(() => document.getElementById('tab-orders').style.background = '', 2000);
    }
    _lastPendingCount = count;
  }).catch(()=>{});
}

// Poll every 60 seconds
pollOrderBadge();
setInterval(pollOrderBadge, 60000);

// ── INIT ──────────────────────────────────────────────
loadDash();
