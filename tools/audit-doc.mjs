import fs from 'fs';
// Gate G7: SetHandler dependency documented — in NOTES.md AND a comment atop both entry pages.
let ok = true;
const need = (cond, msg) => { if(!cond){ ok=false; console.log('FAIL: '+msg); } };
const notes = fs.existsSync('NOTES.md') ? fs.readFileSync('NOTES.md','utf8') : '';
need(/SetHandler/.test(notes), 'NOTES.md mist SetHandler-uitleg');
for (const f of ['index.html','shop.html']){
  const head = fs.readFileSync(f,'utf8').slice(0, 1200);
  need(/SetHandler/.test(head), `${f} mist SetHandler-comment bovenaan`);
  need(/NOTES\.md/.test(head), `${f} verwijst niet naar NOTES.md`);
}
console.log(ok ? 'DOC_OK' : 'DOC_FAIL');
process.exit(ok?0:1);
