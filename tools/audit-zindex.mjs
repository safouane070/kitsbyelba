import fs from 'fs';
// Gate G4z: no bare global-overlay z-index (>=80) may remain; must be var(--z-*).
// Local/component stacking (<=25) is allowed to stay raw.
const files = ['index.html','shop.html','product.php','account.php',
               'css/app.css','css/pages-shared.css','css/responsive-global.css'];
let bare = [];
for (const f of files) {
  if (!fs.existsSync(f)) continue;
  const lines = fs.readFileSync(f,'utf8').split(/\r?\n/);
  lines.forEach((ln, i) => {
    const m = ln.match(/z-index\s*:\s*(\d+)/);
    if (m && Number(m[1]) >= 80) bare.push(`${f}:${i+1}: ${ln.trim()}`);
  });
}
console.log('BARE_ZINDEX=' + bare.length);
if (bare.length) { console.log(bare.join('\n')); process.exit(1); }
