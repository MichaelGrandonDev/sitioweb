<?php

declare(strict_types=1);

/** Biblioteca privada: libros y material en PDF para leer en el visor (biblioteca_ver.php). Solo admin. */

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/biblioteca_lector.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida. Volvé a intentar.');
        redirect('biblioteca_lector.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $str = static fn (string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    try {
        switch ($str('action')) {
            case 'update':
                bib_update($id, $str('title'), $str('kind'), $str('tags'));
                flash('success', 'Datos guardados.');
                redirect('biblioteca_lector.php#libro-' . $id);
            case 'delete':
                bib_delete($id);
                flash('success', 'Documento borrado de la biblioteca.');
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('biblioteca_lector.php');
}

bib_sync();
$books = bib_books();
$flash = take_flash();
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$tag = is_string($_GET['tag'] ?? null) ? mb_substr(trim($_GET['tag']), 0, 40) : '';

$allTags = [];
$totalBytes = 0;
foreach ($books as $b) {
    $totalBytes += (int) $b['file_size'];
    foreach (bib_tags((string) $b['tags']) as $t) {
        $allTags[mb_strtolower($t)] = [$allTags[mb_strtolower($t)][0] ?? $t, ($allTags[mb_strtolower($t)][1] ?? 0) + 1];
    }
    $kindKey = 'kind:' . $b['kind'];
    $allTags[$kindKey] = [bib_kind_label((string) $b['kind']), ($allTags[$kindKey][1] ?? 0) + 1];
}
uksort($allTags, static fn ($a, $b) => [str_starts_with((string) $a, 'kind:') ? 0 : 1, bib_fold((string) $a)] <=> [str_starts_with((string) $b, 'kind:') ? 0 : 1, bib_fold((string) $b)]);

$qFold = bib_fold($q);
$shown = array_values(array_filter($books, static function (array $b) use ($qFold, $tag): bool {
    $tags = array_map('mb_strtolower', bib_tags((string) $b['tags']));
    if ($tag !== '' && !(str_starts_with($tag, 'kind:') ? $b['kind'] === substr($tag, 5) : in_array(mb_strtolower($tag), $tags, true))) {
        return false;
    }
    return $qFold === '' || str_contains(bib_fold($b['title'] . ' ' . $b['tags'] . ' ' . bib_kind_label((string) $b['kind']) . ' ' . $b['original_name']), $qFold);
}));
$recent = array_values(array_filter($books, static fn ($b) => $b['last_read_at'] !== null && (int) $b['last_page'] > 0));
usort($recent, static fn ($a, $b) => strcmp((string) $b['last_read_at'], (string) $a['last_read_at']));
$recent = array_slice($recent, 0, 4);

$filterUrl = static fn (string $t): string => 'biblioteca_lector.php?' . http_build_query(array_filter(['q' => $q, 'tag' => $t]));

bib_page_start('Biblioteca');
?>
  <main class="wrap bib-wrap">
    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <div class="bib-head">
      <div>
        <p class="eyebrow">Material privado · solo admin</p>
        <h1>Biblioteca</h1>
        <p class="muted small"><?= count($books) ?> documentos · <?= h(bib_size_label($totalBytes)) ?></p>
      </div>
      <div class="bib-actions">
        <a class="btn ghost" href="#subir">Subir material</a>
        <button class="btn ghost" type="button" id="bib-view-toggle" aria-pressed="false">Ver en lista</button>
      </div>
    </div>

    <?php if ($recent): ?>
      <section class="bib-recent" aria-label="Seguir leyendo">
        <?php foreach ($recent as $b): ?>
          <a class="bib-recent-item" href="<?= h(biblioteca_link((string) $b['sha256'], (int) $b['last_page'])) ?>">
            <span class="muted small">Seguir leyendo</span>
            <strong><?= h($b['title']) ?></strong>
            <span class="muted small">p. <?= (int) $b['last_page'] ?><?= (int) $b['pages'] > 0 ? ' de ' . (int) $b['pages'] : '' ?></span>
          </a>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <form class="bib-search" method="get" action="biblioteca_lector.php" role="search">
      <label class="sr-only" for="bib-q">Buscar por título o etiqueta</label>
      <input type="search" id="bib-q" name="q" value="<?= h($q) ?>" placeholder="Buscar por título o etiqueta…" autocomplete="off">
      <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= h($tag) ?>"><?php endif; ?>
      <button class="btn primary" type="submit">Buscar</button>
    </form>

    <?php if ($allTags): ?>
      <nav class="bib-tags" aria-label="Filtrar por etiqueta">
        <a class="bib-chip<?= $tag === '' ? ' is-on' : '' ?>" href="<?= h($filterUrl('')) ?>">Todo</a>
        <?php foreach ($allTags as $key => [$label, $n]): ?>
          <a class="bib-chip<?= mb_strtolower($tag) === (string) $key ? ' is-on' : '' ?><?= str_starts_with((string) $key, 'kind:') ? ' is-kind' : '' ?>" href="<?= h($filterUrl((string) $key)) ?>"><?= h($label) ?> <span><?= (int) $n ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if (!$books): ?>
      <p class="panel muted">Todavía no hay documentos. Subí tus PDF desde «Subir material».</p>
    <?php elseif (!$shown): ?>
      <p class="panel muted">No hay documentos que coincidan.</p>
    <?php endif; ?>

    <section class="bib-grid" id="bib-grid">
      <?php foreach ($shown as $b): ?>
        <?php
          $tags = bib_tags((string) $b['tags']);
          $missing = !is_file(bib_file_path($b));
          $readable = in_array($b['format'], ['pdf', 'jpg', 'jpeg', 'png'], true);
        ?>
        <article class="bib-card" id="libro-<?= (int) $b['id'] ?>" data-search="<?= h(bib_fold($b['title'] . ' ' . $b['tags'] . ' ' . bib_kind_label((string) $b['kind']) . ' ' . $b['original_name'])) ?>">
          <a class="bib-cover bib-cover--<?= h($b['format']) ?>" href="<?= h(biblioteca_link((string) $b['sha256'], (int) $b['last_page'])) ?>" aria-hidden="true" tabindex="-1">
            <span><?= h(mb_strtoupper(mb_substr((string) $b['title'], 0, 1))) ?></span>
            <small><?= h(strtoupper((string) $b['format'])) ?></small>
          </a>
          <div class="bib-info">
            <h2><a href="<?= h(biblioteca_link((string) $b['sha256'], (int) $b['last_page'])) ?>"><?= h($b['title']) ?></a></h2>
            <p class="bib-meta muted small">
              <?= h(implode(' · ', array_filter([
                  (int) $b['pages'] > 0 ? (int) $b['pages'] . ((int) $b['pages'] === 1 ? ' página' : ' páginas') : '',
                  bib_size_label((int) $b['file_size']),
                  (int) $b['last_page'] > 0 ? 'vas por la p. ' . (int) $b['last_page'] : '',
              ]))) ?>
            </p>
            <p class="bib-taglist">
              <span class="tag"><?= h(bib_kind_label((string) $b['kind'])) ?></span>
              <?php foreach ($tags as $t): ?><a class="tag tag--soft" href="<?= h($filterUrl(mb_strtolower($t))) ?>"><?= h($t) ?></a><?php endforeach; ?>
              <?php if ($missing): ?><span class="tag warn">Falta el archivo</span><?php endif; ?>
              <?php if (!$readable): ?><span class="tag warn">Convertir a PDF</span><?php endif; ?>
            </p>
            <div class="bib-card-actions">
              <a class="btn primary" href="<?= h(biblioteca_link((string) $b['sha256'], (int) $b['last_page'])) ?>"><?= $readable ? ((int) $b['last_page'] > 1 ? 'Seguir' : 'Leer') : 'Ver' ?></a>
              <details class="admin-details bib-edit">
                <summary>Editar</summary>
                <form method="post" class="stack">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <label>Título <input name="title" maxlength="200" required value="<?= h($b['title']) ?>"></label>
                  <label>Tipo
                    <select name="kind">
                      <?php foreach (BIB_KINDS as $key => $label): ?>
                        <option value="<?= h($key) ?>"<?= $b['kind'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Etiquetas (separadas por coma) <input name="tags" maxlength="500" value="<?= h($b['tags']) ?>" placeholder="acupuntura, diagnóstico"></label>
                  <p class="muted small">Archivo: <?= h($b['original_name'] !== '' ? $b['original_name'] : '—') ?></p>
                  <button class="btn primary" type="submit">Guardar</button>
                </form>
                <form method="post" onsubmit="return confirm('¿Borrar «<?= h(addslashes((string) $b['title'])) ?>» de la biblioteca? Se borra el archivo del servidor.')">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn ghost bib-danger" type="submit">Borrar</button>
                </form>
              </details>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
    <p class="muted small is-hidden" id="bib-none">No hay documentos que coincidan.</p>

    <section class="panel" id="subir">
      <h2>Subir material</h2>
      <p class="hint">
        Subí <strong>PDF</strong> (hasta <?= BIB_MAX_BYTES / 1048576 ?> MB cada uno; los grandes se suben por partes, podés seguir usando el admin en otra pestaña).
        También se aceptan <strong>PowerPoint (PPTX / PPT)</strong>, pero el visor solo lee PDF: para leerlas acá, abrilas en PowerPoint o Keynote y usá
        <em>Archivo → Exportar → PDF</em>, y subí ese PDF. Las imágenes (JPG / PNG) se ven directo.
      </p>
      <div class="bib-upload" id="bib-upload" data-api="biblioteca_api.php">
        <label>Archivos
          <input type="file" id="bib-files" multiple accept=".pdf,.pptx,.ppt,.jpg,.jpeg,.png,application/pdf">
        </label>
        <label>Tipo
          <select id="bib-kind">
            <option value="">Automático</option>
            <?php foreach (BIB_KINDS as $key => $label): ?>
              <option value="<?= h($key) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Etiquetas (opcional)
          <input id="bib-tags" maxlength="500" placeholder="acupuntura, diagnóstico">
        </label>
        <button class="btn primary" type="button" id="bib-send">Subir</button>
      </div>
      <ul class="bib-jobs" id="bib-jobs"></ul>
    </section>
  </main>
  <script src="<?= h(ui_asset('biblioteca.js')) ?>" defer></script>
</body>
</html>
