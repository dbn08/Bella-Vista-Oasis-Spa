document.addEventListener('DOMContentLoaded', () => {
  const dateInput = document.getElementById('appointment_date');
  if (dateInput) {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const localDate = new Date(tomorrow.getTime() - tomorrow.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    dateInput.min = localDate;
    dateInput.addEventListener('change', () => {
      if (dateInput.value) {
        const chosen = new Date(dateInput.value + 'T12:00:00');
        if (chosen.getDay() === 1) {
          alert('The spa is closed on Mondays. Please select another day.');
          dateInput.value = '';
        }
      }
    });
  }

  const serviceSelect = document.getElementById('service_id');
  const guestsSelect = document.getElementById('guests');
  const totalPrice = document.getElementById('totalPrice');
  function updateTotal() {
    if (!serviceSelect || !guestsSelect || !totalPrice) return;
    const option = serviceSelect.options[serviceSelect.selectedIndex];
    const price = Number(option?.dataset?.price || 0);
    const guests = Number(guestsSelect.value || 1);
    const total = price + Math.max(0, guests - 1) * price * 0.8;
    totalPrice.textContent = total.toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
  }
  if (serviceSelect && guestsSelect) {
    serviceSelect.addEventListener('change', updateTotal);
    guestsSelect.addEventListener('change', updateTotal);
    updateTotal();
  }
});

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