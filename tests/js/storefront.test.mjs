// Frontend-logica (filters, zoeken, voorraad) — draait de ECHTE scripts uit de site in een vm
// met een minimale DOM-stub, in dezelfde volgorde als de browser ze laadt.
//   node --test tests/js/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

// Alles-slikkende stub: elke property/aanroep geeft weer een stub, zodat UI-code zonder echte DOM kan draaien.
function stub(over = {}) {
  return new Proxy(function () {}, {
    get(_, k) {
      if (k in over) return over[k];
      if (k === 'then') return undefined;
      if (k === Symbol.toPrimitive) return () => '';
      if (k === Symbol.iterator) return function* () {};
      return stub();
    },
    set: () => true,
    apply: () => stub(),
    construct: () => stub(),
  });
}

// Productfixture, gemodelleerd naar de echte API-rijen
const P = (id, name, extra = {}) => ({
  id, name, version: 'fan', league: 'WK', cat: 'national', price: 37.5, badge: '', image: `k${id}.webp`,
  stock: 2, stock_sizes: { S: 0, M: 1, L: 1, XL: 0 }, player_stock_sizes: null, in_voorraad: 1, ...extra,
});
const FIXTURE = [
  P(1, 'Portugal Thuistenue WK 2026 Ronaldo #7'),
  P(2, 'Argentinië Uittenue WK 2026 Messi #10', { stock: 0, stock_sizes: { M: 0, L: 0 } }), // voorraadartikel, uitverkocht
  P(3, 'Portugal Uittenue WK 2026 Rafa Leão #17', { stock_sizes: { XL: 2 } }),
  P(4, 'FC Barcelona Thuistenue 25/26 Lamine Yamal #10', { league: 'La Liga', cat: 'laliga' }),
  P(5, 'Brazilië Set Donkerblauw 26/27', { in_voorraad: 0, stock: 25 }),
  P(6, 'Santos 11/12 Roze Neymar Jr #11', { cat: 'retro', league: 'Brasil' }),
  P(7, 'Corinthians Hemdset Beige', { cat: 'hemdsetjes', league: 'Brasil', in_voorraad: 0 }),
  P(8, 'Marokko Thuistenue WK 2026', { in_voorraad: 1, stock: 0, stock_sizes: null, player_stock_sizes: { M: 2 } }),
];

function load(files, { inline = [], els = {}, pathname = '/shop.html' } = {}) {
  const location = { href: '', search: '', hash: '', pathname, replace() {} };
  const document = stub({
    getElementById: (id) => (id in els ? els[id] : stub()),
    querySelectorAll: () => [],
    querySelector: () => null,
    readyState: 'complete',
    addEventListener() {},
  });
  const ctx = vm.createContext({
    console, document, location, navigator: {}, history: stub(),
    fetch: () => new Promise(() => {}),
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    IntersectionObserver: class { observe() {} disconnect() {} },
    setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, clearInterval() {}, requestAnimationFrame: () => 0,
    URLSearchParams, encodeURIComponent, decodeURIComponent,
  });
  vm.runInContext('globalThis.window = globalThis; window.addEventListener = () => {};', ctx);
  for (const f of files) vm.runInContext(read(f), ctx, { filename: f });
  for (const [name, src] of inline) vm.runInContext(src, ctx, { filename: name });
  vm.runInContext('PRODUCTS = __fx;', Object.assign(ctx, { __fx: structuredClone(FIXTURE) }));
  return { ctx, location, run: (code) => vm.runInContext(code, ctx) };
}

// Het grote inline <script> van shop.html (bevat de filterlogica: renderGrid, resetShopFilters, …)
function shopInlineScript() {
  const html = read('shop.html');
  const start = html.indexOf('<script>', html.indexOf("kits_asset('js/shop-core.js')"));
  const end = html.indexOf('</script>', start);
  const src = html.slice(start + '<script>'.length, end);
  assert.ok(!src.includes('<?'), 'inline shop-script mag geen PHP bevatten (anders klopt deze extractie niet meer)');
  return src;
}

const SHOP = ['js/product-classify.js', 'js/shop-core.js'];
// shop.html-context: shop-core + het inline pagina-script (declareert CONFIG, filterstate, renderGrid …)
const shopPage = (opts = {}) => load(SHOP, { ...opts, inline: [['shop.html#inline', shopInlineScript()]] });
const gridEl = () => ({ innerHTML: '', classList: { add() {}, remove() {}, toggle() {} } });
const cards = (el) => (el.innerHTML.match(/class="pcard /g) || []).length;

test('scripts laden zonder fouten (classify, shop-core, home, shop-inline)', () => {
  load([...SHOP, 'js/home.js'], { pathname: '/' });
  shopPage();
});

test('productInVoorraad: vlag én echt leverbaar (fan of player)', () => {
  const { run } = shopPage();
  assert.equal(run('productInVoorraad(PRODUCTS[0])'), true);
  assert.equal(run('productInVoorraad(PRODUCTS[1])'), false, 'uitverkocht voorraadshirt hoort niet onder 1–2 werkdagen');
  assert.equal(run('productInVoorraad(PRODUCTS[4])'), false, 'in_voorraad=0 is nabestelling');
  assert.equal(run('productInVoorraad(PRODUCTS[7])'), true, 'alleen player-voorraad telt ook');
});

test('zoeken: diakrieten, aliassen en spelersnamen', () => {
  const { run } = shopPage();
  assert.ok(run("getProductSearchScore(PRODUCTS[2], 'leao')") > 0, 'leao vindt Leão');
  assert.ok(run("getProductSearchScore(PRODUCTS[1], 'argentinie')") > 0, 'zonder trema');
  assert.ok(run("getProductSearchScore(PRODUCTS[0], 'ronaldo')") > 0);
  assert.equal(run("getProductSearchScore(PRODUCTS[0], 'messi')"), 0);
  assert.equal(run("getNavSearchSuggestions('portugal', 1).length"), 1, 'limit wordt gerespecteerd');
});

test('zoeken tijdens typen: 1 letter, extra woorden en onzin', () => {
  const { run } = shopPage();
  const names = (q) => JSON.parse(run(`JSON.stringify(searchProducts(PRODUCTS, ${JSON.stringify(q)}).map(p => p.id))`));
  assert.ok(names('r').includes(1), '"r" (eerste letter) vindt Ronaldo al');
  assert.ok(!names('r').includes(4), '1 letter alleen op woordbegin: de r in "Barcelona" telt niet');
  assert.deepEqual(names('ronaldo'), [1]);
  assert.deepEqual(names('cristiano ronaldo'), [1], 'onbekende voornaam blokkeert niet');
  assert.deepEqual(names('ronaldo shirt'), [1], 'los woord "shirt" blokkeert niet');
  run("PRODUCTS.push(Object.assign({}, PRODUCTS[3], { id: 99, name: 'FC Barcelona Special Zomershirt Lamine Yamal #10' }))");
  assert.deepEqual(names('ronaldo shirt'), [1], '"shirt" haalt geen Zomershirt erbij');
  assert.ok(names('shirt').includes(99), 'alleen "shirt" getypt zoekt wél op shirt');
  assert.deepEqual(names('portugal leao').slice(0, 1), [3], 'meeste woorden passend staat bovenaan');
  assert.deepEqual(names('qqqq'), [], 'onzin geeft niets');
  assert.deepEqual(names('xx yy'), [], 'alleen korte onzin-woorden geven niets');
});

test('Enter in zoekbalk: 1 treffer → product, meerdere op homepage → shop.html?q=', () => {
  const els = { navSearchInput: { value: 'portugal' }, grid: null };
  const { run, location } = shopPage({ els });
  run('var __went = null; goToProductPage = (id) => { __went = id; }; closeNavSearch = () => {}; renderGrid = () => {};');
  run('navSearchActiveIndex = -1; applyNavSearch();');
  assert.equal(run('__went'), null, 'twee Portugal-shirts: niet blind het eerste openen');
  assert.equal(location.href, 'shop.html?q=portugal');
  els.navSearchInput.value = 'yamal';
  run('applyNavSearch();');
  assert.equal(run('__went'), 4, 'precies één treffer → direct naar dat product');
});

test('spelersnaam en nummer uit productnaam (gedeeld: homepage + shop)', () => {
  const { run } = shopPage();
  const split = (n) => JSON.parse(run(`JSON.stringify(splitPlayerName(${JSON.stringify(n)}))`));
  assert.deepEqual(split('Portugal Thuistenue WK 2026 Ronaldo #7'), { kit: 'Portugal Thuistenue WK 2026', player: 'Ronaldo', num: '7' });
  assert.deepEqual(split('Nederland Uittenue WK 2026 F. De Jong #21'), { kit: 'Nederland Uittenue WK 2026', player: 'F. De Jong', num: '21' });
  assert.deepEqual(split('Santos 11/12 Roze Neymar Jr #11'), { kit: 'Santos 11/12 Roze', player: 'Neymar Jr', num: '11' });
  assert.deepEqual(split('AC Milan Special Zomertenue Rafa Leão #10'), { kit: 'AC Milan Special Zomertenue', player: 'Rafa Leão', num: '10' });
  assert.deepEqual(split('Spanje Uittenue WK 2026'), { kit: 'Spanje Uittenue WK 2026', player: '', num: '' });
});

test('homepage op-voorraad-sectie: alleen leverbare voorraad, zoeken filtert', () => {
  const grid = gridEl();
  const search = { value: '', dataset: {}, addEventListener(_, fn) { this.fire = fn; }, closest: () => ({}) };
  const { run } = load([...SHOP, 'js/home.js'], { pathname: '/', els: { 'hero-grid': grid, stockSearch: search, stockCount: {} } });
  run('renderHero()');
  const ids = [...grid.innerHTML.matchAll(/product\.php\?slug=(\d+)/g)].map((m) => Number(m[1]));
  assert.deepEqual([...new Set(ids)].sort(), [1, 3, 4, 6, 8], 'Messi (uitverkocht) en nabestellingen ontbreken');
  assert.match(grid.innerHTML, /<b>7<\/b>Ronaldo/);
  search.value = 'barcelona'; search.fire();
  assert.equal((grid.innerHTML.match(/class="hcard"/g) || []).length, 1);
  search.value = 'zzzz'; search.fire();
  assert.match(grid.innerHTML, /Vraag het via WhatsApp/);
});

test('shop-filters: type, competitie, voorraad, maat, zoeken, sortering', () => {
  const grid = gridEl();
  const { run } = shopPage({ els: { grid } });
  const count = (setup) => { run(`resetShopFilters(); ${setup}; renderGrid(currentFilterCat);`); return cards(grid); };
  assert.equal(count(''), 8, 'Alle tenues toont alles');
  assert.equal(count("currentTypeFilter='retro'"), 1);
  assert.equal(count("currentTypeFilter='hemdsetjes'"), 1);
  assert.equal(count("currentFilterCat='laliga'"), 1);
  assert.equal(count("currentFilterCat='national'"), 5);
  assert.equal(count("currentVoorraadFilter='in_stock'"), 5, 'Messi (0 stuks) niet bij op voorraad');
  assert.equal(count("currentVoorraadFilter='out_of_stock'"), 3);
  assert.equal(count("currentSizeFilter='XL'"), 1);
  assert.equal(count("currentSearchTerm='portugal'"), 2);
  assert.equal(count("currentSearchTerm='qqqq'"), 0);
  assert.match(grid.innerHTML, /Geen resultaten voor/);
  run("resetShopFilters(); currentSortMode='price_asc'; PRODUCTS[0].price = 99; renderGrid('all');");
  const prices = [...grid.innerHTML.matchAll(/class="pprice">€([\d,]+)/g)].map((m) => parseFloat(m[1].replace(',', '.')));
  assert.deepEqual(prices, [...prices].sort((a, b) => a - b), 'prijs oplopend');
});

test('shop-kaart: speler als rugbedrukking, korte tenuenaam, volle naam voor schermlezers', () => {
  const grid = gridEl();
  const { run } = shopPage({ els: { grid } });
  run("resetShopFilters(); currentSearchTerm='ronaldo'; renderGrid('all');");
  assert.match(grid.innerHTML, /<span class="kit-print" aria-hidden="true"><b>7<\/b>Ronaldo<\/span>/);
  assert.match(grid.innerHTML, /class="pcard-link"[^>]*>Portugal Thuistenue WK 2026<span class="sr-only"> Ronaldo #7<\/span><\/a>/);
  assert.match(grid.innerHTML, /alt="Portugal Thuistenue WK 2026 Ronaldo #7"/, 'foto-alt houdt de volle naam');
  run("resetShopFilters(); currentSearchTerm='corinthians'; renderGrid('all');");
  assert.doesNotMatch(grid.innerHTML, /kit-print/, 'geen nummer in de naam → geen rugbedrukking');
});

test('shop: elke product is via precies één competitie bereikbaar', () => {
  const { run } = shopPage();
  const keys = ['premier', 'laliga', 'bundesliga', 'seriea', 'ligue1', 'eredivisie', 'national', 'overig'];
  const hits = JSON.parse(run(`JSON.stringify(PRODUCTS.map(p => ${JSON.stringify(keys)}.filter(k => productMatchesLeague(p, k)).length))`));
  assert.ok(hits.every((n) => n === 1), `elk product hoort in exact 1 competitie, kreeg ${hits}`);
});

test('shop: "Filters resetten" blijft in de categorie van de pagina', () => {
  const { run } = shopPage({ els: { grid: gridEl() } });
  for (const [landing, type, voorraad] of [['retro', 'retro', 'all'], ['', 'all', 'all'], ['voorraad', 'all', 'in_stock']]) {
    run(`currentLandingType = ${JSON.stringify(landing)}; currentTypeFilter = 'sets'; currentVoorraadFilter = 'out_of_stock'; resetShopFilters();`);
    assert.equal(run('currentTypeFilter'), type, `landing "${landing}"`);
    assert.equal(run('currentVoorraadFilter'), voorraad, `landing "${landing}"`);
  }
});

test('shop: filteropties zonder producten worden verborgen', () => {
  const opt = (value) => ({ value, hidden: false, disabled: false });
  const color = { style: {}, opts: ['all', 'red', 'pink', 'beige'].map(opt) };
  color.querySelectorAll = () => color.opts;
  const version = { style: {}, opts: ['all', 'fan', 'player'].map(opt) };
  version.querySelectorAll = () => version.opts;
  const { run } = shopPage({ els: { shopColor: color, shopVersion: version, shopSize: null, shopKitType: null } });
  run('pruneRefineOptions()');
  assert.deepEqual(color.opts.filter((o) => !o.disabled).map((o) => o.value), ['all', 'pink', 'beige']);
  assert.equal(version.style.display, 'none', 'alleen fan → versiefilter heeft geen zin');
});
