import fs from 'fs';
// Gate G3css: responsive-global.css audited. Verify:
//  (1) decision recorded in NOTES.md
//  (2) it is still loaded by the pages (not accidentally orphaned)
//  (3) no nav BASE-rule duplication: .nav-links/.nav-mega appear in
//      responsive-global.css ONLY inside @media (overrides), never as top-level base.
let ok = true;
const fail = m => { ok = false; console.log('FAIL: ' + m); };

const notes = fs.existsSync('NOTES.md') ? fs.readFileSync('NOTES.md','utf8') : '';
if (!/responsive-global\.css/.test(notes) || !/behouden|verwijder|audit/i.test(notes))
  fail('NOTES.md mist de responsive-global.css beslissing');

const idx = fs.readFileSync('index.html','utf8');
if (!/responsive-global\.css/.test(idx)) fail('responsive-global.css niet meer geladen op index.html');

// scan for base (brace-depth 0) nav rules using true char-by-char brace tracking.
// A rule block that opens at depth 0 is a base rule; anything opened at depth>=1
// (i.e. inside @media {...}) is an override.
const css = fs.readFileSync('css/responsive-global.css','utf8');
let depth = 0, buf = '', baseNav = [];
for (const ch of css) {
  if (ch === '{') {
    const sel = buf.split('}').pop().split('{').pop().trim();
    if (depth === 0 && !sel.startsWith('@') && /(^|,|\s)\.(nav-links|nav-mega)\b/.test(sel))
      baseNav.push(sel.replace(/\s+/g,' '));
    depth++; buf = '';
  } else if (ch === '}') { depth = Math.max(0, depth - 1); buf = ''; }
  else buf += ch;
}
if (baseNav.length) fail('nav base-regel(s) buiten @media in responsive-global.css:\n'+baseNav.join('\n'));

console.log(ok ? 'OK' : 'NOT OK');
process.exit(ok?0:1);
