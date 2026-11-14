<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();
$pdo = db();

if ($user['role'] === 'admin') {
    $courses = $pdo->query('SELECT id, title FROM courses ORDER BY sort_order, title')->fetchAll();
} else {
    $stmt = $pdo->prepare("
      SELECT c.id, c.title FROM courses c
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

$recordings = recordings_for_user($user, $courseId);
$doneIds = recording_done_ids((int) $user['id']);
$seen = count(array_filter($recordings, static fn ($r) => in_array((int) $r['id'], $doneIds, true)));
$pct = $recordings ? (int) round($seen / count($recordings) * 100) : 0;

$pageTitle = 'Clases grabadas';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Campus alumno</p>
    <h1>Clases grabadas</h1>
    <p class="lede">Las clases de tus cursos, las más nuevas primero. Miralas cuando quieras y las veces que quieras.</p>
  </div>
  <?php if ($recordings): ?>
    <div class="stat" style="min-width:210px;padding:.9rem 1.1rem">
      <span class="stat-label">Viste <?= $seen ?> de <?= count($recordings) ?></span>
      <div class="mini-progress"><div class="progress"><span style="width:<?= $pct ?>%"></span></div><b><?= $pct ?>%</b></div>
    </div>
  <?php endif; ?>
</div>

<?php if ($courses): ?>
  <div class="toolbar">
    <?php if (count($recordings) > 3): ?>
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

<?php if (!$courses): ?>
  <div class="panel empty-state">
    <strong>Todavía no tenés cursos asignados</strong>
    <p class="muted">Cuando administración te asigne un curso, sus clases aparecen acá.</p>
    <a class="btn primary" href="tickets.php?nuevo=1&amp;to=admin#nueva">Escribir a administración</a>
  </div>
<?php elseif (!$recordings): ?>
  <div class="panel empty-state">
    <strong>Todavía no hay clases grabadas</strong>
    <p class="muted" style="margin:0">Apenas se publique una clase de tus cursos, la vas a ver acá.</p>
  </div>
<?php else: ?>
  <div class="rec-grid">
    <?php foreach ($recordings as $r): ?>
      <?php
        $rid = (int) $r['id'];
        $isSeen = in_array($rid, $doneIds, true);
        $thumb = recording_thumbnail($r);
      ?>
      <a class="rec-card<?= $isSeen ? ' is-seen' : '' ?>" href="resource.php?id=<?= $rid ?>" data-rec data-search="<?= h($r['title'] . ' ' . $r['description'] . ' ' . $r['course_title']) ?>">
        <span class="rec-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . h($thumb) . '\')"' : '' ?>>
          <span class="rec-play"><?= aca_icon('play') ?></span>
          <span class="rec-kind"><?= h(recording_kind($r)) ?></span>
          <?php if ($isSeen): ?><span class="badge done rec-seen">✓ Vista</span><?php endif; ?>
        </span>
        <span class="rec-body">
          <span class="rec-course"><?= h($r['course_title']) ?></span>
          <strong><?= h($r['title']) ?></strong>
          <?php if (trim((string) $r['description']) !== ''): ?>
            <span class="rec-desc"><?= h(short_text((string) $r['description'], 110)) ?></span>
          <?php endif; ?>
          <em><?= h(format_db_time((string) $r['created_at'], 'd/m/Y')) ?></em>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
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
