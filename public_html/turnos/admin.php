<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

function mail_result_text(string $result): string
{
    return match ($result) {
        'sent' => ' Se le mandó el mail con el turno, los requisitos y el consentimiento.',
        'already' => ' El mail ya se había mandado antes.',
        'no_email' => ' No tiene email cargado: mandale los PDF por WhatsApp.',
        default => ' Ojo: no se pudo mandar el mail.',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $adminPass = (string) $config['admin_pass'];
        if (fluxus_password_configured($adminPass) && hash_equals($adminPass, (string) ($_POST['password'] ?? ''))) {
            fluxus_session_login();
            $_SESSION['turnos_admin'] = true;
            redirect('admin.php');
        }
        fluxus_login_failed();
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
            $mail = mark_deposit_paid((int) $row['appointment_id'], 'Seña confirmada por admin');
            flash('success', 'Seña confirmada. Turno acreditado.' . mail_result_text($mail));
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
            $mail = mark_deposit_paid($id, 'Marcado por admin');
            flash('success', 'Seña marcada como paga.' . mail_result_text($mail));
        }
        redirect('admin.php');
    }
    if ($action === 'create_manual') {
        $post = static fn (string $key): string => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $sendMail = $post('send_mail') === '1';
        $input = [
            'name' => $post('name'),
            'email' => $post('email'),
            'phone' => $post('phone'),
            'therapy_id' => (int) $post('therapy_id'),
            'date' => $post('date'),
            'time' => $post('time') === 'custom' ? $post('time_custom') : $post('time'),
            'notes' => $post('notes'),
            'deposit_amount' => (int) $post('deposit_amount'),
        ];
        try {
            $appt = create_manual_appointment($input);
        } catch (RuntimeException $e) {
            $_SESSION['manual_form'] = $input + ['send_mail' => $sendMail, 'time_select' => $post('time'), 'time_custom' => $post('time_custom')];
            flash('error', $e->getMessage());
            redirect('admin.php#manual');
        }
        $msg = 'Turno cargado y confirmado: ' . $input['name'] . ' · ' . format_date_es($appt['date']) . ' · ' . format_time_es($appt['time']) . ' (código ' . $appt['code'] . ').';
        $result = 'skipped';
        if ($sendMail) {
            try {
                $result = send_turno_confirmation($appt['id']);
            } catch (Throwable $e) {
                error_log('Turnos: no se pudo mandar el mail del turno ' . $appt['id'] . ': ' . $e->getMessage());
                $result = 'failed';
            }
            $msg .= mail_result_text($result);
        } else {
            $msg .= ' No se mandó mail: podés mandarlo desde la lista.';
        }
        flash($result === 'failed' ? 'error' : 'success', $msg);
        redirect('admin.php');
    }
    if ($action === 'send_mail') {
        $result = send_turno_confirmation((int) ($_POST['id'] ?? 0), true);
        flash($result === 'sent' ? 'success' : 'error', trim(mail_result_text($result)));
        redirect('admin.php');
    }
    if ($action === 'save_docs') {
        deposit_setting_set('requisitos_text', trim((string) ($_POST['requisitos_text'] ?? '')));
        deposit_setting_set('consentimiento_text', trim((string) ($_POST['consentimiento_text'] ?? '')));
        flash('success', 'Requisitos y consentimiento guardados. Los próximos mails usan estos textos.');
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
  <link rel="stylesheet" href="assets/turnos.css?v=20260929">
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
        $manual = $_SESSION['manual_form'] ?? [];
        unset($_SESSION['manual_form']);
        $manualDate = (string) ($manual['date'] ?? '');
        $manualSlots = preg_match('/^\d{4}-\d{2}-\d{2}$/', $manualDate) ? available_slots_for($manualDate) : [];
        $manualTime = (string) ($manual['time_select'] ?? '');
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
        <h1>Admin turnos</h1>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="btn ghost" type="submit">Salir</button>
        </form>
      </div>

      <section class="panel" id="manual">
        <h2>Cargar turno manual</h2>
        <p class="hint">Para turnos reservados por WhatsApp, teléfono o en persona. Queda confirmado al instante. El mail le llega con el día y la hora, el link para firmar el consentimiento online, las indicaciones previas, los dos PDF y, si ponés seña, un QR para pagarla si quiere.</p>
        <form method="post" class="form-grid" id="manual-form">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="create_manual">
          <label>Nombre y apellido
            <input name="name" required maxlength="120" autocomplete="off" value="<?= h((string) ($manual['name'] ?? '')) ?>">
          </label>
          <label>Email
            <input type="email" name="email" required maxlength="190" autocomplete="off" value="<?= h((string) ($manual['email'] ?? '')) ?>">
          </label>
          <label>Teléfono / WhatsApp
            <input name="phone" required maxlength="40" autocomplete="off" value="<?= h((string) ($manual['phone'] ?? '')) ?>">
          </label>
          <label>Terapia
            <select name="therapy_id" required>
              <option value="">Elegí…</option>
              <?php foreach (therapies() as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) ($manual['therapy_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Día
            <input type="date" name="date" id="manual-date" required min="<?= h(date('Y-m-d')) ?>" value="<?= h($manualDate) ?>">
          </label>
          <label>Horario
            <select name="time" id="manual-time" required>
              <option value=""><?= $manualDate === '' ? 'Primero elegí el día' : ($manualSlots ? 'Elegí…' : 'Sin horarios libres en la grilla') ?></option>
              <?php foreach ($manualSlots as $slot): ?>
                <option value="<?= h($slot) ?>" <?= $manualTime === $slot ? 'selected' : '' ?>><?= h($slot) ?> hs</option>
              <?php endforeach; ?>
              <option value="custom" <?= $manualTime === 'custom' ? 'selected' : '' ?>>Otro horario…</option>
            </select>
          </label>
          <label id="manual-custom" class="<?= $manualTime === 'custom' ? '' : 'is-hidden' ?>">Otro horario (fuera de la grilla)
            <input type="time" name="time_custom" min="06:00" max="22:00" step="300" value="<?= h((string) ($manual['time_custom'] ?? '')) ?>">
          </label>
          <label>Seña opcional (ARS, 0 = sin link de pago)
            <input type="number" name="deposit_amount" min="0" step="100" value="<?= (int) ($manual['deposit_amount'] ?? deposit_amount()) ?>">
          </label>
          <label class="span-2">Notas (opcional, no se muestran al paciente)
            <textarea name="notes" rows="2" maxlength="1000"><?= h((string) ($manual['notes'] ?? '')) ?></textarea>
          </label>
          <label class="check span-2">
            <input type="checkbox" name="send_mail" value="1" <?= ($manual['send_mail'] ?? true) ? 'checked' : '' ?>>
            <span>Enviar mail al paciente</span>
          </label>
          <div class="span-2">
            <button class="btn primary" type="submit">Cargar turno</button>
          </div>
        </form>
      </section>
      <script>
        (function () {
          var date = document.getElementById('manual-date');
          var time = document.getElementById('manual-time');
          var custom = document.getElementById('manual-custom');
          var customInput = custom.querySelector('input');
          function toggleCustom() {
            var on = time.value === 'custom';
            custom.classList.toggle('is-hidden', !on);
            customInput.required = on;
          }
          function option(value, label) {
            var o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            return o;
          }
          date.addEventListener('change', function () {
            var keepCustom = time.value === 'custom';
            time.innerHTML = '';
            time.appendChild(option('', 'Buscando horarios…'));
            if (!date.value) {
              time.firstChild.textContent = 'Primero elegí el día';
              time.appendChild(option('custom', 'Otro horario…'));
              return;
            }
            fetch('api.php?action=slots&date=' + encodeURIComponent(date.value), { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                var slots = (data && data.slots) || [];
                time.firstChild.textContent = slots.length ? 'Elegí…' : 'Sin horarios libres en la grilla';
                slots.forEach(function (s) { time.appendChild(option(s, s + ' hs')); });
                time.appendChild(option('custom', 'Otro horario…'));
                if (keepCustom || !slots.length) { time.value = 'custom'; }
                toggleCustom();
              })
              .catch(function () {
                time.firstChild.textContent = 'No se pudieron cargar los horarios';
                time.appendChild(option('custom', 'Otro horario…'));
                time.value = 'custom';
                toggleCustom();
              });
          });
          time.addEventListener('change', toggleCustom);
          toggleCustom();
        })();
      </script>

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
                <?php
                  $depAmount = money_ars((int) ($a['deposit_amount'] ?? 0));
                  $depText = match ((string) $a['deposit_status']) {
                      'paid' => 'seña ' . $depAmount . ' pagada',
                      'optional' => 'seña ' . $depAmount . ' opcional, sin pagar',
                      'none' => 'sin seña',
                      default => 'seña ' . $depAmount,
                  };
                ?>
                <strong><?= h($a['patient_name']) ?></strong> · <?= h($a['therapy_name']) ?>
                <?php if (($a['source'] ?? '') === 'manual'): ?><span class="tag">Cargado a mano</span><?php endif; ?><br>
                <span class="muted"><?= h(format_date_es($a['date'])) ?> · <?= h(format_time_es($a['time'])) ?></span><br>
                <span class="muted"><?= h($a['patient_phone']) ?> <?= $a['patient_email'] ? '· ' . h($a['patient_email']) : '' ?></span>
                · código <?= h($a['code']) ?>
                · <?= h($depText) ?>
                · <strong><?= $a['status'] === 'confirmed' ? 'Confirmado' : 'Espera seña' ?></strong>
                <?php if ($a['status'] === 'confirmed'): ?>
                  <br><span class="muted"><?= !empty($a['mail_sent_at']) ? 'Mail con requisitos enviado el ' . h(date('d/m H:i', strtotime((string) $a['mail_sent_at']))) : 'Mail con requisitos sin enviar' ?></span>
                <?php endif; ?>
                <br>
                <?php if (!empty($a['consent_accepted_at'])): ?>
                  <span class="tag ok">Consentimiento firmado</span>
                  <span class="muted small"><?= h(date('d/m/Y H:i', strtotime((string) $a['consent_accepted_at']))) ?> · <?= h((string) $a['consent_name']) ?> · DNI <?= h((string) $a['consent_dni']) ?></span>
                <?php else: ?>
                  <span class="tag warn">Consentimiento pendiente</span>
                <?php endif; ?>
              </div>
              <div class="actions" style="display:flex;gap:.4rem;align-items:start;flex-wrap:wrap">
                <?php if ($a['status'] === 'confirmed'): ?>
                  <a class="btn ghost" href="pdf.php?token=<?= h(urlencode($a['token'])) ?>">Requisitos PDF</a>
                  <a class="btn ghost" href="pdf.php?token=<?= h(urlencode($a['token'])) ?>&amp;doc=consentimiento">Consentimiento PDF</a>
                  <a class="btn ghost" href="consentimiento.php?token=<?= h(urlencode($a['token'])) ?>" target="_blank" rel="noopener">Consentimiento online</a>
                  <?php if ($a['patient_email']): ?>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="send_mail">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <button class="btn ghost" type="submit"><?= !empty($a['mail_sent_at']) ? 'Reenviar mail' : 'Enviar mail' ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if (turno_deposit_open($a)): ?>
                    <a class="btn ghost" href="pay.php?token=<?= h(urlencode($a['token'])) ?>" target="_blank" rel="noopener">Link seña</a>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="action" value="mark_deposit_paid">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <button class="btn ghost" type="submit">Marcar seña paga</button>
                    </form>
                  <?php endif; ?>
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
        <h2>Requisitos y consentimiento informado</h2>
        <p class="hint">Horario de turnos: lunes a sábado de 8 a 20 hs (último turno 19 hs), cada una hora. Cuando se confirma el turno (seña acreditada o turno cargado a mano), al paciente le llega un mail con estos dos PDF y el link para firmar el consentimiento online. Un renglón por punto.</p>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_docs">
          <label>Requisitos para la sesión
            <textarea name="requisitos_text" rows="9"><?= h(turno_text_setting('requisitos_text', TURNO_REQUISITOS_DEFAULT)) ?></textarea>
          </label>
          <label>Consentimiento informado (puntos que declara el paciente)
            <textarea name="consentimiento_text" rows="12"><?= h(turno_text_setting('consentimiento_text', TURNO_CONSENTIMIENTO_DEFAULT)) ?></textarea>
          </label>
          <button class="btn primary" type="submit">Guardar textos</button>
        </form>
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
