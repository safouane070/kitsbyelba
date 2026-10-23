# Gates: pre-deploy hardening + SEO-content voor kitsbyelbaa.nl

OWNS: index.html, shop.html, product.php, sitemap.php, robots.txt, css/pages-shared.css, tools/**, NOTES.md

Scope: Vóór de live-deploy — geen achtergebleven domein/SEO-bugs, sensitieve endpoints beoordeeld, sitemap compleet, en keyword-rijke server-side content op home + alle categorie-pagina's zodat crawlers echte tekst zien voor wat mensen zoeken.

## Track A — Security

- [x] G1: Sensitieve endpoints (api/auth, place-order, api/upload, api/coupon_validate, admin AJAX, api/products) beoordeeld op injectie/authz/XSS; bestaande hardening intact; bevindingen gefixt of expliciet geflagd.
  EVIDENCE: Handmatige review 2026-09-02. place-order.php: prijzen server-side uit DB (nooit client), prepared statements overal, CSRF via hash_equals, rate-limit (2 vensters), honeypot, idempotency-key, coupon geclamped 0-100 + gecapt op subtotaal, voorraad in transactie met FOR UPDATE, print-velden gesanitized. api/auth.php: CSRF op alle mutaties, rate-limit + 5-pogingen-lockout, timing-safe dummy-hash, bcrypt cost 12, session_regenerate_id, re-auth voor wachtwoord/verwijderen, GDPR-erasure. api/upload.php: admin-check + CSRF, MIME via finfo, 5MB-limiet, random filename, GD re-encode. api/coupon_validate.php: prepared, rate-limited, fouten gelogd niet gelekt. Geen exploiteerbare issue gevonden. Niet-blokkerend: coupons zonder max_uses/expiry (korting is gecapt, dus niet misbruikbaar).

- [x] G2: Geen gevaarlijke code-patronen (eval, shell_exec/system, SQL-string-concat met superglobals, echo van rauwe $_GET/$_POST) in PHP-bestanden — scanner schoon, met positieve controle getest.
  CHECK: node tools/audit-security-scan.mjs
  EXPECT: SECURITY_SCAN_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=1b15b834ee837ca57d38cec474547b5ee8debc8d1d9ee228850a04460a66a06b; exit=0; EXPECT=matched; output-sha256=0314c004a4532b41d6d58bfd38f3d177488b854617f7fa7cd351e99649b2d88c; output-bytes=56; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

## Track B — Sitemap & indexering

- [x] G3: sitemap.php rendert geldige XML met alle 6 categorie-URLs + minstens 1 product, domein kitsbyelbaa.nl, nul kitsbyelba.com.
  CHECK: node tools/audit-seo-content.mjs sitemap
  EXPECT: SITEMAP_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=4f2d35fb40e680e425bd9e4af2365835c6df7154b07c3aba1b63977ae56ce790; exit=0; EXPECT=matched; output-sha256=b0edf2b690fe1c883312a8284e73bebd441d76681249c00994f3d3c1b3209132; output-bytes=11; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G4: robots.txt heeft een absolute Sitemap-URL naar sitemap.php.
  CHECK: node tools/audit-seo-content.mjs robots
  EXPECT: ROBOTS_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=0ec77898d354e5e67a214ceb06ac34ec9606e8159d9dc82b7f3f0a92a0906118; exit=0; EXPECT=matched; output-sha256=7a787b94d23a6e62a9f56754a6e4680a2201ed269ab870ded32a6262019b2369; output-bytes=10; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

## Track C — Keyword-rijke content

- [x] G5: Elke categorie-pagina (shirts/sets/hemdsetjes/retro/kids/voorraad) rendert een categorie-specifieke, unieke keyword-alinea server-side (zichtbaar in kale HTML, dus zonder JS).
  CHECK: node tools/audit-seo-content.mjs categories
  EXPECT: CATEGORIES_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=271aac6185fe095939c34a79eb9d80acf23e1ae6b8795e550a222c6884915542; exit=0; EXPECT=matched; output-sha256=d75ac2697cc22341cf27d14067644701f73c4c10ce029daa8e804eff06175aee; output-bytes=14; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G6: Elke categorie heeft een unieke meta-description met relevante zoektermen; geen twee identiek.
  CHECK: node tools/audit-seo-content.mjs meta
  EXPECT: META_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=4a0152db867b3b0cd10544a8a59c5f4181d3065b3899471a9e7c11bccc09c0bc; exit=0; EXPECT=matched; output-sha256=8a32952941d88e04daacb6333d271b666a06d149ebe834a2613e4e66d131c49d; output-bytes=8; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G7: Categorie-pagina's hebben een server-side SEO-tekstsectie onder het grid met substantiële inhoud (>=120 woorden zichtbaar per categorie in kale HTML).
  CHECK: node tools/audit-seo-content.mjs contentblock
  EXPECT: CONTENTBLOCK_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=1729da7a9d0791733d23967f45f74402587150261645b15b184bb8b1e8decdb8; exit=0; EXPECT=matched; output-sha256=0201e3988514bf311e73d3b6a88a46bb86c2fdf7b8f6f1341db728e3926a0210; output-bytes=16; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G8: Homepage rendert een keyword-rijke tekstsectie (>=120 woorden) server-side.
  CHECK: node tools/audit-seo-content.mjs home
  EXPECT: HOME_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=b37581671804c5e82357bff0676e108f894184bf9788ddc7bfaa633659cefdcb; exit=0; EXPECT=matched; output-sha256=cdc42c57841cf2b0ba9e71a4aa10de57d21c7fa3350dc949cbb8c1e7793963da; output-bytes=8; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G9: Belangrijke afbeeldingen hebben niet-lege, beschrijvende alt-teksten (product-card-template + league-logos).
  CHECK: node tools/audit-seo-content.mjs alt
  EXPECT: ALT_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=67c6f78c15f5483985974516d6624da5127037c413fca2c5fc7d8ba5720068ad; exit=0; EXPECT=matched; output-sha256=775c815d0636e0c1a40eed55d40d83b441171cb5f0bd762e378faf73be92cb9a; output-bytes=7; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

## Track D — Geen regressies

- [x] G10: php -l schoon op alle bewerkte bestanden.
  CHECK: node tools/audit-seo-content.mjs phplint
  EXPECT: PHPLINT_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=099a4380b885d8cbcd204b27f231916cfaa8f849389be9e34448c453671e5244; exit=0; EXPECT=matched; output-sha256=37c9c0269a9b34b63f50ec559b4107b5246675a2efb8e98b82ac2fd73522bbbd; output-bytes=11; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G11: Home + shop + elke categorie + een product geven HTTP 200 met verwachte titel en zonder gelekte PHP-fout.
  CHECK: node tools/audit-seo-content.mjs http
  EXPECT: HTTP_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=15cd225bcb967bcdaadefc125d93a1039512e0850fcb32dfcf8721b4a64fa737; exit=0; EXPECT=matched; output-sha256=a7a68104c11a1c15ec0bdc023b144a516817f368b31986649f1c9fed69805a82; output-bytes=8; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries

- [x] G12: Nergens in de repo nog een hardcoded kitsbyelba.com.
  CHECK: node tools/audit-seo-content.mjs nodotcom
  EXPECT: NODOTCOM_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=3917f1f274e8eb2051512ff9b2bd45d99095344ffc994d9c2d6a0c75cee8ec1b; exit=0; EXPECT=matched; output-sha256=07f11d80992505e5c58a44a481608749892458f60c9eef25494020b401d83771; output-bytes=12; shell=C:\WINDOWS\system32\cmd.exe; cwd=C:\xampp\htdocs\kitsbyelba; path=28b7982374f4/43 entries
