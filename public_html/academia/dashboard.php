<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();

$pdo = db();
$stmt = $pdo->prepare("
  SELECT c.*,
    (SELECT COUNT(*) FROM course_resources r WHERE r.course_id = c.id AND r.type = 'video') AS video_count,
    (SELECT COUNT(*) FROM course_resources r WHERE r.course_id = c.id AND r.track_completion = 1) AS total_count,
    (SELECT COUNT(*) FROM resource_progress rp
      JOIN course_resources r2 ON r2.id = rp.resource_id
      WHERE r2.course_id = c.id AND r2.track_completion = 1 AND rp.user_id = ?) AS done_count
  FROM courses c
  INNER JOIN enrollments e ON e.course_id = c.id
  WHERE e.user_id = ? AND c.published = 1
  ORDER BY c.sort_order, c.title
");
$stmt->execute([(int) $user['id'], (int) $user['id']]);
$courses = $stmt->fetchAll();

$allRecordings = recordings_for_user($user);
$doneIds = recording_done_ids((int) $user['id']);
$unseen = array_values(array_filter($allRecordings, static fn ($r) => !in_array((int) $r['id'], $doneIds, true)));
$continue = $unseen[0] ?? ($allRecordings[0] ?? null);
$latest = array_slice($allRecordings, 0, 3);
$pendingTickets = tickets_pending_count($user);
$seenCount = count($allRecordings) - count($unseen);
$nextLive = live_classes_for_user($user)[0] ?? null;

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Buen día' : ($hour < 20 ? 'Buenas tardes' : 'Buenas noches');

$pageTitle = 'Inicio';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<section class="welcome">
  <div>
    <p class="eyebrow">Campus alumno</p>
    <h1><?= h($greeting) ?>, <?= h(aca_first_name((string) $user['name'])) ?></h1>
  </div>
  <p>Tus clases en vivo y grabadas, en un solo lugar. Cuando tengas una duda, escribile al profesor desde acá.</p>
  <?php if ($nextLive && ($nextLive['next'] || $nextLive['url'] !== '')): ?>
    <a class="live-banner is-<?= h($nextLive['status']) ?>" href="<?= $nextLive['status'] === 'live' && $nextLive['url'] !== '' ? h($nextLive['url']) . '" target="_blank" rel="noopener' : 'clases.php' ?>">
      <span class="live-pill"><?php if ($nextLive['status'] === 'live'): ?><i class="live-dot"></i>En vivo ahora<?php else: ?>Clase en vivo<?php endif; ?></span>
      <span>
        <small><?= h($nextLive['course_title']) ?></small>
        <strong><?= h($nextLive['next'] ? live_when((int) $nextLive['next']['ts']) : ($nextLive['schedule'] !== '' ? $nextLive['schedule'] : 'Ver horario')) ?></strong>
      </span>
      <em><?= $nextLive['status'] === 'live' ? 'Entrar' : 'Ver clases' ?> →</em>
    </a>
  <?php endif; ?>
  <?php if ($continue): ?>
    <?php $thumb = recording_thumbnail($continue); ?>
    <a class="continue-card" href="resource.php?id=<?= (int) $continue['id'] ?>">
      <span class="continue-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>><?= aca_icon('play') ?></span>
      <span>
        <small><?= $unseen ? 'Seguir viendo' : 'Volver a ver' ?> · <?= h($continue['course_title']) ?></small>
        <strong><?= h($continue['title']) ?></strong>
      </span>
    </a>
  <?php else: ?>
    <div class="actions" style="position:relative;z-index:1">
      <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
    </div>
  <?php endif; ?>
</section>

<div class="stat-grid">
  <a class="stat" href="clases.php">
    <span class="stat-ico"><?= aca_icon('play') ?></span>
    <span class="stat-label">Clases grabadas</span>
    <strong><?= count($allRecordings) ?></strong>
    <em><?= $seenCount ?> vista<?= $seenCount === 1 ? '' : 's' ?> · <?= count($unseen) ?> por ver</em>
  </a>
  <a class="stat<?= $pendingTickets > 0 ? ' is-alert' : '' ?>" href="tickets.php">
    <span class="stat-ico"><?= aca_icon('chat') ?></span>
    <span class="stat-label">Respuestas nuevas</span>
    <strong><?= $pendingTickets ?></strong>
    <em>Ver mis consultas</em>
  </a>
  <a class="stat" href="tickets.php?nuevo=1&amp;to=teacher#nueva">
    <span class="stat-ico"><?= aca_icon('spark') ?></span>
    <span class="stat-label">¿Tenés una duda?</span>
    <strong style="font-size:1.5rem">Consultar</strong>
    <em>al profesor o a administración</em>
  </a>
</div>

<?php if ($latest): ?>
  <div class="section-title">
    <h2>Últimas clases</h2>
    <a href="clases.php">Ver todas →</a>
  </div>
  <div class="rec-grid" style="margin-bottom:1.8rem">
    <?php foreach ($latest as $r): ?>
      <?php
        $thumb = recording_thumbnail($r);
        $isSeen = in_array((int) $r['id'], $doneIds, true);
      ?>
      <a class="rec-card<?= $isSeen ? ' is-seen' : '' ?>" href="resource.php?id=<?= (int) $r['id'] ?>">
        <span class="rec-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>>
          <span class="rec-play"><?= aca_icon('play') ?></span>
          <?php if ($isSeen): ?><span class="badge done rec-seen">✓ Vista</span><?php endif; ?>
        </span>
        <span class="rec-body">
          <span class="rec-course"><?= h($r['course_title']) ?></span>
          <strong><?= h($r['title']) ?></strong>
          <em><?= h(format_db_time((string) $r['created_at'], 'd/m/Y')) ?></em>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="section-title"><h2>Mis cursos</h2></div>
<?php if (!$courses): ?>
  <div class="panel empty-state">
    <strong>Todavía no tenés cursos asignados</strong>
    <p class="muted">Cuando administración te asigne un curso, aparece acá con sus clases.</p>
    <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
  </div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($courses as $course): ?>
      <?php
        $total = (int) $course['total_count'];
        $done = (int) $course['done_count'];
        $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        $meetUrl = trim((string) ($course['meet_url'] ?? ''));
        $meetSchedule = trim((string) ($course['meet_schedule'] ?? ''));
        $coverUrl = course_cover_url($course);
      ?>
      <article class="course-card<?= $coverUrl !== '' ? ' has-cover' : '' ?>">
        <?php if ($coverUrl !== ''): ?>
          <a href="course.php?id=<?= (int) $course['id'] ?>" class="course-cover" tabindex="-1" aria-hidden="true">
            <img src="<?= h($coverUrl) ?>" alt="" width="640" height="360" loading="lazy" decoding="async">
          </a>
        <?php endif; ?>
        <a href="clases.php?course_id=<?= (int) $course['id'] ?>" class="course-card-link">
          <span class="cat"><?= h($course['category'] ?: 'Curso') ?></span>
          <h2><?= h($course['title']) ?></h2>
          <?php if (trim((string) $course['description']) !== ''): ?>
            <p class="muted"><?= h(short_text((string) $course['description'], 160)) ?></p>
          <?php endif; ?>
          <div class="mini-progress" style="margin-top:.8rem"><div class="progress"><span style="width:<?= $pct ?>%"></span></div><b><?= $pct ?>%</b></div>
          <p class="muted small" style="margin:.4rem 0 0"><?= (int) $course['video_count'] ?> clases grabadas</p>
        </a>
        <div class="actions">
          <a class="btn primary small-btn" href="clases.php?course_id=<?= (int) $course['id'] ?>"><?= aca_icon('play') ?> Ver clases</a>
          <a class="btn secondary small-btn" href="course.php?id=<?= (int) $course['id'] ?>">Materiales</a>
        </div>
        <?php if ($meetUrl !== ''): ?>
          <div class="meet-box">
            <?php if ($meetSchedule !== ''): ?>
              <p class="muted small" style="margin:0 0 .45rem"><?= h($meetSchedule) ?></p>
            <?php endif; ?>
            <a class="btn meet" href="<?= h($meetUrl) ?>" target="_blank" rel="noopener noreferrer">Unirme a clase en vivo (Meet)</a>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
