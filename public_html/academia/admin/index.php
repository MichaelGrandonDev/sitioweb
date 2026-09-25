<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$user = require_admin();
$pdo = db();

$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
$activeStudents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND active=1")->fetchColumn();
$inactiveStudents = $totalStudents - $activeStudents;
$totalCourses = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$totalLessons = (int) $pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
$totalTasks = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
$completedLessons = (int) $pdo->query('SELECT COUNT(*) FROM lesson_progress')->fetchColumn();
$completedTasks = (int) $pdo->query('SELECT COUNT(*) FROM task_completions')->fetchColumn();

// Expected lesson completions = sum over enrollments of lessons in that course
$expectedLessons = (int) $pdo->query("
  SELECT COUNT(*)
  FROM enrollments e
  JOIN lessons l ON l.course_id = e.course_id
  JOIN users u ON u.id = e.user_id
  WHERE u.role = 'student' AND u.active = 1
")->fetchColumn();

$overallLessonPct = $expectedLessons > 0
    ? (int) round(($completedLessons / $expectedLessons) * 100)
    : 0;

$expectedTasks = (int) $pdo->query("
  SELECT COUNT(*)
  FROM enrollments e
  JOIN lessons l ON l.course_id = e.course_id
  JOIN tasks t ON t.lesson_id = l.id
  JOIN users u ON u.id = e.user_id
  WHERE u.role = 'student' AND u.active = 1
")->fetchColumn();

$overallTaskPct = $expectedTasks > 0
    ? (int) round(($completedTasks / $expectedTasks) * 100)
    : 0;

$courseMetrics = $pdo->query("
  SELECT
    c.id,
    c.title,
    c.category,
    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students,
    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons,
    (
      SELECT COUNT(*)
      FROM lesson_progress lp
      JOIN lessons l2 ON l2.id = lp.lesson_id
      JOIN users u ON u.id = lp.user_id
      WHERE l2.course_id = c.id AND u.role='student'
    ) AS done_lessons,
    (
      SELECT COUNT(*)
      FROM enrollments e2
      JOIN lessons l3 ON l3.course_id = e2.course_id
      JOIN users u2 ON u2.id = e2.user_id
      WHERE e2.course_id = c.id AND u2.role='student' AND u2.active=1
    ) AS expected
  FROM courses c
  ORDER BY c.sort_order, c.title
")->fetchAll();

$studentMetrics = $pdo->query("
  SELECT
    u.id,
    u.name,
    u.username,
    u.active,
    (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) AS courses,
    (
      SELECT COUNT(*)
      FROM enrollments e
      JOIN lessons l ON l.course_id = e.course_id
      WHERE e.user_id = u.id
    ) AS total_lessons,
    (
      SELECT COUNT(*)
      FROM lesson_progress lp
      JOIN lessons l ON l.id = lp.lesson_id
      JOIN enrollments e ON e.course_id = l.course_id AND e.user_id = u.id
      WHERE lp.user_id = u.id
    ) AS done_lessons,
    (
      SELECT COUNT(*)
      FROM task_completions tc
      WHERE tc.user_id = u.id
    ) AS done_tasks
  FROM users u
  WHERE u.role = 'student'
  ORDER BY u.name
")->fetchAll();

$pageTitle = 'Panel dueño';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin · AcademiaFluxus</p>
<h1>Panel de control</h1>
<p class="lede">Gestioná alumnos, cupos de inscripción, cursos, unidades, galería, blogs, tienda de grabados y cuotas.</p>

<nav class="admin-nav">
  <a class="btn primary" href="users.php">Gestionar alumnos</a>
  <a class="btn secondary" href="admins.php">Administradores</a>
  <a class="btn secondary" href="payments.php">Cuotas y pagos</a>
  <a class="btn secondary" href="shop.php">Tienda cursos</a>
  <a class="btn secondary" href="campus.php">Unidades y materiales</a>
  <a class="btn secondary" href="courses.php">Cursos y clases</a>
  <a class="btn secondary" href="inscriptions.php">Inscripciones / cupos</a>
  <a class="btn secondary" href="shop.php">Tienda (admin)</a>
  <a class="btn secondary" href="../../cursos/">Ver cursos grabados</a>
  <a class="btn secondary" href="../../galeria/admin.php">Editar galería</a>
  <a class="btn secondary" href="../../blogs/admin.php">Editar blogs</a>
  <a class="btn secondary" href="../dashboard.php">Vista alumno</a>
</nav>

<div class="grid metrics-grid">
  <div class="panel metric">
    <p class="metric-label">Alumnos activos</p>
    <h2><?= $activeStudents ?></h2>
    <p class="muted small"><?= $inactiveStudents ?> dados de baja · <?= $totalStudents ?> total</p>
  </div>
  <div class="panel metric">
    <p class="metric-label">Cursos / clases</p>
    <h2><?= $totalCourses ?> / <?= $totalLessons ?></h2>
    <p class="muted small"><?= $totalTasks ?> tareas creadas</p>
  </div>
  <div class="panel metric">
    <p class="metric-label">Completación de clases</p>
    <h2><?= $overallLessonPct ?>%</h2>
    <div class="progress" aria-hidden="true"><span style="width:<?= $overallLessonPct ?>%"></span></div>
    <p class="muted small"><?= $completedLessons ?> de <?= $expectedLessons ?> clases esperadas</p>
  </div>
  <div class="panel metric">
    <p class="metric-label">Completación de tareas</p>
    <h2><?= $overallTaskPct ?>%</h2>
    <div class="progress" aria-hidden="true"><span style="width:<?= $overallTaskPct ?>%"></span></div>
    <p class="muted small"><?= $completedTasks ?> de <?= $expectedTasks ?> tareas esperadas</p>
  </div>
</div>

<section class="panel" style="margin-top:1.25rem">
  <h2>Avance por curso</h2>
  <?php if (!$courseMetrics): ?>
    <p class="muted">Todavía no hay cursos.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Curso</th>
          <th>Alumnos</th>
          <th>Clases</th>
          <th>Completación</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($courseMetrics as $row): ?>
          <?php
            $expected = (int) $row['expected'];
            $done = (int) $row['done_lessons'];
            $pct = $expected > 0 ? (int) round(($done / $expected) * 100) : 0;
          ?>
          <tr>
            <td>
              <strong><?= h($row['title']) ?></strong><br>
              <span class="muted small"><?= h($row['category']) ?></span>
            </td>
            <td><?= (int) $row['students'] ?></td>
            <td><?= (int) $row['lessons'] ?></td>
            <td style="min-width:160px">
              <strong><?= $pct ?>%</strong>
              <div class="progress" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
              <span class="muted small"><?= $done ?>/<?= $expected ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel" style="margin-top:1.25rem">
  <h2>Avance por alumno</h2>
  <?php if (!$studentMetrics): ?>
    <p class="muted">Todavía no hay alumnos. Creá el primero en <a href="users.php">Gestionar alumnos</a>.</p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Alumno</th>
          <th>Usuario</th>
          <th>Estado</th>
          <th>Cursos</th>
          <th>Clases</th>
          <th>Tareas</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($studentMetrics as $s): ?>
          <?php
            $total = (int) $s['total_lessons'];
            $done = (int) $s['done_lessons'];
            $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
          ?>
          <tr>
            <td><?= h($s['name']) ?></td>
            <td><?= h($s['username']) ?></td>
            <td><?= (int) $s['active'] ? '<span class="badge done">Activo</span>' : '<span class="badge">Baja</span>' ?></td>
            <td><?= (int) $s['courses'] ?></td>
            <td style="min-width:140px">
              <strong><?= $pct ?>%</strong>
              <div class="progress" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
              <span class="muted small"><?= $done ?>/<?= $total ?></span>
            </td>
            <td><?= (int) $s['done_tasks'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
