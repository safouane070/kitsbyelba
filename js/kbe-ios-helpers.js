/**
 * iOS / WebKit-vriendelijke scroll + touch-nav (mega menu op apparaten zonder hover).
 * Globaal: kbeScrollIntoView, kbeScrollToTop, kbeScrollElementTo, kbeReducedMotion
 */
(function (global) {
  'use strict';

  function kbeReducedMotion() {
    try {
      return global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }
  global.kbeReducedMotion = kbeReducedMotion;

  global.kbeScrollIntoView = function (el, options) {
    if (!el || typeof el.scrollIntoView !== 'function') return;
    var o = options || {};
    var block = o.block || 'start';
    if (kbeReducedMotion()) {
      el.scrollIntoView({ block: block });
      return;
    }
    try {
      el.scrollIntoView({ behavior: 'smooth', block: block });
    } catch (e) {
      el.scrollIntoView({ block: block });
      return;
    }
    global.setTimeout(function () {
      var r = el.getBoundingClientRect();
      if (r.top >= global.innerHeight - 24 || r.top < -8) {
        el.scrollIntoView({ block: block });
      }
    }, 480);
  };

  global.kbeScrollToTop = function () {
    if (kbeReducedMotion()) {
      global.scrollTo(0, 0);
      return;
    }
    try {
      global.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
      global.scrollTo(0, 0);
      return;
    }
    global.setTimeout(function () {
      if (global.scrollY > 2) global.scrollTo(0, 0);
    }, 480);
  };

  /** Horizontaal/verticaal scrollen in een element (bv. galerij-thumbnails). */
  global.kbeScrollElementTo = function (el, left, top, smooth) {
    if (!el || typeof el.scrollTo !== 'function') return;
    if (kbeReducedMotion() || !smooth) {
      el.scrollTo(left, top);
      return;
    }
    try {
      el.scrollTo({ left: left, top: top, behavior: 'smooth' });
    } catch (e) {
      el.scrollTo(left, top);
      return;
    }
    global.setTimeout(function () {
      if (Math.abs(el.scrollLeft - left) > 3) el.scrollTo(left, top);
    }, 480);
  };

  function initMegaTouchNav() {
    try {
      if (!global.matchMedia || !global.matchMedia('(hover: none)').matches) return;
      var navLinks = document.querySelector('.nav-links');
      if (!navLinks) return;
      var items = navLinks.querySelectorAll('.has-mega');
      items.forEach(function (li) {
        var link = null;
        for (var i = 0; i < li.children.length; i++) {
          var c = li.children[i];
          if (c.nodeName === 'A' && c.classList && c.classList.contains('top-link')) {
            link = c;
            break;
          }
        }
        if (!link) return;
        link.addEventListener('click', function (e) {
          if (global.innerWidth <= 960) return;
          if (li.classList.contains('mega-touch-open')) {
            li.classList.remove('mega-touch-open');
            return;
          }
          e.preventDefault();
          items.forEach(function (other) {
            if (other !== li) other.classList.remove('mega-touch-open');
          });
          li.classList.add('mega-touch-open');
        });
      });
      document.addEventListener(
        'click',
        function (ev) {
          if (navLinks.contains(ev.target)) return;
          items.forEach(function (li) {
            li.classList.remove('mega-touch-open');
          });
        },
        false
      );
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMegaTouchNav);
  } else {
    initMegaTouchNav();
  }
})(typeof window !== 'undefined' ? window : this);
