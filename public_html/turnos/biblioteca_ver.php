<?php

declare(strict_types=1);

/**
 * Visor de la biblioteca: biblioteca_ver.php?id=<sha256 o id>&page=N abre el documento en esa página.
 * Los PDF se leen con PDF.js (assets/pdfjs, sin CDN), pidiendo el archivo por partes a biblioteca_file.php.
 */

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/biblioteca_lector.php';

$book = bib_book((string) ($_GET['id'] ?? ''));
if (!$book) {
    flash('error', 'No se encontró ese documento en la biblioteca.');
    redirect('biblioteca_lector.php');
}
$page = (int) ($_GET['page'] ?? 0);
if ($page < 1) {
    $page = max(0, (int) $book['last_page']);
}
$format = (string) $book['format'];
$base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/turnos/biblioteca_ver.php'))), '/');
$fileUrl = $base . '/biblioteca_file.php?id=' . rawurlencode((string) $book['sha256']);
$viewerUrl = 'assets/pdfjs/web/viewer.html?file=' . rawurlencode($fileUrl) . ($page > 0 ? '#page=' . $page : '');
$missing = !is_file(bib_file_path($book));

bib_page_start((string) $book['title'], 'bib-reader');
?>
  <div class="bib-bar">
    <a class="btn ghost bib-back" href="biblioteca_lector.php" title="Volver a la biblioteca">← <span>Biblioteca</span></a>
    <div class="bib-bar-title">
      <strong><?= h($book['title']) ?></strong>
      <span class="muted small" id="bib-where"><?= $page > 0 ? 'p. ' . $page : '' ?><?= (int) $book['pages'] > 0 ? ($page > 0 ? ' de ' : '') . (int) $book['pages'] . ' páginas' : '' ?></span>
    </div>
    <div class="bib-bar-actions">
      <?php if ($format === 'pdf'): ?>
        <button class="btn ghost" type="button" id="bib-copy" title="Copiar el enlace a la página que estás leyendo">Copiar enlace</button>
      <?php endif; ?>
      <a class="btn ghost" href="<?= h($fileUrl) ?>&amp;dl=1" title="Descargar el archivo">Descargar</a>
    </div>
  </div>

  <?php if ($missing): ?>
    <main class="wrap"><p class="alert error">El archivo de este documento no está en el servidor. Volvé a subirlo desde la biblioteca.</p></main>
  <?php elseif ($format === 'pdf'): ?>
    <script>
      (function () {
        var id = <?= json_encode((string) $book['sha256']) ?>;
        var knownPages = <?= (int) $book['pages'] ?>;
        var csrf = document.querySelector('meta[name="csrf-token"]').content;
        var post = function (data, beacon) {
          var body = new FormData();
          body.append('csrf', csrf);
          body.append('id', id);
          Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
          if (beacon && navigator.sendBeacon) { navigator.sendBeacon('biblioteca_api.php', body); return; }
          fetch('biblioteca_api.php', { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
        };
        var current = 0, saved = <?= (int) $book['last_page'] ?>, timer = null;
        var save = function (beacon) {
          clearTimeout(timer);
          if (current > 0 && current !== saved) { saved = current; post({ action: 'progress', page: current }, beacon); }
        };
        window.bibCurrentPage = function () { return current || <?= max(1, $page) ?>; };
        document.addEventListener('webviewerloaded', function (e) {
          var w = e.detail && e.detail.source;
          if (!w || !w.PDFViewerApplicationOptions) { return; }
          w.PDFViewerApplicationOptions.setAll({
            disablePreferences: true,
            disableAutoFetch: true,
            disableStream: true,
            enableScripting: false,
            localeProperties: { lang: 'es-AR' }
          });
          w.PDFViewerApplication.initializedPromise.then(function () {
            var app = w.PDFViewerApplication;
            var where = document.getElementById('bib-where');
            app.eventBus.on('pagechanging', function (evt) {
              current = evt.pageNumber;
              where.textContent = 'p. ' + current + (app.pagesCount ? ' de ' + app.pagesCount : '');
              clearTimeout(timer);
              timer = setTimeout(save, 1500);
            });
            app.eventBus.on('pagesloaded', function (evt) {
              if (evt.pagesCount && evt.pagesCount !== knownPages) { knownPages = evt.pagesCount; post({ action: 'pages', pages: evt.pagesCount }); }
              current = current || app.page;
              where.textContent = 'p. ' + app.page + ' de ' + evt.pagesCount;
            });
          });
        });
        window.addEventListener('pagehide', function () { save(true); });
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { save(true); } });
      })();
    </script>
    <iframe class="bib-frame" id="bib-frame" src="<?= h($viewerUrl) ?>" title="<?= h($book['title']) ?>" allow="fullscreen" allowfullscreen></iframe>
  <?php elseif (in_array($format, ['jpg', 'jpeg', 'png'], true)): ?>
    <div class="bib-image" id="bib-image">
      <img src="<?= h($fileUrl) ?>" alt="<?= h($book['title']) ?>" title="Tocá para acercar o alejar">
    </div>
  <?php else: ?>
    <main class="wrap">
      <section class="panel">
        <h2>Esta presentación no se puede leer en el visor</h2>
        <p>El visor lee PDF. Para leerla acá: descargala, abrila en PowerPoint o Keynote, elegí <em>Archivo → Exportar → PDF</em> y subí ese PDF a la biblioteca.
          Después podés borrar esta versión.</p>
        <p><a class="btn primary" href="<?= h($fileUrl) ?>&amp;dl=1">Descargar la presentación</a></p>
      </section>
    </main>
  <?php endif; ?>
  <script src="assets/biblioteca.js?v=20260929a"></script>
</body>
</html>
