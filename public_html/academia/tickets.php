<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = require_login();
if (is_staff($user)) {
    redirect('admin/tickets.php');
}
$pdo = db();

$courses = $pdo->prepare("
  SELECT c.id, c.title FROM courses c
  JOIN enrollments e ON e.course_id = c.id
  WHERE e.user_id = ? AND c.published = 1
  ORDER BY c.sort_order, c.title
");
$courses->execute([(int) $user['id']]);
$courses = $courses->fetchAll();
$courseIds = array_map('intval', array_column($courses, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión expiró. Probá de nuevo.');
        redirect('tickets.php');
    }
    $to = (string) ($_POST['to'] ?? 'teacher');
    $to = isset(TICKET_TO[$to]) ? $to : 'teacher';
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $courseId = in_array($courseId, $courseIds, true) ? $courseId : null;
    $resourceId = (int) ($_POST['resource_id'] ?? 0);
    if ($resourceId > 0) {
        $check = $pdo->prepare('SELECT course_id FROM course_resources WHERE id = ?');
        $check->execute([$resourceId]);
        $resourceCourse = (int) $check->fetchColumn();
        $resourceId = ($resourceCourse > 0 && in_array($resourceCourse, $courseIds, true)) ? $resourceId : null;
    } else {
        $resourceId = null;
    }
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));

    if ($subject === '' || $body === '') {
        flash('error', 'Escribí el asunto y tu consulta.');
        redirect('tickets.php?nuevo=1#nueva');
    }
    $id = ticket_create($user, $to, $courseId, $resourceId, mb_substr($subject, 0, 150), mb_substr($body, 0, 5000));
    flash('success', 'Consulta enviada. Te avisamos por email cuando te respondan.');
    redirect('ticket.php?id=' . $id);
}

$stmt = $pdo->prepare("
  SELECT t.*, c.title AS course_title,
    (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS messages
  FROM tickets t
  LEFT JOIN courses c ON c.id = t.course_id
  WHERE t.user_id = ?
  ORDER BY datetime(t.updated_at) DESC, t.id DESC
");
$stmt->execute([(int) $user['id']]);
$tickets = $stmt->fetchAll();

$prefTo = (string) ($_GET['to'] ?? 'teacher');
$prefCourse = (int) ($_GET['course_id'] ?? 0);
$prefResource = (int) ($_GET['resource_id'] ?? 0);
$prefSubject = '';
if ($prefResource > 0) {
    $r = $pdo->prepare('SELECT title, course_id FROM course_resources WHERE id = ?');
    $r->execute([$prefResource]);
    if ($row = $r->fetch()) {
        $prefSubject = 'Duda sobre: ' . $row['title'];
        $prefCourse = (int) $row['course_id'];
    }
}
$showForm = isset($_GET['nuevo']) || !$tickets;

$pageTitle = 'Mis consultas';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head student-page-head">
  <div>
    <p class="eyebrow">Campus alumno</p>
    <h1>Mis consultas</h1>
    <p class="lede">Escribile al profesor por dudas de las clases, o a administración por acceso e inscripción.</p>
  </div>
</div>

<?php if (!$showForm): ?>
  <p class="actions"><a class="btn primary" href="tickets.php?nuevo=1#nueva">Nueva consulta</a></p>
<?php endif; ?>

<?php if ($showForm): ?>
<section class="panel" id="nueva" style="margin-bottom:1.25rem">
  <h2 class="panel-title">Nueva consulta</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="resource_id" value="<?= $prefResource ?>">
    <fieldset class="check-box">
      <legend>¿A quién le escribís?</legend>
      <label class="check">
        <input type="radio" name="to" value="teacher" <?= $prefTo !== 'admin' ? 'checked' : '' ?>>
        <span><strong>Profesor</strong> · dudas sobre las clases, ejercicios o contenidos</span>
      </label>
      <label class="check">
        <input type="radio" name="to" value="admin" <?= $prefTo === 'admin' ? 'checked' : '' ?>>
        <span><strong>Administración</strong> · acceso, horarios, inscripción u otras consultas</span>
      </label>
    </fieldset>
    <?php if ($courses): ?>
      <label>Curso (opcional)
        <select name="course_id">
          <option value="0">— Ninguno en particular —</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $prefCourse ? 'selected' : '' ?>><?= h($c['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>Asunto <input name="subject" required maxlength="150" value="<?= h($prefSubject) ?>" placeholder="Ej: No me sale el ejercicio del minuto 12"></label>
    <label>Tu consulta <textarea name="body" required rows="6" maxlength="5000" placeholder="Contanos con detalle así te podemos ayudar mejor"></textarea></label>
    <div class="actions">
      <button class="btn primary" type="submit">Enviar consulta</button>
      <?php if ($tickets): ?><a class="btn secondary" href="tickets.php">Cancelar</a><?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($tickets): ?>
<section class="panel">
  <h2 class="panel-title">Historial</h2>
  <?php foreach ($tickets as $t): ?>
    <a class="ticket-row<?= (int) $t['student_unread'] ? ' is-unread' : '' ?>" href="ticket.php?id=<?= (int) $t['id'] ?>">
      <span>
        <strong><?= h($t['subject']) ?></strong>
        <span class="muted small">
          Para <?= h(TICKET_TO[$t['to_role']] ?? 'Profesor') ?>
          <?= $t['course_title'] ? ' · ' . h($t['course_title']) : '' ?>
          · <?= (int) $t['messages'] ?> mensaje<?= (int) $t['messages'] === 1 ? '' : 's' ?>
          · <?= h(format_db_time((string) $t['updated_at'], 'd/m/Y H:i')) ?>
        </span>
      </span>
      <?php if ((int) $t['student_unread']): ?>
        <span class="badge done">Respuesta nueva</span>
      <?php else: ?>
        <span class="badge ticket-<?= h($t['status']) ?>"><?= h($t['status'] === 'open' ? 'Esperando respuesta' : (TICKET_STATUS[$t['status']] ?? '')) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
