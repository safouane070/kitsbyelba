/**
 * nav-mega.js — verbergt lege categorieën/leagues in de navigatie:
 *   1. league-links in de nav-mega-menu zonder producten;
 *   2. hele categorieën zonder producten (bv. Kids) — overal waar ze getoond
 *      worden: de nav-tabs, de homepage-tegels én de shop-categorietabs.
 * Zo beland je nooit op een lege categorie- of league-pagina.
 *
 * Geladen door includes/nav.php → draait op ELKE pagina met de gedeelde nav.
 * Zelfstandig (IIFE, geen globals) zodat het niet botst met de shop-/home-JS.
 *
 * De matching-logica hieronder is een 1-op-1 kopie van de "single source of
 * truth" in shop.html (detectProductType + LEAGUE_KEYWORDS + productMatchesLeague).
 * Wijzig je die daar → pas 'm hier ook aan.
 *
 * Fallback: als de productlijst niet geladen kan worden, blijft ALLES staan
 * (liever alle leagues tonen dan per ongeluk alles verbergen).
 */
(function () {
  'use strict';

  var LEAGUE_KEYWORDS = {
    premier:    ['premier'],
    laliga:     ['la liga', 'laliga'],
    bundesliga: ['bundesliga'],
    seriea:     ['serie'],
    ligue1:     ['ligue'],
    eredivisie: ['eredivisie'],
    national:   ['national', 'wk', 'landen']
  };

  // 1-op-1 met shop.html/index.html
  function detectProductType(p) {
    var catDb = String(p.cat || '').toLowerCase();
    if (catDb === 'hemsetjes' || catDb === 'hemdsetjes') return 'hemdsetjes';
    if (catDb === 'training') return 'shirts';
    if (catDb === 'retro') return 'retro';
    if (catDb === 'kids') return 'kids';
    var name = String(p.name || '').toLowerCase();
    var desc = String(p.description || '').toLowerCase();
    if (name.indexOf('retro kids') !== -1) return 'kids';
    if (name.indexOf('kids kit') !== -1 || name.indexOf(' kids ') !== -1 || /\bkids\b/.test(name)) return 'kids';
    if (/\bretro\b|\bvintage\b/.test(name) || /\bretro\b|\bvintage\b/.test(desc)) return 'retro';
    if (/\bhem\b|\bhemdje\b|\bhemset|\bhemdsetjes\b/.test(name)) return 'hemdsetjes';
    if (name.indexOf('full kit set') !== -1 || name.indexOf(' kit set') !== -1 || /\bset\b/.test(name)) return 'sets';
    return 'shirts';
  }

  function leagueHit(cat, lg, key) {
    if (cat === key) return true;
    return (LEAGUE_KEYWORDS[key] || [key]).some(function (kw) { return lg.indexOf(kw) !== -1; });
  }

  function matchesLeague(p, key) {
    key = String(key || '').toLowerCase();
    if (!key || key === 'all') return true;
    var cat = String(p.cat || '').toLowerCase();
    var lg = String(p.league || '').toLowerCase();
    if (key === 'overig') {
      return !Object.keys(LEAGUE_KEYWORDS).some(function (k) { return leagueHit(cat, lg, k); });
    }
    return leagueHit(cat, lg, key);
  }

  function prune(products) {
    var megas = document.querySelectorAll('#navLinks .nav-mega');
    for (var i = 0; i < megas.length; i++) {
      var mega = megas[i];
      var links = mega.querySelectorAll('a[href*="league="]');
      var remaining = 0;
      for (var j = 0; j < links.length; j++) {
        var a = links[j];
        var href = a.getAttribute('href') || '';
        var mCat = href.match(/^([a-z]+)\?/i);
        var mLg = href.match(/league=([a-z0-9]+)/i);
        if (!mCat || !mLg) { remaining++; continue; }
        var cat = mCat[1].toLowerCase();
        var lslug = mLg[1].toLowerCase();
        var has = products.some(function (p) {
          return detectProductType(p) === cat && matchesLeague(p, lslug);
        });
        if (has) {
          remaining++;
        } else {
          a.remove();
        }
      }
      // Categorie zonder enige gevulde league → geen (lege) dropdown tonen.
      if (remaining === 0) {
        var li = mega.closest('.has-mega');
        if (li) li.classList.remove('has-mega');
      }
    }
  }

  var CATS = ['shirts', 'sets', 'hemdsetjes', 'retro', 'kids'];

  function hideEmptyCats(products) {
    CATS.forEach(function (cat) {
      var has = products.some(function (p) { return detectProductType(p) === cat; });
      if (has) return;
      // 1. Nav-tab (gedeelde nav, ook in het mobiele menu)
      var navLink = document.getElementById('navType-' + cat);
      if (navLink) {
        var li = navLink.closest('li');
        (li || navLink).remove();
      }
      // 2. Homepage-tegels ("Shop per categorie")
      var cards = document.querySelectorAll('.catcard[href="' + cat + '"]');
      for (var c = 0; c < cards.length; c++) cards[c].remove();
      // 3. Shop-categorietabs
      var tabs = document.querySelectorAll('#catTabs .ftab[data-cat-filter="' + cat + '"]');
      for (var t = 0; t < tabs.length; t++) tabs[t].remove();
    });
  }

  function init() {
    fetch('api/products.php')
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (list) {
        if (!Array.isArray(list) || !list.length) return;
        prune(list);          // lege leagues uit de mega-menu
        hideEmptyCats(list);  // lege categorieën volledig verbergen
      })
      .catch(function () { /* stil: bij fout blijft alles staan */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
