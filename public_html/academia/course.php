<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$courseId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) {
    flash('error', 'Curso no encontrado.');
    redirect('dashboard.php');
}
if (!user_can_access_course($user, $courseId)) {
    flash('error', 'No tenés acceso a este curso.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('course.php?id=' . $courseId);
    }
    if (($_POST['action'] ?? '') === 'toggle_resource') {
        $rid = (int) ($_POST['resource_id'] ?? 0);
        $check = db()->prepare('SELECT id, track_completion FROM course_resources WHERE id = ? AND course_id = ?');
        $check->execute([$rid, $courseId]);
        $res = $check->fetch();
        if ($res && (int) $res['track_completion'] === 1) {
            $exists = db()->prepare('SELECT 1 FROM resource_progress WHERE user_id = ? AND resource_id = ?');
            $exists->execute([(int) $user['id'], $rid]);
            if ($exists->fetch()) {
                db()->prepare('DELETE FROM resource_progress WHERE user_id = ? AND resource_id = ?')
                    ->execute([(int) $user['id'], $rid]);
            } else {
                db()->prepare('INSERT OR IGNORE INTO resource_progress (user_id, resource_id) VALUES (?, ?)')
                    ->execute([(int) $user['id'], $rid]);
            }
        }
    }
    redirect('course.php?id=' . $courseId . '#sec-' . (int) ($_POST['section_id'] ?? 0));
}

$sections = db()->prepare('SELECT * FROM course_sections WHERE course_id = ? ORDER BY sort_order, id');
$sections->execute([$courseId]);
$sections = $sections->fetchAll();

$resources = db()->prepare('SELECT * FROM course_resources WHERE course_id = ? ORDER BY sort_order, id');
$resources->execute([$courseId]);
$bySection = [];
foreach ($resources->fetchAll() as $r) {
    $bySection[(int) $r['section_id']][] = $r;
}

$done = db()->prepare('SELECT resource_id FROM resource_progress WHERE user_id = ?');
$done->execute([(int) $user['id']]);
$doneIds = array_map('intval', array_column($done->fetchAll(), 'resource_id'));

$trackable = 0;
$doneTrack = 0;
foreach ($bySection as $list) {
    foreach ($list as $r) {
        if ((int) $r['track_completion'] !== 1) {
            continue;
        }
        $trackable++;
        if (in_array((int) $r['id'], $doneIds, true)) {
            $doneTrack++;
        }
    }
}
$pct = $trackable > 0 ? (int) round(($doneTrack / $trackable) * 100) : 0;

$announcements = db()->prepare('SELECT * FROM course_announcements WHERE course_id = ? ORDER BY datetime(created_at) DESC, id DESC LIMIT 8');
$announcements->execute([$courseId]);
$announcements = $announcements->fetchAll();

$events = db()->prepare("SELECT * FROM course_events WHERE course_id = ? AND datetime(starts_at) >= datetime('now', '-1 day') ORDER BY datetime(starts_at) ASC LIMIT 8");
$events->execute([$courseId]);
$events = $events->fetchAll();

// Fallback: Meet as event if no events
if (!$events && trim((string) ($course['meet_url'] ?? '')) !== '') {
    $events = [[
        'title' => 'Clase en vivo (Meet)',
        'starts_at' => '',
        'location' => (string) ($course['meet_schedule'] ?? 'Semanal'),
        'url' => (string) $course['meet_url'],
    ]];
}

$myCourses = db()->prepare("
  SELECT c.id, c.title FROM courses c
  INNER JOIN enrollments e ON e.course_id = c.id
  WHERE e.user_id = ? AND c.published = 1
  ORDER BY c.sort_order, c.title
");
$myCourses->execute([(int) $user['id']]);
$myCourses = $myCourses->fetchAll();
if ($user['role'] === 'admin' && !$myCourses) {
    $myCourses = db()->query('SELECT id, title FROM courses ORDER BY sort_order, title')->fetchAll();
}

$pageTitle = $course['title'];
$campusMode = true;
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<div class="campus">
  <aside class="campus-nav" id="campus-nav">
    <p class="campus-nav-label">Este curso</p>
    <a class="campus-nav-item active" href="course.php?id=<?= $courseId ?>"><?= h($course['title']) ?></a>
    <a class="campus-nav-item" href="#avisos">Avisos</a>
    <a class="campus-nav-item" href="#eventos">Próximos eventos</a>
    <?php if (trim((string) ($course['meet_url'] ?? '')) !== ''): ?>
      <a class="campus-nav-item meet" href="<?= h((string) $course['meet_url']) ?>" target="_blank" rel="noopener">Clase en vivo (Meet)</a>
    <?php endif; ?>
    <?php if ($user['role'] === 'admin'): ?>
      <a class="campus-nav-item" href="admin/campus.php?course_id=<?= $courseId ?>">Editar campus</a>
    <?php endif; ?>

    <p class="campus-nav-label">Campus</p>
    <a class="campus-nav-item" href="dashboard.php">Área personal</a>
    <a class="campus-nav-item" href="dashboard.php">Mis cursos</a>
    <?php foreach ($myCourses as $mc): ?>
      <?php if ((int) $mc['id'] === $courseId) continue; ?>
      <a class="campus-nav-item subtle" href="course.php?id=<?= (int) $mc['id'] ?>"><?= h($mc['title']) ?></a>
    <?php endforeach; ?>
  </aside>

  <div class="campus-main">
    <header class="campus-course-head">
      <button type="button" class="campus-toggle" id="campus-toggle" aria-label="Menú del curso">☰</button>
      <div>
        <p class="eyebrow"><?= h($course['category'] ?: 'Curso') ?></p>
        <h1><?= h($course['title']) ?></h1>
        <?php if (trim((string) $course['description']) !== ''): ?>
          <p class="lede"><?= h($course['description']) ?></p>
        <?php endif; ?>
      </div>
      <div class="campus-progress-box" aria-label="Progreso del curso">
        <strong><?= $doneTrack ?> / <?= $trackable ?></strong>
        <span>completados</span>
        <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
        <em><?= $pct ?>%</em>
      </div>
    </header>

    <?php if (!$sections): ?>
      <div class="campus-section">
        <div class="campus-section-body">
          <p class="muted">Todavía no hay secciones en este curso. El administrador puede armar el campus desde Admin → Editar campus.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($sections as $section): ?>
      <?php
        $sid = (int) $section['id'];
        $items = $bySection[$sid] ?? [];
        $secTotal = 0;
        $secDone = 0;
        foreach ($items as $r) {
            if ((int) $r['track_completion'] !== 1) {
                continue;
            }
            $secTotal++;
            if (in_array((int) $r['id'], $doneIds, true)) {
                $secDone++;
            }
        }
      ?>
      <section class="campus-section" id="sec-<?= $sid ?>">
        <button type="button" class="campus-section-head" data-collapse aria-expanded="true">
          <span><?= h($section['title']) ?></span>
          <?php if ($secTotal > 0): ?>
            <em class="campus-section-meta">Progreso: <?= $secDone ?> / <?= $secTotal ?></em>
          <?php endif; ?>
          <span class="chev" aria-hidden="true">▾</span>
        </button>
        <div class="campus-section-body">
          <?php if (!$items): ?>
            <p class="muted small">Sin recursos en esta sección.</p>
          <?php endif; ?>
          <?php foreach ($items as $r): ?>
            <?php
              $rid = (int) $r['id'];
              $meta = resource_type_meta((string) $r['type']);
              $isDone = in_array($rid, $doneIds, true);
              $href = 'resource.php?id=' . $rid;
              if ($r['type'] === 'meet' && trim((string) $r['url']) !== '') {
                  $href = (string) $r['url'];
              }
            ?>
            <article class="campus-resource <?= $isDone ? 'is-done' : '' ?>">
              <span class="res-icon type-<?= h((string) $r['type']) ?>" title="<?= h($meta['label']) ?>"><?= h($meta['icon']) ?></span>
              <div class="res-body">
                <h3>
                  <a href="<?= h($href) ?>" <?= $r['type'] === 'meet' ? 'target="_blank" rel="noopener"' : '' ?>>
                    <?= h($r['title']) ?>
                  </a>
                </h3>
                <?php if (trim((string) $r['description']) !== ''): ?>
                  <p><?= h($r['description']) ?></p>
                <?php endif; ?>
              </div>
              <div class="res-actions">
                <?php if ((int) $r['track_completion'] === 1): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_resource">
                    <input type="hidden" name="resource_id" value="<?= $rid ?>">
                    <input type="hidden" name="section_id" value="<?= $sid ?>">
                    <button class="res-check <?= $isDone ? 'on' : '' ?>" type="submit" title="<?= $isDone ? 'Desmarcar' : 'Marcar hecho' ?>">
                      <?= $isDone ? '✓' : '○' ?>
                    </button>
                  </form>
                <?php endif; ?>
                <a class="btn primary small-btn" href="<?= h($href) ?>" <?= $r['type'] === 'meet' ? 'target="_blank" rel="noopener"' : '' ?>>
                  Abrir
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <aside class="campus-aside">
    <section class="campus-widget" id="avisos">
      <h2>Últimos avisos</h2>
      <?php if (!$announcements): ?>
        <p class="muted small">Todavía no hay avisos publicados.</p>
      <?php else: ?>
        <ul class="campus-feed">
          <?php foreach ($announcements as $a): ?>
            <li>
              <strong><?= h($a['title']) ?></strong>
              <?php if (trim((string) $a['body']) !== ''): ?>
                <span><?= h(short_text((string) $a['body'], 110)) ?></span>
              <?php endif; ?>
              <em><?= h(date('d M, H:i', strtotime((string) $a['created_at']) ?: time())) ?></em>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="campus-widget" id="eventos">
      <h2>Próximos eventos</h2>
      <?php if (!$events): ?>
        <p class="muted small">Sin eventos próximos.</p>
      <?php else: ?>
        <ul class="campus-feed events">
          <?php foreach ($events as $ev): ?>
            <li>
              <strong><?= h($ev['title']) ?></strong>
              <?php if (!empty($ev['starts_at'])): ?>
                <em><?= h(date('d/m/Y H:i', strtotime((string) $ev['starts_at']) ?: time())) ?></em>
              <?php elseif (!empty($ev['location'])): ?>
                <em><?= h((string) $ev['location']) ?></em>
              <?php endif; ?>
              <?php if (!empty($ev['url'])): ?>
                <a class="btn secondary small-btn" href="<?= h((string) $ev['url']) ?>" target="_blank" rel="noopener">Abrir</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="campus-widget">
      <h2>Participante</h2>
      <p class="campus-user">
        <span class="avatar"><?= h(strtoupper(substr((string) $user['name'], 0, 1))) ?></span>
        <span>
          <strong><?= h($user['name']) ?></strong>
          <em><?= $user['role'] === 'admin' ? 'Administración' : 'Alumno/a' ?></em>
        </span>
      </p>
    </section>
  </aside>
</div>
<script>
(function () {
  var toggle = document.getElementById('campus-toggle');
  var nav = document.getElementById('campus-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      nav.classList.toggle('open');
    });
  }
  document.querySelectorAll('[data-collapse]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var section = btn.closest('.campus-section');
      if (!section) return;
      section.classList.toggle('collapsed');
      btn.setAttribute('aria-expanded', section.classList.contains('collapsed') ? 'false' : 'true');
    });
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
