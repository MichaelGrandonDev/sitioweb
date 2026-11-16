<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$user = require_staff();
$pdo = db();
$isGeneral = is_full_admin($user);
$courseIds = staff_course_ids($user);
$openTickets = tickets_pending_count($user);

if ($isGeneral) {
    $totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
    $activeStudents = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND active=1")->fetchColumn();
    $inactiveStudents = $totalStudents - $activeStudents;
    $totalCourses = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    $totalVideos = (int) $pdo->query("SELECT COUNT(*) FROM course_resources WHERE type = 'video'")->fetchColumn();

    $completed = (int) $pdo->query("
      SELECT COUNT(*)
      FROM resource_progress rp
      JOIN course_resources r ON r.id = rp.resource_id
      JOIN enrollments e ON e.course_id = r.course_id AND e.user_id = rp.user_id
      JOIN users u ON u.id = rp.user_id
      WHERE u.role = 'student' AND u.active = 1 AND r.track_completion = 1
    ")->fetchColumn();

    $expected = (int) $pdo->query("
      SELECT COUNT(*)
      FROM enrollments e
      JOIN course_resources r ON r.course_id = e.course_id AND r.track_completion = 1
      JOIN users u ON u.id = e.user_id
      WHERE u.role = 'student' AND u.active = 1
    ")->fetchColumn();

    $courseMetrics = $pdo->query("
      SELECT
        c.id, c.title, c.category,
        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students,
        (SELECT COUNT(*) FROM course_resources r WHERE r.course_id = c.id AND r.type = 'video') AS videos,
        (
          SELECT COUNT(*) FROM resource_progress rp
          JOIN course_resources r2 ON r2.id = rp.resource_id
          JOIN enrollments e3 ON e3.course_id = r2.course_id AND e3.user_id = rp.user_id
          JOIN users u ON u.id = rp.user_id
          WHERE r2.course_id = c.id AND r2.track_completion = 1 AND u.role = 'student' AND u.active = 1
        ) AS done,
        (
          SELECT COUNT(*) FROM enrollments e2
          JOIN course_resources r3 ON r3.course_id = e2.course_id AND r3.track_completion = 1
          JOIN users u2 ON u2.id = e2.user_id
          WHERE e2.course_id = c.id AND u2.role = 'student' AND u2.active = 1
        ) AS expected
      FROM courses c
      ORDER BY c.sort_order, c.title
    ")->fetchAll();

    $studentMetrics = $pdo->query("
      SELECT
        u.id, u.name, u.email, u.active,
        (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) AS courses,
        (
          SELECT COUNT(*) FROM enrollments e
          JOIN course_resources r ON r.course_id = e.course_id AND r.track_completion = 1
          WHERE e.user_id = u.id
        ) AS total,
        (
          SELECT COUNT(*) FROM resource_progress rp
          JOIN course_resources r ON r.id = rp.resource_id AND r.track_completion = 1
          JOIN enrollments e ON e.course_id = r.course_id AND e.user_id = u.id
          WHERE rp.user_id = u.id
        ) AS done
      FROM users u
      WHERE u.role = 'student'
      ORDER BY u.name
    ")->fetchAll();
} else {
    $ph = $courseIds === [] ? '0' : implode(',', array_fill(0, count($courseIds), '?'));
    $params = $courseIds;

    $totalCourses = count($courseIds);
    if ($courseIds === []) {
        $totalVideos = 0;
        $activeStudents = 0;
        $completed = 0;
        $expected = 0;
        $courseMetrics = [];
        $studentMetrics = [];
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM course_resources WHERE type = 'video' AND course_id IN ($ph)");
        $st->execute($params);
        $totalVideos = (int) $st->fetchColumn();

        $st = $pdo->prepare("
          SELECT COUNT(DISTINCT e.user_id)
          FROM enrollments e
          JOIN users u ON u.id = e.user_id
          WHERE e.course_id IN ($ph) AND u.role = 'student' AND u.active = 1
        ");
        $st->execute($params);
        $activeStudents = (int) $st->fetchColumn();

        $st = $pdo->prepare("
          SELECT COUNT(*)
          FROM resource_progress rp
          JOIN course_resources r ON r.id = rp.resource_id
          JOIN enrollments e ON e.course_id = r.course_id AND e.user_id = rp.user_id
          JOIN users u ON u.id = rp.user_id
          WHERE r.course_id IN ($ph) AND u.role = 'student' AND u.active = 1 AND r.track_completion = 1
        ");
        $st->execute($params);
        $completed = (int) $st->fetchColumn();

        $st = $pdo->prepare("
          SELECT COUNT(*)
          FROM enrollments e
          JOIN course_resources r ON r.course_id = e.course_id AND r.track_completion = 1
          JOIN users u ON u.id = e.user_id
          WHERE e.course_id IN ($ph) AND u.role = 'student' AND u.active = 1
        ");
        $st->execute($params);
        $expected = (int) $st->fetchColumn();

        $st = $pdo->prepare("
          SELECT
            c.id, c.title, c.category,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students,
            (SELECT COUNT(*) FROM course_resources r WHERE r.course_id = c.id AND r.type = 'video') AS videos,
            (
              SELECT COUNT(*) FROM resource_progress rp
              JOIN course_resources r2 ON r2.id = rp.resource_id
              JOIN enrollments e3 ON e3.course_id = r2.course_id AND e3.user_id = rp.user_id
              JOIN users u ON u.id = rp.user_id
              WHERE r2.course_id = c.id AND r2.track_completion = 1 AND u.role = 'student' AND u.active = 1
            ) AS done,
            (
              SELECT COUNT(*) FROM enrollments e2
              JOIN course_resources r3 ON r3.course_id = e2.course_id AND r3.track_completion = 1
              JOIN users u2 ON u2.id = e2.user_id
              WHERE e2.course_id = c.id AND u2.role = 'student' AND u2.active = 1
            ) AS expected
          FROM courses c
          WHERE c.id IN ($ph)
          ORDER BY c.sort_order, c.title
        ");
        $st->execute($params);
        $courseMetrics = $st->fetchAll();

        if (user_has_permission($user, 'view_students')) {
            $st = $pdo->prepare("
              SELECT
                u.id, u.name, u.email, u.active,
                (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id AND e.course_id IN ($ph)) AS courses,
                (
                  SELECT COUNT(*) FROM enrollments e
                  JOIN course_resources r ON r.course_id = e.course_id AND r.track_completion = 1
                  WHERE e.user_id = u.id AND e.course_id IN ($ph)
                ) AS total,
                (
                  SELECT COUNT(*) FROM resource_progress rp
                  JOIN course_resources r ON r.id = rp.resource_id AND r.track_completion = 1
                  JOIN enrollments e ON e.course_id = r.course_id AND e.user_id = u.id
                  WHERE rp.user_id = u.id AND e.course_id IN ($ph)
                ) AS done
              FROM users u
              WHERE u.role = 'student'
                AND EXISTS (
                  SELECT 1 FROM enrollments e WHERE e.user_id = u.id AND e.course_id IN ($ph)
                )
              ORDER BY u.name
            ");
            $st->execute(array_merge($params, $params, $params, $params));
            $studentMetrics = $st->fetchAll();
        } else {
            $studentMetrics = [];
        }
    }
    $totalStudents = $activeStudents;
    $inactiveStudents = 0;
}

$overallPct = $expected > 0 ? (int) round(($completed / $expected) * 100) : 0;

$pageTitle = $isGeneral ? 'Panel de administración' : 'Portal de profesores';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= $isGeneral ? 'Administración' : 'Portal profesores' ?> · AcademiaFluxus</p>
    <h1>Hola, <?= h(aca_first_name((string) $user['name'])) ?></h1>
    <p class="lede"><?= $isGeneral
      ? 'Alumnos, clases grabadas y consultas, todo desde acá.'
      : 'Tu espacio de profesor: perfil, cursos asignados, clases y consultas.' ?></p>
  </div>
  <?php if (!$isGeneral): ?>
    <div class="actions">
      <a class="btn secondary" href="perfil.php"><?= aca_icon('user') ?> Mi perfil</a>
    </div>
  <?php endif; ?>
</div>

<div class="quick-grid">
  <?php if ($isGeneral): ?>
    <a class="quick is-primary" href="users.php?nuevo=1">
      <span class="stat-ico"><?= aca_icon('plus') ?></span>
      <span><strong>Agregar alumno</strong><span>Con su email y una contraseña</span></span>
    </a>
  <?php endif; ?>
  <?php if (user_has_permission($user, 'manage_course_content')): ?>
    <a class="quick<?= !$isGeneral ? ' is-primary' : '' ?>" href="grabaciones.php">
      <span class="stat-ico"><?= aca_icon('upload') ?></span>
      <span><strong>Subir una clase</strong><span>Video o link de YouTube/Drive</span></span>
    </a>
  <?php endif; ?>
  <?php if (user_has_permission($user, 'reply_tickets')): ?>
    <a class="quick" href="tickets.php">
      <span class="stat-ico"><?= aca_icon('chat') ?></span>
      <span><strong>Responder consultas</strong><span><?= $openTickets > 0 ? $openTickets . ' sin responder' : 'Todo al día' ?></span></span>
    </a>
  <?php endif; ?>
  <?php if ($isGeneral): ?>
    <a class="quick" href="sitio_textos.php">
      <span class="stat-ico"><?= aca_icon('globe') ?></span>
      <span><strong>Editar el sitio web</strong><span>Textos, fotos, WhatsApp y letra</span></span>
    </a>
    <a class="quick" href="recordatorios.php">
      <span class="stat-ico"><?= aca_icon('mail') ?></span>
      <span><strong>Recordatorios</strong><span>Mails a alumnos con clases pendientes</span></span>
    </a>
    <a class="quick" href="admins.php">
      <span class="stat-ico"><?= aca_icon('key') ?></span>
      <span><strong>Administradores / Profesores</strong><span>Alta, permisos y cursos</span></span>
    </a>
  <?php elseif (user_has_permission($user, 'edit_course_info')): ?>
    <a class="quick" href="courses.php">
      <span class="stat-ico"><?= aca_icon('book') ?></span>
      <span><strong>Mis cursos</strong><span>Descripción, portada y perfil</span></span>
    </a>
  <?php endif; ?>
</div>

<div class="stat-grid">
  <?php if ($isGeneral || user_has_permission($user, 'view_students')): ?>
    <a class="stat" href="<?= $isGeneral ? 'users.php' : 'estudiantes.php' ?>">
      <span class="stat-ico"><?= aca_icon('users') ?></span>
      <span class="stat-label"><?= $isGeneral ? 'Alumnos activos' : 'Alumnos en tus cursos' ?></span>
      <strong><?= $activeStudents ?></strong>
      <?php if ($isGeneral): ?>
        <em><?= $inactiveStudents ?> de baja · <?= $totalStudents ?> en total</em>
      <?php else: ?>
        <em>Solo lectura</em>
      <?php endif; ?>
    </a>
  <?php endif; ?>
  <?php if (user_has_permission($user, 'manage_course_content') || $isGeneral): ?>
    <a class="stat" href="grabaciones.php">
      <span class="stat-ico"><?= aca_icon('video') ?></span>
      <span class="stat-label">Clases grabadas</span>
      <strong><?= $totalVideos ?></strong>
      <em>en <?= $totalCourses ?> curso<?= $totalCourses === 1 ? '' : 's' ?></em>
    </a>
  <?php endif; ?>
  <div class="stat">
    <span class="stat-ico"><?= aca_icon('eye') ?></span>
    <span class="stat-label">Avance de los alumnos</span>
    <strong><?= $overallPct ?>%</strong>
    <em><?= $completed ?> de <?= $expected ?> contenidos vistos</em>
  </div>
  <?php if (user_has_permission($user, 'reply_tickets')): ?>
    <a class="stat<?= $openTickets > 0 ? ' is-alert' : '' ?>" href="tickets.php">
      <span class="stat-ico"><?= aca_icon('chat') ?></span>
      <span class="stat-label">Consultas sin responder</span>
      <strong><?= $openTickets ?></strong>
      <em>Ver consultas</em>
    </a>
  <?php endif; ?>
</div>

<section class="panel" style="margin-top:1.25rem">
  <h2><?= $isGeneral ? 'Avance por curso' : 'Avance de tus cursos' ?></h2>
  <?php if (!$courseMetrics): ?>
    <p class="muted"><?= $isGeneral ? 'Todavía no hay cursos.' : 'Todavía no tenés cursos asignados. Pedile al administrador general que te asigne uno.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Curso</th>
          <th>Alumnos</th>
          <th>Clases grabadas</th>
          <th>Avance</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($courseMetrics as $row): ?>
          <?php
            $exp = (int) $row['expected'];
            $done = (int) $row['done'];
            $pct = $exp > 0 ? (int) round(($done / $exp) * 100) : 0;
          ?>
          <tr>
            <td>
              <strong><?= h($row['title']) ?></strong><br>
              <span class="muted small"><?= h($row['category']) ?></span>
            </td>
            <td><?= (int) $row['students'] ?></td>
            <td><?= (int) $row['videos'] ?></td>
            <td style="min-width:160px">
              <strong><?= $pct ?>%</strong>
              <div class="progress" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
              <span class="muted small"><?= $done ?>/<?= $exp ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<?php if ($isGeneral || user_has_permission($user, 'view_students')): ?>
<section class="panel" style="margin-top:1.25rem">
  <h2><?= $isGeneral ? 'Avance por alumno' : 'Alumnos de tus cursos' ?></h2>
  <?php if (!$studentMetrics): ?>
    <p class="muted"><?= $isGeneral
      ? 'Todavía no hay alumnos. Creá el primero en <a href="users.php">Alumnos</a>.'
      : 'Todavía no hay alumnos inscritos en tus cursos.' ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Alumno</th>
          <th>Email</th>
          <th>Estado</th>
          <th>Cursos</th>
          <th>Avance</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($studentMetrics as $s): ?>
          <?php
            $total = (int) $s['total'];
            $done = (int) $s['done'];
            $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
          ?>
          <tr>
            <td><?= h($s['name']) ?></td>
            <td><?= h((string) $s['email']) ?></td>
            <td><?= (int) $s['active'] ? '<span class="badge done">Activo</span>' : '<span class="badge">Baja</span>' ?></td>
            <td><?= (int) $s['courses'] ?></td>
            <td style="min-width:140px">
              <strong><?= $pct ?>%</strong>
              <div class="progress" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
              <span class="muted small"><?= $done ?>/<?= $total ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
