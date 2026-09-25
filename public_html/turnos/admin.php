<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        if (hash_equals((string) $config['admin_pass'], (string) ($_POST['password'] ?? ''))) {
            $_SESSION['turnos_admin'] = true;
            redirect('admin.php');
        }
        flash('error', 'Contraseña incorrecta.');
        redirect('admin.php');
    }
    require_admin();
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('admin.php');
    }
    if ($action === 'logout') {
        unset($_SESSION['turnos_admin']);
        redirect('admin.php');
    }
    if ($action === 'cancel') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        flash('success', 'Turno cancelado (el horario queda libre).');
        redirect('admin.php');
    }
    if ($action === 'block') {
        $date = trim((string) ($_POST['date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            db()->prepare('INSERT OR REPLACE INTO blocked_dates (date, reason) VALUES (?, ?)')->execute([$date, $reason]);
            flash('success', 'Día bloqueado.');
        }
        redirect('admin.php');
    }
    if ($action === 'unblock') {
        $date = trim((string) ($_POST['date'] ?? ''));
        db()->prepare('DELETE FROM blocked_dates WHERE date = ?')->execute([$date]);
        flash('success', 'Día desbloqueado.');
        redirect('admin.php');
    }
    if ($action === 'confirm_deposit') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $pay = db()->prepare('SELECT * FROM deposit_payments WHERE id = ? LIMIT 1');
        $pay->execute([$pid]);
        $row = $pay->fetch();
        if ($row) {
            db()->prepare("UPDATE deposit_payments SET status = 'approved', confirmed_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $pid]);
            mark_deposit_paid((int) $row['appointment_id'], 'Seña confirmada por admin');
            flash('success', 'Seña confirmada. Turno acreditado.');
        }
        redirect('admin.php');
    }
    if ($action === 'reject_deposit') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        db()->prepare("UPDATE deposit_payments SET status = 'rejected', confirmed_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $pid]);
        flash('success', 'Seña rechazada.');
        redirect('admin.php');
    }
    if ($action === 'save_deposit_settings') {
        deposit_setting_set('amount', (string) max(1, (int) ($_POST['amount'] ?? 15000)));
        deposit_setting_set('transfer_holder', trim((string) ($_POST['transfer_holder'] ?? '')));
        deposit_setting_set('transfer_bank', trim((string) ($_POST['transfer_bank'] ?? '')));
        deposit_setting_set('transfer_alias', trim((string) ($_POST['transfer_alias'] ?? '')));
        deposit_setting_set('transfer_cbu', trim((string) ($_POST['transfer_cbu'] ?? '')));
        deposit_setting_set('transfer_note', trim((string) ($_POST['transfer_note'] ?? '')));
        flash('success', 'Datos de seña actualizados.');
        redirect('admin.php');
    }
    if ($action === 'mark_deposit_paid') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            mark_deposit_paid($id, 'Marcado por admin');
            flash('success', 'Seña marcada como paga.');
        }
        redirect('admin.php');
    }
}

$flash = take_flash();
$logged = !empty($_SESSION['turnos_admin']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin turnos · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css">
</head>
<body>
  <header class="top">
    <a class="brand brand--home" href="../" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span>Turnos · Admin</span>
    </a>
    <nav><a href="../">Inicio</a></nav>
  </header>
  <main class="wrap">
    <?php if ($flash): ?>
      <div class="alert <?= h($flash['type'] === 'error' ? 'error' : '') ?>" style="<?= $flash['type'] !== 'error' ? 'background:#d9f0e4;color:#164b36;padding:.8rem 1rem;border-radius:8px;' : '' ?>">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!$logged): ?>
      <section class="panel">
        <h1>Admin de turnos</h1>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="login">
          <label>Contraseña <input type="password" name="password" required></label>
          <button class="btn primary" type="submit">Entrar</button>
        </form>
      </section>
    <?php else: ?>
      <?php
        $depCfg = deposit_cfg();
        $pendingDeposits = db()->query("
          SELECT p.*, a.code, a.patient_name, a.date, a.time, t.name AS therapy_name
          FROM deposit_payments p
          JOIN appointments a ON a.id = p.appointment_id
          JOIN therapies t ON t.id = a.therapy_id
          WHERE p.status = 'pending'
          ORDER BY p.created_at DESC
          LIMIT 40
        ")->fetchAll();
        $upcoming = db()->query("
          SELECT a.*, t.name AS therapy_name
          FROM appointments a
          JOIN therapies t ON t.id = a.therapy_id
          WHERE a.status IN ('confirmed','pending_deposit') AND a.date >= date('now')
          ORDER BY a.date, a.time
          LIMIT 80
        ")->fetchAll();
        $blocked = db()->query('SELECT * FROM blocked_dates ORDER BY date')->fetchAll();
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
        <h1>Admin turnos</h1>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="btn ghost" type="submit">Salir</button>
        </form>
      </div>

      <section class="panel">
        <h2>Seña · monto y transferencia</h2>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_deposit_settings">
          <label>Monto seña (ARS) <input type="number" name="amount" min="1" value="<?= (int) ($depCfg['amount'] ?? 15000) ?>"></label>
          <label>Titular <input name="transfer_holder" value="<?= h((string) ($depCfg['transfer_holder'] ?? '')) ?>"></label>
          <label>Banco <input name="transfer_bank" value="<?= h((string) ($depCfg['transfer_bank'] ?? '')) ?>"></label>
          <label>Alias <input name="transfer_alias" value="<?= h((string) ($depCfg['transfer_alias'] ?? '')) ?>"></label>
          <label>CBU/CVU <input name="transfer_cbu" value="<?= h((string) ($depCfg['transfer_cbu'] ?? '')) ?>"></label>
          <label>Nota <input name="transfer_note" value="<?= h((string) ($depCfg['transfer_note'] ?? '')) ?>"></label>
          <button class="btn primary" type="submit">Guardar seña</button>
        </form>
        <p class="hint">Mercado Pago tarjeta/QR: <?= deposit_mp_enabled() ? 'activo' : 'cargar mp_access_token en turnos/config.php' ?></p>
      </section>

      <?php if ($pendingDeposits): ?>
      <section class="panel">
        <h2>Señas por confirmar</h2>
        <?php foreach ($pendingDeposits as $p): ?>
          <article style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:.75rem 0;border-bottom:1px solid var(--line)">
            <div>
              <strong><?= h($p['patient_name']) ?></strong> · <?= h($p['therapy_name']) ?><br>
              <span class="muted"><?= h($p['date']) ?> <?= h(substr((string) $p['time'], 0, 5)) ?> · <?= h(money_ars((int) $p['amount'])) ?> · <?= h($p['method']) ?></span><br>
              <span class="muted">Ref: <?= h($p['transfer_ref'] ?: '—') ?> · código <?= h($p['code']) ?></span>
            </div>
            <div class="actions" style="display:flex;gap:.4rem">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="confirm_deposit">
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn primary" type="submit">Confirmar</button>
              </form>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="reject_deposit">
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn ghost" type="submit">Rechazar</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <section class="panel">
        <h2>Próximos turnos</h2>
        <?php if (!$upcoming): ?>
          <p class="muted">No hay turnos a futuro.</p>
        <?php else: ?>
          <?php foreach ($upcoming as $a): ?>
            <article style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:.75rem 0;border-bottom:1px solid var(--line)">
              <div>
                <strong><?= h($a['patient_name']) ?></strong> · <?= h($a['therapy_name']) ?><br>
                <span class="muted"><?= h(format_date_es($a['date'])) ?> · <?= h(format_time_es($a['time'])) ?></span><br>
                <span class="muted"><?= h($a['patient_phone']) ?> <?= $a['patient_email'] ? '· ' . h($a['patient_email']) : '' ?></span>
                · código <?= h($a['code']) ?>
                · seña <?= h(money_ars((int) ($a['deposit_amount'] ?? 15000))) ?>
                · <strong><?= $a['status'] === 'confirmed' ? 'Confirmado' : 'Espera seña' ?></strong>
              </div>
              <div class="actions" style="display:flex;gap:.4rem;align-items:start;flex-wrap:wrap">
                <?php if ($a['status'] === 'confirmed'): ?>
                  <a class="btn ghost" href="pdf.php?token=<?= h(urlencode($a['token'])) ?>">PDF</a>
                <?php else: ?>
                  <a class="btn ghost" href="pay.php?token=<?= h(urlencode($a['token'])) ?>">Link seña</a>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_deposit_paid">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn primary" type="submit">Marcar seña paga</button>
                  </form>
                <?php endif; ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn ghost" type="submit">Cancelar</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="panel">
        <h2>Bloquear un día</h2>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="block">
          <label>Fecha <input type="date" name="date" required></label>
          <label>Motivo <input name="reason" placeholder="Ej: feriado / viaje"></label>
          <button class="btn primary" type="submit">Bloquear</button>
        </form>
        <?php if ($blocked): ?>
          <h3 style="margin-top:1rem">Días bloqueados</h3>
          <?php foreach ($blocked as $b): ?>
            <form method="post" style="display:flex;gap:.5rem;align-items:center;margin:.4rem 0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="unblock">
              <input type="hidden" name="date" value="<?= h($b['date']) ?>">
              <span><?= h($b['date']) ?> <?= $b['reason'] ? '· ' . h($b['reason']) : '' ?></span>
              <button class="btn ghost" type="submit">Quitar</button>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
