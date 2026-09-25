<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
if ($user['role'] === 'admin') {
    // admin can peek own view but usually use admin panel
}
$pdo = db();
$cfg = payment_cfg();
$uid = (int) $user['id'];

if ($user['role'] === 'student' && (int) ($user['fee_active'] ?? 0) === 1) {
    ensure_invoice_for_user($uid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('payments.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);

    $invStmt = $pdo->prepare('SELECT * FROM fee_invoices WHERE id = ? AND user_id = ? LIMIT 1');
    $invStmt->execute([$invoiceId, $uid]);
    $invoice = $invStmt->fetch();
    if (!$invoice || $invoice['status'] === 'paid' || $invoice['status'] === 'waived') {
        flash('error', 'Cuota no disponible para pago.');
        redirect('payments.php');
    }

    if ($action === 'pay_mp') {
        if (!mp_enabled()) {
            flash('error', 'El pago con tarjeta todavía no está habilitado. Usá transferencia o consultá a Fluxus.');
            redirect('payments.php');
        }
        $pref = create_mp_preference($invoice, $user);
        if (empty($pref['ok']) || empty($pref['init_point'])) {
            flash('error', 'No se pudo iniciar Mercado Pago: ' . ($pref['error'] ?? ''));
            redirect('payments.php');
        }
        header('Location: ' . $pref['init_point']);
        exit;
    }

    if ($action === 'pay_transfer') {
        $ref = trim((string) ($_POST['transfer_ref'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($ref === '') {
            flash('error', 'Indicá el número de operación o los últimos dígitos de la transferencia.');
            redirect('payments.php');
        }
        $pdo->prepare("
          INSERT INTO payments (invoice_id, user_id, amount, method, status, transfer_ref, note)
          VALUES (?, ?, ?, 'transfer', 'pending', ?, ?)
        ")->execute([$invoiceId, $uid, (int) $invoice['amount'], $ref, $note]);
        flash('success', 'Transferencia informada. Queda pendiente de confirmación del administrador.');
        redirect('payments.php');
    }

    redirect('payments.php');
}

// Refresh fee_active from DB
$u = $pdo->prepare('SELECT fee_active FROM users WHERE id = ?');
$u->execute([$uid]);
$feeActive = (int) ($u->fetchColumn() ?: 0);

$invoices = $pdo->prepare('SELECT * FROM fee_invoices WHERE user_id = ? ORDER BY period_ym DESC');
$invoices->execute([$uid]);
$invoices = $invoices->fetchAll();

$payments = $pdo->prepare("
  SELECT p.*, i.period_ym, i.label
  FROM payments p
  JOIN fee_invoices i ON i.id = p.invoice_id
  WHERE p.user_id = ?
  ORDER BY p.created_at DESC
  LIMIT 40
");
$payments->execute([$uid]);
$payments = $payments->fetchAll();

$pageTitle = 'Mis cuotas';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">Pagos</p>
<h1>Mis cuotas</h1>
<p class="lede">Consultá tu estado de cuenta, informá transferencias o pagá con tarjeta (Mercado Pago).</p>
<p><a class="btn secondary" href="dashboard.php">← Mis cursos</a></p>

<?php if (!$feeActive && $user['role'] === 'student'): ?>
  <div class="panel" style="margin-top:1rem">
    <p class="muted">Todavía no tenés una cuota mensual asignada. Cuando Fluxus active tu plan, vas a ver acá el detalle mes a mes.</p>
  </div>
<?php endif; ?>

<section class="panel" style="margin-top:1rem">
  <h2>Estado de cuenta</h2>
  <?php if (!$invoices): ?>
    <p class="muted">Sin cuotas registradas por ahora.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Período</th><th>Monto</th><th>Vence</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><?= h(period_label((string) $inv['period_ym'])) ?></td>
            <td><strong><?= h(money_ars((int) $inv['amount'])) ?></strong></td>
            <td class="small muted"><?= h($inv['due_date'] ?: '—') ?></td>
            <td><span class="badge <?= $inv['status'] === 'paid' ? 'done' : '' ?>"><?= h(invoice_status_label((string) $inv['status'])) ?></span></td>
            <td>
              <?php if (in_array($inv['status'], ['pending', 'overdue'], true)): ?>
                <details class="pay-box">
                  <summary class="btn primary small-btn">Pagar</summary>
                  <div class="pay-options">
                    <?php if (mp_enabled()): ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="pay_mp">
                        <input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                        <button class="btn meet" type="submit">Pagar con tarjeta (Mercado Pago)</button>
                      </form>
                    <?php endif; ?>
                    <div class="transfer-box">
                      <p class="small"><strong>Transferencia bancaria</strong></p>
                      <p class="small muted">
                        Titular: <?= h((string) ($cfg['transfer_holder'] ?? '')) ?><br>
                        Banco: <?= h((string) ($cfg['transfer_bank'] ?? '')) ?><br>
                        Alias: <strong><?= h((string) ($cfg['transfer_alias'] ?? '')) ?></strong><br>
                        <?php if (!empty($cfg['transfer_cbu'])): ?>CBU: <?= h((string) $cfg['transfer_cbu']) ?><br><?php endif; ?>
                        <?= h((string) ($cfg['transfer_note'] ?? '')) ?>
                      </p>
                      <form method="post" class="stack">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="pay_transfer">
                        <input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
                        <label>Nº de operación / referencia
                          <input name="transfer_ref" required placeholder="Ej: 12345678">
                        </label>
                        <label>Nota (opcional)
                          <input name="note" placeholder="Banco desde el que transferiste">
                        </label>
                        <button class="btn primary" type="submit">Ya transferí · informar pago</button>
                      </form>
                    </div>
                  </div>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel" style="margin-top:1rem">
  <h2>Historial de pagos</h2>
  <?php if (!$payments): ?>
    <p class="muted">Todavía no registraste pagos.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Fecha</th><th>Cuota</th><th>Método</th><th>Monto</th><th>Estado</th></tr></thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td class="small"><?= h($p['created_at']) ?></td>
            <td><?= h(period_label((string) $p['period_ym'])) ?></td>
            <td><?= $p['method'] === 'mercadopago' ? 'Tarjeta / MP' : ($p['method'] === 'transfer' ? 'Transferencia' : 'Manual') ?></td>
            <td><?= h(money_ars((int) $p['amount'])) ?></td>
            <td><span class="badge <?= $p['status'] === 'approved' ? 'done' : '' ?>"><?= h(payment_status_label((string) $p['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<style>
.pay-box { display:inline-block; }
.pay-box > summary { list-style:none; cursor:pointer; }
.pay-options {
  margin-top: .75rem;
  padding: 1rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  display: grid;
  gap: .85rem;
  min-width: min(100vw - 3rem, 22rem);
}
.transfer-box { padding-top: .5rem; border-top: 1px solid var(--line); }
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
