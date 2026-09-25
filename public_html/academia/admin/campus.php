<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();

$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$courses = db()->query('SELECT * FROM courses ORDER BY sort_order, title')->fetchAll();
if ($courseId <= 0 && $courses) {
    $courseId = (int) $courses[0]['id'];
}

$course = null;
foreach ($courses as $c) {
    if ((int) $c['id'] === $courseId) {
        $course = $c;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('campus.php?course_id=' . $courseId);
    }
    $action = (string) ($_POST['action'] ?? '');
    $courseId = (int) ($_POST['course_id'] ?? $courseId);

    if ($action === 'add_section') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '' && $courseId > 0) {
            db()->prepare('INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, ?, ?)')
                ->execute([$courseId, $title, 50]);
            flash('success', 'Unidad programática creada.');
        }
    }

    if ($action === 'rename_section') {
        $id = (int) ($_POST['section_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($id > 0 && $title !== '' && $courseId > 0) {
            db()->prepare('UPDATE course_sections SET title = ? WHERE id = ? AND course_id = ?')
                ->execute([$title, $id, $courseId]);
            flash('success', 'Unidad actualizada.');
        }
    }

    if ($action === 'delete_section') {
        $id = (int) ($_POST['section_id'] ?? 0);
        $files = db()->prepare('SELECT file_path FROM course_resources WHERE section_id = ? AND course_id = ?');
        $files->execute([$id, $courseId]);
        foreach ($files->fetchAll() as $row) {
            delete_upload((string) ($row['file_path'] ?? ''));
        }
        db()->prepare('DELETE FROM course_sections WHERE id = ? AND course_id = ?')->execute([$id, $courseId]);
        flash('success', 'Unidad eliminada.');
    }

    if ($action === 'add_resource') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $type = (string) ($_POST['type'] ?? 'link');
        $allowed = ['video', 'pdf', 'file', 'link', 'page', 'forum', 'folder', 'meet'];
        if (!in_array($type, $allowed, true)) {
            $type = 'link';
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $track = isset($_POST['track_completion']) ? 1 : 0;
        $filePath = '';

        $upload = store_course_upload($courseId, $_FILES['upload'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!empty($upload['error'])) {
            flash('error', (string) $upload['error']);
            redirect('campus.php?course_id=' . $courseId);
        }
        if (!empty($upload['ok']) && !empty($upload['path'])) {
            $filePath = (string) $upload['path'];
            if ($type === 'link' || $type === 'folder' || $type === 'meet' || $type === 'page' || $type === 'forum') {
                $type = guess_resource_type_from_upload($filePath, 'file');
            } elseif ($type === 'video' || $type === 'pdf') {
                // keep chosen type if sensible
                $guessed = guess_resource_type_from_upload($filePath, $type);
                if ($type === 'pdf' && $guessed !== 'pdf') {
                    $type = $guessed;
                }
                if ($type === 'video' && $guessed !== 'video' && $url === '') {
                    $type = $guessed;
                }
            }
            if ($title === '' && !empty($upload['name'])) {
                $title = (string) pathinfo((string) $upload['name'], PATHINFO_FILENAME);
            }
        }

        if ($title !== '' && $sectionId > 0 && $courseId > 0) {
            db()->prepare("
              INSERT INTO course_resources
                (course_id, section_id, type, title, description, url, content, file_path, track_completion, sort_order)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$courseId, $sectionId, $type, $title, $description, $url, $content, $filePath, $track, 99]);
            flash('success', 'Contenido publicado en la unidad. Visible en campus y, si el curso está en tienda, en cursos grabados.');
        } elseif ($title === '') {
            flash('error', 'Indicá un título o subí un archivo.');
        }
    }

    if ($action === 'delete_resource') {
        $id = (int) ($_POST['resource_id'] ?? 0);
        $row = db()->prepare('SELECT file_path FROM course_resources WHERE id = ? AND course_id = ?');
        $row->execute([$id, $courseId]);
        $res = $row->fetch();
        if ($res) {
            delete_upload((string) ($res['file_path'] ?? ''));
        }
        db()->prepare('DELETE FROM course_resources WHERE id = ? AND course_id = ?')->execute([$id, $courseId]);
        flash('success', 'Recurso eliminado.');
    }

    if ($action === 'add_announcement') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title !== '' && $courseId > 0) {
            db()->prepare('INSERT INTO course_announcements (course_id, title, body) VALUES (?, ?, ?)')
                ->execute([$courseId, $title, $body]);
            flash('success', 'Aviso publicado.');
        }
    }

    if ($action === 'delete_announcement') {
        $id = (int) ($_POST['announcement_id'] ?? 0);
        db()->prepare('DELETE FROM course_announcements WHERE id = ? AND course_id = ?')->execute([$id, $courseId]);
        flash('success', 'Aviso eliminado.');
    }

    if ($action === 'add_event') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $starts = trim((string) ($_POST['starts_at'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        if ($title !== '' && $starts !== '' && $courseId > 0) {
            $startsAt = str_replace('T', ' ', $starts);
            if (strlen($startsAt) === 16) {
                $startsAt .= ':00';
            }
            db()->prepare('INSERT INTO course_events (course_id, title, starts_at, location, url) VALUES (?, ?, ?, ?, ?)')
                ->execute([$courseId, $title, $startsAt, $location, $url]);
            flash('success', 'Evento creado.');
        }
    }

    if ($action === 'delete_event') {
        $id = (int) ($_POST['event_id'] ?? 0);
        db()->prepare('DELETE FROM course_events WHERE id = ? AND course_id = ?')->execute([$id, $courseId]);
        flash('success', 'Evento eliminado.');
    }

    redirect('campus.php?course_id=' . $courseId);
}

$sections = [];
$resources = [];
$announcements = [];
$events = [];
if ($course) {
    $s = db()->prepare('SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id');
    $s->execute([$courseId]);
    $sections = $s->fetchAll();

    $r = db()->prepare('SELECT * FROM course_resources WHERE course_id = ? ORDER BY sort_order, id');
    $r->execute([$courseId]);
    $resources = $r->fetchAll();

    $a = db()->prepare('SELECT * FROM course_announcements WHERE course_id = ? ORDER BY id DESC');
    $a->execute([$courseId]);
    $announcements = $a->fetchAll();

    $e = db()->prepare('SELECT * FROM course_events WHERE course_id = ? ORDER BY datetime(starts_at) ASC');
    $e->execute([$courseId]);
    $events = $e->fetchAll();
}

$pageTitle = 'Unidades y materiales';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Unidades programáticas y materiales</h1>
<p class="lede">
  Armá las unidades del curso y subí PDFs, videos, documentos o enlaces.
  El mismo contenido alimenta el campus de AcademiaFluxus y los cursos grabados de la tienda.
</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="courses.php">Cursos</a>
  <a class="btn secondary" href="shop.php">Tienda</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
  <?php if ($course): ?>
    <a class="btn primary" href="../course.php?id=<?= $courseId ?>" target="_blank">Ver campus alumno</a>
  <?php endif; ?>
</nav>

<section class="panel" style="margin:1rem 0">
  <form method="get" class="stack">
    <label>Curso a editar
      <select name="course_id" onchange="this.form.submit()">
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $courseId ? 'selected' : '' ?>>
            <?= h($c['title']) ?><?= (int) ($c['published'] ?? 1) ? '' : ' (baja)' ?>
            <?= (int) ($c['shop_listed'] ?? 0) ? ' · tienda' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</section>

<?php if (!$course): ?>
  <div class="panel"><p class="muted">Creá un curso primero en Cursos.</p></div>
<?php else: ?>

<section class="panel" style="margin-bottom:1rem">
  <h2>Nueva unidad programática</h2>
  <p class="muted small">Ej: Unidad 1 · Fundamentos · Grabaciones · Material PDF · Evaluación</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_section">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <label>Nombre de la unidad <input name="title" required placeholder="Unidad 2 · Respiración"></label>
    <button class="btn primary" type="submit">Crear unidad</button>
  </form>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2>Subir / publicar contenido</h2>
  <form method="post" class="stack" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_resource">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <label>Unidad
      <select name="section_id" required>
        <option value="">Elegí…</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= (int) $s['id'] ?>"><?= h($s['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Tipo
      <select name="type">
        <option value="pdf">PDF / apunte</option>
        <option value="video">Video (archivo o YouTube/Drive)</option>
        <option value="file">Archivo (Word, PowerPoint, imagen, audio, ZIP)</option>
        <option value="link">Enlace externo</option>
        <option value="page">Página de texto</option>
        <option value="folder">Carpeta (Drive)</option>
        <option value="meet">Clase Meet</option>
        <option value="forum">Foro / avisos</option>
      </select>
    </label>
    <label>Título <input name="title" placeholder="Si subís un archivo, puede quedar el nombre del archivo"></label>
    <label>Descripción <textarea name="description"></textarea></label>
    <label>Subir archivo (hasta 45 MB)
      <input type="file" name="upload" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.gif,.mp4,.webm,.mp3,.m4a,.txt,.zip">
    </label>
    <p class="muted small">Para videos largos conviene YouTube o Drive y pegar el link abajo.</p>
    <label>URL (video, PDF, Meet, Drive…) <input name="url" placeholder="https://…"></label>
    <label>Contenido (páginas / foros) <textarea name="content"></textarea></label>
    <label class="check"><input type="checkbox" name="track_completion" value="1" checked> Contar en el progreso del alumno</label>
    <button class="btn primary" type="submit">Publicar en la unidad</button>
  </form>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2>Estructura del curso</h2>
  <?php if (!$sections): ?>
    <p class="muted">Todavía no hay unidades. Creá la primera arriba.</p>
  <?php endif; ?>
  <?php foreach ($sections as $s): ?>
    <div style="margin:1rem 0;padding-top:1rem;border-top:1px solid var(--line)">
      <div class="actions" style="justify-content:space-between;margin-bottom:.5rem;flex-wrap:wrap">
        <form method="post" class="actions" style="flex:1;gap:.5rem;align-items:center">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="rename_section">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>">
          <input name="title" value="<?= h($s['title']) ?>" required style="min-width:12rem;flex:1">
          <button class="btn secondary small-btn" type="submit">Renombrar</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_section">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="section_id" value="<?= (int) $s['id'] ?>">
          <button class="btn danger small-btn" type="submit" onclick="return confirm('¿Borrar unidad y sus materiales?')">Borrar unidad</button>
        </form>
      </div>
      <?php
        $sectionResources = array_filter($resources, static fn ($r) => (int) $r['section_id'] === (int) $s['id']);
      ?>
      <?php if (!$sectionResources): ?>
        <p class="muted small">Sin contenidos todavía.</p>
      <?php endif; ?>
      <?php foreach ($sectionResources as $r): ?>
        <div class="lesson-card" style="margin-bottom:.5rem">
          <div>
            <h3 style="font-size:1.05rem;margin:0">
              <?= h($r['title']) ?>
              <span class="badge"><?= h(resource_type_meta((string) $r['type'])['label']) ?></span>
              <?php if (trim((string) ($r['file_path'] ?? '')) !== ''): ?>
                <span class="badge done">Archivo</span>
              <?php endif; ?>
            </h3>
            <p class="muted small" style="margin:.25rem 0 0">
              <?php if (trim((string) ($r['file_path'] ?? '')) !== ''): ?>
                <?= h(basename((string) $r['file_path'])) ?>
              <?php else: ?>
                <?= h($r['url'] ?: $r['description'] ?: '—') ?>
              <?php endif; ?>
            </p>
          </div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_resource">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="resource_id" value="<?= (int) $r['id'] ?>">
            <button class="btn danger small-btn" type="submit">Eliminar</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2>Avisos (columna derecha)</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_announcement">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <label>Título <input name="title" required placeholder="CERTAMEN II"></label>
    <label>Texto <textarea name="body"></textarea></label>
    <button class="btn primary" type="submit">Publicar aviso</button>
  </form>
  <ul class="list" style="margin-top:1rem">
    <?php foreach ($announcements as $a): ?>
      <li class="lesson-card">
        <div>
          <strong><?= h($a['title']) ?></strong>
          <p class="muted small"><?= h($a['body']) ?></p>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_announcement">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
          <button class="btn danger" type="submit">Borrar</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="panel">
  <h2>Eventos / calendario</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_event">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <label>Título <input name="title" required placeholder="QIGONG MÉDICO"></label>
    <label>Fecha y hora <input type="datetime-local" name="starts_at" required></label>
    <label>Lugar / nota <input name="location" placeholder="Online · Meet"></label>
    <label>Link (Meet u otro) <input name="url" type="url"></label>
    <button class="btn primary" type="submit">Crear evento</button>
  </form>
  <ul class="list" style="margin-top:1rem">
    <?php foreach ($events as $ev): ?>
      <li class="lesson-card">
        <div>
          <strong><?= h($ev['title']) ?></strong>
          <p class="muted small"><?= h($ev['starts_at']) ?> · <?= h($ev['location']) ?></p>
        </div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_event">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
          <button class="btn danger" type="submit">Borrar</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
