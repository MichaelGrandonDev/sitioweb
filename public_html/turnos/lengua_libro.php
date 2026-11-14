<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/pacientes.php';

$self = 'lengua_libro.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pac_require_post($self);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $key = is_string($_POST['rule'] ?? null) ? $_POST['rule'] : '';
    $back = $self;
    try {
        if ($action === 'set_book') {
            $docId = (int) ($_POST['doc_id'] ?? 0);
            if ($docId > 0 && !pac_doc($docId)) {
                throw new RuntimeException('Ese documento no existe.');
            }
            pac_set_setting('tongue_book_doc', (string) $docId);
            flash('success', $docId ? 'Libro de referencia elegido.' : 'El libro se detecta solo por el nombre.');
        } elseif ($action === 'save_rule') {
            $in = $_POST;
            if (isset($_POST['usar_pagina'])) {
                $in['pagina'] = (string) (int) $_POST['usar_pagina'];
            }
            pac_tongue_rule_save($key, $in);
            flash('success', 'Regla guardada.');
            $back .= '#r-' . $key;
        } elseif ($action === 'reset_rule') {
            pac_tongue_rule_reset($key);
            flash('success', 'Regla restaurada a la de fábrica.');
            $back .= '#r-' . $key;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$st = pac_tongue_book_status();
$doc = $st['doc'];
$rules = pac_tongue_rules(true);
$cites = array_map('pac_tongue_rule_cite', $rules);
$count = array_count_values(array_column($cites, 'estado'));
$docs = array_values(array_filter(pac_docs(), static fn ($d) => (int) $d['active'] === 1 && $d['status'] === 'listo'));
$chosen = (int) pac_setting('tongue_book_doc');

pac_page_start('Lengua: libro de referencia', 'lengua');
?>
    <div class="pac-head">
      <div>
        <p class="eyebrow">Glosodiagnosis</p>
        <h1>Libro de referencia</h1>
        <p class="muted">«<?= h(PAC_TONGUE_BOOK_TITLE) ?>»: sus categorías son las opciones de la lengua en la ficha, en el análisis de la foto y en el diagnóstico.</p>
      </div>
    </div>

    <section class="panel">
      <h2>Estado</h2>
      <?php if ($doc === null): ?>
        <div class="alert warn">El libro todavía no está en la Biblioteca. Subilo en <a href="biblioteca.php">Biblioteca MTC</a>: se reconoce solo por el nombre
          (o elegilo abajo). Mientras tanto, las citas de la lengua dicen «<?= h(PAC_TONGUE_PENDING) ?>».</div>
      <?php else: ?>
        <p><strong><?= h((string) $doc['title']) ?></strong> · <?= (int) $doc['pages'] ?> páginas ·
          <span class="tag <?= $st['listo'] ? 'ok' : 'warn' ?>"><?= h((string) $doc['status']) ?></span></p>
        <?php if ($st['sin_texto']): ?>
          <div class="alert warn">Casi no tiene texto por página: parece un PDF escaneado. Hace falta pasarlo por OCR y volver a subirlo
            para poder buscar y vincular sus páginas.</div>
        <?php endif; ?>
        <p class="muted small">Reglas con página puesta: <?= (int) ($count['libro'] ?? 0) ?> · página encontrada por búsqueda (verificar): <?= (int) ($count['auto'] ?? 0) ?>
          · sin página: <?= (int) ($count['pendiente'] ?? 0) ?>.</p>
      <?php endif; ?>
      <form method="post" class="pac-search">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="set_book">
        <label>Documento de la Biblioteca que es el libro
          <select name="doc_id">
            <option value="0">Detectar por el nombre<?= $chosen === 0 && $doc ? ' (ahora: ' . h(pac_trim((string) $doc['title'], 50)) . ')' : '' ?></option>
            <?php foreach ($docs as $d): ?>
              <option value="<?= (int) $d['id'] ?>"<?= $chosen === (int) $d['id'] ? ' selected' : '' ?>><?= h(pac_trim((string) $d['title'], 90)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn ghost" type="submit">Guardar</button>
      </form>
    </section>

    <section class="panel">
      <h2>Signos de la lengua → patrones</h2>
      <p class="hint">Cada signo con lo que significa y a qué patrones orienta. Esto se muestra junto a lo sugerido por la foto, suma puntos a los protocolos
        con esos patrones y va en el caso que lee la IA. Poné la página del libro donde lo dice; si la dejás vacía y el libro está cargado,
        se busca sola en su texto (marcada «verificar»).</p>
      <?php foreach (PAC_TONGUE as $campo => [$label, , , $group]): ?>
        <?php $list = array_filter($rules, static fn ($r) => $r['campo'] === $campo); ?>
        <?php if (!$list) continue; ?>
        <h3 class="pac-sub"><?= h(PAC_TONGUE_GROUPS[$group] . ' · ' . $label) ?></h3>
        <?php foreach ($list as $k => $r): $c = $cites[$k]; ?>
          <details class="admin-details pac-rule" id="r-<?= h($k) ?>">
            <summary><strong><?= h(pac_tongue_label($r['valor'])) ?></strong>
              <?= $r['patrones'] !== '' ? '→ ' . h($r['patrones']) : '<span class="muted">(normal)</span>' ?>
              <span class="tag <?= $c['estado'] === 'libro' ? 'ok' : 'warn' ?>"><?= h($c['estado'] === 'libro' ? 'p. ' . $r['pagina'] : ($c['estado'] === 'auto' ? 'p. ' . $c['pagina'] . ' verificar' : 'sin página')) ?></span>
              <?= !$r['activo'] ? '<span class="tag">apagada</span>' : '' ?><?= $r['editada'] ? ' <span class="muted small">editada</span>' : '' ?></summary>
            <form method="post" class="form-grid">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="save_rule">
              <input type="hidden" name="rule" value="<?= h($k) ?>">
              <label class="span-2">Qué significa <textarea name="significado" rows="2" maxlength="600"><?= h($r['significado']) ?></textarea></label>
              <label class="span-2">Patrones a los que orienta (separados por «;», con el nombre de tus protocolos)
                <input name="patrones" maxlength="300" value="<?= h($r['patrones']) ?>"></label>
              <label>Página del libro <input name="pagina" maxlength="9" inputmode="numeric" placeholder="Ej: 45 o 45-47" value="<?= h($r['pagina']) ?>"></label>
              <label>Palabras para buscarla <input name="buscar" maxlength="160" value="<?= h($r['buscar']) ?>"></label>
              <label class="check"><input type="checkbox" name="activo" value="1"<?= $r['activo'] ? ' checked' : '' ?>><span>Usar esta regla</span></label>
              <p class="span-2 small"><?= h($c['texto']) ?><?php if ($c['link']): ?> · <a href="<?= h($c['link']) ?>" target="_blank" rel="noopener">abrir en el lector</a><?php endif; ?></p>
              <div class="pac-actions span-2">
                <button class="btn primary small" type="submit">Guardar</button>
                <?php if ($c['estado'] === 'auto'): ?>
                  <button class="btn ghost small" type="submit" name="usar_pagina" value="<?= (int) $c['pagina'] ?>">Usar la p. <?= (int) $c['pagina'] ?></button>
                <?php endif; ?>
                <?php if ($r['editada']): ?>
                  <button class="btn ghost small" type="submit" name="action" value="reset_rule" formnovalidate>Restaurar la de fábrica</button>
                <?php endif; ?>
              </div>
            </form>
          </details>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </section>
<?php
pac_page_end();
