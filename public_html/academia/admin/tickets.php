<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();

$status = (string) ($_GET['status'] ?? 'open');
$status = isset(TICKET_STATUS[$status]) || $status === 'all' ? $status : 'open';
$to = (string) ($_GET['to'] ?? '');
$to = isset(TICKET_TO[$to]) ? $to : '';

$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 't.status = ?';
    $params[] = $status;
}
if ($to !== '') {
    $where[] = 't.to_role = ?';
    $params[] = $to;
}
$stmt = db()->prepare("
  SELECT t.*, u.name AS student_name, u.email AS student_email, c.title AS course_title,
    (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS messages,
    (SELECT body FROM ticket_messages m WHERE m.ticket_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_body
  FROM tickets t
  JOIN users u ON u.id = t.user_id
  LEFT JOIN courses c ON c.id = t.course_id
  " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
  ORDER BY datetime(t.updated_at) DESC, t.id DESC
  LIMIT 200
");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$counts = [];
foreach (db()->query('SELECT status, COUNT(*) AS n FROM tickets GROUP BY status')->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['n'];
}

$qs = static fn (array $over) => 'tickets.php?' . http_build_query(array_filter(array_merge(['status' => $status, 'to' => $to], $over), static fn ($v) => $v !== ''));

$pageTitle = 'Consultas';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Consultas de alumnos</h1>
<p class="lede">Las consultas para el profesor y para administración llegan acá y a tu email. Cuando respondés, al alumno le llega un aviso.</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
  <a class="btn secondary" href="grabaciones.php">Clases</a>
</nav>

<nav class="rec-filter" aria-label="Estado">
  <?php foreach (TICKET_STATUS + ['all' => 'Todas'] as $key => $label): ?>
    <a class="btn <?= $status === $key ? 'primary' : 'secondary' ?> small-btn" href="<?= h($qs(['status' => $key])) ?>">
      <?= h($label) ?><?= isset($counts[$key]) ? ' (' . $counts[$key] . ')' : '' ?>
    </a>
  <?php endforeach; ?>
</nav>
<nav class="rec-filter" aria-label="Destinatario">
  <a class="btn <?= $to === '' ? 'primary' : 'secondary' ?> small-btn" href="<?= h($qs(['to' => ''])) ?>">Profesor y administración</a>
  <?php foreach (TICKET_TO as $key => $label): ?>
    <a class="btn <?= $to === $key ? 'primary' : 'secondary' ?> small-btn" href="<?= h($qs(['to' => $key])) ?>">Solo <?= h(mb_strtolower($label)) ?></a>
  <?php endforeach; ?>
</nav>

<section class="panel">
  <?php if (!$tickets): ?>
    <p class="muted">No hay consultas <?= $status === 'open' ? 'sin responder' : 'en esta vista' ?>.</p>
  <?php endif; ?>
  <?php foreach ($tickets as $t): ?>
    <a class="ticket-row<?= $t['status'] === 'open' ? ' is-unread' : '' ?>" href="../ticket.php?id=<?= (int) $t['id'] ?>">
      <span>
        <strong><?= h($t['subject']) ?></strong>
        <span class="muted small">
          <?= h($t['student_name']) ?> · para <?= h(mb_strtolower(TICKET_TO[$t['to_role']] ?? 'profesor')) ?>
          <?= $t['course_title'] ? ' · ' . h($t['course_title']) : '' ?>
          · <?= h(format_db_time((string) $t['updated_at'], 'd/m/Y H:i')) ?>
        </span>
        <span class="small"><?= h(short_text((string) $t['last_body'], 140)) ?></span>
      </span>
      <span class="badge ticket-<?= h($t['status']) ?>"><?= h(TICKET_STATUS[$t['status']] ?? '') ?></span>
    </a>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
