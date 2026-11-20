/* Bella Vista Oasis Spa - site.js (restore point)
   Treatment menu slider at fade-in on scroll lang. */

/* ===== Treatment menu slider ===== */
try {
/* Treatment menu slider: idagdag sa pinakadulo ng assets/script.js */
(function () {
  var CHEVRON = {
    prev: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>',
    next: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>'
  };

  function makeBtn(cls, html, label) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = cls;
    b.innerHTML = html;
    b.setAttribute('aria-label', label);
    return b;
  }

  function initMenuSlider() {
    var grid = document.querySelector('.service-grid');
    if (!grid || grid.classList.contains('is-slider')) return;

    var cards = Array.prototype.filter.call(grid.children, function (el) {
      return el.classList.contains('service-card');
    });
    if (cards.length < 2) return;

    /* wrapper: viewport + controls */
    var parent = grid.parentNode;
    var viewport = document.createElement('div');
    viewport.className = 'menu-viewport';
    viewport.setAttribute('role', 'region');
    viewport.setAttribute('aria-roledescription', 'carousel');
    viewport.setAttribute('aria-label', 'Treatment menu');
    parent.insertBefore(viewport, grid);
    viewport.appendChild(grid);
    grid.classList.add('is-slider');

    var controls = document.createElement('div');
    controls.className = 'menu-controls';
    var prev = makeBtn('menu-arrow', CHEVRON.prev, 'Previous treatments');
    var next = makeBtn('menu-arrow', CHEVRON.next, 'Next treatments');
    var dotsWrap = document.createElement('div');
    dotsWrap.className = 'menu-dots';
    controls.appendChild(prev);
    controls.appendChild(dotsWrap);
    controls.appendChild(next);
    parent.insertBefore(controls, viewport.nextSibling);

    var index = 0, max = 0, per = 3, dots = [];

    function perView() {
      var w = window.innerWidth;
      return w > 1080 ? 3 : (w > 760 ? 2 : 1);
    }

    function go(i) {
      if (i > max) i = 0;
      if (i < 0) i = max;
      index = i;
      grid.style.transform = 'translateX(-' + cards[index].offsetLeft + 'px)';
      dots.forEach(function (d, n) {
        d.classList.toggle('is-active', n === index);
        if (n === index) d.setAttribute('aria-current', 'true');
        else d.removeAttribute('aria-current');
      });
    }

    function build() {
      per = perView();
      max = Math.max(0, cards.length - per);
      grid.style.setProperty('--per', per);
      dotsWrap.innerHTML = '';
      dots = [];
      for (var n = 0; n <= max; n++) {
        (function (n) {
          var d = makeBtn('menu-dot', '', 'Go to treatments ' + (n + 1));
          d.addEventListener('click', function () { go(n); });
          dotsWrap.appendChild(d);
          dots.push(d);
        })(n);
      }
      controls.hidden = (max === 0);
      go(Math.min(index, max));
    }

    prev.addEventListener('click', function () { go(index - 1); });
    next.addEventListener('click', function () { go(index + 1); });

    viewport.parentNode.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') go(index - 1);
      if (e.key === 'ArrowRight') go(index + 1);
    });

    /* swipe / drag (hindi masisira ang click sa "Book Treatment") */
    var startX = null, dragged = false;
    viewport.addEventListener('dragstart', function (e) { e.preventDefault(); });
    viewport.addEventListener('pointerdown', function (e) {
      startX = e.clientX;
      dragged = false;
      viewport.classList.add('dragging');
    });
    document.addEventListener('pointerup', function (e) {
      if (startX === null) return;
      var dx = e.clientX - startX;
      startX = null;
      viewport.classList.remove('dragging');
      if (Math.abs(dx) > 8) dragged = true;
      if (Math.abs(dx) > 45) go(index + (dx < 0 ? 1 : -1));
    });
    document.addEventListener('pointercancel', function () {
      startX = null;
      viewport.classList.remove('dragging');
    });
    viewport.addEventListener('click', function (e) {
      if (dragged) { e.preventDefault(); e.stopPropagation(); dragged = false; }
    }, true);

    var t;
    window.addEventListener('resize', function () {
      clearTimeout(t);
      t = setTimeout(build, 150);
    });

    build();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMenuSlider);
  } else {
    initMenuSlider();
  }
})();
} catch (err) { console.error("Treatment menu slider failed:", err); }

/* ===== Fade-in on scroll ===== */
try {
/* Fade-in on scroll: idagdag sa pinakadulo ng assets/script.js
   (pagkatapos ng menu-slider code) */
(function () {
  if (!('IntersectionObserver' in window)) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  function initReveal() {
    var targets = [];

    /* variant: 'left' | 'right' | null, stagger: pasunod-sunod ang labas */
    function mark(selector, variant, stagger) {
      var counts = new Map();
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
        if (el.classList.contains('reveal')) return;
        var parent = el.parentNode;
        var i = counts.get(parent) || 0;
        counts.set(parent, i + 1);
        el.classList.add('reveal');
        if (variant) el.classList.add('reveal-' + variant);
        if (stagger) el.style.setProperty('--d', (i * 0.12).toFixed(2) + 's');
        targets.push(el);
      });
    }

    /* Home page */
    mark('.intro-art', 'left');
    mark('.intro-text', 'right');
    mark('.promise > div', null, true);
    mark('.section-heading');
    if (document.querySelector('.menu-viewport')) {
      mark('.menu-viewport');
      mark('.menu-controls');
    } else {
      mark('.service-card', null, true);
    }
    mark('.quote-band > *', null, true);
    mark('.policies .eyebrow, .policies h2');
    mark('.policy-grid > div', null, true);
    mark('.small-note');
    mark('.footer > *', null, true);

    /* Booking at confirmation pages */
    mark('.booking-aside > *', null, true);
    mark('.booking-form-wrap > *');
    mark('.confirmation-card > *', null, true);

    if (!targets.length) return;

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        el.classList.add('is-visible');
        io.unobserve(el);

        /* pagkatapos ng animation, ibalik sa normal para gumana ang hover */
        el.addEventListener('transitionend', function done(e) {
          if (e.target !== el || e.propertyName !== 'opacity') return;
          el.removeEventListener('transitionend', done);
          el.classList.remove('reveal', 'reveal-left', 'reveal-right', 'is-visible');
          el.style.removeProperty('--d');
        });
      });
    }, { threshold: 0, rootMargin: '0px 0px -8% 0px' });

    targets.forEach(function (el) { io.observe(el); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initReveal);
  } else {
    initReveal();
  }
})();
} catch (err) { console.error("Fade-in on scroll failed:", err); }
