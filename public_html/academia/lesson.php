<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$lessonId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare("
  SELECT l.*, c.title AS course_title, c.id AS course_id
  FROM lessons l
  JOIN courses c ON c.id = l.course_id
  WHERE l.id = ?
  LIMIT 1
");
$stmt->execute([$lessonId]);
$lesson = $stmt->fetch();
if (!$lesson) {
    flash('error', 'Clase no encontrada.');
    redirect('dashboard.php');
}

$access = db()->prepare('SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ?');
$access->execute([(int) $user['id'], (int) $lesson['course_id']]);
if (!$access->fetch() && $user['role'] !== 'admin') {
    flash('error', 'No tenés acceso a esta clase.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('lesson.php?id=' . $lessonId);
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'complete_lesson') {
        $ins = db()->prepare('INSERT OR IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)');
        $ins->execute([(int) $user['id'], $lessonId]);
        flash('success', 'Clase marcada como realizada.');
    }
    if ($action === 'complete_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $check = db()->prepare('SELECT id FROM tasks WHERE id = ? AND lesson_id = ?');
        $check->execute([$taskId, $lessonId]);
        if ($check->fetch()) {
            $ins = db()->prepare('INSERT OR REPLACE INTO task_completions (user_id, task_id, note) VALUES (?, ?, ?)');
            $ins->execute([(int) $user['id'], $taskId, $note]);
            flash('success', 'Tarea registrada.');
        }
    }
    redirect('lesson.php?id=' . $lessonId);
}

$tasks = db()->prepare('SELECT * FROM tasks WHERE lesson_id = ? ORDER BY sort_order, id');
$tasks->execute([$lessonId]);
$tasks = $tasks->fetchAll();

$doneLesson = db()->prepare('SELECT 1 FROM lesson_progress WHERE user_id = ? AND lesson_id = ?');
$doneLesson->execute([(int) $user['id'], $lessonId]);
$isDone = (bool) $doneLesson->fetch();

$taskDone = db()->prepare('SELECT task_id, note FROM task_completions WHERE user_id = ?');
$taskDone->execute([(int) $user['id']]);
$taskMap = [];
foreach ($taskDone->fetchAll() as $row) {
    $taskMap[(int) $row['task_id']] = $row['note'];
}

$pageTitle = $lesson['title'];
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow"><?= h($lesson['course_title']) ?></p>
<h1><?= h($lesson['title']) ?></h1>
<p class="lede"><?= h($lesson['description']) ?></p>
<p><a class="btn secondary" href="course.php?id=<?= (int) $lesson['course_id'] ?>">← Volver al curso</a></p>

<?= embed_video((string) $lesson['video_url']) ?>

<form method="post" class="stack" style="margin:1rem 0">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="complete_lesson">
  <?php if ($isDone): ?>
    <span class="badge done">Clase realizada</span>
  <?php else: ?>
    <button class="btn primary" type="submit">Marcar clase como realizada</button>
  <?php endif; ?>
</form>

<?php if ($tasks): ?>
  <section class="panel">
    <h2>Tareas de esta clase</h2>
    <?php foreach ($tasks as $task): ?>
      <?php $done = array_key_exists((int) $task['id'], $taskMap); ?>
      <div class="task">
        <h3><?= h($task['title']) ?></h3>
        <p class="muted"><?= nl2br(h($task['instructions'])) ?></p>
        <?php if ($done): ?>
          <p><span class="badge done">Completada</span></p>
          <?php if ($taskMap[(int) $task['id']] !== ''): ?>
            <p class="muted small">Nota: <?= h($taskMap[(int) $task['id']]) ?></p>
          <?php endif; ?>
        <?php else: ?>
          <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="complete_task">
            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
            <label>Comentario (opcional)
              <textarea name="note" placeholder="Cómo te sentiste, dudas, comentarios..."></textarea>
            </label>
            <button class="btn primary" type="submit">Marcar tarea hecha</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
