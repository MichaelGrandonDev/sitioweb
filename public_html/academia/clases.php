<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();
$pdo = db();

if (is_staff($user)) {
    $courses = $pdo->query('SELECT id, title, teacher_name, teacher_bio, teacher_photo_path FROM courses ORDER BY sort_order, title')->fetchAll();
    if (is_teacher($user)) {
        $allowed = teacher_course_ids($user);
        $courses = array_values(array_filter($courses, static fn ($c) => in_array((int) $c['id'], $allowed, true)));
    }
} else {
    $stmt = $pdo->prepare("
      SELECT c.id, c.title, c.teacher_name, c.teacher_bio, c.teacher_photo_path FROM courses c
      JOIN enrollments e ON e.course_id = c.id
      WHERE e.user_id = ? AND c.published = 1
      ORDER BY c.sort_order, c.title
    ");
    $stmt->execute([(int) $user['id']]);
    $courses = $stmt->fetchAll();
}
$courseIds = array_map('intval', array_column($courses, 'id'));
$courseId = (int) ($_GET['course_id'] ?? 0);
if (!in_array($courseId, $courseIds, true)) {
    $courseId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'clases.php' . ($courseId > 0 ? '?course_id=' . $courseId : '');
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión expiró. Probá de nuevo.');
        redirect($back);
    }
    $rid = (int) ($_POST['resource_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, course_id, type, is_bonus FROM course_resources WHERE id = ? AND type = 'video'");
    $st->execute([$rid]);
    $res = $st->fetch();
    if (($_POST['action'] ?? '') === 'toggle_seen' && $res && in_array((int) $res['course_id'], $courseIds, true) && !recording_is_locked($res, $user)) {
        $cid = (int) $res['course_id'];
        $wasComplete = recording_course_plan($cid, $user)['complete'];
        recording_set_seen((int) $user['id'], $rid, ($_POST['seen'] ?? '') === '1');
        if (!$wasComplete && recording_course_plan($cid, $user)['complete']) {
            $_SESSION['celebrate_course'] = $cid;
        }
    }
    redirect($back . '#rec-' . $rid);
}
$celebrate = (int) ($_SESSION['celebrate_course'] ?? 0);
unset($_SESSION['celebrate_course']);

// Un bloque por curso: clases numeradas en orden + bonus aparte.
$blocks = [];
foreach ($courses as $c) {
    if ($courseId > 0 && (int) $c['id'] !== $courseId) {
        continue;
    }
    $plan = recording_course_plan((int) $c['id'], $user);
    if ($plan['classes'] || $plan['bonus']) {
        $blocks[] = ['course' => $c, 'plan' => $plan];
    }
}
$total = array_sum(array_map(static fn ($b) => $b['plan']['total'], $blocks));
$seen = array_sum(array_map(static fn ($b) => $b['plan']['seen'], $blocks));
$pct = $total > 0 ? (int) round($seen / $total * 100) : 0;
$itemCount = array_sum(array_map(static fn ($b) => count($b['plan']['classes']) + count($b['plan']['bonus']), $blocks));
$isAdmin = is_staff($user);

$live = live_classes_for_user($user, $courseId);
$liveLabels = ['live' => 'En vivo ahora', 'today' => 'Hoy', 'upcoming' => 'Próxima clase', 'weekly' => 'Clase semanal'];

$pageTitle = 'Clases';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<?php
  $courseTitle = '';
  if ($courseId > 0) {
      foreach ($courses as $c) {
          if ((int) $c['id'] === $courseId) {
              $courseTitle = (string) $c['title'];
              break;
          }
      }
  }
?>
<nav class="admin-nav" style="margin-bottom:1rem">
  <?php if ($courseId > 0): ?>
    <a class="btn secondary" href="mi-curso.php?id=<?= $courseId ?>">← <?= h($courseTitle !== '' ? $courseTitle : 'Curso') ?></a>
  <?php endif; ?>
  <a class="btn secondary" href="cursos.php">Mis cursos</a>
</nav>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= $courseTitle !== '' ? h($courseTitle) : 'Campus alumno' ?></p>
    <h1>Clases</h1>
    <p class="lede">En vivo cuando toca, y grabadas cuando quieras. Volvé al curso para ver materiales y Meet.</p>
  </div>
  <?php if (count($blocks) > 1 && $total > 0): ?>
    <div class="stat" style="min-width:210px;padding:.9rem 1.1rem">
      <span class="stat-label">Viste <?= $seen ?> de <?= $total ?> clases</span>
      <div class="mini-progress"><div class="progress"><span style="width:<?= $pct ?>%"></span></div><b><?= $pct ?>%</b></div>
    </div>
  <?php endif; ?>
</div>

<?php if ($courses): ?>
  <div class="toolbar">
    <?php if ($itemCount > 5): ?>
      <label class="search">
        <?= aca_icon('search') ?>
        <input type="search" placeholder="Buscar una clase" aria-label="Buscar clase" data-filter="[data-rec]" data-filter-empty="#no-rec">
      </label>
    <?php endif; ?>
    <?php if (count($courses) > 1): ?>
      <nav class="seg" aria-label="Filtrar por curso">
        <a class="<?= $courseId === 0 ? 'is-on' : '' ?>" href="clases.php">Todos</a>
        <?php foreach ($courses as $c): ?>
          <a class="<?= (int) $c['id'] === $courseId ? 'is-on' : '' ?>" href="clases.php?course_id=<?= (int) $c['id'] ?>"><?= h($c['title']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($courses): ?>
  <div class="section-title"><h2>En vivo</h2></div>
  <?php if (!$live): ?>
    <p class="panel muted live-empty"><?= aca_icon('video') ?> Por ahora no hay clases en vivo programadas. Cuando haya, vas a ver acá el día, la hora y el botón para entrar.</p>
  <?php else: ?>
    <div class="live-grid">
      <?php foreach ($live as $l): ?>
        <article class="live-card is-<?= h($l['status']) ?>">
          <div class="live-top">
            <span class="live-pill"><?php if ($l['status'] === 'live'): ?><i class="live-dot"></i><?php endif; ?><?= h($liveLabels[$l['status']]) ?></span>
            <span class="rec-course"><?= h($l['course_title']) ?></span>
          </div>
          <?php if ($l['next']): ?>
            <strong class="live-when"><?= h(live_when((int) $l['next']['ts'])) ?></strong>
            <span class="live-title"><?= h($l['next']['title']) ?></span>
          <?php else: ?>
            <strong class="live-when"><?= h($l['schedule'] !== '' ? $l['schedule'] : 'Horario a confirmar') ?></strong>
          <?php endif; ?>
          <?php if ($l['next'] && $l['schedule'] !== ''): ?>
            <span class="muted small"><?= aca_icon('clock') ?> Todas las semanas: <?= h($l['schedule']) ?></span>
          <?php endif; ?>
          <?php if ($l['later']): ?>
            <span class="muted small">Después: <?= h(implode(' · ', array_map(static fn ($s) => live_when((int) $s['ts']), $l['later']))) ?></span>
          <?php endif; ?>
          <?php if ($l['url'] !== ''): ?>
            <a class="btn <?= $l['status'] === 'live' ? 'primary' : 'secondary' ?>" href="<?= h($l['url']) ?>" target="_blank" rel="noopener"><?= aca_icon('video') ?> <?= $l['status'] === 'live' ? 'Entrar a la clase' : 'Link de la clase' ?></a>
          <?php else: ?>
            <span class="muted small">El link para entrar aparece acá antes de la clase.</span>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="section-title" style="margin-top:1.8rem"><h2>Grabadas</h2></div>
<?php endif; ?>

<?php if (!$courses): ?>
  <div class="panel empty-state">
    <strong>Todavía no tenés cursos asignados</strong>
    <p class="muted">Cuando administración te asigne un curso, sus clases aparecen acá.</p>
    <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
  </div>
<?php elseif (!$blocks): ?>
  <div class="panel empty-state">
    <strong>Todavía no hay clases grabadas</strong>
    <p class="muted" style="margin:0">Apenas se publique una clase de tus cursos, la vas a ver acá.</p>
  </div>
<?php else: ?>
  <?php
    // Fila de una clase: número, título y botón de vista. Las bonus bloqueadas se muestran sin link.
    $renderItem = static function (array $r, array $course) use ($courseId): void {
        $rid = (int) $r['id'];
        $thumb = recording_thumbnail($r);
        $locked = !empty($r['locked']);
        $label = recording_label($r);
        $tag = $locked ? 'div' : 'a';
        ?>
        <li class="class-item<?= $r['done'] ? ' is-seen' : '' ?><?= $locked ? ' is-locked' : '' ?>" id="rec-<?= $rid ?>" data-rec data-search="<?= h($r['title'] . ' ' . $r['description'] . ' ' . $course['title'] . ' ' . $label) ?>">
          <<?= $tag ?> class="class-link"<?= $locked ? ' aria-disabled="true"' : ' href="resource.php?id=' . $rid . '"' ?>>
            <span class="class-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>>
              <span class="class-num"><?= $locked ? aca_icon('lock') : ($r['done'] ? aca_icon('check') : (int) $r['num']) ?></span>
            </span>
            <span class="class-body">
              <small><?= h($label) ?><?php if ($r['done'] && !$locked): ?> · <b class="class-seen-tag">Vista</b><?php endif; ?></small>
              <strong><?= h($r['title']) ?></strong>
              <?php if ($locked): ?>
                <span class="class-desc">Se desbloquea al completar todas las clases.</span>
              <?php elseif (trim((string) $r['description']) !== ''): ?>
                <span class="class-desc"><?= h(short_text((string) $r['description'], 110)) ?></span>
              <?php endif; ?>
            </span>
          </<?= $tag ?>>
          <?php if (!$locked): ?>
            <form method="post" class="class-check">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle_seen">
              <input type="hidden" name="resource_id" value="<?= $rid ?>">
              <input type="hidden" name="seen" value="<?= $r['done'] ? '0' : '1' ?>">
              <input type="hidden" name="course_id" value="<?= $courseId ?>">
              <button class="seen-toggle<?= $r['done'] ? ' is-on' : '' ?>" type="submit" aria-pressed="<?= $r['done'] ? 'true' : 'false' ?>" title="<?= $r['done'] ? 'Vista. Tocá para desmarcarla' : 'Marcar como vista' ?>">
                <?= aca_icon('check') ?><span><?= $r['done'] ? 'Vista' : 'Marcar como vista' ?></span>
              </button>
            </form>
          <?php endif; ?>
        </li>
        <?php
    };
  ?>
  <?php foreach ($blocks as $b): ?>
    <?php
      $c = $b['course'];
      $p = $b['plan'];
      $cid = (int) $c['id'];
    ?>
    <section class="course-classes" id="curso-<?= $cid ?>">
      <header class="cc-head">
        <div>
          <p class="eyebrow" style="margin:0">Curso</p>
          <h2><?= h($c['title']) ?></h2>
          <?= aca_teacher_block($c, 'compact') ?>
        </div>
        <?php if ($p['total'] > 0): ?>
          <div class="cc-progress">
            <span><b><?= $p['seen'] ?></b> de <?= $p['total'] ?> clases vistas</span>
            <div class="mini-progress"><div class="progress"><span style="width:<?= $p['pct'] ?>%"></span></div><b><?= $p['pct'] ?>%</b></div>
          </div>
        <?php endif; ?>
      </header>

      <?php if ($p['complete']): ?>
        <?= aca_course_done_banner((string) $c['title'], $cid, count($p['bonus']), false, $celebrate === $cid ? 'is-new' : '') ?>
      <?php endif; ?>

      <?php if ($p['classes']): ?>
        <ol class="class-list">
          <?php foreach ($p['classes'] as $r) { $renderItem($r, $c); } ?>
        </ol>
      <?php endif; ?>

      <?php if ($p['bonus']): ?>
        <div class="bonus-block<?= $p['bonus_unlocked'] ? '' : ' is-locked' ?>" id="bonus-<?= $cid ?>">
          <div class="bonus-head">
            <span class="bonus-ico"><?= aca_icon($p['bonus_unlocked'] ? 'gift' : 'lock') ?></span>
            <div>
              <h3>Bonus</h3>
              <p>
                <?php if (!$p['bonus_unlocked']): ?>
                  <?php $left = $p['total'] - $p['seen']; ?>
                  Se desbloquea al completar todas las clases<?= $left > 0 ? ' (te ' . ($left === 1 ? 'falta 1' : 'faltan ' . $left) . ')' : '' ?>.
                <?php elseif ($isAdmin && RECORDING_BONUS_NEEDS_COMPLETION): ?>
                  Los alumnos las ven al completar todas las clases. No cuentan para el progreso.
                <?php else: ?>
                  Clases extra de regalo. No cuentan para el progreso.
                <?php endif; ?>
              </p>
            </div>
          </div>
          <ol class="class-list is-bonus">
            <?php foreach ($p['bonus'] as $r) { $renderItem($r, $c); } ?>
          </ol>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  <p class="panel empty-state" id="no-rec" hidden>No encontramos clases con ese nombre.</p>
<?php endif; ?>

<section class="panel" style="margin-top:1.8rem;display:flex;flex-wrap:wrap;align-items:center;gap:1rem;justify-content:space-between">
  <div>
    <h2 style="margin:0 0 .2rem">¿Tenés una duda?</h2>
    <p class="muted" style="margin:0">Mandá una consulta y te respondemos por acá; también te avisamos por email.</p>
  </div>
  <div class="actions">
    <a class="btn primary" href="tickets.php?nuevo=1&amp;to=teacher#nueva">Consultar al profesor</a>
    <a class="btn secondary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
