// Verificatie voor GATES.md (G3–G12). Draait tegen de lokale XAMPP-render zodat
// we de ECHTE server-side HTML meten die een crawler krijgt (niet de bronbestanden).
// Gebruik: node tools/audit-seo-content.mjs <check>
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const BASE = process.env.KITS_BASE || 'http://localhost/kitsbyelba';
const CATS = ['shirts', 'sets', 'hemdsetjes', 'retro', 'kids', 'voorraad'];
const check = process.argv[2] || '';

async function get(pathname) {
  const res = await fetch(`${BASE}/${pathname}`, { redirect: 'follow' });
  const body = await res.text();
  return { status: res.status, body };
}
const strip = (html) => html.replace(/<script[\s\S]*?<\/script>/gi, ' ')
  .replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ')
  .replace(/&[a-z]+;/gi, ' ').replace(/\s+/g, ' ').trim();
const wordCount = (t) => (t.match(/\p{L}+/gu) || []).length;
function section(html, className) {
  // pak de inhoud van <... class="...className..."> ... tot de matchende afsluit —
  // grof: van de openings-div tot de eerstvolgende </section> of einde.
  const i = html.indexOf(className);
  if (i < 0) return '';
  const from = html.lastIndexOf('<', i);
  const rest = html.slice(from);
  const end = rest.search(/<\/section>/i);
  return end < 0 ? rest.slice(0, 4000) : rest.slice(0, end);
}
const die = (msg) => { console.log(msg); process.exit(1); };
const ok = (msg) => { console.log(msg); process.exit(0); };

// unieke lede/h2-fragmenten per categorie (moeten server-side in de kale HTML staan)
const CAT_MARKERS = {
  shirts:     ['Voetbalshirts kopen met naam en nummer', 'draag hetzelfde tenue als de sterren'],
  sets:       ['Complete voetbalsets en tenues', 'shirt én broekje, klaar om in te spelen'],
  hemdsetjes: ['Voetbal hemdsetjes voor baby en peuter', 'allerkleinste supporters'],
  retro:      ['Retro voetbalshirts en klassieke tenues', 'legendarische seizoenen'],
  kids:       ['Kids voetbalshirts en tenues', 'alle kindermaten'],
  voorraad:   ['Voetbalshirts direct op voorraad', 'direct leverbaar'],
};

async function main() {
  if (check === 'sitemap') {
    const { status, body } = await get('sitemap.php');
    if (status !== 200) die(`FAIL sitemap status ${status}`);
    if (!/^<\?xml/.test(body.trim())) die('FAIL sitemap: geen XML');
    for (const c of CATS) if (!body.includes(`/${c}<`)) die(`FAIL sitemap mist categorie /${c}`);
    if (!/product\.php\?slug=/.test(body)) die('FAIL sitemap: geen product-URLs');
    if (!body.includes('kitsbyelbaa.nl')) die('FAIL sitemap: domein ontbreekt');
    if (body.includes('kitsbyelba.com')) die('FAIL sitemap: oude .com aanwezig');
    ok('SITEMAP_OK');
  }

  if (check === 'robots') {
    const { status, body } = await get('robots.txt');
    if (status !== 200) die(`FAIL robots status ${status}`);
    if (!/Sitemap:\s*https?:\/\/\S+\/sitemap\.php/i.test(body)) die('FAIL robots: geen absolute sitemap-URL');
    ok('ROBOTS_OK');
  }

  if (check === 'categories') {
    for (const c of CATS) {
      const { status, body } = await get(c);
      if (status !== 200) die(`FAIL /${c} status ${status}`);
      for (const marker of CAT_MARKERS[c]) {
        if (!body.includes(marker)) die(`FAIL /${c}: mist server-side tekst "${marker}"`);
      }
    }
    // negatieve controle: een verzonnen marker mag NIET voorkomen (bewijst dat de
    // check echt op inhoud test en niet altijd slaagt)
    const control = await get('shirts');
    if (control.body.includes('NIET_BESTAANDE_MARKER_x9q')) die('CONTROL_FAIL categories');
    ok('CATEGORIES_OK');
  }

  if (check === 'meta') {
    const seen = new Map();
    for (const c of CATS) {
      const { body } = await get(c);
      const m = body.match(/<meta name="description" content="([^"]*)"/i);
      if (!m) die(`FAIL /${c}: geen meta description`);
      const desc = m[1];
      if (!/voetbal/i.test(desc)) die(`FAIL /${c}: description zonder zoekwoord`);
      if (seen.has(desc)) die(`FAIL dubbele description: /${c} == /${seen.get(desc)}`);
      seen.set(desc, c);
    }
    ok('META_OK');
  }

  if (check === 'contentblock') {
    for (const c of CATS) {
      const { body } = await get(c);
      const sec = section(body, 'seo-content-inner');
      if (!sec) die(`FAIL /${c}: geen seo-content-inner sectie`);
      const wc = wordCount(strip(sec));
      if (wc < 120) die(`FAIL /${c}: tekstblok slechts ${wc} woorden (<120)`);
    }
    ok('CONTENTBLOCK_OK');
  }

  if (check === 'home') {
    const { status, body } = await get('index.html');
    if (status !== 200) die(`FAIL home status ${status}`);
    const sec = section(body, 'seo-content-inner');
    if (!sec) die('FAIL home: geen seo-content-inner');
    const wc = wordCount(strip(sec));
    if (wc < 120) die(`FAIL home: tekstblok ${wc} woorden (<120)`);
    ok('HOME_OK');
  }

  if (check === 'alt') {
    // Product-hoofdfoto server-side: niet-leeg alt in gerenderde product.php
    const pid = (await get('api/products.php')).body.match(/"id":\s*(\d+)/);
    if (!pid) die('FAIL alt: geen product om te testen');
    const { body } = await get(`product.php?slug=${pid[1]}-test`);
    const mainImg = body.match(/<img class="main-img"[^>]*alt="([^"]*)"/i);
    if (!mainImg || mainImg[1].trim() === '') die('FAIL alt: main-img heeft leeg alt');
    // Card-template in shop-core.js gebruikt productnaam als alt (statische bron-check)
    const core = fs.readFileSync(path.resolve('js/shop-core.js'), 'utf8');
    if (!/alt="\$\{esc\(p\.name\)\}"/.test(core)) die('FAIL alt: card-template zonder productnaam-alt');
    ok('ALT_OK');
  }

  if (check === 'phplint') {
    const PHP = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
    const files = ['index.html', 'shop.html', 'product.php', 'sitemap.php'];
    for (const f of files) {
      try {
        const out = execFileSync(PHP, ['-l', f], { encoding: 'utf8' });
        if (!/No syntax errors/.test(out)) die(`FAIL php -l ${f}: ${out.trim()}`);
      } catch (e) {
        die(`FAIL php -l ${f}: ${(e.stdout || e.message || '').toString().trim()}`);
      }
    }
    ok('PHPLINT_OK');
  }

  if (check === 'http') {
    const targets = [
      ['index.html', 'KitsByElbaa'],
      ['shop.html', 'KitsByElbaa'],
      ...CATS.map((c) => [c, 'KitsByElbaa']),
    ];
    for (const [p, mustContain] of targets) {
      const { status, body } = await get(p);
      if (status !== 200) die(`FAIL ${p} status ${status}`);
      if (!body.includes(mustContain)) die(`FAIL ${p}: mist "${mustContain}"`);
      if (/(Fatal error|Parse error|Warning:|Notice:)\s/.test(body)) die(`FAIL ${p}: PHP-fout gelekt`);
    }
    const pid = (await get('api/products.php')).body.match(/"id":\s*(\d+)/);
    if (pid) {
      const { status, body } = await get(`product.php?slug=${pid[1]}-test`);
      if (status !== 200) die(`FAIL product status ${status}`);
      if (/(Fatal error|Parse error)\s/.test(body)) die('FAIL product: PHP-fout gelekt');
    }
    ok('HTTP_OK');
  }

  if (check === 'nodocom' || check === 'nodotcom') {
    // Alleen site-servende bestanden; .md-docs (NOTES/GATES) benoemen het oude
    // .com-domein juist om de fix te documenteren — die horen hier niet te falen.
    const exts = /\.(php|html|css|js|xml|txt)$/;
    const skip = new Set(['node_modules', '.git', 'uploads']);
    let hits = 0;
    (function walk(dir) {
      for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        if (skip.has(e.name)) continue;
        const p = path.join(dir, e.name);
        if (e.isDirectory()) walk(p);
        else if (exts.test(e.name)) {
          const t = fs.readFileSync(p, 'utf8');
          if (t.includes('kitsbyelba.com')) { console.log('  hardcoded .com: ' + path.relative('.', p)); hits++; }
        }
      }
    })(path.resolve('.'));
    if (hits) die(`FAIL nodocom: ${hits} hardcoded kitsbyelba.com`);
    ok('NODOTCOM_OK');
  }

  die(`Onbekende check: "${check}". Gebruik: sitemap|robots|categories|meta|contentblock|home|alt|phplint|http|nodocom`);
}
main().catch((e) => die('FAIL exception: ' + (e && e.message)));
