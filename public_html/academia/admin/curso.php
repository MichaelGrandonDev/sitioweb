<?php

declare(strict_types=1);

/**
 * Hub de un curso en el panel (admin o profesor):
 * portada clara + botones a clases, materiales, datos y alumnos.
 */

require __DIR__ . '/../bootstrap.php';
$me = require_staff();
$pdo = db();
$isGeneral = is_full_admin($me);

$courseId = (int) ($_GET['id'] ?? $_GET['course_id'] ?? 0);
$courses = staff_courses($me);
$course = null;
foreach ($courses as $c) {
    if ((int) $c['id'] === $courseId) {
        $course = $c;
        break;
    }
}
if (!$course) {
    flash('error', 'Curso no encontrado o sin acceso.');
    redirect('courses.php');
}

$canContent = user_can_manage_course_content($me, $courseId);
$canInfo = user_can_edit_course_info($me, $courseId);
$canStudents = $isGeneral || user_has_permission($me, 'view_students');
if (!$canContent && !$canInfo && !$canStudents) {
    admin_forbidden('No tenés permiso para este curso.');
}

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'inicio')));
if (!in_array($tab, ['inicio', 'datos'], true)) {
    $tab = 'inicio';
}

// ——— Guardar datos del curso (misma lógica que courses.php) ———
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canInfo) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('curso.php?id=' . $courseId . '&tab=datos');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_course') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $teacherName = trim((string) ($_POST['teacher_name'] ?? ''));
        $teacherBio = trim((string) ($_POST['teacher_bio'] ?? ''));
        $meetUrl = trim((string) ($_POST['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($_POST['meet_schedule'] ?? ''));
        if ($title !== '') {
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
            flash($photoErrors ? 'error' : 'success', $photoErrors
                ? 'Datos guardados, pero alguna foto falló (' . implode('; ', $photoErrors) . ').'
                : 'Curso actualizado.');
            redirect('curso.php?id=' . $courseId . '&tab=datos');
        }
    }

    if ($isGeneral && $action === 'toggle_course') {
        $pdo->prepare('UPDATE courses SET published = CASE WHEN published = 1 THEN 0 ELSE 1 END WHERE id = ?')
            ->execute([$courseId]);
        flash('success', 'Estado del curso actualizado.');
        redirect('curso.php?id=' . $courseId);
    }
}

// Recargar curso tras posibles cambios
foreach (staff_courses($me) as $c) {
    if ((int) $c['id'] === $courseId) {
        $course = $c;
        break;
    }
}

$st = $pdo->prepare("SELECT COUNT(*) FROM course_resources WHERE course_id = ? AND type = 'video'");
$st->execute([$courseId]);
$videoCount = (int) $st->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM course_resources WHERE course_id = ?');
$st->execute([$courseId]);
$resourceCount = (int) $st->fetchColumn();
$st = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = ?');
$st->execute([$courseId]);
$studentCount = (int) $st->fetchColumn();

$coverUrl = course_cover_url($course);
$active = (int) ($course['published'] ?? 1) === 1;

$pageTitle = (string) $course['title'];
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<nav class="admin-nav" style="margin-bottom:1rem">
  <a class="btn secondary" href="courses.php">← Todos los cursos</a>
  <a class="btn secondary" href="index.php">Panel</a>
</nav>

<header class="staff-course-hero<?= $coverUrl !== '' ? ' has-cover' : '' ?>">
  <?php if ($coverUrl !== ''): ?>
    <img class="staff-course-hero-img" src="<?= h($coverUrl) ?>" alt="" width="1200" height="480" decoding="async">
  <?php endif; ?>
  <div class="staff-course-hero-body">
    <p class="eyebrow"><?= h((string) ($course['category'] ?: 'Curso')) ?></p>
    <h1><?= h((string) $course['title']) ?></h1>
    <p class="staff-course-meta">
      <?= $active ? '<span class="badge done">Activo</span>' : '<span class="badge">Dado de baja</span>' ?>
      <span class="muted small"><?= $videoCount ?> clase<?= $videoCount === 1 ? '' : 's' ?> · <?= $resourceCount ?> material<?= $resourceCount === 1 ? '' : 'es' ?> · <?= $studentCount ?> alumno<?= $studentCount === 1 ? '' : 's' ?></span>
    </p>
    <?php if (trim((string) ($course['description'] ?? '')) !== ''): ?>
      <p class="lede" style="margin:.5rem 0 0"><?= h(short_text((string) $course['description'], 180)) ?></p>
    <?php endif; ?>
  </div>
</header>

<div class="staff-hub-tabs" role="tablist">
  <a class="staff-hub-tab<?= $tab === 'inicio' ? ' is-on' : '' ?>" href="curso.php?id=<?= $courseId ?>">Inicio</a>
  <?php if ($canInfo): ?>
    <a class="staff-hub-tab<?= $tab === 'datos' ? ' is-on' : '' ?>" href="curso.php?id=<?= $courseId ?>&amp;tab=datos">Datos del curso</a>
  <?php endif; ?>
</div>

<?php if ($tab === 'inicio'): ?>
  <div class="staff-hub-grid">
    <?php if ($canContent): ?>
      <a class="staff-hub-btn is-primary" href="grabaciones.php?course_id=<?= $courseId ?>">
        <span class="stat-ico"><?= aca_icon('video') ?></span>
        <strong>Clases</strong>
        <span>Subir, ordenar y marcar bonus · <?= $videoCount ?> cargada<?= $videoCount === 1 ? '' : 's' ?></span>
      </a>
      <a class="staff-hub-btn" href="campus.php?course_id=<?= $courseId ?>">
        <span class="stat-ico"><?= aca_icon('layers') ?></span>
        <strong>Materiales</strong>
        <span>Unidades, PDFs y recursos · <?= $resourceCount ?> en total</span>
      </a>
    <?php endif; ?>
    <?php if ($canInfo): ?>
      <a class="staff-hub-btn" href="curso.php?id=<?= $courseId ?>&amp;tab=datos">
        <span class="stat-ico"><?= aca_icon('pen') ?></span>
        <strong>Datos y portada</strong>
        <span>Título, descripción, foto, Meet y profesor</span>
      </a>
    <?php endif; ?>
    <?php if ($canStudents): ?>
      <a class="staff-hub-btn" href="<?= $isGeneral ? 'users.php' : 'estudiantes.php' ?>">
        <span class="stat-ico"><?= aca_icon('users') ?></span>
        <strong>Alumnos</strong>
        <span><?= $studentCount ?> inscrito<?= $studentCount === 1 ? '' : 's' ?> en este curso</span>
      </a>
    <?php endif; ?>
    <a class="staff-hub-btn" href="../course.php?id=<?= $courseId ?>" target="_blank" rel="noopener">
      <span class="stat-ico"><?= aca_icon('eye') ?></span>
      <strong>Ver como alumno</strong>
      <span>Abrir el campus del curso</span>
    </a>
  </div>
<?php else: ?>
  <section class="panel">
    <h2>Editar curso</h2>
    <form method="post" class="stack" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_course">
      <div class="form-grid two">
        <label>Título <input name="title" required value="<?= h((string) $course['title']) ?>"></label>
        <label>Categoría <input name="category" value="<?= h((string) ($course['category'] ?? '')) ?>"></label>
      </div>
      <label>Descripción (la ve el alumno) <textarea name="description" rows="5"><?= h((string) ($course['description'] ?? '')) ?></textarea></label>
      <div class="cover-field">
        <?php if ($coverUrl !== ''): ?>
          <img class="cover-preview" src="<?= h($coverUrl) ?>" alt="" width="160" height="90" loading="lazy">
        <?php endif; ?>
        <div class="stack">
          <label><?= $coverUrl !== '' ? 'Cambiar foto del curso' : 'Foto del curso' ?> <input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
          <?php if ($coverUrl !== ''): ?>
            <label class="check"><input type="checkbox" name="remove_cover" value="1"> Quitar la foto</label>
          <?php endif; ?>
        </div>
      </div>
      <fieldset class="teacher-fieldset">
        <legend>Profesor del curso</legend>
        <label>Nombre del profesor <input name="teacher_name" value="<?= h((string) ($course['teacher_name'] ?? '')) ?>"></label>
        <label>Breve descripción <textarea name="teacher_bio" rows="3"><?= h((string) ($course['teacher_bio'] ?? '')) ?></textarea></label>
        <?php $teacherUrl = course_teacher_url($course); ?>
        <div class="cover-field">
          <?php if ($teacherUrl !== ''): ?>
            <img class="teacher-preview" src="<?= h($teacherUrl) ?>" alt="" width="72" height="72" loading="lazy">
          <?php endif; ?>
          <div class="stack">
            <label><?= $teacherUrl !== '' ? 'Cambiar foto del profesor' : 'Foto del profesor' ?> <input type="file" name="teacher_photo" accept="image/jpeg,image/png,image/webp"></label>
            <?php if ($teacherUrl !== ''): ?>
              <label class="check"><input type="checkbox" name="remove_teacher_photo" value="1"> Quitar la foto del profesor</label>
            <?php endif; ?>
          </div>
        </div>
      </fieldset>
      <div class="form-grid two">
        <label>Link Google Meet <input name="meet_url" type="url" value="<?= h((string) ($course['meet_url'] ?? '')) ?>"></label>
        <label>Horario semanal <input name="meet_schedule" value="<?= h((string) ($course['meet_schedule'] ?? '')) ?>"></label>
      </div>
      <div class="actions">
        <button class="btn primary" type="submit">Guardar cambios</button>
        <a class="btn secondary" href="curso.php?id=<?= $courseId ?>">Volver al curso</a>
      </div>
    </form>
    <?php if ($isGeneral): ?>
      <div class="actions" style="margin-top:1rem;flex-wrap:wrap">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="toggle_course">
          <button class="btn secondary" type="submit"><?= $active ? 'Dar de baja curso' : 'Reactivar curso' ?></button>
        </form>
        <form method="post" action="courses.php" onsubmit="return confirm('¿Eliminar este curso y todo su contenido de forma permanente?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_course">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <button class="btn danger" type="submit">Eliminar curso</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
