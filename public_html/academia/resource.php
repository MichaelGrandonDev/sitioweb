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
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('resource.php?id=' . $resourceId);
    }
    if (($_POST['action'] ?? '') === 'complete' && (int) $resource['track_completion'] === 1) {
        db()->prepare('INSERT OR IGNORE INTO resource_progress (user_id, resource_id) VALUES (?, ?)')
            ->execute([(int) $user['id'], $resourceId]);
        // Sync lesson progress if linked
        if (!empty($resource['lesson_id'])) {
            db()->prepare('INSERT OR IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)')
                ->execute([(int) $user['id'], (int) $resource['lesson_id']]);
        }
        flash('success', 'Marcado como realizado.');
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
?>
<p class="eyebrow"><?= h($resource['course_title']) ?> · <?= h($resource['section_title']) ?></p>
<h1><?= h($resource['title']) ?></h1>
<p class="lede"><?= h($resource['description']) ?></p>
<p class="actions">
  <a class="btn secondary" href="course.php?id=<?= $courseId ?>">← Volver al campus</a>
  <span class="badge"><?= h($meta['label']) ?></span>
  <?php if ($isDone): ?><span class="badge done">Realizado</span><?php endif; ?>
</p>

<section class="panel" style="margin-top:1rem">
  <?php
    $fileUrl = resource_file_url($resource);
    $hasFile = trim((string) ($resource['file_path'] ?? '')) !== '';
  ?>
  <?php if ($type === 'video'): ?>
    <?php if ($hasFile): ?>
      <video controls playsinline style="width:100%;max-height:70vh;background:#000;border-radius:8px">
        <source src="<?= h($fileUrl) ?>">
      </video>
      <p style="margin-top:.75rem"><a class="btn secondary" href="<?= h($fileUrl) ?>&download=1">Descargar video</a></p>
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
    <p class="muted">Este espacio es informativo. Para consultas, escribinos por WhatsApp o email del curso.</p>
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
    <button class="btn primary" type="submit">Marcar como realizado</button>
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
