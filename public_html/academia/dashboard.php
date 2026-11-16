<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();

$pdo = db();
$stmt = $pdo->prepare("
  SELECT c.*
  FROM courses c
  INNER JOIN enrollments e ON e.course_id = c.id
  WHERE e.user_id = ? AND c.published = 1
  ORDER BY c.sort_order, c.title
");
$stmt->execute([(int) $user['id']]);
$courses = $stmt->fetchAll();

// Las bonus no cuentan como clases: quedan fuera del progreso, de "Seguir viendo" y de las últimas.
$allRecordings = array_values(array_filter(recordings_for_user($user), static fn ($r) => (int) $r['is_bonus'] !== 1));
$doneIds = recording_done_ids((int) $user['id']);
$plans = [];
$labels = [];
foreach (array_unique(array_merge(array_map('intval', array_column($courses, 'id')), array_map('intval', array_column($allRecordings, 'course_id')))) as $cid) {
    $plans[$cid] = recording_course_plan($cid, $user);
    foreach ($plans[$cid]['classes'] as $p) {
        $labels[(int) $p['id']] = recording_label($p);
    }
}
$unseen = array_values(array_filter($allRecordings, static fn ($r) => !in_array((int) $r['id'], $doneIds, true)));
$courseTitles = array_column($allRecordings, 'course_title', 'course_id');
// "Seguir viendo": la próxima clase sin ver, en orden, del primer curso que tenga pendientes.
$continue = null;
foreach ($plans as $cid => $plan) {
    foreach ($plan['classes'] as $p) {
        if (!$p['done']) {
            $continue = $p + ['course_title' => (string) ($courseTitles[$cid] ?? '')];
            break 2;
        }
    }
}
$continue ??= $allRecordings[0] ?? null;
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
<section class="welcome student-welcome">
  <div class="student-welcome-copy">
    <p class="eyebrow">Campus alumno</p>
    <h1><?= h($greeting) ?>, <?= h(aca_first_name((string) $user['name'])) ?></h1>
    <p>Elegí un curso con su foto y ahí están las clases, los materiales y el Meet.</p>
  </div>
  <?php if ($nextLive && ($nextLive['next'] || $nextLive['url'] !== '')): ?>
    <a class="live-banner is-<?= h($nextLive['status']) ?>" href="<?= $nextLive['status'] === 'live' && $nextLive['url'] !== '' ? h($nextLive['url']) . '" target="_blank" rel="noopener' : 'cursos.php' ?>">
      <span class="live-pill"><?php if ($nextLive['status'] === 'live'): ?><i class="live-dot"></i>En vivo ahora<?php else: ?>Clase en vivo<?php endif; ?></span>
      <span>
        <small><?= h($nextLive['course_title']) ?></small>
        <strong><?= h($nextLive['next'] ? live_when((int) $nextLive['next']['ts']) : ($nextLive['schedule'] !== '' ? $nextLive['schedule'] : 'Ver horario')) ?></strong>
      </span>
      <em><?= $nextLive['status'] === 'live' ? 'Entrar' : 'Ver cursos' ?> →</em>
    </a>
  <?php endif; ?>
  <?php if ($continue): ?>
    <?php $thumb = recording_thumbnail($continue); ?>
    <a class="continue-card" href="resource.php?id=<?= (int) $continue['id'] ?>">
      <span class="continue-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>><span class="continue-play"><?= aca_icon('play') ?></span></span>
      <span class="continue-text">
        <small><?= $unseen ? 'Seguir viendo' : 'Volver a ver' ?> · <?= h($continue['course_title']) ?><?= isset($labels[(int) $continue['id']]) ? ' · ' . h($labels[(int) $continue['id']]) : '' ?></small>
        <strong><?= h($continue['title']) ?></strong>
      </span>
      <span class="continue-go" aria-hidden="true">→</span>
    </a>
  <?php else: ?>
    <div class="actions" style="position:relative;z-index:1">
      <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
    </div>
  <?php endif; ?>
</section>

<div class="stat-grid student-stats">
  <a class="stat" href="cursos.php">
    <span class="stat-ico"><?= aca_icon('book') ?></span>
    <span class="stat-label">Mis cursos</span>
    <strong><?= count($courses) ?></strong>
    <em>Abrí uno con su foto</em>
  </a>
  <a class="stat" href="cursos.php">
    <span class="stat-ico"><?= aca_icon('play') ?></span>
    <span class="stat-label">Clases grabadas</span>
    <strong><?= count($allRecordings) ?></strong>
    <em><?= $seenCount ?> vista<?= $seenCount === 1 ? '' : 's' ?> · <?= count($unseen) ?> por ver</em>
  </a>
  <a class="stat<?= $pendingTickets > 0 ? ' is-alert' : '' ?>" href="tickets.php">
    <span class="stat-ico"><?= aca_icon('chat') ?></span>
    <span class="stat-label">Consultas</span>
    <strong><?= $pendingTickets ?></strong>
    <em><?= $pendingTickets > 0 ? 'Respuestas nuevas' : 'Escribile al profesor' ?></em>
  </a>
</div>

<?php if ($courses): ?>
  <div class="section-title">
    <h2>Mis cursos</h2>
    <a href="cursos.php">Ver todos →</a>
  </div>
  <div class="staff-course-grid student-course-grid">
    <?php foreach (array_slice($courses, 0, 4) as $i => $course): ?>
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
          <?php if ($plan['total'] > 0): ?>
            <span class="student-tile-bar" aria-hidden="true"><i style="width:<?= $plan['pct'] ?>%"></i></span>
            <span class="staff-course-tile-foot">
              <span class="badge soft"><?= $plan['seen'] ?>/<?= $plan['total'] ?> clases</span>
              <em>Abrir →</em>
            </span>
          <?php else: ?>
            <span class="staff-course-tile-foot">
              <span class="badge soft">Sin clases</span>
              <em>Abrir →</em>
            </span>
          <?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="panel empty-state student-empty">
    <strong>Todavía no tenés cursos asignados</strong>
    <p class="muted">Cuando administración te asigne uno, aparece en Mis cursos.</p>
    <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
  </div>
<?php endif; ?>

<?php if ($latest): ?>
  <div class="section-title">
    <h2>Últimas clases</h2>
  </div>
  <div class="rec-grid student-rec-grid">
    <?php foreach ($latest as $i => $r): ?>
      <?php
        $thumb = recording_thumbnail($r);
        $isSeen = in_array((int) $r['id'], $doneIds, true);
      ?>
      <a class="rec-card<?= $isSeen ? ' is-seen' : '' ?>" href="resource.php?id=<?= (int) $r['id'] ?>" style="--i:<?= $i ?>">
        <span class="rec-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>>
          <span class="rec-play"><?= aca_icon('play') ?></span>
          <?php if ($isSeen): ?><span class="badge done rec-seen">✓ Vista</span><?php endif; ?>
        </span>
        <span class="rec-body">
          <span class="rec-course"><?= h($r['course_title']) ?><?= isset($labels[(int) $r['id']]) ? ' · ' . h($labels[(int) $r['id']]) : '' ?></span>
          <strong><?= h($r['title']) ?></strong>
          <em><?= h(format_db_time((string) $r['created_at'], 'd/m/Y')) ?></em>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
