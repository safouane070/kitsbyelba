import fs from 'fs';
// Gate G2: inline <script> in index.html/shop.html shrank (shared logic moved to cacheable core).
// Baselines (pre-dedup ronde 2): index inline 1777 lines, shop inline 1837 lines.
const BASE = { 'index.html': 1777, 'shop.html': 1837 };
function inlineLines(html){
  return (html.match(/<script>[\s\S]*?<\/script>/g)||[])
    .reduce((a,b)=>a + b.split(/\r?\n/).length, 0);
}
let ok = true;
for (const [f, base] of Object.entries(BASE)){
  const now = inlineLines(fs.readFileSync(f,'utf8'));
  const pct = Math.round((1 - now/base)*100);
  const pass = now <= base*0.8;          // >=20% shrink
  if (!pass) ok = false;
  console.log(`${f}: ${base} -> ${now} inline script-lines (${pct}% kleiner) ${pass?'OK':'TE GROOT'}`);
}
console.log(ok? 'SHRINK_OK' : 'SHRINK_FAIL');
process.exit(ok?0:1);
