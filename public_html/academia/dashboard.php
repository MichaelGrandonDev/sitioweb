<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();
if ($user['role'] === 'admin') {
    // admins can also see student dashboard
}

$pdo = db();
$stmt = $pdo->prepare("
  SELECT c.*,
    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
    (SELECT COUNT(*) FROM lesson_progress lp
      JOIN lessons l2 ON l2.id = lp.lesson_id
      WHERE l2.course_id = c.id AND lp.user_id = ?) AS done_count
  FROM courses c
  INNER JOIN enrollments e ON e.course_id = c.id
  WHERE e.user_id = ? AND c.published = 1
  ORDER BY c.sort_order, c.title
");
$stmt->execute([(int) $user['id'], (int) $user['id']]);
$courses = $stmt->fetchAll();

$pageTitle = 'Mis cursos';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">Hola, <?= h($user['name']) ?></p>
<h1>Mis cursos</h1>
<p class="lede">Entrá al campus de cada curso: secciones, grabaciones, PDFs, avisos y clase en vivo.</p>

<?php if (!$courses): ?>
  <div class="panel">
    <p class="muted">Todavía no tenés cursos asignados. Podés comprar cursos grabados o esperar a que te activen el acceso.</p>
    <p style="margin-top:.75rem"><a class="btn primary" href="shop.php">Ver cursos grabados</a></p>
  </div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($courses as $course): ?>
      <?php
        $total = (int) $course['lesson_count'];
        $done = (int) $course['done_count'];
        $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $meetUrl = trim((string) ($course['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($course['meet_schedule'] ?? ''));
      ?>
      <article class="course-card">
        <a href="course.php?id=<?= (int) $course['id'] ?>" class="course-card-link">
          <span class="cat"><?= h($course['category'] ?: 'Curso') ?></span>
          <h2><?= h($course['title']) ?></h2>
          <p class="muted"><?= h($course['description']) ?></p>
          <div class="progress" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
          <p class="muted small"><?= $done ?>/<?= $total ?> clases · <?= $pct ?>%</p>
        </a>
        <?php if ($meetUrl !== ''): ?>
          <div class="meet-box">
            <?php if ($meetSchedule !== ''): ?>
              <p class="muted small" style="margin:0 0 .45rem"><?= h($meetSchedule) ?></p>
            <?php endif; ?>
            <a
              class="btn meet"
              href="<?= h($meetUrl) ?>"
              target="_blank"
              rel="noopener noreferrer"
            >Unirme a clase en vivo (Meet)</a>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
