<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$resourceId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare("
  SELECT r.*, s.title AS section_title, c.title AS course_title, c.id AS course_id
  FROM course_resources r
  JOIN course_sections s ON s.id = r.section_id
  JOIN courses c ON c.id = r.course_id
  WHERE r.id = ?
  LIMIT 1
");
$stmt->execute([$resourceId]);
$resource = $stmt->fetch();
if (!$resource) {
    flash('error', 'Recurso no encontrado.');
    redirect('dashboard.php');
}
$courseId = (int) $resource['course_id'];
if (!user_can_access_course($user, $courseId)) {
    flash('error', 'No tenés acceso a este recurso.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = ($_POST['ajax'] ?? '') === '1';
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        if ($ajax) {
            http_response_code(403);
            header('Content-Type: application/json');
            exit('{"ok":false}');
        }
        flash('error', 'Sesión inválida.');
        redirect('resource.php?id=' . $resourceId);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'uncomplete') {
        db()->prepare('DELETE FROM resource_progress WHERE user_id = ? AND resource_id = ?')
            ->execute([(int) $user['id'], $resourceId]);
    }
    if ($action === 'complete' && $ajax) {
        db()->prepare('INSERT OR IGNORE INTO resource_progress (user_id, resource_id) VALUES (?, ?)')
            ->execute([(int) $user['id'], $resourceId]);
    }
    if ($ajax) {
        header('Content-Type: application/json');
        exit(json_encode(['ok' => true, 'done' => $action === 'complete']));
    }
    if ($action === 'complete' && (int) $resource['track_completion'] === 1) {
        db()->prepare('INSERT OR IGNORE INTO resource_progress (user_id, resource_id) VALUES (?, ?)')
            ->execute([(int) $user['id'], $resourceId]);
        // Sync lesson progress if linked
        if (!empty($resource['lesson_id'])) {
            db()->prepare('INSERT OR IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)')
                ->execute([(int) $user['id'], (int) $resource['lesson_id']]);
        }
        flash('success', $resource['type'] === 'video' ? 'Clase marcada como vista.' : 'Marcado como realizado.');
    }
    redirect('resource.php?id=' . $resourceId);
}

$done = db()->prepare('SELECT 1 FROM resource_progress WHERE user_id = ? AND resource_id = ?');
$done->execute([(int) $user['id'], $resourceId]);
$isDone = (bool) $done->fetch();

$type = (string) $resource['type'];
$meta = resource_type_meta($type);
$pageTitle = $resource['title'];
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';

if ($type === 'video'):
    $playlist = recordings_of_course($courseId);
    $doneIds = recording_done_ids((int) $user['id']);
    $pos = (int) array_search($resourceId, array_map('intval', array_column($playlist, 'id')), true);
    $prev = $playlist[$pos - 1] ?? null;
    $next = $playlist[$pos + 1] ?? null;
    $hasFile = trim((string) ($resource['file_path'] ?? '')) !== '';
    $isAdmin = $user['role'] === 'admin';
?>
<a class="btn ghost small-btn" href="clases.php?course_id=<?= $courseId ?>" style="margin:-.4rem 0 .6rem -.6rem"><?= aca_icon('arrow-left') ?> Clases de <?= h($resource['course_title']) ?></a>
<div class="player-layout">
  <div>
    <div class="player" id="player-box">
      <?php if ($hasFile): ?>
        <video id="player" controls playsinline preload="metadata" controlsList="nodownload" disablePictureInPicture disableRemotePlayback x-webkit-airplay="deny">
          <source src="<?= h(resource_file_url($resource)) ?>" type="video/mp4">
        </video>
        <button class="resume-pill" type="button" id="resume" hidden><?= aca_icon('play') ?> <span></span></button>
      <?php else: ?>
        <?= embed_video((string) $resource['url']) ?>
      <?php endif; ?>
    </div>
    <div class="player-meta">
      <p class="eyebrow" style="margin:0"><?= h($resource['course_title']) ?> · Clase <?= $pos + 1 ?> de <?= count($playlist) ?></p>
      <h1><?= h($resource['title']) ?></h1>
      <?php if (trim((string) $resource['description']) !== ''): ?>
        <p class="lede" style="margin:0"><?= nl2br(h((string) $resource['description'])) ?></p>
      <?php endif; ?>
      <div class="player-bar">
        <button class="btn <?= $isDone ? 'secondary is-done' : 'primary' ?> seen-btn" type="button" id="seen" data-done="<?= $isDone ? '1' : '0' ?>">
          <?= aca_icon('check') ?> <span><?= $isDone ? 'Vista' : 'Marcar como vista' ?></span>
        </button>
        <a class="btn secondary" href="tickets.php?nuevo=1&amp;to=teacher&amp;course_id=<?= $courseId ?>&amp;resource_id=<?= $resourceId ?>#nueva"><?= aca_icon('chat') ?> Consultar sobre esta clase</a>
        <?php if ($isAdmin && $hasFile): ?>
          <a class="btn ghost" href="<?= h(resource_file_url($resource)) ?>&download=1"><?= aca_icon('upload') ?> Descargar</a>
        <?php endif; ?>
        <span class="spacer"></span>
        <?php if ($prev): ?><a class="btn ghost" href="resource.php?id=<?= (int) $prev['id'] ?>"><?= aca_icon('arrow-left') ?> Anterior</a><?php endif; ?>
        <?php if ($next): ?><a class="btn secondary" href="resource.php?id=<?= (int) $next['id'] ?>">Siguiente <?= aca_icon('arrow-right') ?></a><?php endif; ?>
      </div>
      <p class="hint" style="margin-top:.2rem">
        <?= $hasFile ? 'Se marca como vista sola cuando llegás al final. Si se corta, retomás desde donde la dejaste.' : '¿No carga el video? Probá desde otro navegador o mandanos una consulta.' ?>
      </p>
    </div>
  </div>

  <aside class="panel playlist">
    <header>
      <h2><?= h($resource['course_title']) ?></h2>
      <p><?= count(array_filter($playlist, static fn ($p) => in_array((int) $p['id'], $doneIds, true))) ?> de <?= count($playlist) ?> clases vistas</p>
    </header>
    <ol>
      <?php foreach ($playlist as $i => $p): ?>
        <?php $pDone = in_array((int) $p['id'], $doneIds, true); ?>
        <li>
          <a class="<?= (int) $p['id'] === $resourceId ? 'is-current' : '' ?>" href="resource.php?id=<?= (int) $p['id'] ?>">
            <span class="pl-num<?= $pDone ? ' is-done' : '' ?>"<?= (int) $p['id'] === $resourceId ? ' id="pl-current"' : '' ?>><?= $pDone ? aca_icon('check') : $i + 1 ?></span>
            <span><?= h($p['title']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </aside>
</div>

<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>;
  var btn = document.getElementById('seen');
  var mark = function (done) {
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('ajax', '1');
    fd.append('action', done ? 'complete' : 'uncomplete');
    return fetch('resource.php?id=<?= $resourceId ?>', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { return; }
      btn.setAttribute('data-done', done ? '1' : '0');
      btn.className = 'btn ' + (done ? 'secondary is-done' : 'primary') + ' seen-btn';
      btn.querySelector('span').textContent = done ? 'Vista' : 'Marcar como vista';
      var cur = document.getElementById('pl-current');
      if (cur) { cur.classList.toggle('is-done', done); }
    });
  };
  btn.addEventListener('click', function () { mark(btn.getAttribute('data-done') !== '1'); });

  var video = document.getElementById('player');
  if (!video) { return; }
  var box = document.getElementById('player-box');
  box.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  video.addEventListener('dragstart', function (e) { e.preventDefault(); });
  var key = 'aca-pos-<?= (int) $user['id'] ?>-<?= $resourceId ?>';
  var saved = Number(localStorage.getItem(key) || 0);
  var pill = document.getElementById('resume');
  var fmt = function (s) { s = Math.floor(s); var m = Math.floor(s / 60); return m + ':' + String(s % 60).padStart(2, '0'); };
  if (saved > 20) {
    pill.hidden = false;
    pill.querySelector('span').textContent = 'Seguir desde ' + fmt(saved);
    pill.addEventListener('click', function () { video.currentTime = saved; video.play(); pill.hidden = true; });
  }
  var last = 0;
  video.addEventListener('play', function () { pill.hidden = true; });
  video.addEventListener('timeupdate', function () {
    if (Math.abs(video.currentTime - last) > 5) {
      last = video.currentTime;
      localStorage.setItem(key, String(Math.floor(video.currentTime)));
    }
    if (video.duration && video.currentTime / video.duration > 0.9 && btn.getAttribute('data-done') !== '1' && !btn.dataset.sent) {
      btn.dataset.sent = '1';
      mark(true);
    }
  });
  video.addEventListener('ended', function () { localStorage.removeItem(key); });
})();
</script>
<?php
    require __DIR__ . '/includes/footer.php';
    return;
endif;
?>
<p class="eyebrow"><?= h($resource['course_title']) ?> · <?= h($resource['section_title']) ?></p>
<h1><?= h($resource['title']) ?></h1>
<p class="lede"><?= h($resource['description']) ?></p>
<p class="actions">
  <a class="btn secondary" href="<?= $type === 'video' ? 'clases.php?course_id=' . $courseId : 'course.php?id=' . $courseId ?>">← Volver</a>
  <a class="btn secondary" href="tickets.php?nuevo=1&amp;to=teacher&amp;course_id=<?= $courseId ?>&amp;resource_id=<?= $resourceId ?>#nueva">Consultar al profesor sobre esta clase</a>
  <span class="badge"><?= h($meta['label']) ?></span>
  <?php if ($isDone): ?><span class="badge done"><?= $type === 'video' ? 'Vista' : 'Realizado' ?></span><?php endif; ?>
</p>

<section class="panel" style="margin-top:1rem">
  <?php
    $fileUrl = resource_file_url($resource);
    $hasFile = trim((string) ($resource['file_path'] ?? '')) !== '';
  ?>
  <?php if ($type === 'video'): ?>
    <?php if ($hasFile): ?>
      <video controls playsinline preload="metadata" controlsList="nodownload" disablePictureInPicture disableRemotePlayback oncontextmenu="return false" style="width:100%;max-height:70vh;background:#000;border-radius:8px">
        <source src="<?= h($fileUrl) ?>" type="video/mp4">
      </video>
      <p class="muted small" style="margin-top:.5rem">¿No se ve el video? Probá desde Safari o avisanos con una consulta.</p>
      <?php if ($user['role'] === 'admin'): ?>
        <p style="margin-top:.75rem"><a class="btn secondary" href="<?= h($fileUrl) ?>&download=1">Descargar video</a></p>
      <?php endif; ?>
    <?php else: ?>
      <?= embed_video((string) $resource['url']) ?>
    <?php endif; ?>
  <?php elseif ($type === 'pdf'): ?>
    <?php if ($fileUrl !== ''): ?>
      <p>
        <a class="btn primary" href="<?= h($fileUrl) ?>" target="_blank" rel="noopener">Abrir PDF</a>
        <a class="btn secondary" href="<?= h($fileUrl) ?><?= $hasFile ? '&download=1' : '' ?>" <?= $hasFile ? '' : 'target="_blank" rel="noopener"' ?>>Descargar</a>
      </p>
      <div class="video-frame" style="min-height:70vh">
        <iframe src="<?= h($fileUrl) ?>" title="PDF" loading="lazy"></iframe>
      </div>
    <?php else: ?>
      <p class="muted">Sin archivo PDF cargado.</p>
    <?php endif; ?>
  <?php elseif ($type === 'file'): ?>
    <?php if ($fileUrl !== ''): ?>
      <?php
        $ext = strtolower(pathinfo((string) ($resource['file_path'] ?: $resource['url']), PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true);
        $isAudio = in_array($ext, ['mp3', 'm4a'], true);
      ?>
      <?php if ($isImage): ?>
        <img src="<?= h($fileUrl) ?>" alt="<?= h($resource['title']) ?>" style="max-width:100%;border-radius:8px">
      <?php elseif ($isAudio): ?>
        <audio controls src="<?= h($fileUrl) ?>" style="width:100%"></audio>
      <?php endif; ?>
      <p style="margin-top:.75rem">
        <a class="btn primary" href="<?= h($fileUrl) ?><?= $hasFile ? '&download=1' : '' ?>" <?= $hasFile ? '' : 'target="_blank" rel="noopener"' ?>>
          Descargar / abrir archivo
        </a>
      </p>
    <?php else: ?>
      <p class="muted">Sin archivo cargado.</p>
    <?php endif; ?>
  <?php elseif ($type === 'meet' || $type === 'link'): ?>
    <?php if (trim((string) $resource['url']) !== ''): ?>
      <p><a class="btn <?= $type === 'meet' ? 'meet' : 'primary' ?>" href="<?= h((string) $resource['url']) ?>" target="_blank" rel="noopener">
        <?= $type === 'meet' ? 'Unirme a la clase en vivo' : 'Abrir enlace' ?>
      </a></p>
    <?php else: ?>
      <p class="muted">Sin enlace configurado.</p>
    <?php endif; ?>
  <?php elseif ($type === 'page'): ?>
    <div class="page-content"><?= nl2br(h((string) $resource['content'])) ?></div>
  <?php elseif ($type === 'forum'): ?>
    <p class="muted">Este espacio es informativo. Para dudas, <a href="tickets.php?nuevo=1&amp;to=teacher&amp;course_id=<?= $courseId ?>#nueva">mandá una consulta al profesor</a>.</p>
    <?php if (trim((string) $resource['content']) !== ''): ?>
      <div class="page-content"><?= nl2br(h((string) $resource['content'])) ?></div>
    <?php endif; ?>
  <?php elseif ($type === 'folder'): ?>
    <p class="muted">Carpeta de materiales. Abrí el enlace para ver los archivos.</p>
    <?php if (trim((string) $resource['url']) !== ''): ?>
      <p><a class="btn primary" href="<?= h((string) $resource['url']) ?>" target="_blank" rel="noopener">Abrir carpeta</a></p>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">Recurso disponible.</p>
    <?php if ($fileUrl !== ''): ?>
      <p><a class="btn primary" href="<?= h($fileUrl) ?>" target="_blank" rel="noopener">Abrir</a></p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ((int) $resource['track_completion'] === 1 && !$isDone): ?>
  <form method="post" class="stack" style="margin-top:1rem;max-width:28rem">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="complete">
    <button class="btn primary" type="submit"><?= $type === 'video' ? 'Marcar como vista' : 'Marcar como realizado' ?></button>
  </form>
<?php endif; ?>

<?php
// Legacy tasks if linked to a lesson
if (!empty($resource['lesson_id'])) {
    $tasks = db()->prepare('SELECT * FROM tasks WHERE lesson_id = ? ORDER BY sort_order, id');
    $tasks->execute([(int) $resource['lesson_id']]);
    $tasks = $tasks->fetchAll();
    if ($tasks) {
        echo '<section class="panel" style="margin-top:1rem"><h2>Tareas</h2>';
        foreach ($tasks as $task) {
            echo '<div class="task"><h3>' . h($task['title']) . '</h3><p class="muted">' . nl2br(h($task['instructions'])) . '</p></div>';
        }
        echo '</section>';
    }
}
?>
<?php require __DIR__ . '/includes/footer.php'; ?>
