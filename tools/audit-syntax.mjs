import fs from 'fs';
import { execSync } from 'child_process';
// Gate G2b: shop-core.js + the inline scripts of the pages that load it parse cleanly.
function inlineScripts(html){
  return (html.match(/<script>[\s\S]*?<\/script>/g)||[])
    .map(b=>b.replace(/^<script>/,'').replace(/<\/script>$/,''))
    .filter(c=>c.trim().length);
}
const targets = [];
targets.push(['js/shop-core.js', fs.readFileSync('js/shop-core.js','utf8')]);
for (const f of ['index.html','shop.html']){
  inlineScripts(fs.readFileSync(f,'utf8')).forEach((c,i)=>
    targets.push([`${f} inline#${i+1}`, 'async function __w(){\n'+c+'\n}']));
}
let fails = 0;
for (const [label, code] of targets){
  const tmp = 'tools/_chk.mjs';
  fs.writeFileSync(tmp, code);
  try { execSync('node --check "'+tmp+'"',{stdio:'pipe'}); console.log('OK   '+label); }
  catch(e){ fails++; console.log('FAIL '+label+'\n'+e.stderr.toString().split('\n').slice(0,3).join('\n')); }
  fs.unlinkSync(tmp);
}
console.log(fails? 'SYNTAX_FAIL' : 'SYNTAX_OK');
process.exit(fails?1:0);
