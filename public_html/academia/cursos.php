<?php

declare(strict_types=1);

/**
 * Mis cursos (alumno): grilla con foto → al entrar, hub del curso.
 */

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();
$pdo = db();

if (is_staff($user)) {
    // Staff “viendo como alumno”: todos los cursos publicados (profesor: solo asignados).
    $courses = $pdo->query('SELECT * FROM courses WHERE published = 1 ORDER BY sort_order, title')->fetchAll();
    if (is_teacher($user)) {
        $allowed = teacher_course_ids($user);
        $courses = array_values(array_filter($courses, static fn ($c) => in_array((int) $c['id'], $allowed, true)));
    }
} else {
    $stmt = $pdo->prepare("
      SELECT c.*
      FROM courses c
      INNER JOIN enrollments e ON e.course_id = c.id
      WHERE e.user_id = ? AND c.published = 1
      ORDER BY c.sort_order, c.title
    ");
    $stmt->execute([(int) $user['id']]);
    $courses = $stmt->fetchAll();
}

$plans = [];
foreach ($courses as $c) {
    $plans[(int) $c['id']] = recording_course_plan((int) $c['id'], $user);
}

$pageTitle = 'Mis cursos';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head student-page-head">
  <div>
    <p class="eyebrow">Campus alumno</p>
    <h1>Mis cursos</h1>
    <p class="lede">Elegí un curso con su foto. Adentro están las clases, los materiales y el Meet.</p>
  </div>
</div>

<?php if (!$courses): ?>
  <div class="panel empty-state student-empty">
    <strong>Todavía no tenés cursos asignados</strong>
    <p class="muted">Cuando administración te asigne uno, aparece acá.</p>
    <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
  </div>
<?php else: ?>
  <div class="staff-course-grid student-course-grid student-course-grid-lg">
    <?php foreach ($courses as $i => $course): ?>
      <?php
        $cid = (int) $course['id'];
        $coverUrl = course_cover_url($course);
        $plan = $plans[$cid];
      ?>
      <a class="staff-course-tile student-course-tile<?= $coverUrl !== '' ? ' has-cover' : '' ?>" href="mi-curso.php?id=<?= $cid ?>" style="--i:<?= $i ?>">
        <span class="staff-course-tile-media">
          <?php if ($coverUrl !== ''): ?>
            <img src="<?= h($coverUrl) ?>" alt="" width="640" height="360" loading="lazy" decoding="async">
          <?php else: ?>
            <span class="staff-course-tile-placeholder"><?= aca_icon('book') ?></span>
          <?php endif; ?>
          <?php if ($plan['total'] > 0): ?>
            <span class="student-tile-pct"><?= $plan['pct'] ?>%</span>
          <?php endif; ?>
        </span>
        <span class="staff-course-tile-body">
          <span class="cat"><?= h((string) ($course['category'] ?: 'Curso')) ?></span>
          <strong><?= h((string) $course['title']) ?></strong>
          <?php if (trim((string) ($course['description'] ?? '')) !== ''): ?>
            <span class="student-tile-desc"><?= h(short_text((string) $course['description'], 90)) ?></span>
          <?php endif; ?>
          <?php if ($plan['total'] > 0): ?>
            <span class="student-tile-bar" aria-hidden="true"><i style="width:<?= $plan['pct'] ?>%"></i></span>
            <span class="staff-course-tile-foot">
              <span class="badge soft"><?= $plan['seen'] ?>/<?= $plan['total'] ?> clases</span>
              <em>Abrir →</em>
            </span>
          <?php else: ?>
            <span class="staff-course-tile-foot">
              <span class="badge soft">Sin clases aún</span>
              <em>Abrir →</em>
            </span>
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
