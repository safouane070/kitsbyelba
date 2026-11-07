// Hero-waaier: elke paar seconden schuift elk shirt één plek naar links. Het linker verdwijnt ("out"),
// het eerstvolgende wachtende shirt komt rechts binnen. Werkt met elk aantal shirts vanaf 8 (7 zichtbaar,
// de rest wacht rechts in de rij). De stapelvolgorde (CSS) loopt vast van links naar rechts, dus tijdens
// het schuiven springt er nooit een shirt door een ander heen. De server kiest welke shirts er zijn
// (alleen producten die te koop zijn, zie includes/hero_kits.php).
// Stopt buiten beeld, in een verborgen tab, bij hover/focus en bij "minder beweging".
(function(){
  var kits = Array.prototype.slice.call(document.querySelectorAll('.hero-kit'));
  var VISIBLE = 7, N = kits.length;
  if (N < VISIBLE + 1 || matchMedia('(prefers-reduced-motion: reduce)').matches) return; // te weinig: waaier staat stil
  var wrap = kits[0].parentNode;
  var pos = kits.map(function(_, i){ return i; }); // 0–6 zichtbaar, 7..N-1 wachten rechts
  var timer = null, inView = true, hover = false;

  function show(k, slot){
    var hidden = slot === 'in' || slot === 'out';
    k.dataset.slot = slot;
    if (hidden) { k.tabIndex = -1; k.setAttribute('aria-hidden', 'true'); }
    else { k.removeAttribute('tabindex'); k.removeAttribute('aria-hidden'); }
  }
  function tick(){
    // Wie vorige keer links verdween, springt zonder animatie naar de wachtrij rechts
    kits.forEach(function(k){
      if (k.dataset.slot === 'out') { k.classList.add('no-t'); k.dataset.slot = 'in'; void k.offsetWidth; k.classList.remove('no-t'); }
    });
    pos = pos.map(function(p){ return p === 0 ? N - 1 : p - 1; });
    kits.forEach(function(k, i){
      k.style.animation = 'none';
      var p = pos[i];
      show(k, p < VISIBLE ? String(p) : p === N - 1 ? 'out' : 'in');
    });
  }
  function sync(){
    var run = inView && !hover && !document.hidden;
    if (run && !timer) timer = setInterval(tick, 4000);
    if (!run && timer) { clearInterval(timer); timer = null; }
  }
  if ('IntersectionObserver' in window) new IntersectionObserver(function(es){ inView = es[0].isIntersecting; sync(); }).observe(wrap);
  wrap.addEventListener('pointerenter', function(){ hover = true; sync(); });
  wrap.addEventListener('pointerleave', function(){ hover = false; sync(); });
  wrap.addEventListener('focusin', function(){ hover = true; sync(); });   // toetsenbord: niet wegschuiven onder de focus
  wrap.addEventListener('focusout', function(){ hover = false; sync(); });
  document.addEventListener('visibilitychange', sync);
  setTimeout(sync, 1200); // eerst de binnenkomst laten uitspelen
})();
