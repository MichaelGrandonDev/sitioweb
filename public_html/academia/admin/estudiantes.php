<?php

declare(strict_types=1);

/**
 * Lista de solo lectura: alumnos inscritos en los cursos del profesor.
 */

require __DIR__ . '/../bootstrap.php';
$me = require_permission('view_students');

if (is_full_admin($me)) {
    redirect('users.php');
}

$courseIds = teacher_course_ids($me);
$students = [];
$coursesByUser = [];

if ($courseIds !== []) {
    $ph = implode(',', array_fill(0, count($courseIds), '?'));
    $stmt = db()->prepare("
      SELECT DISTINCT u.id, u.name, u.email, u.active
      FROM users u
      JOIN enrollments e ON e.user_id = u.id
      WHERE u.role = 'student' AND e.course_id IN ($ph)
      ORDER BY u.name
    ");
    $stmt->execute($courseIds);
    $students = $stmt->fetchAll();

    $cst = db()->prepare("
      SELECT e.user_id, c.title
      FROM enrollments e
      JOIN courses c ON c.id = e.course_id
      WHERE e.course_id IN ($ph)
      ORDER BY c.sort_order, c.title
    ");
    $cst->execute($courseIds);
    foreach ($cst->fetchAll() as $row) {
        $coursesByUser[(int) $row['user_id']][] = (string) $row['title'];
    }
}

$pageTitle = 'Alumnos de mis cursos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Profesor</p>
<h1>Alumnos de tus cursos</h1>
<p class="lede">Solo lectura: nombre, email y en qué curso están. No podés crear ni inscribir alumnos (eso lo hace el administrador general).</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
</nav>

<section class="panel">
  <?php if ($courseIds === []): ?>
    <p class="muted">Todavía no tenés cursos asignados.</p>
  <?php elseif (!$students): ?>
    <p class="muted">Todavía no hay alumnos inscritos en tus cursos.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Alumno</th>
          <th>Email</th>
          <th>Estado</th>
          <th>Cursos (tuyos)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><?= h($s['name']) ?></td>
            <td><?= h((string) $s['email']) ?></td>
            <td><?= (int) $s['active'] ? '<span class="badge done">Activo</span>' : '<span class="badge">Baja</span>' ?></td>
            <td class="small"><?= h(implode(' · ', $coursesByUser[(int) $s['id']] ?? [])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
