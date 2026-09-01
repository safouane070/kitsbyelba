// Gate G6: each category page serves a DISTINCT server-side <title> + canonical
// (raw HTML, no JS). Requires the local server at BASE.
const BASE = process.env.KBE_BASE || 'http://localhost/kitsbyelba';
const pages = ['shop.html','shirts','sets','hemdsetjes','retro','kids','voorraad'];
const get = async u => { const r = await fetch(u); return await r.text(); };
const pick = (html, re) => { const m = html.match(re); return m ? m[1].trim() : null; };

let ok = true, seenTitles = new Set(), seenCanon = new Set();
for (const p of pages){
  let html;
  try { html = await get(`${BASE}/${p}`); }
  catch(e){ console.log(`FAIL ${p}: ${e.message}`); ok=false; continue; }
  if (/(?:Fatal error|Parse error|Warning:|Notice:|Deprecated:)/.test(html)){
    console.log(`FAIL ${p}: PHP error/warning in output`); ok=false;
  }
  const title = pick(html, /<title>([^<]*)<\/title>/i);
  const canon = pick(html, /<link rel="canonical" href="([^"]*)"/i);
  if (!title || !canon){ console.log(`FAIL ${p}: missing title/canonical`); ok=false; continue; }
  if (seenTitles.has(title)){ console.log(`FAIL ${p}: duplicate title "${title}"`); ok=false; }
  if (seenCanon.has(canon)){ console.log(`FAIL ${p}: duplicate canonical "${canon}"`); ok=false; }
  seenTitles.add(title); seenCanon.add(canon);
  console.log(`${p.padEnd(12)} title="${title}"  canonical=${canon}`);
}
console.log(ok ? 'SEO_DISTINCT' : 'SEO_FAIL');
process.exit(ok?0:1);
