<?php if (!empty($useShell)): ?>
    </main>
  </div>
  <nav class="bottom-nav" aria-label="Secciones">
    <?php foreach ($mobileItems as [$href, $label, $icon, $badge]): ?>
      <a class="<?= $current === $href ? 'is-active' : '' ?>" href="<?= h($base . $href) ?>">
        <span class="bn-ico"><?= aca_icon($icon) ?><?php if ($badge > 0): ?><b><?= (int) $badge ?></b><?php endif; ?></span>
        <span><?= h(str_replace('Clases grabadas', 'Clases', $label)) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($isAdminArea): ?>
      <button type="button" data-side-open><span class="bn-ico"><?= aca_icon('menu') ?></span><span>Más</span></button>
    <?php endif; ?>
  </nav>
</div>
<?php else: ?>
</main>
<footer class="foot">
  <a href="https://fluxusterapia.com">FluxusTerapia</a>
</footer>
<?php endif; ?>
<script>
(function () {
  var body = document.body;
  document.querySelectorAll('[data-side-open]').forEach(function (b) {
    b.addEventListener('click', function () { body.classList.add('side-open'); });
  });
  document.querySelectorAll('[data-side-close]').forEach(function (b) {
    b.addEventListener('click', function () { body.classList.remove('side-open'); });
  });

  document.addEventListener('click', function (ev) {
    var open = ev.target.closest('[data-open-dialog]');
    if (open) {
      var dlg = document.getElementById(open.getAttribute('data-open-dialog'));
      if (dlg && dlg.showModal) { dlg.showModal(); }
    }
    var close = ev.target.closest('[data-close-dialog]');
    if (close) {
      var d = close.closest('dialog');
      if (d) { d.close(); }
    }
    var gen = ev.target.closest('[data-gen-pass]');
    if (gen) {
      var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
      var bytes = new Uint32Array(10);
      crypto.getRandomValues(bytes);
      var pass = '';
      bytes.forEach(function (n) { pass += chars[n % chars.length]; });
      var input = document.getElementById(gen.getAttribute('data-gen-pass'));
      if (input) { input.type = 'text'; input.value = pass; input.dispatchEvent(new Event('input')); }
    }
    var copy = ev.target.closest('[data-copy]');
    if (copy) {
      var src = document.getElementById(copy.getAttribute('data-copy'));
      var text = !src ? '' : (src.tagName === 'INPUT' || src.tagName === 'TEXTAREA' ? src.value : src.textContent);
      var done = function () {
        if (copy.classList.contains('is-copied')) { return; }
        var old = copy.innerHTML;
        copy.classList.add('is-copied');
        copy.textContent = '¡Copiado!';
        setTimeout(function () { copy.innerHTML = old; copy.classList.remove('is-copied'); }, 1800);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text.trim()).then(done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text.trim();
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        done();
      }
    }
  });

  document.querySelectorAll('dialog').forEach(function (d) {
    d.addEventListener('click', function (ev) { if (ev.target === d) { d.close(); } });
  });

  document.querySelectorAll('[data-filter]').forEach(function (input) {
    var items = document.querySelectorAll(input.getAttribute('data-filter'));
    var empty = document.querySelector(input.getAttribute('data-filter-empty') || '#none');
    var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    var run = function () {
      var q = norm(input.value.trim());
      var status = document.querySelector('[data-filter-status].is-on');
      var st = status ? status.getAttribute('data-filter-status') : '';
      var shown = 0;
      items.forEach(function (el) {
        var ok = (!q || norm(el.getAttribute('data-search')).indexOf(q) !== -1)
          && (!st || st === 'all' || el.getAttribute('data-status') === st);
        el.hidden = !ok;
        if (ok) { shown++; }
      });
      if (empty) { empty.hidden = shown > 0; }
    };
    input.addEventListener('input', run);
    document.querySelectorAll('[data-filter-status]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('[data-filter-status]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
        run();
      });
    });
  });
})();
</script>
</body>
</html>
