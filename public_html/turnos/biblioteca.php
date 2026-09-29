<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/pacientes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pac_require_post('biblioteca.php');
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $back = 'biblioteca.php';
    try {
        $docId = (int) ($_POST['doc_id'] ?? 0);
        $protoId = (int) ($_POST['protocol_id'] ?? 0);
        switch ($action) {
            case 'doc_update':
                $doc = pac_doc($docId) ?? throw new RuntimeException('Documento no encontrado.');
                $title = mb_substr(pac_post_str('title', 200), 0, 200);
                $tag = (string) ($_POST['tag'] ?? '');
                db()->prepare('UPDATE pac_docs SET title = ?, tag = ?, active = ? WHERE id = ?')->execute([
                    $title !== '' ? $title : $doc['title'],
                    array_key_exists($tag, PAC_DOC_TAGS) ? $tag : $doc['tag'],
                    ($_POST['active'] ?? '') === '1' ? 1 : 0,
                    $docId,
                ]);
                flash('success', 'Documento actualizado.');
                $back .= '#docs';
                break;
            case 'doc_toggle':
                db()->prepare('UPDATE pac_docs SET active = 1 - active WHERE id = ?')->execute([$docId]);
                flash('success', 'Listo.');
                $back .= '#docs';
                break;
            case 'doc_reprocess':
                $doc = pac_doc($docId) ?? throw new RuntimeException('Documento no encontrado.');
                $res = pac_doc_process($doc);
                flash($res['status'] === 'listo' ? 'success' : 'error', $res['status'] === 'listo'
                    ? 'Texto leído de nuevo en el servidor: ' . $res['pages_with_text'] . ' partes con texto.'
                    : 'No se encontró texto en el documento.');
                $back .= '#docs';
                break;
            case 'doc_delete':
                pac_doc_delete($docId);
                flash('success', 'Documento borrado de la biblioteca.');
                $back .= '#docs';
                break;
            case 'protocol_preview':
                $values = [];
                foreach (PAC_PROTOCOL_FIELDS as $key => [$label, $max]) {
                    $values[$key] = mb_substr(pac_post_str($key, (int) $max), 0, (int) $max);
                }
                $values['categoria'] = ($_POST['categoria'] ?? '') === 'condicion' ? 'condicion' : 'patron';
                $values['active'] = ($_POST['active'] ?? '') === '1' ? '1' : '0';
                if ($values['nombre'] === '') {
                    throw new RuntimeException('El protocolo necesita un nombre.');
                }
                if ($protoId > 0 && !pac_protocol($protoId)) {
                    throw new RuntimeException('Ese protocolo ya no existe.');
                }
                $_SESSION['pac_proto_prev'] = ['id' => $protoId, 'values' => $values];
                redirect('biblioteca.php?proto_preview=1#proto-preview');
            case 'protocol_discard':
                unset($_SESSION['pac_proto_prev']);
                flash('success', 'Cambios descartados. El protocolo quedó como estaba.');
                $back .= $protoId > 0 ? '#protocolo-' . $protoId : '#protocolos';
                break;
            case 'protocol_save':
                $prev = $_SESSION['pac_proto_prev'] ?? null;
                if (!is_array($prev) || (int) $prev['id'] !== $protoId) {
                    throw new RuntimeException('Primero tocá «Vista previa» para revisar el protocolo antes de guardarlo.');
                }
                if ($protoId > 0 && ($_POST['confirm_overwrite'] ?? '') !== '1') {
                    $back = 'biblioteca.php?proto_preview=1#proto-preview';
                    throw new RuntimeException('Marcá «Sí, reemplazar» para guardar los cambios del protocolo.');
                }
                $pid = pac_protocol_save($prev['values'], $protoId);
                unset($_SESSION['pac_proto_prev']);
                flash('success', 'Protocolo guardado.');
                $back .= '#protocolo-' . $pid;
                break;
            case 'protocol_delete':
                pac_protocol_delete($protoId);
                flash('success', 'Protocolo borrado.');
                $back .= '#protocolos';
                break;
            case 'protocol_seed':
                $n = pac_protocols_seed(db(), true);
                flash('success', $n ? 'Se agregaron ' . $n . ' protocolos base.' : 'Ya estaban todos los protocolos base.');
                $back .= '#protocolos';
                break;
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$docs = pac_docs();
$protocols = pac_protocols();
$protoPrev = isset($_GET['proto_preview']) && is_array($_SESSION['pac_proto_prev'] ?? null) ? $_SESSION['pac_proto_prev'] : null;
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 200) : '';
$results = $q !== '' ? pac_search(pac_terms($q), 20) : [];
$statusLabel = [
    'subiendo' => ['Subiendo…', 'warn'],
    'procesando' => ['Leyendo texto…', 'warn'],
    'listo' => ['Listo', 'ok'],
    'sin_texto' => ['Sin texto', 'warn'],
    'error' => ['Error', 'warn'],
];

pac_page_start('Biblioteca MTC', 'biblioteca');
?>
    <div class="pac-head">
      <div>
        <p class="eyebrow">Pacientes · material de referencia</p>
        <h1>Biblioteca MTC</h1>
      </div>
      <div class="pac-actions">
        <a class="btn ghost" href="#docs">Documentos</a>
        <a class="btn ghost" href="#buscar">Buscar</a>
        <a class="btn ghost" href="#protocolos">Protocolos</a>
      </div>
    </div>
    <p class="hint">
      Lo que subas acá es la base de los diagnósticos y tratamientos que genera la sección Pacientes: cada sugerencia cita el documento y la página
      (o diapositiva). Los protocolos de abajo son editables y son los que se usan para reconocer los patrones.
    </p>

    <section class="panel" id="subir">
      <h2>Subir material</h2>
      <p class="hint">
        Formatos: <strong>PDF</strong>, <strong>PowerPoint PPTX</strong>, <strong>Word DOCX</strong> y <strong>texto TXT / MD</strong>, hasta <?= PAC_DOC_MAX / 1048576 ?> MB cada uno
        (los archivos grandes se suben por partes). Los PDF se leen en tu navegador y se citan por página. <strong>PPT antiguo</strong> (.ppt): se lee en forma aproximada;
        para mejores citas guardalo como PPTX o PDF. Los PDF escaneados (fotos de páginas) no tienen texto: pasalos antes por un OCR.
      </p>
      <div class="pac-drop" id="pac-drop" data-max="<?= PAC_DOC_MAX ?>">
        <label>Elegí uno o varios archivos
          <input type="file" id="pac-files" multiple accept=".pdf,.pptx,.ppt,.docx,.txt,.md">
        </label>
        <label>Tipo
          <select id="pac-tag">
            <?php foreach (PAC_DOC_TAGS as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= $key === 'libro' ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn primary" type="button" id="pac-upload">Subir y procesar</button>
      </div>
      <ul class="pac-jobs" id="pac-jobs"></ul>
    </section>

    <section class="panel" id="docs">
      <h2>Documentos (<?= count($docs) ?>)</h2>
      <?php if (!$docs): ?>
        <p class="muted">Todavía no subiste material.</p>
      <?php endif; ?>
      <?php foreach ($docs as $d): ?>
        <?php [$sLabel, $sTag] = $statusLabel[$d['status']] ?? [$d['status'], 'warn']; ?>
        <article class="pac-row">
          <span>
            <strong><?= h($d['title']) ?></strong>
            <span class="tag"><?= h(PAC_DOC_TAGS[$d['tag']] ?? $d['tag']) ?></span>
            <span class="tag <?= h($sTag) ?>"><?= h($sLabel) ?></span>
            <?php if ((int) $d['active'] !== 1): ?><span class="tag warn">Desactivado</span><?php endif; ?><br>
            <span class="muted small"><?= h(implode(' · ', array_filter([
                PAC_DOC_KINDS[$d['kind']][0] ?? $d['kind'],
                (int) $d['pages'] > 0 ? (int) $d['pages'] . ' ' . ($d['kind'] === 'pdf' && !str_contains((string) $d['note'], 'por partes') ? 'páginas' : ($d['kind'] === 'pptx' || $d['kind'] === 'ppt' ? 'diapositivas' : 'partes')) : '',
                (int) $d['chars'] > 0 ? number_format((int) $d['chars'], 0, ',', '.') . ' caracteres' : '',
                pac_size_label((int) $d['file_size']),
                date('d/m/Y', strtotime((string) $d['created_at'])),
            ]))) ?></span>
            <?php if ($d['note'] !== ''): ?><br><span class="muted small"><?= h($d['note']) ?></span><?php endif; ?>
          </span>
          <span class="pac-actions">
            <?php if ($d['file_path'] !== ''): ?>
              <a class="btn ghost" href="pacientes_archivo.php?tipo=doc&amp;id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener">Abrir</a>
            <?php endif; ?>
            <form method="post">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="doc_toggle">
              <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
              <button class="btn ghost" type="submit"><?= (int) $d['active'] === 1 ? 'Desactivar' : 'Activar' ?></button>
            </form>
          </span>
          <details class="admin-details">
            <summary>Editar</summary>
            <form method="post" class="form-grid">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="doc_update">
              <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
              <label>Título <input name="title" maxlength="200" value="<?= h($d['title']) ?>"></label>
              <label>Tipo
                <select name="tag">
                  <?php foreach (PAC_DOC_TAGS as $key => $label): ?>
                    <option value="<?= h($key) ?>"<?= $d['tag'] === $key ? ' selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="check span-2"><input type="checkbox" name="active" value="1"<?= (int) $d['active'] === 1 ? ' checked' : '' ?>><span>Usar en búsquedas y diagnósticos</span></label>
              <div class="span-2 pac-actions">
                <button class="btn primary" type="submit">Guardar</button>
              </div>
            </form>
            <div class="pac-actions">
              <?php if ($d['file_path'] !== ''): ?>
                <form method="post" onsubmit="return confirm('¿Volver a leer el texto en el servidor? En los PDF la lectura del servidor es aproximada (por partes, no por página).')">
                  <?= pac_csrf_field() ?>
                  <input type="hidden" name="action" value="doc_reprocess">
                  <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                  <button class="btn ghost" type="submit">Volver a leer el texto</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('¿Borrar este documento de la biblioteca?')">
                <?= pac_csrf_field() ?>
                <input type="hidden" name="action" value="doc_delete">
                <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                <button class="btn ghost" type="submit">Borrar</button>
              </form>
            </div>
          </details>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="panel" id="buscar">
      <h2>Buscar en la biblioteca</h2>
      <form method="get" class="pac-search" action="biblioteca.php#buscar">
        <label>Palabras (síntomas, patrones, puntos…)
          <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ej: vacío de qi de bazo lengua pálida">
        </label>
        <button class="btn primary" type="submit">Buscar</button>
      </form>
      <?php if ($q !== '' && !$results): ?>
        <p class="muted">Sin resultados en los documentos activos.</p>
      <?php endif; ?>
      <?php foreach ($results as $r): ?>
        <article class="pac-hit">
          <p class="small"><strong><?= h(pac_cite($r)) ?></strong> <span class="tag"><?= h(PAC_DOC_TAGS[$r['doc_tag']] ?? $r['doc_tag']) ?></span></p>
          <p class="small"><?= h(pac_excerpt((string) $r['text'], $r['stems'], 600)) ?></p>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="panel" id="protocolos">
      <h2>Protocolos (<?= count($protocols) ?>)</h2>
      <p class="hint">
        Cada protocolo relaciona un patrón o condición con sus signos de lengua (lo principal), los datos del paciente y, opcionalmente, el pulso;
        y con el principio de tratamiento, los meridianos, los puntos para moxar y las cinco técnicas: tuina, chi kung, moxibustión, ventosas y auriculoterapia (sin agujas).
        El botón «Generar» de cada paciente busca qué protocolos coinciden, primero por la lengua. Podés editarlos, agregar los tuyos
        (por ejemplo, copiando un protocolo de un libro) y desactivar los que no uses. Escribí los puntos con su código (E36, B6, V20…) para que se filtren solos
        en embarazo. Antes de guardar ves una vista previa con los cambios.
      </p>
      <?php if ($protoPrev): ?>
        <?php
          $pv = $protoPrev['values'];
          $saved = (int) $protoPrev['id'] > 0 ? pac_protocol((int) $protoPrev['id']) : null;
          $codes = pac_point_codes((string) $pv['puntos']);
          $avoid = array_values(array_intersect($codes, PAC_PREGNANCY_AVOID));
          $protoChanges = [];
          if ($saved) {
              foreach (PAC_PROTOCOL_FIELDS + ['categoria' => ['Categoría', 0], 'active' => ['Activo', 0]] as $key => [$label]) {
                  $a = trim((string) $saved[$key]);
                  $b = trim((string) $pv[$key]);
                  if ($a !== $b) {
                      $protoChanges[] = ['label' => $label, 'before' => $a, 'after' => $b];
                  }
              }
          }
        ?>
        <div class="panel pac-eval pac-preview" id="proto-preview">
          <p class="eyebrow">Vista previa — todavía no se guardó</p>
          <h3><?= $saved ? 'Cambios en «' . h((string) $saved['nombre']) . '»' : 'Nuevo protocolo: ' . h($pv['nombre']) ?></h3>
          <div class="pac-applied">
            <?php foreach (PAC_PROTOCOL_FIELDS as $key => [$label]): ?>
              <?php if (trim((string) $pv[$key]) !== ''): ?>
                <p class="small"><strong><?= h($label) ?>:</strong> <?= nl2br(h((string) $pv[$key])) ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
            <p class="small"><strong>Puntos reconocidos:</strong> <?= $codes ? h(pac_points_text($codes)) : '<em>ninguno (escribí los códigos, por ejemplo E36, B6)</em>' ?></p>
            <?php if ($avoid): ?>
              <p class="small"><span class="tag warn">Embarazo</span> En pacientes embarazadas se van a quitar solos: <?= h(pac_points_text($avoid)) ?>.</p>
            <?php endif; ?>
            <p class="small"><strong>Estado:</strong> <?= $pv['active'] === '1' ? 'activo (se usa al generar)' : 'desactivado' ?> · <?= $pv['categoria'] === 'condicion' ? 'Condición / dolor' : 'Patrón MTC' ?></p>
          </div>
          <?php if ($saved): ?>
            <div class="pac-diff">
              <?php if (!$protoChanges): ?>
                <p class="muted">No cambia nada respecto de lo guardado.</p>
              <?php else: ?>
                <p class="small">Cambian: <?= h(implode(', ', array_column($protoChanges, 'label'))) ?>.</p>
                <?php foreach ($protoChanges as $c): ?>
                  <details class="pac-version">
                    <summary><?= h($c['label']) ?></summary>
                    <div class="pac-diff-cols">
                      <div><p class="eyebrow">Ahora</p><p class="small"><?= $c['before'] !== '' ? nl2br(h($c['before'])) : '<em>vacío</em>' ?></p></div>
                      <div><p class="eyebrow">Quedaría</p><p class="small"><?= $c['after'] !== '' ? nl2br(h($c['after'])) : '<em>vacío</em>' ?></p></div>
                    </div>
                  </details>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="pac-actions" style="margin-top:.8rem">
            <?php if (!$saved || $protoChanges): ?>
              <form method="post" class="pac-actions">
                <?= pac_csrf_field() ?>
                <input type="hidden" name="action" value="protocol_save">
                <input type="hidden" name="protocol_id" value="<?= (int) $protoPrev['id'] ?>">
                <?php if ($saved): ?>
                  <label class="check"><input type="checkbox" name="confirm_overwrite" value="1" required><span>Sí, reemplazar</span></label>
                <?php endif; ?>
                <button class="btn primary" type="submit"><?= $saved ? 'Guardar cambios' : 'Crear protocolo' ?></button>
              </form>
            <?php endif; ?>
            <form method="post">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="protocol_discard">
              <input type="hidden" name="protocol_id" value="<?= (int) $protoPrev['id'] ?>">
              <button class="btn ghost" type="submit">Descartar</button>
            </form>
          </div>
          <details class="admin-details">
            <summary>Volver a editar</summary>
            <form method="post" class="form-grid">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="protocol_preview">
              <input type="hidden" name="protocol_id" value="<?= (int) $protoPrev['id'] ?>">
              <?php $proto = $pv; require __DIR__ . '/includes/pac_protocol_form.php'; ?>
              <div class="span-2"><button class="btn primary" type="submit">Actualizar vista previa</button></div>
            </form>
          </details>
        </div>
      <?php endif; ?>
      <?php foreach ($protocols as $pr): ?>
        <details class="pac-proto" id="protocolo-<?= (int) $pr['id'] ?>">
          <summary>
            <strong><?= h($pr['nombre']) ?></strong>
            <?php if ((int) $pr['active'] !== 1): ?><span class="tag warn">Desactivado</span><?php endif; ?>
            <span class="muted small"> · <?= h(pac_trim((string) $pr['puntos'], 80)) ?></span>
          </summary>
          <form method="post" class="form-grid">
            <?= pac_csrf_field() ?>
            <input type="hidden" name="action" value="protocol_preview">
            <input type="hidden" name="protocol_id" value="<?= (int) $pr['id'] ?>">
            <?php $proto = $pr; require __DIR__ . '/includes/pac_protocol_form.php'; ?>
            <div class="span-2 pac-actions">
              <button class="btn primary" type="submit">Vista previa de los cambios</button>
            </div>
          </form>
          <form method="post" onsubmit="return confirm('¿Borrar este protocolo?')">
            <?= pac_csrf_field() ?>
            <input type="hidden" name="action" value="protocol_delete">
            <input type="hidden" name="protocol_id" value="<?= (int) $pr['id'] ?>">
            <button class="btn ghost" type="submit">Borrar protocolo</button>
          </form>
        </details>
      <?php endforeach; ?>
      <details class="pac-proto" id="nuevo-protocolo">
        <summary><strong>+ Nuevo protocolo</strong></summary>
        <form method="post" class="form-grid">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="action" value="protocol_preview">
          <?php $proto = ['active' => 1, 'categoria' => 'patron']; require __DIR__ . '/includes/pac_protocol_form.php'; ?>
          <div class="span-2"><button class="btn primary" type="submit">Vista previa del protocolo</button></div>
        </form>
      </details>
      <form method="post" style="margin-top:.8rem">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="protocol_seed">
        <button class="btn ghost" type="submit">Agregar los protocolos base que falten</button>
      </form>
    </section>
<?php
pac_page_end(['assets/vendor/pdf.min.js', 'assets/pacientes.js?v=20260929c']);
