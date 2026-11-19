/* Bella Vista Oasis Spa - site.js
   Treatment menu slider, add-ons popup, fade-in on scroll, footer social links.
   Bawat bahagi ay nasa try/catch para hindi mapigilan ng isang error ang iba. */

/* ===== Add-ons card at popup ===== */
try {
/* Add-ons card at popup: ilagay sa PINAKAITAAS ng assets/script.js */
(function () {
  var ADDONS = [
    { title: 'Comfort', items: [['Extra-soft pillow set', 50], ['Body support pillow', 50], ['Heated blanket', 80]] },
    { title: 'Oils & aroma', items: [['Premium essential oil', 150], ['Lavender or eucalyptus aromatherapy', 100], ['Take-home aroma oil', 350]] },
    { title: 'Heat & relaxation', items: [['Hot towel compress', 100], ['Hot stone (back & shoulders)', 250], ['Foot soak or foot scrub', 200]] },
    { title: 'Extras', items: [['Herbal tea & light snack', 120], ['Scalp massage (end of session)', 200]] }
  ];
  var INTRO = 'Personalize your treatment with a few thoughtful extras.';
  var NOTE = 'Add-ons are arranged with our spa team and never extend your session time. ' +
    'If you are pregnant or have a medical condition, please declare it when booking so we can suggest safe options, such as a pregnancy support pillow.';

  function esc(t) {
    return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function peso(n) { return '\u20B1' + n; }

  function init() {
    var grid = document.querySelector('.service-grid');
    if (!grid || grid.querySelector('.service-card--addons')) return;

    /* alisin ang Private Lounge card kung active pa */
    Array.prototype.slice.call(grid.querySelectorAll('.service-card')).forEach(function (c) {
      var h = c.querySelector('h3');
      if (h && /private lounge/i.test(h.textContent)) c.parentNode.removeChild(c);
    });

    var min = Infinity;
    ADDONS.forEach(function (g) {
      g.items.forEach(function (i) { if (i[1] < min) min = i[1]; });
    });

    /* ang card sa menu */
    var card = document.createElement('article');
    card.className = 'service-card service-card--addons';
    card.innerHTML =
      '<p class="service-duration">Personalize your visit</p>' +
      '<h3>Add-ons</h3>' +
      '<p>Make your treatment extra special with premium oils, softer pillows, warm compresses and more.</p>' +
      '<ul class="addon-tags">' +
        ADDONS.map(function (g) { return '<li>' + esc(g.title) + '</li>'; }).join('') +
      '</ul>' +
      '<div class="service-bottom"><strong>From ' + peso(min) + '</strong>' +
      '<a href="#" class="addons-open" role="button">View add-ons \u2192</a></div>';
    grid.appendChild(card);

    /* ang popup */
    var groups = ADDONS.map(function (g) {
      return '<div class="addon-group"><h3>' + esc(g.title) + '</h3><ul>' +
        g.items.map(function (i) {
          return '<li><span>' + esc(i[0]) + '</span><strong>' + peso(i[1]) + '</strong></li>';
        }).join('') + '</ul></div>';
    }).join('');

    var modal = document.createElement('div');
    modal.className = 'modal addons-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'addons-title');
    modal.innerHTML =
      '<div class="modal-content">' +
        '<div class="addons-photo" aria-hidden="true"></div>' +
        '<div class="addons-body">' +
          '<button type="button" class="addons-close" aria-label="Close">\u00D7</button>' +
          '<p class="eyebrow">Personalize your visit</p>' +
          '<h2 id="addons-title">Add-ons</h2>' +
          '<p class="addons-intro">' + esc(INTRO) + '</p>' +
          '<div class="addon-groups">' + groups + '</div>' +
          '<p class="addons-note">' + esc(NOTE) + '</p>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    var closeBtn = modal.querySelector('.addons-close');
    var opener = null;

    function open(e) {
      if (e) e.preventDefault();
      opener = document.activeElement;
      modal.classList.add('open');
      document.body.style.overflow = 'hidden';
      closeBtn.focus();
    }
    function close() {
      modal.classList.remove('open');
      document.body.style.overflow = '';
      if (opener && opener.focus) opener.focus();
    }

    card.querySelector('.addons-open').addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('open')) close();
    });
  }

  if (document.querySelector('.service-grid')) init();
  else document.addEventListener('DOMContentLoaded', init);
})();
} catch (err) { console.error("Add-ons card at popup failed:", err); }

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

/* ===== Footer social links ===== */
try {
/* Social media links sa footer: idagdag sa pinakadulo ng assets/script.js */
(function () {
  /* PALITAN ang mga link ng totoong page/account ninyo */
  var SOCIALS = [
    { name: 'Facebook',  url: 'https://www.facebook.com/',  icon: '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>' },
    { name: 'Instagram', url: 'https://www.instagram.com/', icon: '<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>' },
    { name: 'TikTok',    url: 'https://www.tiktok.com/',    icon: '<path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/>' },
    { name: 'Email',     url: 'mailto:hello@example.com',   icon: '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>' }
  ];

  function init() {
    var footer = document.querySelector('.footer:not(.compact-footer)');
    if (!footer || footer.querySelector('.footer-social')) return;

    var nav = document.createElement('nav');
    nav.className = 'footer-social';
    nav.setAttribute('aria-label', 'Social media');
    nav.innerHTML =
      '<p>Find us on social media</p><ul>' +
      SOCIALS.map(function (s) {
        var external = /^https?:/.test(s.url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<li><a href="' + s.url + '"' + external + ' aria-label="' + s.name + '" title="' + s.name + '">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true">' + s.icon + '</svg></a></li>';
      }).join('') +
      '</ul>';
    footer.appendChild(nav);
  }

  if (document.querySelector('.footer')) init();
  else document.addEventListener('DOMContentLoaded', init);
})();
} catch (err) { console.error("Footer social links failed:", err); }
