(function () {
  'use strict';

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.content : '';

  /* Lista: filtro al escribir y vista en grilla / lista */
  var grid = document.getElementById('bib-grid');
  var q = document.getElementById('bib-q');
  if (grid && q) {
    var none = document.getElementById('bib-none');
    var fold = function (s) {
      return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    };
    q.addEventListener('input', function () {
      var words = fold(q.value).split(/\s+/).filter(Boolean);
      var visible = 0;
      grid.querySelectorAll('.bib-card').forEach(function (card) {
        var hay = card.getAttribute('data-search') || '';
        var ok = words.every(function (w) { return hay.indexOf(w) !== -1; });
        card.classList.toggle('is-hidden', !ok);
        if (ok) { visible++; }
      });
      if (none) { none.classList.toggle('is-hidden', visible > 0); }
    });
  }
  var toggle = document.getElementById('bib-view-toggle');
  if (grid && toggle) {
    var apply = function (list) {
      grid.classList.toggle('is-list', list);
      toggle.textContent = list ? 'Ver en grilla' : 'Ver en lista';
      toggle.setAttribute('aria-pressed', list ? 'true' : 'false');
    };
    var stored = null;
    try { stored = localStorage.getItem('bib-view'); } catch (e) {}
    apply(stored === 'list');
    toggle.addEventListener('click', function () {
      var list = !grid.classList.contains('is-list');
      apply(list);
      try { localStorage.setItem('bib-view', list ? 'list' : 'grid'); } catch (e) {}
    });
  }

  /* Subida por partes */
  var box = document.getElementById('bib-upload');
  if (box) {
    var api = box.getAttribute('data-api');
    var input = document.getElementById('bib-files');
    var jobs = document.getElementById('bib-jobs');
    var send = document.getElementById('bib-send');
    var busy = false;

    var call = function (fields, file) {
      var body = new FormData();
      body.append('csrf', csrf);
      Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
      if (file) { body.append('chunk', file, 'chunk'); }
      return fetch(api, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Respuesta inválida del servidor (' + r.status + ').' }; });
      });
    };

    var uploadOne = async function (file) {
      var li = document.createElement('li');
      var label = document.createElement('span');
      var bar = document.createElement('progress');
      bar.max = file.size;
      bar.value = 0;
      label.textContent = file.name + ' · preparando…';
      li.appendChild(label);
      li.appendChild(bar);
      jobs.appendChild(li);
      var fail = function (msg) {
        label.textContent = file.name + ' · ' + msg;
        label.className = 'is-error';
        bar.remove();
        return false;
      };
      var init = await call({
        action: 'upload_init', name: file.name, size: file.size,
        kind: document.getElementById('bib-kind').value, tags: document.getElementById('bib-tags').value
      });
      if (!init.ok) { return fail(init.error || 'No se pudo empezar la subida.'); }
      var offset = 0, retries = 0, res = null;
      while (offset < file.size) {
        var end = Math.min(file.size, offset + init.chunk);
        try {
          res = await call({ action: 'upload_chunk', token: init.token, offset: offset, total: file.size }, file.slice(offset, end));
        } catch (e) {
          res = { ok: false, error: 'Se cortó la conexión.' };
        }
        if (res.resync && typeof res.received === 'number') { offset = res.received; continue; }
        if (!res.ok) {
          if (++retries <= 3 && /conexi|lleg/i.test(res.error || '')) { await new Promise(function (r) { setTimeout(r, 2000 * retries); }); continue; }
          return fail(res.error || 'Error al subir.');
        }
        retries = 0;
        offset = res.received;
        bar.value = offset;
        label.textContent = file.name + ' · ' + Math.round(offset * 100 / file.size) + ' %';
      }
      bar.remove();
      label.className = 'is-ok';
      label.textContent = file.name + (res && res.duplicate ? ' · ya estaba en la biblioteca' : ' · listo');
      return true;
    };

    send.addEventListener('click', async function () {
      if (busy) { return; }
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) { input.click(); return; }
      busy = true;
      send.disabled = true;
      var okCount = 0;
      for (var i = 0; i < files.length; i++) {
        if (await uploadOne(files[i])) { okCount++; }
      }
      busy = false;
      send.disabled = false;
      input.value = '';
      if (okCount > 0) {
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = 'biblioteca_lector.php';
        a.className = 'btn ghost';
        a.textContent = 'Actualizar la lista';
        li.appendChild(a);
        jobs.appendChild(li);
      }
    });
    window.addEventListener('beforeunload', function (e) {
      if (busy) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  /* Visor: copiar enlace a la página actual */
  var copy = document.getElementById('bib-copy');
  if (copy) {
    copy.addEventListener('click', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('page', String(window.bibCurrentPage ? window.bibCurrentPage() : 1));
      url.hash = '';
      var done = function () {
        var old = copy.textContent;
        copy.textContent = 'Copiado';
        setTimeout(function () { copy.textContent = old; }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url.toString()).then(done, function () { window.prompt('Enlace a esta página:', url.toString()); });
      } else {
        window.prompt('Enlace a esta página:', url.toString());
      }
    });
  }

  var img = document.getElementById('bib-image');
  if (img) {
    img.addEventListener('click', function () { img.classList.toggle('is-zoomed'); });
  }
})();
