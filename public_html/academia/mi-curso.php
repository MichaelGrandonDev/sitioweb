<?php

declare(strict_types=1);

/**
 * Hub de un curso (alumno): foto + botones a clases, materiales, Meet y consultas.
 */

require __DIR__ . '/bootstrap.php';
$user = require_login();
$pdo = db();
$courseId = (int) ($_GET['id'] ?? $_GET['course_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course || (int) ($course['published'] ?? 1) !== 1) {
    flash('error', 'Curso no encontrado.');
    redirect('cursos.php');
}
if (!user_can_access_course($user, $courseId)) {
    flash('error', 'No tenés acceso a este curso.');
    redirect('cursos.php');
}

$plan = recording_course_plan($courseId, $user);
$st = $pdo->prepare('SELECT COUNT(*) FROM course_resources WHERE course_id = ?');
$st->execute([$courseId]);
$resourceCount = (int) $st->fetchColumn();

$coverUrl = course_cover_url($course);
$meetUrl = trim((string) ($course['meet_url'] ?? ''));
$meetSchedule = trim((string) ($course['meet_schedule'] ?? ''));
$live = live_classes_for_user($user, $courseId);
$nextLive = $live[0] ?? null;

$pageTitle = (string) $course['title'];
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<nav class="admin-nav" style="margin-bottom:1rem">
  <a class="btn secondary" href="cursos.php">← Mis cursos</a>
  <a class="btn secondary" href="dashboard.php">Inicio</a>
</nav>

<header class="staff-course-hero<?= $coverUrl !== '' ? ' has-cover' : '' ?>">
  <?php if ($coverUrl !== ''): ?>
    <img class="staff-course-hero-img" src="<?= h($coverUrl) ?>" alt="" width="1200" height="480" decoding="async">
  <?php endif; ?>
  <div class="staff-course-hero-body">
    <p class="eyebrow"><?= h((string) ($course['category'] ?: 'Curso')) ?></p>
    <h1><?= h((string) $course['title']) ?></h1>
    <p class="staff-course-meta">
      <?php if ($plan['total'] > 0): ?>
        <span class="badge soft"><?= $plan['seen'] ?> de <?= $plan['total'] ?> clases vistas · <?= $plan['pct'] ?>%</span>
      <?php else: ?>
        <span class="badge soft">Todavía no hay clases grabadas</span>
      <?php endif; ?>
      <?php if ($plan['complete']): ?>
        <span class="badge done">Completado</span>
      <?php endif; ?>
    </p>
    <?php if (trim((string) ($course['description'] ?? '')) !== ''): ?>
      <p class="lede" style="margin:.5rem 0 0"><?= h(short_text((string) $course['description'], 200)) ?></p>
    <?php endif; ?>
  </div>
</header>

<div style="margin:0 0 1rem"><?= aca_teacher_block($course, 'compact') ?></div>

<?php if ($nextLive && ($nextLive['next'] || $nextLive['url'] !== '')): ?>
  <a class="live-banner is-<?= h($nextLive['status']) ?>" style="margin-bottom:1rem" href="<?= $nextLive['status'] === 'live' && $nextLive['url'] !== '' ? h($nextLive['url']) . '" target="_blank" rel="noopener' : 'clases.php?course_id=' . $courseId ?>">
    <span class="live-pill"><?php if ($nextLive['status'] === 'live'): ?><i class="live-dot"></i>En vivo ahora<?php else: ?>Clase en vivo<?php endif; ?></span>
    <span>
      <small><?= h($nextLive['course_title']) ?></small>
      <strong><?= h($nextLive['next'] ? live_when((int) $nextLive['next']['ts']) : ($nextLive['schedule'] !== '' ? $nextLive['schedule'] : 'Ver horario')) ?></strong>
    </span>
    <em><?= $nextLive['status'] === 'live' ? 'Entrar' : 'Ver →' ?></em>
  </a>
<?php endif; ?>

<div class="staff-hub-grid">
  <a class="staff-hub-btn is-primary" href="clases.php?course_id=<?= $courseId ?>">
    <span class="stat-ico"><?= aca_icon('play') ?></span>
    <strong>Clases</strong>
    <span>En vivo y grabadas · <?= $plan['total'] ?> clase<?= $plan['total'] === 1 ? '' : 's' ?></span>
  </a>
  <a class="staff-hub-btn" href="course.php?id=<?= $courseId ?>">
    <span class="stat-ico"><?= aca_icon('layers') ?></span>
    <strong>Materiales</strong>
    <span>Unidades, PDFs y recursos · <?= $resourceCount ?> en total</span>
  </a>
  <?php if ($meetUrl !== ''): ?>
    <a class="staff-hub-btn" href="<?= h($meetUrl) ?>" target="_blank" rel="noopener noreferrer">
      <span class="stat-ico"><?= aca_icon('video') ?></span>
      <strong>Clase en vivo (Meet)</strong>
      <span><?= $meetSchedule !== '' ? h($meetSchedule) : 'Abrir Google Meet' ?></span>
    </a>
  <?php endif; ?>
  <a class="staff-hub-btn" href="tickets.php?nuevo=1&amp;to=teacher&amp;course_id=<?= $courseId ?>#nueva">
    <span class="stat-ico"><?= aca_icon('chat') ?></span>
    <strong>Consultar</strong>
    <span>Escribile al profesor de este curso</span>
  </a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
