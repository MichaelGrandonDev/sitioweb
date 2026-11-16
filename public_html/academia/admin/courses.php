<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$me = require_staff();
$isGeneral = is_full_admin($me);
if (!$isGeneral && !user_has_permission($me, 'edit_course_info') && !user_has_permission($me, 'manage_course_content')) {
    admin_forbidden('No tenés permiso para ver los cursos.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('courses.php');
    }
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'create_course') {
        if (!$isGeneral) {
            admin_forbidden('Solo el administrador general puede crear cursos.');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $teacherName = trim((string) ($_POST['teacher_name'] ?? ''));
        $teacherBio = trim((string) ($_POST['teacher_bio'] ?? ''));
        $meetUrl = trim((string) ($_POST['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($_POST['meet_schedule'] ?? ''));
        $slug = strtolower(trim(preg_replace('~[^a-zA-Z0-9]+~', '-', $title) ?? '', '-'));
        if ($title !== '') {
            $pdo->prepare('INSERT INTO courses (title, slug, category, description, teacher_name, teacher_bio, meet_url, meet_schedule, sort_order, published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
                ->execute([$title, $slug . '-' . substr((string) time(), -4), $category, $description, $teacherName, $teacherBio, $meetUrl, $meetSchedule, 99]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, ?, ?)')
                ->execute([$newId, 'Unidad 1', 10]);
            $cover = store_course_cover($newId, $_FILES['cover'] ?? []);
            if ($cover['ok']) {
                $pdo->prepare('UPDATE courses SET cover_path = ? WHERE id = ?')->execute([$cover['path'], $newId]);
            }
            $teacher = store_course_teacher($newId, $_FILES['teacher_photo'] ?? []);
            if ($teacher['ok']) {
                $pdo->prepare('UPDATE courses SET teacher_photo_path = ? WHERE id = ?')->execute([$teacher['path'], $newId]);
            }
            $photoErrors = array_filter([
                ($cover['error'] ?? '') !== '' ? 'portada: ' . $cover['error'] : '',
                ($teacher['error'] ?? '') !== '' ? 'profesor: ' . $teacher['error'] : '',
            ]);
            if ($photoErrors) {
                flash('error', 'Curso creado, pero alguna foto no se guardó (' . implode('; ', $photoErrors) . ').');
            } else {
                flash('success', 'Curso creado. Entrá al hub y cargá clases y materiales.');
            }
            redirect('curso.php?id=' . $newId);
        }
    }

    if ($action === 'update_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if (!user_can_edit_course_info($me, $courseId)) {
            admin_forbidden('No podés editar este curso.');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $teacherName = trim((string) ($_POST['teacher_name'] ?? ''));
        $teacherBio = trim((string) ($_POST['teacher_bio'] ?? ''));
        $meetUrl = trim((string) ($_POST['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($_POST['meet_schedule'] ?? ''));
        if ($courseId > 0 && $title !== '') {
            $pdo->prepare('UPDATE courses SET title = ?, category = ?, description = ?, teacher_name = ?, teacher_bio = ?, meet_url = ?, meet_schedule = ? WHERE id = ?')
                ->execute([$title, $category, $description, $teacherName, $teacherBio, $meetUrl, $meetSchedule, $courseId]);

            $old = $pdo->prepare('SELECT cover_path, teacher_photo_path FROM courses WHERE id = ?');
            $old->execute([$courseId]);
            $oldRow = $old->fetch() ?: [];
            $oldCover = (string) ($oldRow['cover_path'] ?? '');
            $oldTeacher = (string) ($oldRow['teacher_photo_path'] ?? '');

            $cover = store_course_cover($courseId, $_FILES['cover'] ?? []);
            if ($cover['ok']) {
                $pdo->prepare('UPDATE courses SET cover_path = ? WHERE id = ?')->execute([$cover['path'], $courseId]);
                delete_upload($oldCover);
            } elseif (!empty($_POST['remove_cover']) && $oldCover !== '') {
                $pdo->prepare("UPDATE courses SET cover_path = '' WHERE id = ?")->execute([$courseId]);
                delete_upload($oldCover);
            }

            $teacher = store_course_teacher($courseId, $_FILES['teacher_photo'] ?? []);
            if ($teacher['ok']) {
                $pdo->prepare('UPDATE courses SET teacher_photo_path = ? WHERE id = ?')->execute([$teacher['path'], $courseId]);
                delete_upload($oldTeacher);
            } elseif (!empty($_POST['remove_teacher_photo']) && $oldTeacher !== '') {
                $pdo->prepare("UPDATE courses SET teacher_photo_path = '' WHERE id = ?")->execute([$courseId]);
                delete_upload($oldTeacher);
            }

            $photoErrors = array_filter([
                ($cover['error'] ?? '') !== '' ? 'portada: ' . $cover['error'] : '',
                ($teacher['error'] ?? '') !== '' ? 'profesor: ' . $teacher['error'] : '',
            ]);
            if ($photoErrors) {
                flash('error', 'Datos guardados, pero alguna foto no se cambió (' . implode('; ', $photoErrors) . ').');
            } else {
                flash('success', 'Curso actualizado.');
            }
        }
    }

    if ($action === 'toggle_course') {
        if (!$isGeneral) {
            admin_forbidden('Solo el administrador general puede dar de baja cursos.');
        }
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $pdo->prepare('UPDATE courses SET published = CASE WHEN published = 1 THEN 0 ELSE 1 END WHERE id = ?')
                ->execute([$courseId]);
            flash('success', 'Estado del curso actualizado (activo / dado de baja).');
        }
    }

    if ($action === 'delete_course') {
        if (!$isGeneral) {
            admin_forbidden('Solo el administrador general puede eliminar cursos.');
        }
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $files = $pdo->prepare('SELECT file_path FROM course_resources WHERE course_id = ?');
            $files->execute([$courseId]);
            foreach ($files->fetchAll() as $row) {
                delete_upload((string) ($row['file_path'] ?? ''));
            }
            $paths = $pdo->prepare('SELECT cover_path, teacher_photo_path FROM courses WHERE id = ?');
            $paths->execute([$courseId]);
            $row = $paths->fetch() ?: [];
            delete_upload((string) ($row['cover_path'] ?? ''));
            delete_upload((string) ($row['teacher_photo_path'] ?? ''));
            $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
            flash('success', 'Curso eliminado.');
        }
    }

    if ($action === 'create_lesson') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if (!user_can_manage_course_content($me, $courseId)) {
            admin_forbidden('No podés agregar clases a este curso.');
        }
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
        if (!$isGeneral) {
            admin_forbidden();
        }
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
        $row = $pdo->prepare('SELECT course_id FROM lessons WHERE id = ?');
        $row->execute([$id]);
        $lessonCourse = (int) ($row->fetchColumn() ?: 0);
        if (!user_can_manage_course_content($me, $lessonCourse)) {
            admin_forbidden();
        }
        $pdo->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]);
        flash('success', 'Clase eliminada.');
    }

    redirect('courses.php');
}

$courses = staff_courses($me);
$allowedIds = staff_course_ids($me);
if ($allowedIds === []) {
    $lessons = [];
} else {
    $ph = implode(',', array_fill(0, count($allowedIds), '?'));
    $ls = db()->prepare("SELECT l.*, c.title AS course_title FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.course_id IN ($ph) ORDER BY c.sort_order, l.sort_order, l.id");
    $ls->execute($allowedIds);
    $lessons = $ls->fetchAll();
}

$pageTitle = $isGeneral ? 'Cursos' : 'Mis cursos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= $isGeneral ? 'Administración' : 'Portal profesores' ?></p>
    <h1><?= $isGeneral ? 'Cursos' : 'Mis cursos' ?></h1>
    <p class="lede">Elegí un curso para ver clases, materiales y datos. Cada tarjeta abre el panel de ese curso.</p>
  </div>
</div>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
</nav>

<?php if ($isGeneral): ?>
<section class="panel" style="margin-bottom:1rem">
  <h2>Nuevo curso</h2>
  <form method="post" class="stack" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_course">
    <label>Título <input name="title" required placeholder="Ej: Chikung nivel 1"></label>
    <label>Categoría <input name="category" placeholder="Chikung / Hipopresivos / Taichi / Grabado..."></label>
    <label>Descripción (la ve el alumno) <textarea name="description" rows="5" placeholder="De qué trata el curso, a quién está dirigido, qué va a aprender…"></textarea></label>
    <label>Foto del curso <input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
    <fieldset class="teacher-fieldset">
      <legend>Profesor del curso</legend>
      <p class="muted small" style="margin:0 0 .6rem">Opcional. Si lo cargás, el alumno lo ve en el campus.</p>
      <label>Nombre del profesor <input name="teacher_name" placeholder="Ej: Ana Pérez"></label>
      <label>Breve descripción <textarea name="teacher_bio" rows="3" placeholder="Formación, estilo de enseñanza, una frase de presentación…"></textarea></label>
      <label>Foto del profesor <input type="file" name="teacher_photo" accept="image/jpeg,image/png,image/webp"></label>
    </fieldset>
    <label>Link Google Meet semanal <input name="meet_url" type="url" placeholder="https://meet.google.com/xxx-xxxx-xxx"></label>
    <label>Horario de la clase en vivo <input name="meet_schedule" placeholder="Ej: Jueves 19:00 hs"></label>
    <button class="btn primary" type="submit">Crear curso</button>
  </form>
</section>
<?php endif; ?>

<section style="margin-bottom:1.5rem">
  <h2 class="section-title" style="margin-bottom:.85rem"><?= $isGeneral ? 'Todos los cursos' : 'Cursos asignados' ?></h2>
  <?php if (!$courses): ?>
    <div class="panel empty-state">
      <strong><?= $isGeneral ? 'Todavía no hay cursos' : 'Todavía no tenés cursos asignados' ?></strong>
      <p class="muted"><?= $isGeneral ? 'Creá el primero con el formulario de arriba.' : 'Pedile al administrador general que te asigne uno.' ?></p>
    </div>
  <?php else: ?>
    <div class="staff-course-grid">
      <?php foreach ($courses as $c): ?>
        <?php
          $cid = (int) $c['id'];
          $active = (int) ($c['published'] ?? 1) === 1;
          $coverUrl = course_cover_url($c);
        ?>
        <a class="staff-course-tile<?= $coverUrl !== '' ? ' has-cover' : '' ?><?= $active ? '' : ' is-off' ?>" href="curso.php?id=<?= $cid ?>">
          <span class="staff-course-tile-media">
            <?php if ($coverUrl !== ''): ?>
              <img src="<?= h($coverUrl) ?>" alt="" width="640" height="360" loading="lazy" decoding="async">
            <?php else: ?>
              <span class="staff-course-tile-placeholder"><?= aca_icon('book') ?></span>
            <?php endif; ?>
          </span>
          <span class="staff-course-tile-body">
            <span class="cat"><?= h((string) ($c['category'] ?: 'Curso')) ?></span>
            <strong><?= h((string) $c['title']) ?></strong>
            <span class="staff-course-tile-foot">
              <?= $active ? '<span class="badge done">Activo</span>' : '<span class="badge">Baja</span>' ?>
              <em>Abrir →</em>
            </span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
