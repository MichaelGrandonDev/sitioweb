<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$admin = require_admin();
$pdo = db();
$cfg = payment_cfg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('payments.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_transfer_data') {
        payment_settings_save([
            'transfer_holder' => (string) ($_POST['transfer_holder'] ?? ''),
            'transfer_bank' => (string) ($_POST['transfer_bank'] ?? ''),
            'transfer_alias' => (string) ($_POST['transfer_alias'] ?? ''),
            'transfer_cbu' => (string) ($_POST['transfer_cbu'] ?? ''),
            'transfer_note' => (string) ($_POST['transfer_note'] ?? ''),
        ]);
        flash('success', 'Datos de transferencia actualizados. Los alumnos ya ven el nuevo CBU/alias.');
    }

    if ($action === 'save_plan') {
        $amount = (int) ($_POST['amount'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? 'Cuota mensual'));
        if ($amount > 0) {
            $plan = default_fee_plan();
            if ($plan) {
                $pdo->prepare('UPDATE fee_plans SET name = ?, amount = ? WHERE id = ?')
                    ->execute([$name, $amount, (int) $plan['id']]);
            } else {
                $pdo->prepare('INSERT INTO fee_plans (name, amount, active) VALUES (?, ?, 1)')
                    ->execute([$name, $amount]);
            }
            payment_setting_set('default_monthly_amount', (string) $amount);
            flash('success', 'Monto de cuota actualizado: ' . money_ars($amount));
        }
    }

    if ($action === 'generate_month') {
        $ym = trim((string) ($_POST['period_ym'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = date('Y-m');
        }
        $n = generate_monthly_invoices($ym);
        flash('success', $n > 0
            ? "Se generaron {$n} cuotas de " . period_label($ym) . '.'
            : 'No había cuotas nuevas para ' . period_label($ym) . ' (¿alumnos sin cuota activa?).');
    }

    if ($action === 'set_student_fee') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $active = !empty($_POST['fee_active']) ? 1 : 0;
        $pdo->prepare("UPDATE users SET fee_active = ? WHERE id = ? AND role = 'student'")
            ->execute([$active, $uid]);
        flash('success', $active ? 'Cuota activada para el alumno.' : 'Cuota desactivada.');
    }

    if ($action === 'create_invoice') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $ym = trim((string) ($_POST['period_ym'] ?? date('Y-m')));
        $amount = (int) ($_POST['amount'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = date('Y-m');
        }
        $plan = default_fee_plan();
        if ($amount <= 0) {
            $amount = $plan ? (int) $plan['amount'] : (int) ($cfg['default_monthly_amount'] ?? 25000);
        }
        if ($uid > 0) {
            try {
                $pdo->prepare("
                  INSERT INTO fee_invoices (user_id, plan_id, period_ym, label, amount, status, due_date)
                  VALUES (?, ?, ?, ?, ?, 'pending', ?)
                ")->execute([
                    $uid,
                    $plan ? (int) $plan['id'] : null,
                    $ym,
                    'Cuota ' . period_label($ym),
                    $amount,
                    $ym . '-10',
                ]);
                flash('success', 'Cuota creada para el alumno.');
            } catch (Throwable $e) {
                flash('error', 'No se pudo crear (¿ya existe esa cuota para ese mes?).');
            }
        }
    }

    if ($action === 'edit_invoice') {
        $iid = (int) ($_POST['invoice_id'] ?? 0);
        $amount = (int) ($_POST['amount'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'pending');
        $due = trim((string) ($_POST['due_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (!in_array($status, ['paid', 'pending', 'waived', 'overdue'], true)) {
            $status = 'pending';
        }
        if ($iid > 0 && $amount > 0) {
            $pdo->prepare('UPDATE fee_invoices SET amount = ?, status = ?, due_date = ?, notes = ? WHERE id = ?')
                ->execute([$amount, $status, $due, $notes, $iid]);
            if ($status === 'paid') {
                mark_invoice_paid($iid, $notes !== '' ? $notes : 'Actualizado por admin');
            }
            flash('success', 'Cuota modificada.');
        }
    }

    if ($action === 'delete_invoice') {
        $iid = (int) ($_POST['invoice_id'] ?? 0);
        if ($iid > 0) {
            $pdo->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$iid]);
            $pdo->prepare('DELETE FROM fee_invoices WHERE id = ?')->execute([$iid]);
            flash('success', 'Cuota eliminada.');
        }
    }

    if ($action === 'confirm_payment') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $pay = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $pay->execute([$pid]);
        $row = $pay->fetch();
        if ($row) {
            $pdo->prepare("UPDATE payments SET status = 'approved', confirmed_at = ?, confirmed_by = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), (int) $admin['id'], $pid]);
            mark_invoice_paid((int) $row['invoice_id'], 'Confirmado por admin');
            flash('success', 'Pago confirmado.');
        }
    }

    if ($action === 'reject_payment') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $pdo->prepare("UPDATE payments SET status = 'rejected', confirmed_at = ?, confirmed_by = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), (int) $admin['id'], $pid]);
        flash('success', 'Pago rechazado.');
    }

    if ($action === 'edit_payment') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $amount = (int) ($_POST['amount'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'pending');
        $method = (string) ($_POST['method'] ?? 'manual');
        $ref = trim((string) ($_POST['transfer_ref'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
            $status = 'pending';
        }
        if (!in_array($method, ['transfer', 'mercadopago', 'manual', 'cash'], true)) {
            $method = 'manual';
        }
        $pay = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $pay->execute([$pid]);
        $row = $pay->fetch();
        if ($row && $amount > 0) {
            $pdo->prepare('UPDATE payments SET amount = ?, status = ?, method = ?, transfer_ref = ?, note = ?, confirmed_at = ?, confirmed_by = ? WHERE id = ?')
                ->execute([
                    $amount,
                    $status,
                    $method,
                    $ref,
                    $note,
                    $status === 'approved' ? date('Y-m-d H:i:s') : null,
                    $status === 'approved' ? (int) $admin['id'] : null,
                    $pid,
                ]);
            if ($status === 'approved') {
                mark_invoice_paid((int) $row['invoice_id'], $note !== '' ? $note : 'Pago editado por admin');
            } elseif ($status === 'rejected') {
                // si era la única aprobación, volver cuota a pending
                $pdo->prepare("UPDATE fee_invoices SET status = 'pending' WHERE id = ? AND status = 'paid'")
                    ->execute([(int) $row['invoice_id']]);
            }
            flash('success', 'Pago modificado.');
        }
    }

    if ($action === 'delete_payment') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $pay = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
        $pay->execute([$pid]);
        $row = $pay->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$pid]);
            $left = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'approved'");
            $left->execute([(int) $row['invoice_id']]);
            if ((int) $left->fetchColumn() === 0) {
                $pdo->prepare("UPDATE fee_invoices SET status = 'pending' WHERE id = ? AND status = 'paid'")
                    ->execute([(int) $row['invoice_id']]);
            }
            flash('success', 'Pago eliminado.');
        }
    }

    if ($action === 'add_payment') {
        $iid = (int) ($_POST['invoice_id'] ?? 0);
        $amount = (int) ($_POST['amount'] ?? 0);
        $method = (string) ($_POST['method'] ?? 'manual');
        $status = (string) ($_POST['status'] ?? 'approved');
        $ref = trim((string) ($_POST['transfer_ref'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $inv = $pdo->prepare('SELECT * FROM fee_invoices WHERE id = ? LIMIT 1');
        $inv->execute([$iid]);
        $invoice = $inv->fetch();
        if ($invoice && $amount > 0) {
            if (!in_array($method, ['transfer', 'mercadopago', 'manual', 'cash'], true)) {
                $method = 'manual';
            }
            if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
                $status = 'approved';
            }
            $pdo->prepare("
              INSERT INTO payments (invoice_id, user_id, amount, method, status, transfer_ref, note, confirmed_at, confirmed_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $iid,
                (int) $invoice['user_id'],
                $amount,
                $method,
                $status,
                $ref,
                $note,
                $status === 'approved' ? date('Y-m-d H:i:s') : null,
                $status === 'approved' ? (int) $admin['id'] : null,
            ]);
            if ($status === 'approved') {
                mark_invoice_paid($iid, $note !== '' ? $note : 'Pago cargado por admin');
            }
            flash('success', 'Pago registrado.');
        }
    }

    if ($action === 'mark_invoice') {
        $iid = (int) ($_POST['invoice_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'paid');
        if (!in_array($status, ['paid', 'pending', 'waived', 'overdue'], true)) {
            $status = 'paid';
        }
        $pdo->prepare('UPDATE fee_invoices SET status = ? WHERE id = ?')->execute([$status, $iid]);
        if ($status === 'paid') {
            $inv = $pdo->prepare('SELECT * FROM fee_invoices WHERE id = ?');
            $inv->execute([$iid]);
            $invoice = $inv->fetch();
            if ($invoice) {
                $pdo->prepare("
                  INSERT INTO payments (invoice_id, user_id, amount, method, status, note, confirmed_at, confirmed_by)
                  VALUES (?, ?, ?, 'manual', 'approved', 'Marcado por admin', ?, ?)
                ")->execute([
                    $iid,
                    (int) $invoice['user_id'],
                    (int) $invoice['amount'],
                    date('Y-m-d H:i:s'),
                    (int) $admin['id'],
                ]);
            }
        }
        flash('success', 'Cuota actualizada.');
    }

    redirect('payments.php');
}

$cfg = payment_cfg(); // refresh after possible POST (redirect anyway)
$plan = default_fee_plan();
$period = date('Y-m');

$pendingTransfers = $pdo->query("
  SELECT p.*, u.name AS student_name, i.period_ym, i.label
  FROM payments p
  JOIN users u ON u.id = p.user_id
  JOIN fee_invoices i ON i.id = p.invoice_id
  WHERE p.status = 'pending'
  ORDER BY p.created_at DESC
")->fetchAll();

$allPayments = $pdo->query("
  SELECT p.*, u.name AS student_name, i.period_ym
  FROM payments p
  JOIN users u ON u.id = p.user_id
  JOIN fee_invoices i ON i.id = p.invoice_id
  ORDER BY p.created_at DESC
  LIMIT 80
")->fetchAll();

$invoices = $pdo->query("
  SELECT i.*, u.name AS student_name, u.username
  FROM fee_invoices i
  JOIN users u ON u.id = i.user_id
  ORDER BY i.period_ym DESC, u.name ASC
  LIMIT 120
")->fetchAll();

$students = $pdo->query("
  SELECT id, name, username, email, fee_active
  FROM users WHERE role = 'student'
  ORDER BY name
")->fetchAll();

$stats = [
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM fee_invoices WHERE status = 'pending'")->fetchColumn(),
    'paid' => (int) $pdo->query("SELECT COUNT(*) FROM fee_invoices WHERE status = 'paid'")->fetchColumn(),
    'month_paid' => (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fee_invoices WHERE status = 'paid' AND period_ym = '" . date('Y-m') . "'")->fetchColumn(),
    'month_pending' => (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fee_invoices WHERE status = 'pending' AND period_ym = '" . date('Y-m') . "'")->fetchColumn(),
];

$pageTitle = 'Cuotas y pagos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Cuotas y pagos</h1>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
  <a class="btn secondary" href="campus.php">Campus</a>
</nav>

<div class="grid metrics-grid">
  <div class="panel metric">
    <p class="metric-label">Cuotas pendientes</p>
    <h2><?= $stats['pending'] ?></h2>
  </div>
  <div class="panel metric">
    <p class="metric-label">Cuotas pagadas</p>
    <h2><?= $stats['paid'] ?></h2>
  </div>
  <div class="panel metric">
    <p class="metric-label">Cobrado este mes</p>
    <h2><?= h(money_ars($stats['month_paid'])) ?></h2>
  </div>
  <div class="panel metric">
    <p class="metric-label">Por cobrar este mes</p>
    <h2><?= h(money_ars($stats['month_pending'])) ?></h2>
  </div>
</div>

<section class="panel" style="margin-top:1rem">
  <h2>Datos para transferencias (CBU / Alias)</h2>
  <p class="muted small">Esto es lo que ven los alumnos al pagar. Podés cambiarlo cuando quieras.</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_transfer_data">
    <div class="form-grid two">
      <label>Titular <input name="transfer_holder" value="<?= h((string) ($cfg['transfer_holder'] ?? '')) ?>" required></label>
      <label>Banco <input name="transfer_bank" value="<?= h((string) ($cfg['transfer_bank'] ?? '')) ?>"></label>
      <label>Alias
        <input name="transfer_alias" value="<?= h((string) ($cfg['transfer_alias'] ?? '')) ?>" placeholder="michael.grandon.mp">
      </label>
      <label>CBU / CVU
        <input name="transfer_cbu" value="<?= h((string) ($cfg['transfer_cbu'] ?? '')) ?>" placeholder="00000031000… o dejá vacío si usás solo alias">
      </label>
    </div>
    <label>Nota para el alumno
      <textarea name="transfer_note"><?= h((string) ($cfg['transfer_note'] ?? '')) ?></textarea>
    </label>
    <button class="btn primary" type="submit">Guardar datos de transferencia</button>
  </form>
</section>

<section class="panel" style="margin-top:1rem">
  <h2>Monto de la cuota mensual</h2>
  <form method="post" class="stack" style="max-width:28rem">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_plan">
    <label>Nombre <input name="name" value="<?= h((string) ($plan['name'] ?? 'Cuota mensual AcademiaFluxus')) ?>"></label>
    <label>Monto (ARS) <input type="number" name="amount" min="1" required value="<?= (int) ($plan['amount'] ?? ($cfg['default_monthly_amount'] ?? 25000)) ?>"></label>
    <button class="btn primary" type="submit">Guardar monto</button>
  </form>
  <p class="muted small" style="margin-top:.75rem">
    Mercado Pago tarjeta: <?= mp_enabled() ? 'configurado ✓' : 'pendiente (token en config.php)' ?>.
  </p>
</section>

<section class="panel" style="margin-top:1rem">
  <h2>Generar cuotas del mes</h2>
  <form method="post" class="stack" style="max-width:20rem">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="generate_month">
    <label>Mes <input type="month" name="period_ym" value="<?= h($period) ?>"></label>
    <button class="btn primary" type="submit">Generar cuotas</button>
  </form>
</section>

<section class="panel" style="margin-top:1rem">
  <h2>Crear cuota manual</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_invoice">
    <div class="form-grid two">
      <label>Alumno
        <select name="user_id" required>
          <option value="">Elegí…</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['username']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Mes <input type="month" name="period_ym" value="<?= h($period) ?>"></label>
      <label>Monto (ARS) <input type="number" name="amount" min="1" placeholder="Vacío = monto default"></label>
    </div>
    <button class="btn secondary" type="submit">Crear cuota</button>
  </form>
</section>

<?php if ($pendingTransfers): ?>
<section class="panel" style="margin-top:1rem">
  <h2>Pagos por confirmar</h2>
  <table class="table">
    <thead><tr><th>Alumno</th><th>Cuota</th><th>Método</th><th>Monto</th><th>Referencia</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pendingTransfers as $p): ?>
        <tr>
          <td><?= h($p['student_name']) ?></td>
          <td><?= h(period_label((string) $p['period_ym'])) ?></td>
          <td><?= h($p['method']) ?></td>
          <td><?= h(money_ars((int) $p['amount'])) ?></td>
          <td class="small"><?= h($p['transfer_ref'] ?: $p['note']) ?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="confirm_payment">
              <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
              <button class="btn primary small-btn" type="submit">Confirmar</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="reject_payment">
              <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
              <button class="btn danger small-btn" type="submit">Rechazar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:1rem">
  <h2>Administrar pagos</h2>
  <p class="muted small">Editá monto, estado, método o borrá un pago.</p>
  <?php foreach ($allPayments as $p): ?>
    <details class="panel" style="margin:.65rem 0;padding:.85rem 1rem">
      <summary>
        <strong><?= h($p['student_name']) ?></strong> ·
        <?= h(period_label((string) $p['period_ym'])) ?> ·
        <?= h(money_ars((int) $p['amount'])) ?> ·
        <span class="badge <?= $p['status'] === 'approved' ? 'done' : '' ?>"><?= h(payment_status_label((string) $p['status'])) ?></span>
      </summary>
      <form method="post" class="stack" style="margin-top:.85rem">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_payment">
        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
        <div class="form-grid two">
          <label>Monto <input type="number" name="amount" min="1" value="<?= (int) $p['amount'] ?>"></label>
          <label>Estado
            <select name="status">
              <?php foreach (['pending' => 'En revisión', 'approved' => 'Aprobado', 'rejected' => 'Rechazado'] as $k => $lab): ?>
                <option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= $lab ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Método
            <select name="method">
              <?php foreach (['transfer' => 'Transferencia', 'mercadopago' => 'Mercado Pago', 'manual' => 'Manual', 'cash' => 'Efectivo'] as $k => $lab): ?>
                <option value="<?= $k ?>" <?= $p['method'] === $k ? 'selected' : '' ?>><?= $lab ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Referencia <input name="transfer_ref" value="<?= h((string) $p['transfer_ref']) ?>"></label>
        </div>
        <label>Nota <input name="note" value="<?= h((string) $p['note']) ?>"></label>
        <div class="actions">
          <button class="btn primary" type="submit">Guardar cambios</button>
        </div>
      </form>
      <form method="post" style="margin-top:.5rem" onsubmit="return confirm('¿Borrar este pago?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_payment">
        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
        <button class="btn danger small-btn" type="submit">Eliminar pago</button>
      </form>
    </details>
  <?php endforeach; ?>
  <?php if (!$allPayments): ?>
    <p class="muted">Todavía no hay pagos registrados.</p>
  <?php endif; ?>
</section>

<section class="panel" style="margin-top:1rem">
  <h2>Alumnos · cuota activa</h2>
  <table class="table">
    <thead><tr><th>Alumno</th><th>Usuario</th><th>Cuota</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <td><?= h($s['name']) ?></td>
          <td class="small muted"><?= h($s['username']) ?></td>
          <td><?= (int) $s['fee_active'] ? '<span class="badge done">Activa</span>' : '<span class="badge">Off</span>' ?></td>
          <td>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_student_fee">
              <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
              <?php if ((int) $s['fee_active']): ?>
                <button class="btn secondary small-btn" type="submit">Desactivar</button>
              <?php else: ?>
                <input type="hidden" name="fee_active" value="1">
                <button class="btn primary small-btn" type="submit">Activar cuota</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="panel" style="margin-top:1rem">
  <h2>Cuotas · editar / borrar</h2>
  <?php foreach ($invoices as $i): ?>
    <details class="panel" style="margin:.65rem 0;padding:.85rem 1rem">
      <summary>
        <strong><?= h($i['student_name']) ?></strong> ·
        <?= h(period_label((string) $i['period_ym'])) ?> ·
        <?= h(money_ars((int) $i['amount'])) ?> ·
        <span class="badge <?= $i['status'] === 'paid' ? 'done' : '' ?>"><?= h(invoice_status_label((string) $i['status'])) ?></span>
      </summary>
      <form method="post" class="stack" style="margin-top:.85rem">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_invoice">
        <input type="hidden" name="invoice_id" value="<?= (int) $i['id'] ?>">
        <div class="form-grid two">
          <label>Monto <input type="number" name="amount" min="1" value="<?= (int) $i['amount'] ?>"></label>
          <label>Estado
            <select name="status">
              <?php foreach (['pending' => 'Pendiente', 'paid' => 'Pagada', 'overdue' => 'Vencida', 'waived' => 'Bonificada'] as $k => $lab): ?>
                <option value="<?= $k ?>" <?= $i['status'] === $k ? 'selected' : '' ?>><?= $lab ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Vencimiento <input type="date" name="due_date" value="<?= h((string) $i['due_date']) ?>"></label>
          <label>Notas <input name="notes" value="<?= h((string) $i['notes']) ?>"></label>
        </div>
        <button class="btn primary" type="submit">Guardar cuota</button>
      </form>
      <form method="post" class="stack" style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--line)">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="invoice_id" value="<?= (int) $i['id'] ?>">
        <strong class="small">Registrar pago sobre esta cuota</strong>
        <div class="form-grid two">
          <label>Monto <input type="number" name="amount" min="1" value="<?= (int) $i['amount'] ?>"></label>
          <label>Método
            <select name="method">
              <option value="manual">Manual</option>
              <option value="transfer">Transferencia</option>
              <option value="cash">Efectivo</option>
              <option value="mercadopago">Mercado Pago</option>
            </select>
          </label>
          <label>Estado
            <select name="status">
              <option value="approved">Aprobado</option>
              <option value="pending">En revisión</option>
            </select>
          </label>
          <label>Referencia <input name="transfer_ref"></label>
        </div>
        <label>Nota <input name="note"></label>
        <button class="btn secondary" type="submit">Cargar pago</button>
      </form>
      <form method="post" style="margin-top:.5rem" onsubmit="return confirm('¿Eliminar esta cuota y sus pagos?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_invoice">
        <input type="hidden" name="invoice_id" value="<?= (int) $i['id'] ?>">
        <button class="btn danger small-btn" type="submit">Eliminar cuota</button>
      </form>
    </details>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
