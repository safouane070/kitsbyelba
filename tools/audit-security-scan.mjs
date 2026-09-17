// Gate G2 — conservatieve scan op hoog-risico PHP-patronen.
// Detecteert: eval, shell-exec-familie, SQL-string-concatenatie met superglobals,
// en echo/print van rauwe superglobals zonder htmlspecialchars.
// Bevat een POSITIEVE CONTROLE (known-bad fixture) zodat "niets gevonden" bewijsbaar
// betekent dat de detector werkt en niet stiekem stukloopt.
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(process.argv[2] || '.');

function phpFiles(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'node_modules' || e.name === '.git' || e.name === 'uploads') continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) phpFiles(p, acc);
    else if (/\.(php|html)$/.test(e.name)) acc.push(p);
  }
  return acc;
}

// stripComments: verwijder // en # regel-comments en /* */ blok-comments (grof, goed genoeg
// om false positives in commentaar te voorkomen).
function stripComments(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:])\/\/[^\n]*/g, '$1 ')
    .replace(/(^|\s)#[^\n]*/g, '$1 ');
}

const RULES = [
  { id: 'eval',        re: /\beval\s*\(/, desc: 'eval()' },
  { id: 'shell',       re: /\b(shell_exec|passthru|proc_open|popen)\s*\(/, desc: 'shell-exec-familie' },
  // exec()/system() als PHP-shellfunctie — NIET ->exec (PDO-methode) of _exec/word-deel
  { id: 'exec-system', re: /(^|[^_\w>])(exec|system)\s*\(/, desc: 'exec()/system() shell' },
  // SQL string-interpolatie/-concatenatie met superglobal binnen query/exec-call
  { id: 'sql-concat',  re: /->(query|exec)\s*\(\s*["'][^"']*\$_(GET|POST|REQUEST|COOKIE)/, desc: 'SQL-concat met superglobal' },
  { id: 'sql-dot',     re: /->(query|exec)\s*\([^)]*\.\s*\$_(GET|POST|REQUEST|COOKIE)/, desc: 'SQL-concat (.) met superglobal' },
  // echo/print van rauwe superglobal zonder escaping op dezelfde regel
  { id: 'echo-raw',    re: /\b(echo|print)\b[^;\n]*\$_(GET|POST|REQUEST|COOKIE)\b(?![^;\n]*htmlspecialchars)/, desc: 'echo van rauwe superglobal' },
];

function scanText(txt) {
  const clean = stripComments(txt);
  const lines = clean.split(/\n/);
  const hits = [];
  lines.forEach((line, i) => {
    for (const r of RULES) {
      if (r.re.test(line)) hits.push({ rule: r.id, desc: r.desc, line: i + 1, text: line.trim().slice(0, 120) });
    }
  });
  return hits;
}

// ---- POSITIEVE CONTROLE ----
const badFixture = `<?php $x = $pdo->query("SELECT * FROM u WHERE id=$_GET[id]"); eval($_POST['c']); echo $_GET['q']; system($_REQUEST['cmd']);`;
const control = scanText(badFixture);
const controlRules = new Set(control.map(h => h.rule));
const mustDetect = ['eval', 'sql-concat', 'echo-raw', 'exec-system'];
const controlOk = mustDetect.every(r => controlRules.has(r));
if (!controlOk) {
  console.log('CONTROL_FAIL — detector miste in fixture: ' +
    mustDetect.filter(r => !controlRules.has(r)).join(', '));
  process.exit(1);
}

// ---- ECHTE SCAN ----
const findings = [];
for (const f of phpFiles(ROOT)) {
  const rel = path.relative(ROOT, f).replace(/\\/g, '/');
  for (const h of scanText(fs.readFileSync(f, 'utf8'))) {
    findings.push({ file: rel, ...h });
  }
}

if (findings.length) {
  console.log(`SECURITY_SCAN_FINDINGS=${findings.length} (positieve controle OK)`);
  for (const f of findings) console.log(`  ${f.file}:${f.line} [${f.desc}] ${f.text}`);
  process.exit(1);
}
console.log('SECURITY_SCAN_OK (positieve controle OK, 0 bevindingen)');
