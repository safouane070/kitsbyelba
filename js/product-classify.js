/**
 * Gedeelde productclassificatie — categorie (detectProductType) en
 * fan/player-versie (detectProductVersion).
 *
 * Eén canonieke bron voor de hele site. Dit stond eerder 3× gekopieerd
 * (shop-core.js, admin.js, nav-mega.js) en dreef uit elkaar: admin miste de
 * kids-categorie en woog 'set' vóór retro/hemdsetjes, waardoor de admin een
 * product anders indeelde dan de winkel. Deze versie is de shop-core-logica;
 * winkel én admin classificeren nu identiek.
 *
 * Als global function declarations geladen (window-scope), zodat shop-core.js,
 * admin.js en nav-mega.js ze delen. Laad dit bestand VÓÓR die scripts:
 *  - storefront: via includes/nav.php (non-defer, staat vóór shop-core.js)
 *  - admin:      in admin.php, vóór js/admin.js
 */
function detectProductType(p) {
  const catDb = String(p.cat || '').toLowerCase();
  if (catDb === 'hemsetjes' || catDb === 'hemdsetjes') return 'hemdsetjes';
  if (catDb === 'training') return 'shirts';
  if (catDb === 'retro') return 'retro';
  if (catDb === 'kids') return 'kids';
  const name = String(p.name || '').toLowerCase();
  const desc = String(p.description || '').toLowerCase();
  if (name.includes('retro kids')) return 'kids';
  if (name.includes('kids kit') || name.includes(' kids ') || /\bkids\b/.test(name)) return 'kids';
  if (/\bretro\b|\bvintage\b/.test(name) || /\bretro\b|\bvintage\b/.test(desc)) return 'retro';
  if (/\bhem\b|\bhemdje\b|\bhemset|\bhemdsetjes\b/.test(name)) return 'hemdsetjes';
  if (name.includes('full kit set') || name.includes(' kit set') || /\bset\b/.test(name)) return 'sets';
  return 'shirts';
}

function detectProductVersion(p) {
  if (!p) return 'fan';
  const v = String(p.version || '').trim().toLowerCase();
  if (v === 'player' || v === 'fan') return v;
  const hay = `${p.name || ''} ${p.description || ''} ${p.fit_info || ''} ${p.size_advice || ''}`.toLowerCase();
  if (
    hay.includes('player version') ||
    hay.includes('player fit') ||
    hay.includes('players version') ||
    /\bplayer\b/.test(hay)
  ) {
    return 'player';
  }
  if (
    hay.includes('spelersversie') ||
    hay.includes('spelerversie') ||
    hay.includes('spelers versie') ||
    hay.includes('speler versie') ||
    hay.includes('spelers kit') ||
    hay.includes('spelerseditie') ||
    hay.includes('spelers editie')
  ) {
    return 'player';
  }
  if (hay.includes('fan version') || hay.includes('fan fit') || /\bfan\b/.test(hay)) return 'fan';
  if (hay.includes('fanversie') || hay.includes('fan versie') || hay.includes('fansversie')) return 'fan';
  return 'fan';
}
