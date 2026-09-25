<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('courses.php');
    }
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'create_course') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $meetUrl = trim((string) ($_POST['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($_POST['meet_schedule'] ?? ''));
        $slug = strtolower(trim(preg_replace('~[^a-zA-Z0-9]+~', '-', $title) ?? '', '-'));
        if ($title !== '') {
            $pdo->prepare('INSERT INTO courses (title, slug, category, description, meet_url, meet_schedule, sort_order, published) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                ->execute([$title, $slug . '-' . substr((string) time(), -4), $category, $description, $meetUrl, $meetSchedule, 99]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, ?, ?)')
                ->execute([$newId, 'Unidad 1', 10]);
            flash('success', 'Curso creado. Cargá las unidades y materiales en Campus virtual.');
            redirect('campus.php?course_id=' . $newId);
        }
    }

    if ($action === 'update_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $meetUrl = trim((string) ($_POST['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($_POST['meet_schedule'] ?? ''));
        if ($courseId > 0 && $title !== '') {
            $pdo->prepare('UPDATE courses SET title = ?, category = ?, description = ?, meet_url = ?, meet_schedule = ? WHERE id = ?')
                ->execute([$title, $category, $description, $meetUrl, $meetSchedule, $courseId]);
            flash('success', 'Curso actualizado.');
        }
    }

    if ($action === 'toggle_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $pdo->prepare('UPDATE courses SET published = CASE WHEN published = 1 THEN 0 ELSE 1 END WHERE id = ?')
                ->execute([$courseId]);
            flash('success', 'Estado del curso actualizado (activo / dado de baja).');
        }
    }

    if ($action === 'delete_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $files = $pdo->prepare('SELECT file_path FROM course_resources WHERE course_id = ?');
            $files->execute([$courseId]);
            foreach ($files->fetchAll() as $row) {
                delete_upload((string) ($row['file_path'] ?? ''));
            }
            $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
            flash('success', 'Curso eliminado.');
        }
    }

    if ($action === 'create_lesson') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $video = trim((string) ($_POST['video_url'] ?? ''));
        if ($courseId && $title !== '') {
            $pdo->prepare('INSERT INTO lessons (course_id, title, description, video_url, sort_order) VALUES (?, ?, ?, ?, ?)')
                ->execute([$courseId, $title, $description, $video, 99]);
            flash('success', 'Clase creada (también se refleja en el campus).');
        }
    }

    if ($action === 'create_task') {
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $instructions = trim((string) ($_POST['instructions'] ?? ''));
        if ($lessonId && $title !== '') {
            $pdo->prepare('INSERT INTO tasks (lesson_id, title, instructions, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$lessonId, $title, $instructions, 99]);
            flash('success', 'Tarea creada.');
        }
    }

    if ($action === 'delete_lesson') {
        $id = (int) ($_POST['lesson_id'] ?? 0);
        $pdo->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]);
        flash('success', 'Clase eliminada.');
    }

    redirect('courses.php');
}

$courses = db()->query('SELECT * FROM courses ORDER BY sort_order, title')->fetchAll();
$lessons = db()->query('SELECT l.*, c.title AS course_title FROM lessons l JOIN courses c ON c.id = l.course_id ORDER BY c.sort_order, l.sort_order, l.id')->fetchAll();

$pageTitle = 'Cursos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Cursos y clases</h1>
<p class="lede">Creá cursos, dalos de baja o eliminálos. El contenido de las unidades (PDF, videos, archivos) se carga en Campus virtual y sirve tanto para el campus como para cursos grabados de la tienda.</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="campus.php">Unidades y materiales</a>
  <a class="btn secondary" href="shop.php">Tienda</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
</nav>

<section class="panel" style="margin-bottom:1rem">
  <h2>Nuevo curso</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_course">
    <label>Título <input name="title" required placeholder="Ej: Chikung nivel 1"></label>
    <label>Categoría <input name="category" placeholder="Chikung / Hipopresivos / Taichi / Grabado..."></label>
    <label>Descripción <textarea name="description"></textarea></label>
    <label>Link Google Meet semanal <input name="meet_url" type="url" placeholder="https://meet.google.com/xxx-xxxx-xxx"></label>
    <label>Horario de la clase en vivo <input name="meet_schedule" placeholder="Ej: Jueves 19:00 hs"></label>
    <button class="btn primary" type="submit">Crear curso</button>
  </form>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2>Cursos existentes</h2>
  <?php if (!$courses): ?>
    <p class="muted">Todavía no hay cursos.</p>
  <?php else: ?>
    <?php foreach ($courses as $c): ?>
      <?php $cid = (int) $c['id']; $active = (int) ($c['published'] ?? 1) === 1; ?>
      <article style="margin:1.25rem 0;padding-top:1.25rem;border-top:1px solid var(--line)">
        <div class="actions" style="justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap">
          <div>
            <strong style="font-size:1.15rem"><?= h($c['title']) ?></strong>
            <?= $active ? '<span class="badge done">Activo</span>' : '<span class="badge">Dado de baja</span>' ?>
            <?php if ((int) ($c['shop_listed'] ?? 0)): ?>
              <span class="badge">En tienda</span>
            <?php endif; ?>
            <p class="muted small" style="margin:.25rem 0 0"><?= h($c['category'] ?: 'Sin categoría') ?></p>
          </div>
          <div class="actions">
            <a class="btn primary small-btn" href="campus.php?course_id=<?= $cid ?>">Unidades / materiales</a>
            <a class="btn secondary small-btn" href="../course.php?id=<?= $cid ?>" target="_blank">Ver campus</a>
          </div>
        </div>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_course">
          <input type="hidden" name="course_id" value="<?= $cid ?>">
          <div class="form-grid two">
            <label>Título <input name="title" required value="<?= h($c['title']) ?>"></label>
            <label>Categoría <input name="category" value="<?= h($c['category']) ?>"></label>
          </div>
          <label>Descripción <textarea name="description"><?= h($c['description']) ?></textarea></label>
          <div class="form-grid two">
            <label>Link Google Meet <input name="meet_url" type="url" value="<?= h((string) ($c['meet_url'] ?? '')) ?>"></label>
            <label>Horario semanal <input name="meet_schedule" value="<?= h((string) ($c['meet_schedule'] ?? '')) ?>"></label>
          </div>
          <div class="actions">
            <button class="btn secondary" type="submit">Guardar cambios</button>
          </div>
        </form>
        <div class="actions" style="margin-top:.75rem">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle_course">
            <input type="hidden" name="course_id" value="<?= $cid ?>">
            <button class="btn secondary" type="submit"><?= $active ? 'Dar de baja curso' : 'Reactivar curso' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('¿Eliminar este curso y todo su contenido de forma permanente?');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_course">
            <input type="hidden" name="course_id" value="<?= $cid ?>">
            <button class="btn danger" type="submit">Eliminar curso</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2>Nueva clase rápida (YouTube o Drive)</h2>
  <p class="muted small">También podés cargar videos y PDFs por unidad en <a href="campus.php">Campus virtual</a>.</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_lesson">
    <label>Curso
      <select name="course_id" required>
        <option value="">Elegí curso…</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= h($c['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Título de la clase <input name="title" required></label>
    <label>Descripción <textarea name="description"></textarea></label>
    <label>Link YouTube o Google Drive <input name="video_url" placeholder="https://youtube.com/... o https://drive.google.com/file/d/..."></label>
    <button class="btn primary" type="submit">Publicar clase</button>
  </form>
</section>

<section class="panel" style="margin-bottom:1rem">
  <h2>Nueva tarea</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_task">
    <label>Clase
      <select name="lesson_id" required>
        <option value="">Elegí clase…</option>
        <?php foreach ($lessons as $l): ?>
          <option value="<?= (int) $l['id'] ?>"><?= h($l['course_title'] . ' · ' . $l['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Título de la tarea <input name="title" required></label>
    <label>Instrucciones <textarea name="instructions" placeholder="Ej: Practica 10 minutos y comentá cómo te sentiste"></textarea></label>
    <button class="btn primary" type="submit">Crear tarea</button>
  </form>
</section>

<table class="table">
  <thead><tr><th>Curso</th><th>Clase</th><th>Video</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($lessons as $l): ?>
      <tr>
        <td><?= h($l['course_title']) ?></td>
        <td><?= h($l['title']) ?></td>
        <td class="small muted"><?= h(strlen((string) $l['video_url']) > 48 ? substr((string) $l['video_url'], 0, 45) . '…' : (string) $l['video_url']) ?></td>
        <td>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_lesson">
            <input type="hidden" name="lesson_id" value="<?= (int) $l['id'] ?>">
            <button class="btn danger" type="submit">Eliminar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/footer.php'; ?>
