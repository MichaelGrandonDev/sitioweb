<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$me = require_admin();
$pdo = db();

$courses = $pdo->query('SELECT id, title, published FROM courses ORDER BY sort_order, title')->fetchAll();
$courseIds = array_map('intval', array_column($courses, 'id'));
$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
if (!in_array($courseId, $courseIds, true)) {
    $courseId = $courseIds[0] ?? 0;
}

$isAjax = ($_POST['ajax'] ?? '') === '1';

function grabaciones_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        if ($isAjax) {
            grabaciones_json(['ok' => false, 'error' => 'La sesión expiró. Recargá la página.'], 403);
        }
        flash('error', 'Sesión inválida.');
        redirect('grabaciones.php?course_id=' . $courseId);
    }
    $action = (string) ($_POST['action'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($action === 'upload_start') {
        if (!in_array($courseId, $courseIds, true)) {
            grabaciones_json(['ok' => false, 'error' => 'Elegí un curso.'], 400);
        }
        grabaciones_json(recording_upload_start($courseId, (string) ($_POST['filename'] ?? ''), (int) ($_POST['size'] ?? 0)));
    }

    if ($action === 'upload_chunk') {
        @set_time_limit(300);
        $res = recording_upload_chunk((string) ($_POST['token'] ?? ''), (int) ($_POST['offset'] ?? -1), $_FILES['chunk'] ?? []);
        grabaciones_json($res, $res['ok'] ? 200 : 409);
    }

    if ($action === 'upload_finish') {
        if ($title === '') {
            grabaciones_json(['ok' => false, 'error' => 'Poné un título a la clase.'], 400);
        }
        $res = recording_upload_finish((string) ($_POST['token'] ?? ''));
        if (!$res['ok']) {
            grabaciones_json($res, 409);
        }
        recording_add((int) $res['course_id'], $title, $description, '', (string) $res['path']);
        flash('success', 'Clase "' . $title . '" publicada. Ya la ven los alumnos del curso.');
        grabaciones_json(['ok' => true, 'redirect' => 'grabaciones.php?course_id=' . (int) $res['course_id']]);
    }

    if ($action === 'upload_cancel') {
        recording_upload_cancel((string) ($_POST['token'] ?? ''));
        grabaciones_json(['ok' => true]);
    }

    if ($action === 'add_link') {
        $url = trim((string) ($_POST['url'] ?? ''));
        if ($courseId <= 0 || $title === '') {
            flash('error', 'Elegí el curso y poné un título.');
        } elseif (!recording_url_supported($url)) {
            flash('error', 'Pegá un link de YouTube o de Google Drive (archivo de video).');
        } else {
            recording_add($courseId, $title, $description, $url, '');
            flash('success', 'Clase "' . $title . '" publicada. Ya la ven los alumnos del curso.');
        }
    }

    if ($action === 'update') {
        $rid = (int) ($_POST['resource_id'] ?? 0);
        if ($rid > 0 && $title !== '') {
            $pdo->prepare("UPDATE course_resources SET title = ?, description = ? WHERE id = ? AND type = 'video'")
                ->execute([$title, $description, $rid]);
            flash('success', 'Clase actualizada.');
        }
    }

    if ($action === 'delete') {
        $rid = (int) ($_POST['resource_id'] ?? 0);
        $row = $pdo->prepare("SELECT file_path FROM course_resources WHERE id = ? AND type = 'video'");
        $row->execute([$rid]);
        $res = $row->fetch();
        if ($res) {
            delete_upload((string) $res['file_path']);
            $pdo->prepare('DELETE FROM course_resources WHERE id = ?')->execute([$rid]);
            flash('success', 'Clase eliminada.');
        }
    }

    redirect('grabaciones.php?course_id=' . $courseId);
}

$recordings = $courseId > 0 ? recordings_for_user($me, $courseId) : [];
$students = 0;
if ($courseId > 0) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN users u ON u.id = e.user_id WHERE e.course_id = ? AND u.role = 'student' AND u.active = 1");
    $st->execute([$courseId]);
    $students = (int) $st->fetchColumn();
}

$pageTitle = 'Clases grabadas';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administración</p>
    <h1>Clases grabadas</h1>
    <p class="lede">Subí el video de la clase o pegá el link de YouTube o Drive. Lo ven al instante los alumnos que tienen asignado ese curso.</p>
  </div>
  <a class="btn secondary" href="../clases.php<?= $courseId ? '?course_id=' . $courseId : '' ?>"><?= aca_icon('eye') ?> Ver como alumno</a>
</div>

<?php if (!$courses): ?>
  <div class="panel empty-state">
    <strong>Primero creá un curso</strong>
    <p class="muted">Las clases se publican dentro de un curso.</p>
    <a class="btn primary" href="courses.php">Ir a Cursos</a>
  </div>
<?php else: ?>

<section class="panel" style="margin-bottom:1rem;display:flex;flex-wrap:wrap;align-items:flex-end;gap:1rem">
  <form method="get" style="flex:1;min-width:240px">
    <label>Curso
      <select name="course_id" onchange="this.form.submit()">
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $courseId ? 'selected' : '' ?>>
            <?= h($c['title']) ?><?= (int) $c['published'] ? '' : ' (dado de baja)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <p class="muted small" style="margin:0 0 .7rem;display:flex;align-items:center;gap:.4rem">
    <?= aca_icon('users') ?> <?= $students ?> alumno<?= $students === 1 ? '' : 's' ?> con acceso ·
    <a href="users.php" style="color:var(--accent);font-weight:500">asignar alumnos</a>
  </p>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2 class="panel-title">Nueva clase grabada</h2>
  <div class="tabs" role="tablist" style="margin-bottom:1rem">
    <button type="button" data-rec-tab="file" aria-selected="true"><?= aca_icon('upload') ?> Subir video</button>
    <button type="button" data-rec-tab="link" aria-selected="false"><?= aca_icon('link') ?> Link de YouTube o Drive</button>
  </div>

  <form method="post" class="stack" id="rec-file-form" data-rec-pane="file">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <label>Título de la clase <input name="title" required placeholder="Ej: Clase 3 · Hipopresivos de pie"></label>
    <label>Descripción (opcional) <textarea name="description" placeholder="Qué se trabaja en esta clase"></textarea></label>
    <label class="dropzone" data-dropzone>
      <input type="file" name="video" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.m4v,.webm" required>
      <span class="dz-ico"><?= aca_icon('upload') ?></span>
      <strong data-dz-title>Arrastrá el video acá o tocá para elegirlo</strong>
      <span data-dz-sub>MP4, MOV, M4V o WebM · sin límite de tamaño</span>
    </label>
    <p class="hint">
      Los videos pesados tardan: no cierres esta pestaña hasta que termine. Si el video es de iPhone (.MOV) y algún alumno no lo puede ver,
      exportalo como MP4 o subilo a YouTube como "No listado".
    </p>
    <div class="rec-progress upload-status" hidden>
      <div class="progress"><span style="width:0%"></span></div>
      <div class="upload-line"><strong data-rec-status></strong><span data-rec-eta></span></div>
    </div>
    <div class="actions">
      <button class="btn primary" type="submit"><?= aca_icon('upload') ?> Subir y publicar</button>
      <button class="btn secondary" type="button" data-rec-cancel hidden>Cancelar subida</button>
    </div>
  </form>

  <form method="post" class="stack" data-rec-pane="link" hidden>
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_link">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <label>Título de la clase <input name="title" required placeholder="Ej: Clase 3 · Hipopresivos de pie"></label>
    <label>Descripción (opcional) <textarea name="description" placeholder="Qué se trabaja en esta clase"></textarea></label>
    <label>Link del video
      <input name="url" type="url" required placeholder="https://youtu.be/… o https://drive.google.com/file/d/…">
    </label>
    <p class="muted small" style="margin:0">YouTube: subilo como "No listado". Drive: en Compartir elegí "Cualquier persona con el enlace".</p>
    <button class="btn primary" type="submit"><?= aca_icon('check') ?> Publicar clase</button>
  </form>
</section>

<section class="panel">
  <h2 class="panel-title">Clases publicadas (<?= count($recordings) ?>)</h2>
  <?php if (!$recordings): ?>
    <p class="muted">Este curso todavía no tiene clases grabadas.</p>
  <?php endif; ?>
  <div class="rec-admin-list">
  <?php foreach ($recordings as $r): ?>
    <?php
      $rid = (int) $r['id'];
      $full = trim((string) $r['file_path']) !== '' ? resolve_upload_path((string) $r['file_path']) : null;
      $thumb = recording_thumbnail($r);
    ?>
    <article class="rec-admin">
      <a class="rec-thumb" href="../resource.php?id=<?= $rid ?>"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>>
        <span class="rec-play"><?= aca_icon('play') ?></span>
      </a>
      <div>
        <h3><a href="../resource.php?id=<?= $rid ?>"><?= h($r['title']) ?></a></h3>
        <p class="muted small">
          <?php if ($full !== null): ?>
            <span class="badge soft">Video subido · <?= h(format_bytes((int) filesize($full))) ?></span>
          <?php elseif (trim((string) $r['file_path']) !== ''): ?>
            <span class="badge off">Archivo no encontrado</span>
          <?php else: ?>
            <span class="badge soft"><?= str_contains((string) $r['url'], 'drive.google') ? 'Google Drive' : 'YouTube' ?></span>
          <?php endif; ?>
          · Publicada el <?= h(format_db_time((string) $r['created_at'], 'd/m/Y')) ?>
        </p>
        <details style="margin-top:.5rem">
          <summary class="muted small">Editar título o descripción</summary>
          <form method="post" class="stack" style="margin-top:.5rem">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="resource_id" value="<?= $rid ?>">
            <label>Título <input name="title" required value="<?= h($r['title']) ?>"></label>
            <label>Descripción <textarea name="description"><?= h((string) $r['description']) ?></textarea></label>
            <button class="btn secondary small-btn" type="submit">Guardar</button>
          </form>
        </details>
      </div>
      <div class="actions">
        <a class="btn secondary small-btn" href="../resource.php?id=<?= $rid ?>"><?= aca_icon('eye') ?> Ver</a>
        <form method="post" onsubmit="return confirm('¿Eliminar esta clase? Si el video está subido, se borra del servidor.')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="resource_id" value="<?= $rid ?>">
          <button class="btn ghost small-btn" type="submit" title="Eliminar" aria-label="Eliminar"><?= aca_icon('trash') ?></button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>

<script>
(function () {
  var CHUNK = <?= RECORDING_CHUNK_BYTES ?>;
  var tabs = document.querySelectorAll('[data-rec-tab]');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        document.querySelector('[data-rec-pane="' + t.getAttribute('data-rec-tab') + '"]').hidden = !on;
      });
    });
  });

  var form = document.getElementById('rec-file-form');
  var box = form.querySelector('.rec-progress');
  var bar = box.querySelector('.progress > span');
  var status = box.querySelector('[data-rec-status]');
  var submit = form.querySelector('button[type="submit"]');
  var cancelBtn = form.querySelector('[data-rec-cancel]');
  var eta = box.querySelector('[data-rec-eta]');
  var token = null;
  var cancelled = false;
  var mb = function (b) { return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : Math.round(b / 1048576) + ' MB'; };

  var dz = form.querySelector('[data-dropzone]');
  var showFile = function () {
    var f = form.elements.video.files[0];
    dz.classList.toggle('has-file', !!f);
    dz.querySelector('[data-dz-title]').textContent = f ? f.name : 'Arrastrá el video acá o tocá para elegirlo';
    dz.querySelector('[data-dz-sub]').textContent = f ? mb(f.size) + ' · tocá para cambiarlo' : 'MP4, MOV, M4V o WebM · sin límite de tamaño';
    if (f && !form.elements.title.value.trim()) {
      form.elements.title.value = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ');
    }
  };
  form.elements.video.addEventListener('change', showFile);
  ['dragenter', 'dragover'].forEach(function (t) { dz.addEventListener(t, function () { dz.classList.add('is-over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { dz.addEventListener(t, function () { dz.classList.remove('is-over'); }); });

  function post(fields, file) {
    var fd = new FormData();
    fd.append('csrf', form.elements.csrf.value);
    fd.append('course_id', form.elements.course_id.value);
    fd.append('ajax', '1');
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    if (file) fd.append('chunk', file, 'chunk');
    return fetch('grabaciones.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'El servidor respondió con un error (' + r.status + ').' }; });
      });
  }

  function fail(msg) {
    status.textContent = msg;
    status.style.color = '#7a1f1f';
    eta.textContent = '';
    submit.disabled = false;
    cancelBtn.hidden = true;
    window.onbeforeunload = null;
  }

  async function sendChunk(file, offset) {
    var blob = file.slice(offset, Math.min(offset + CHUNK, file.size));
    for (var attempt = 1; attempt <= 5; attempt++) {
      try {
        var res = await post({ action: 'upload_chunk', token: token, offset: String(offset) }, blob);
        if (res.ok) return res.received;
        if (typeof res.received === 'number') return res.received;
        if (attempt === 5) throw new Error(res.error || 'Error al subir.');
      } catch (e) {
        if (attempt === 5) throw e;
      }
      status.textContent = 'Se cortó la conexión, reintentando (' + attempt + '/4)…';
      await new Promise(function (ok) { setTimeout(ok, attempt * 2000); });
    }
  }

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    var file = form.elements.video.files[0];
    if (!file || !form.elements.title.value.trim()) return;
    submit.disabled = true;
    cancelBtn.hidden = false;
    cancelled = false;
    box.hidden = false;
    status.style.color = '';
    bar.style.width = '0%';
    status.textContent = 'Preparando…';
    window.onbeforeunload = function () { return 'La subida todavía no terminó.'; };
    try {
      var start = await post({ action: 'upload_start', filename: file.name, size: String(file.size) });
      if (!start.ok) return fail(start.error);
      token = start.token;
      var offset = 0;
      var t0 = Date.now();
      while (offset < file.size) {
        if (cancelled) return;
        offset = await sendChunk(file, offset);
        var pct = Math.floor(offset / file.size * 100);
        bar.style.width = pct + '%';
        var secs = (Date.now() - t0) / 1000;
        var speed = secs > 0 ? offset / secs : 0;
        var left = speed > 0 ? (file.size - offset) / speed : 0;
        status.textContent = 'Subiendo ' + pct + '% · ' + mb(offset) + ' de ' + mb(file.size);
        eta.textContent = speed > 0 && offset < file.size
          ? (speed / 1048576).toFixed(1) + ' MB/s · ' + (left > 90 ? 'faltan ~' + Math.round(left / 60) + ' min' : 'falta menos de 2 min')
          : '';
      }
      eta.textContent = '';
      status.textContent = 'Publicando la clase…';
      var done = await post({ action: 'upload_finish', token: token, title: form.elements.title.value, description: form.elements.description.value });
      if (!done.ok) return fail(done.error);
      window.onbeforeunload = null;
      location.href = done.redirect;
    } catch (e) {
      fail((e && e.message ? e.message : 'Error de conexión.') + ' Probá de nuevo.');
    }
  });

  cancelBtn.addEventListener('click', function () {
    cancelled = true;
    if (token) post({ action: 'upload_cancel', token: token });
    fail('Subida cancelada.');
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
