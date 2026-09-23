(function(){
  var data = document.getElementById('kbeSocialData');
  var items = data ? JSON.parse(data.textContent) : [];
  var lb = document.getElementById('socialLb'), stage = document.getElementById('socialLbStage'), count = document.getElementById('socialLbCount');
  var cards = document.querySelectorAll('.social-card');
  var cur = 0;

  // Stil voorvertonen in de tegel zodra hij in beeld is (niet bij "minder beweging" / databesparing).
  var calm = matchMedia('(prefers-reduced-motion: reduce)').matches || (navigator.connection && navigator.connection.saveData);
  var io = null;
  if (!calm && 'IntersectionObserver' in window) {
    io = new IntersectionObserver(function(es){
      es.forEach(function(e){
        var v = e.target.querySelector('video');
        if (e.isIntersecting && !lb.open) { v.play().then(function(){ e.target.classList.add('previewing'); }).catch(function(){}); }
        else { v.pause(); }
      });
    }, {threshold: .6});
    document.querySelectorAll('.social-card.vid').forEach(function(c){ io.observe(c); });
  }

  function show(i){
    cur = (i + items.length) % items.length;
    var it = items[cur];
    stage.innerHTML = '';
    var el = document.createElement(it.video ? 'video' : 'img');
    el.src = it.src;
    if (it.video) { el.controls = true; el.playsInline = true; el.autoplay = true; if (it.poster) el.poster = it.poster; }
    else { el.alt = 'Foto ' + (cur + 1) + ' van ' + items.length; }
    stage.appendChild(el);
    count.textContent = (cur + 1) + ' / ' + items.length;
  }
  function open(i){
    cards.forEach(function(c){ var v = c.querySelector('video'); if (v) v.pause(); });
    show(i);
    lb.showModal();
    document.documentElement.style.overflow = 'hidden';
  }
  // Eén opruimfunctie voor élke manier van sluiten — de pagina mag nooit vergrendeld blijven.
  function cleanup(){
    if (!stage.firstChild && document.documentElement.style.overflow !== 'hidden') return;
    stage.innerHTML = ''; // stopt ook het geluid
    document.documentElement.style.overflow = '';
    var c = cards[cur]; if (c) c.focus({preventScroll: true});
    if (io) document.querySelectorAll('.social-card.vid').forEach(function(v){ io.unobserve(v); io.observe(v); });
  }
  function closeLb(){ if (lb.open) lb.close(); cleanup(); }
  lb.addEventListener('close', cleanup);
  // Vangnet: reageer direct zodra 'open' verdwijnt (Android-terugknop e.d.), niet pas op het close-event.
  new MutationObserver(function(){ if (!lb.open) cleanup(); }).observe(lb, {attributes: true, attributeFilter: ['open']});
  lb.addEventListener('cancel', function(e){ e.preventDefault(); closeLb(); });

  cards.forEach(function(c){ c.addEventListener('click', function(){ open(+c.dataset.i); }); });
  lb.querySelector('.social-lb-close').addEventListener('click', closeLb);
  lb.querySelector('.social-lb-prev').addEventListener('click', function(){ show(cur - 1); });
  lb.querySelector('.social-lb-next').addEventListener('click', function(){ show(cur + 1); });
  // Tik naast de foto/video sluit, net als op Instagram.
  stage.addEventListener('click', function(e){ if (e.target === stage) closeLb(); });
  lb.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { e.preventDefault(); closeLb(); }
    if (e.key === 'ArrowLeft') show(cur - 1);
    if (e.key === 'ArrowRight') show(cur + 1);
  });
  // Vegen op telefoon.
  var x0 = null;
  lb.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, {passive: true});
  lb.addEventListener('touchend', function(e){
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0; x0 = null;
    if (Math.abs(dx) > 50) show(cur + (dx < 0 ? 1 : -1));
  }, {passive: true});
})();
