<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$isStaff = $user['role'] === 'admin';
$ticketId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$ticket = ticket_find($ticketId);

if (!$ticket || (!$isStaff && (int) $ticket['user_id'] !== (int) $user['id'])) {
    flash('error', 'Consulta no encontrada.');
    redirect($isStaff ? 'admin/tickets.php' : 'tickets.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión expiró. Probá de nuevo.');
        redirect('ticket.php?id=' . $ticketId);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reply') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            flash('error', 'Escribí un mensaje.');
        } else {
            ticket_reply($ticket, $user, mb_substr($body, 0, 5000));
            flash('success', $isStaff ? 'Respuesta enviada. Le avisamos al alumno por email.' : 'Mensaje enviado.');
        }
    } elseif ($action === 'close' || $action === 'reopen') {
        db()->prepare('UPDATE tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$action === 'close' ? 'closed' : 'open', $ticketId]);
        flash('success', $action === 'close' ? 'Consulta cerrada.' : 'Consulta reabierta.');
    }
    redirect('ticket.php?id=' . $ticketId);
}

if (!$isStaff && (int) $ticket['student_unread'] === 1) {
    db()->prepare('UPDATE tickets SET student_unread = 0 WHERE id = ?')->execute([$ticketId]);
}

$messages = db()->prepare("
  SELECT m.*, u.name, u.role
  FROM ticket_messages m
  JOIN users u ON u.id = m.user_id
  WHERE m.ticket_id = ?
  ORDER BY m.id
");
$messages->execute([$ticketId]);
$messages = $messages->fetchAll();

$pageTitle = 'Consulta';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">
  Consulta para <?= h(TICKET_TO[$ticket['to_role']] ?? 'Profesor') ?>
  <?= $ticket['course_title'] ? ' · ' . h($ticket['course_title']) : '' ?>
</p>
<h1><?= h($ticket['subject']) ?></h1>
<p class="actions">
  <a class="btn secondary" href="<?= $isStaff ? 'admin/tickets.php' : 'tickets.php' ?>">← Volver a consultas</a>
  <span class="badge ticket-<?= h($ticket['status']) ?>"><?= h(TICKET_STATUS[$ticket['status']] ?? '') ?></span>
  <?php if ($ticket['resource_id']): ?>
    <a class="btn secondary small-btn" href="resource.php?id=<?= (int) $ticket['resource_id'] ?>">Clase: <?= h((string) $ticket['resource_title']) ?></a>
  <?php endif; ?>
</p>
<?php if ($isStaff): ?>
  <p class="muted small">Alumno: <strong><?= h($ticket['student_name']) ?></strong> · <?= h($ticket['student_email']) ?></p>
<?php endif; ?>

<section class="ticket-thread">
  <?php foreach ($messages as $m): ?>
    <?php $fromStaff = $m['role'] === 'admin'; ?>
    <article class="ticket-msg<?= $fromStaff ? ' is-staff' : '' ?>">
      <header>
        <strong><?= $fromStaff ? h(TICKET_TO[$ticket['to_role']] ?? 'Profesor') . ' · ' . h($m['name']) : h($m['name']) ?></strong>
        <em><?= h(format_db_time((string) $m['created_at'], 'd/m/Y H:i')) ?></em>
      </header>
      <p><?= nl2br(h($m['body'])) ?></p>
    </article>
  <?php endforeach; ?>
</section>

<section class="panel" style="margin-top:1rem">
  <?php if ($ticket['status'] === 'closed'): ?>
    <p class="muted">Esta consulta está cerrada.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= $ticketId ?>">
      <input type="hidden" name="action" value="reopen">
      <button class="btn secondary" type="submit">Reabrir consulta</button>
    </form>
  <?php else: ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= $ticketId ?>">
      <input type="hidden" name="action" value="reply">
      <label><?= $isStaff ? 'Responder al alumno' : 'Agregar un mensaje' ?>
        <textarea name="body" required rows="5" maxlength="5000"></textarea>
      </label>
      <button class="btn primary" type="submit"><?= $isStaff ? 'Enviar respuesta' : 'Enviar' ?></button>
    </form>
    <form method="post" style="margin-top:.75rem">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= $ticketId ?>">
      <input type="hidden" name="action" value="close">
      <button class="btn secondary small-btn" type="submit"><?= $isStaff ? 'Cerrar consulta' : 'Ya está resuelta, cerrar' ?></button>
    </form>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
