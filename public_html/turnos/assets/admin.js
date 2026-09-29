(function () {
  'use strict';

  var root = document.documentElement;
  var typingFields = 'input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=file]), textarea, select';

  /* Con el teclado del teléfono abierto, las pestañas de abajo se esconden y la barra de acciones baja al borde. */
  document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches(typingFields)) { root.classList.add('is-typing'); }
  });
  document.addEventListener('focusout', function () {
    setTimeout(function () {
      var a = document.activeElement;
      if (!a || !a.matches || !a.matches(typingFields)) { root.classList.remove('is-typing'); }
    }, 60);
  });

  /* «Más»: se cierra al tocar afuera, al elegir una opción o con Escape. */
  var more = document.querySelector('.admin-more');
  if (more) {
    document.addEventListener('click', function (e) {
      if (more.open && !more.contains(e.target)) { more.open = false; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { more.open = false; }
    });
    more.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { more.open = false; });
    });
  }

  /* Confirmación antes de acciones que no se deshacen: data-confirm en el <form> o en el botón. */
  var lastSubmitter = null;
  document.addEventListener('click', function (e) {
    lastSubmitter = e.target.closest ? e.target.closest('button, input[type=submit]') : null;
  }, true);
  document.addEventListener('submit', function (e) {
    var btn = e.submitter || lastSubmitter;
    var msg = (btn && btn.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  }, true);

  /* Paneles que en pantalla grande arrancan abiertos. */
  if (window.matchMedia('(min-width: 900px)').matches) {
    document.querySelectorAll('details[data-open-wide]').forEach(function (d) { d.open = true; });
  }

  /* #ancla: abre los <details> que la contienen y la muestra. */
  var openHash = function () {
    var id = decodeURIComponent(location.hash.slice(1));
    var el = id && document.getElementById(id);
    if (!el) { return; }
    for (var p = el; p; p = p.parentElement) {
      if (p.tagName === 'DETAILS') { p.open = true; }
    }
    el.scrollIntoView();
  };
  openHash();
  window.addEventListener('hashchange', openHash);
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href*="#"]') : null;
    if (a && a.pathname === location.pathname && a.hash && a.hash === location.hash) { setTimeout(openHash, 0); }
  });

  /* Filtro al escribir: <input data-filter="#lista"> oculta los [data-search] que no coinciden (y los grupos vacíos). */
  var fold = function (s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  };
  document.querySelectorAll('input[data-filter]').forEach(function (input) {
    var list = document.querySelector(input.getAttribute('data-filter'));
    if (!list) { return; }
    var empty = document.querySelector(input.getAttribute('data-filter-empty') || '#none');
    var items = Array.prototype.slice.call(list.querySelectorAll('[data-search]'));
    items.forEach(function (it) { it.setAttribute('data-search', fold(it.getAttribute('data-search'))); });
    var run = function () {
      var words = fold(input.value).split(/\s+/).filter(Boolean);
      var visible = 0;
      items.forEach(function (it) {
        var hay = it.getAttribute('data-search');
        var ok = words.every(function (w) { return hay.indexOf(w) !== -1; });
        it.classList.toggle('is-hidden', !ok);
        if (ok) { visible++; }
      });
      list.querySelectorAll('[data-group]').forEach(function (g) {
        g.classList.toggle('is-hidden', words.length > 0 && !g.querySelector('[data-search]:not(.is-hidden)'));
      });
      if (empty) { empty.classList.toggle('is-hidden', visible > 0 || !words.length); }
    };
    input.addEventListener('input', run);
    if (input.value) { run(); }
  });
})();
