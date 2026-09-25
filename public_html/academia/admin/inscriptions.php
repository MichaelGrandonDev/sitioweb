<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('inscriptions.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve' && $id > 0) {
        // Si venía de transferencia, al aprobar cupo también damos por pago
        $pdo->prepare("UPDATE inscriptions SET status = 'pending_approval' WHERE id = ? AND status = 'pending_payment'")
            ->execute([$id]);
        $res = approve_inscription($id);
        if (!empty($res['ok'])) {
            flash('success', !empty($res['mail'])
                ? 'Cupo aprobado y mail de acceso enviado.'
                : 'Cupo aprobado, pero el mail no se pudo enviar. Revisá el correo del alumno.');
        } else {
            flash('error', (string) ($res['error'] ?? 'No se pudo aprobar'));
        }
    }

    if ($action === 'reject' && $id > 0) {
        $pdo->prepare("UPDATE inscriptions SET status = 'rejected' WHERE id = ?")->execute([$id]);
        flash('success', 'Solicitud rechazada.');
    }

    if ($action === 'save_settings') {
        payment_setting_set('inscription_amount', (string) max(0, (int) ($_POST['inscription_amount'] ?? 0)));
        $notify = trim((string) ($_POST['admin_notify_email'] ?? ''));
        if ($notify === '' || filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            payment_setting_set('admin_notify_email', $notify);
        }
        flash('success', 'Configuración de inscripción guardada.');
    }

    redirect('inscriptions.php');
}

$rows = $pdo->query("
  SELECT i.*, c.title AS course_title
  FROM inscriptions i
  LEFT JOIN courses c ON c.id = i.course_id
  ORDER BY i.created_at DESC
  LIMIT 100
")->fetchAll();
$cfg = payment_cfg();

$pageTitle = 'Inscripciones';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Inscripciones y cupos</h1>
<p class="lede">Cuando alguien se inscribe a AcademiaFluxus (gratis o paga), llega acá. Al aprobar el cupo se crea el usuario y se envía el mail con contraseña y link.</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
  <a class="btn secondary" href="payments.php">Cuotas</a>
</nav>

<section class="panel" style="margin:1rem 0">
  <h2>Configuración de inscripción</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_settings">
    <div class="form-grid two">
      <label>Monto inscripción (ARS) — 0 = gratuita
        <input type="number" min="0" name="inscription_amount" value="<?= (int) ($cfg['inscription_amount'] ?? 0) ?>">
      </label>
      <label>Email para avisos de cupo
        <input type="email" name="admin_notify_email" value="<?= h((string) ($cfg['admin_notify_email'] ?? '')) ?>" placeholder="hola@fluxusterapia.com">
      </label>
    </div>
    <button class="btn secondary" type="submit">Guardar</button>
  </form>
</section>

<section class="panel">
  <h2>Solicitudes</h2>
  <?php if (!$rows): ?>
    <p class="muted">Todavía no hay inscripciones.</p>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <article class="lesson-card" style="margin-bottom:.65rem;align-items:flex-start">
        <div>
          <strong>#<?= (int) $r['id'] ?> · <?= h($r['name']) ?></strong>
          <span class="badge <?= $r['status'] === 'approved' ? 'done' : '' ?>"><?= h($r['status']) ?></span>
          <p class="muted small">
            <?= h($r['email']) ?>
            <?= $r['phone'] ? ' · ' . h($r['phone']) : '' ?>
            · <?= h($r['course_title'] ?: 'Academia') ?>
            · <?= (int) $r['amount'] > 0 ? h(money_ars((int) $r['amount'])) : 'Gratis' ?>
            <?= $r['transfer_ref'] ? ' · ref ' . h($r['transfer_ref']) : '' ?>
            · <?= h($r['created_at']) ?>
          </p>
        </div>
        <div class="actions" style="flex-direction:column">
          <?php if (in_array($r['status'], ['pending_approval', 'pending_payment', 'paid'], true)): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn primary small-btn" type="submit">Aprobar cupo y enviar acceso</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn danger small-btn" type="submit">Rechazar</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
