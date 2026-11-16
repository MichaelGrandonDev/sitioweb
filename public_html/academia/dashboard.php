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
        <small><?= $unseen ? 'Seguir viendo' : 'Volver a ver' ?> · <?= h($continue['course_title']) ?><?= isset($labels[(int) $continue['id']]) ? ' · ' . h($labels[(int) $continue['id']]) : '' ?></small>
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
          <span class="rec-course"><?= h($r['course_title']) ?><?= isset($labels[(int) $r['id']]) ? ' · ' . h($labels[(int) $r['id']]) : '' ?></span>
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
        $plan = $plans[(int) $course['id']];
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
          <?php if ($plan['total'] > 0): ?>
            <p class="course-card-progress">
              <?php if ($plan['complete']): ?>
                <span class="badge done"><?= aca_icon('check') ?> Completado</span>
              <?php endif; ?>
              <span><b><?= $plan['seen'] ?></b> de <?= $plan['total'] ?> clases vistas</span>
            </p>
            <div class="mini-progress"><div class="progress"><span style="width:<?= $plan['pct'] ?>%"></span></div><b><?= $plan['pct'] ?>%</b></div>
            <?php if ($plan['bonus']): ?>
              <p class="muted small" style="margin:.4rem 0 0;display:flex;align-items:center;gap:.35rem">
                <?= aca_icon($plan['bonus_unlocked'] ? 'gift' : 'lock') ?>
                <?= count($plan['bonus']) ?> bonus <?= $plan['bonus_unlocked'] ? 'disponible' . (count($plan['bonus']) === 1 ? '' : 's') : '· se desbloquea' . (count($plan['bonus']) === 1 ? '' : 'n') . ' al completar' ?>
              </p>
            <?php endif; ?>
          <?php else: ?>
            <p class="muted small" style="margin:.8rem 0 0">Todavía no hay clases grabadas.</p>
          <?php endif; ?>
        </a>
        <?= aca_teacher_block($course, 'compact') ?>
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
