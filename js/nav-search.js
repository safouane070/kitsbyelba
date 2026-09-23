/* Zelfstandige nav-zoek voor pagina's zonder shop-core (product.php, account.php).
   Gebruikt dezelfde modal-markup (includes/nav-search.php) + dezelfde CSS (css/app.css).
   Haalt de productenlijst één keer op uit api/products.php.
   Doet niks als shop-core al een openNavSearch levert (index/shop). */
(function () {
  if (typeof window.openNavSearch === 'function') return;

  var PRODUCTS = [];
  var loaded = false, activeIdx = -1, suggestions = [];
  var POPULAR = ['Barcelona', 'Real Madrid', 'Oranje', 'Portugal', 'Retro'];

  // Kopie van navSearchEmptyHtml in js/shop-core.js.
  function emptyHtml(q) {
    var wa = typeof window.kbeWaDigits === 'function' ? window.kbeWaDigits() : '31684446255';
    var msg = 'Hoi! Ik zoek een shirt van ' + q + '. Kunnen jullie dat bestellen?';
    return '<div class="nav-search-empty"><strong>Niet gevonden: “' + esc(q) + '”</strong>'
      + '<span>We bestellen bijna elk shirt voor je na (7–12 werkdagen).</span>'
      + '<a class="nav-search-wa" href="https://wa.me/' + wa + '?text=' + encodeURIComponent(msg) + '" target="_blank" rel="noopener">Vraag het via WhatsApp</a></div>';
  }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function imgSrc(file) {
    if (!file) return '';
    var s = String(file).trim().replace(/\\/g, '/').replace(/^\/+/, '');
    if (/^https?:\/\//i.test(s)) return s;
    var prefix = 'uploads/products/';
    while (s.toLowerCase().indexOf(prefix) === 0) s = s.slice(prefix.length);
    if (!s) return '';
    return prefix + s.split('/').filter(Boolean).map(encodeURIComponent).join('/');
  }
  function thumbSrc(file) {
    var full = imgSrc(file);
    if (!full || /^https?:\/\//i.test(full)) return full;
    var base = full.split('/').pop().replace(/\.[^.]+$/, '');
    return 'uploads/products/thumbs/' + base + '.webp';
  }
  function load(cb) {
    if (loaded) { cb && cb(); return; }
    fetch('api/products.php').then(function (r) { return r.json(); }).then(function (d) {
      PRODUCTS = Array.isArray(d) ? d : (d.products || d.data || []);
      loaded = true; cb && cb();
    }).catch(function () { loaded = true; cb && cb(); });
  }
  function score(p, q) {
    var name = String(p.name || '').toLowerCase();
    var hay = (name + ' ' + String(p.league || '') + ' ' + String(p.cat || '')).toLowerCase();
    if (hay.indexOf(q) === -1) return 0;
    if (name.indexOf(q) === 0) return 3;
    if (name.indexOf(q) !== -1) return 2;
    return 1;
  }
  // Kopie van SEARCH_ALIASES in js/shop-core.js.
  var ALIASES = {
    'barca': 'barcelona', 'psg': 'paris', 'spurs': 'tottenham', 'juve': 'juventus', 'atleti': 'atletico', 'bvb': 'dortmund',
    'man utd': 'manchester united', 'man united': 'manchester united', 'man city': 'manchester city',
    'oranje': 'nederland', 'holland': 'nederland', 'england': 'engeland', 'germany': 'duitsland', 'spain': 'spanje',
    'france': 'frankrijk', 'morocco': 'marokko', 'brazil': 'brazili', 'brasil': 'brazili', 'belgium': 'belgi'
  };
  function aliases(q) {
    return Object.keys(ALIASES).reduce(function (s, k) {
      return s.replace(new RegExp('(^|\\s)' + k + '(?=\\s|$)', 'g'), '$1' + ALIASES[k]);
    }, q);
  }
  function suggest(qraw, limit) {
    limit = limit || 8;
    var q = aliases(String(qraw || '').trim().toLowerCase());
    if (!PRODUCTS.length) return [];
    if (!q) return PRODUCTS.filter(function (p) { return p.image || p.image2 || p.image3; }).slice(0, limit);
    return PRODUCTS.map(function (p) { return { p: p, s: score(p, q) }; })
      .filter(function (x) { return x.s > 0; })
      .sort(function (a, b) { return b.s - a.s; })
      .slice(0, limit).map(function (x) { return x.p; });
  }
  function slugify(s) { return String(s == null ? '' : s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  function go(id) {
    var p = PRODUCTS.find(function (x) { return Number(x.id) === Number(id); });
    var slug = p ? id + '-' + slugify(p.name) : '';
    window.location.href = slug
      ? 'product.php?slug=' + encodeURIComponent(slug)
      : 'product.php?id=' + encodeURIComponent(id);
  }
  function setIdx(n) {
    activeIdx = n;
    var els = document.querySelectorAll('#navSearchResults .nav-search-item');
    els.forEach(function (el, i) { el.classList.toggle('is-active', i === activeIdx); });
  }
  function render(qraw) {
    var box = document.getElementById('navSearchResults'); if (!box) return;
    var q = String(qraw || '').trim();
    var items = suggest(q, 8); suggestions = items; activeIdx = -1;
    if (!items.length) {
      box.innerHTML = q ? emptyHtml(q) : '<div class="nav-search-empty">Type om direct producten te zien.</div>';
      return;
    }
    var popular = !q ? '<div class="nav-search-popular">' + POPULAR.filter(function (t) { return suggest(t, 1).length; }).map(function (t) {
      return '<button type="button" class="nav-search-chip" data-term="' + esc(t) + '">' + esc(t) + '</button>';
    }).join('') + '</div>' : '';
    box.innerHTML = popular + items.map(function (p, idx) {
      var full = imgSrc(p.image || p.image2 || p.image3), th = thumbSrc(p.image || p.image2 || p.image3);
      return '<button type="button" class="nav-search-item" onmouseenter="__navsIdx(' + idx + ')" onclick="__navsGo(' + Number(p.id) + ')">' +
        (full ? '<img src="' + th + '" data-full="' + full + '" alt="' + esc(p.name || '') + '" width="48" height="58" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=this.getAttribute(\'data-full\')">' : '<div></div>') +
        '<div><div class="nav-search-item-name">' + esc(p.name || '') + '</div><div class="nav-search-item-meta">' + esc(p.league || '') + '</div></div>' +
        '<div class="nav-search-item-price">€' + Number(p.price || 0).toFixed(2).replace('.', ',') + '</div></button>';
    }).join('');
    box.querySelectorAll('.nav-search-chip').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var t = btn.getAttribute('data-term') || '';
        var inp = document.getElementById('navSearchInput');
        if (inp) { inp.value = t; render(t); inp.focus(); }
      });
    });
  }
  window.openNavSearch = function () {
    var bg = document.getElementById('navSearchBg'), m = document.getElementById('navSearchModal'), inp = document.getElementById('navSearchInput');
    if (bg) bg.classList.add('on');
    if (m) m.classList.add('on');
    if (inp) { inp.value = ''; setTimeout(function () { inp.focus(); }, 50); }
    load(function () { render((inp && inp.value) || ''); });
  };
  window.closeNavSearch = function () {
    var bg = document.getElementById('navSearchBg'), m = document.getElementById('navSearchModal');
    if (bg) bg.classList.remove('on');
    if (m) m.classList.remove('on');
    activeIdx = -1;
  };
  window.renderNavSearchSuggestions = function (q) { render(q); };
  window.applyNavSearch = function () { if (suggestions.length) go(suggestions[Math.max(0, activeIdx)].id); };
  window.handleNavSearchKeydown = function (e) {
    var n = suggestions.length;
    if (e.key === 'Escape') { window.closeNavSearch(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); if (!n) return; setIdx(activeIdx < n - 1 ? activeIdx + 1 : 0); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); if (!n) return; setIdx(activeIdx > 0 ? activeIdx - 1 : n - 1); return; }
    if (e.key === 'Enter') { e.preventDefault(); window.applyNavSearch(); return; }
  };
  window.__navsIdx = setIdx;
  window.__navsGo = function (id) { window.closeNavSearch(); go(id); };
})();
