<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_once __DIR__ . '/includes/mtc_plan.php';
require_once __DIR__ . '/includes/admin_ui.php';

function mail_result_text(string $result): string
{
    return match ($result) {
        'sent' => ' Se le mandó el mail con el turno, los requisitos y el consentimiento.',
        'already' => ' El mail ya se había mandado antes.',
        'no_email' => ' No tiene email cargado: mandale los PDF por WhatsApp.',
        default => ' Ojo: no se pudo mandar el mail.',
    };
}

/**
 * Selector del lugar de la sesión: uno de la lista u "Otra dirección…" escrita a mano.
 * $selected = id del lugar o "other"; $custom = valores de la dirección escrita a mano.
 */
function location_picker(array $locations, string $selected, array $custom = []): string
{
    if (!$locations) {
        $selected = 'other';
    }
    $html = '<div class="location-picker span-2" data-location-picker><label>Lugar de la sesión<select name="location_id">';
    foreach ($locations as $loc) {
        $html .= '<option value="' . (int) $loc['id'] . '"' . ($selected === (string) $loc['id'] ? ' selected' : '') . '>'
            . h(turno_location_label($loc) . ((int) $loc['is_default'] === 1 ? ' (predeterminado)' : '')) . '</option>';
    }
    $html .= '<option value="other"' . ($selected === 'other' ? ' selected' : '') . '>Otra dirección…</option></select></label>'
        . '<div class="location-custom' . ($selected === 'other' ? '' : ' is-hidden') . '">'
        . '<label>Nombre del lugar (opcional)<input name="location_name" maxlength="120" autocomplete="off" placeholder="Ej: Consultorio Coronel Suárez" value="' . h((string) ($custom['name'] ?? '')) . '"></label>'
        . '<label>Dirección<input name="location_address" maxlength="200" autocomplete="off" placeholder="Calle, número y ciudad" value="' . h((string) ($custom['address'] ?? '')) . '"></label>'
        . '<label class="check"><input type="checkbox" name="location_save" value="1"' . (($custom['save'] ?? true) ? ' checked' : '') . '>'
        . '<span>Guardar esta dirección en la lista para usarla en otros turnos</span></label>'
        . '</div></div>';
    return $html;
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
            [$location, $saveLocation] = turno_location_from_form($_POST);
            $appt = create_manual_appointment($input + ['location' => $location]);
        } catch (RuntimeException $e) {
            $_SESSION['manual_form'] = $input + [
                'send_mail' => $sendMail,
                'time_select' => $post('time'),
                'time_custom' => $post('time_custom'),
                'location_id' => $post('location_id'),
                'location_name' => $post('location_name'),
                'location_address' => $post('location_address'),
                'location_save' => $post('location_save') === '1',
            ];
            flash('error', $e->getMessage());
            redirect('admin.php#manual');
        }
        $msg = 'Turno cargado y confirmado: ' . $input['name'] . ' · ' . format_date_es($appt['date']) . ' · ' . format_time_es($appt['time'])
            . ' · ' . turno_location_label($location) . ' (código ' . $appt['code'] . ').';
        if ($saveLocation) {
            turno_location_save($location);
            $msg .= ' La dirección quedó guardada en la lista.';
        }
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
    if ($action === 'set_location') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            [$location, $saveLocation] = turno_location_from_form($_POST);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('admin.php');
        }
        db()->prepare('UPDATE appointments SET location_name = ?, location_address = ?, location_notes = ?, location_maps = ? WHERE id = ?')
            ->execute([$location['name'], $location['address'], $location['notes'], $location['maps'], $id]);
        if ($saveLocation) {
            turno_location_save($location);
        }
        flash('success', 'Lugar del turno cambiado a: ' . turno_location_label($location) . '.'
            . ($saveLocation ? ' La dirección quedó guardada en la lista.' : '')
            . ' Si ya le habías mandado el mail, reenviáselo para que le llegue la dirección nueva.');
        redirect('admin.php');
    }
    if (in_array($action, ['location_add', 'location_update', 'location_default', 'location_delete'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $fields = [];
        foreach (['name', 'address', 'notes', 'maps_url'] as $key) {
            $fields[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
        }
        try {
            if ($action === 'location_add') {
                $newId = turno_location_save($fields);
                if (($_POST['make_default'] ?? '') === '1') {
                    turno_location_set_default($newId);
                }
                flash('success', 'Lugar agregado a la lista.');
            } elseif ($action === 'location_update') {
                turno_location_save($fields, $id);
                flash('success', 'Lugar actualizado. Los turnos ya dados conservan la dirección que tenían.');
            } elseif ($action === 'location_default') {
                turno_location_set_default($id);
                flash('success', 'Lugar predeterminado cambiado. Se usa para los turnos nuevos.');
            } else {
                turno_location_delete($id);
                flash('success', 'Lugar borrado de la lista. Los turnos ya dados conservan su dirección.');
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin.php#lugares');
    }
    if ($action === 'send_mail') {
        $result = send_turno_confirmation((int) ($_POST['id'] ?? 0), true);
        flash($result === 'sent' ? 'success' : 'error', trim(mail_result_text($result)));
        redirect('admin.php');
    }
    if ($action === 'save_docs') {
        // Igual al texto por defecto = sin personalizar, así una actualización del texto por defecto se aplica sola.
        $docText = static function (string $field, string $default): string {
            $value = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST[$field] ?? '')));
            return $value === trim($default) ? '' : $value;
        };
        deposit_setting_set('requisitos_text', $docText('requisitos_text', TURNO_REQUISITOS_DEFAULT));
        flash('success', 'Requisitos guardados. Los próximos mails usan este texto.');
        redirect('admin.php');
    }
    if (in_array($action, ['consent_save', 'consent_approve', 'consent_draft', 'consent_reset', 'consent_preview_pdf', 'consent_preview_html'], true)) {
        $therapyId = max(0, (int) ($_POST['therapy_id'] ?? 0));
        $text = is_string($_POST['text'] ?? null) ? $_POST['text'] : '';
        $anchor = 'admin.php#consent-' . $therapyId;
        if ($action === 'consent_preview_pdf' || $action === 'consent_preview_html') {
            $template = trim(str_replace(["\r\n", "\r"], "\n", $text));
            $sample = turno_consent_sample($therapyId);
            if ($action === 'consent_preview_pdf') {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Vista-previa-consentimiento.pdf"');
                header('Cache-Control: no-store');
                echo turno_consentimiento_pdf($sample, $template !== '' ? $template : turno_consent_general());
                exit;
            }
            $previewHtml = turno_consent_html($template !== '' ? $template : turno_consent_general(), turno_consent_values($sample));
            header('Cache-Control: no-store');
            ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Vista previa del consentimiento · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css?v=20260929e">
</head>
<body>
  <main class="wrap">
    <div class="alert" style="background:#fbefd9;color:#7a4b0f;padding:.8rem 1rem;border-radius:8px;margin:1rem 0">
      Vista previa con datos de ejemplo, como la ve el paciente al firmar online. No se guardó nada.
    </div>
    <p class="eyebrow">Tu turno · Consentimiento informado</p>
    <h1>Consentimiento informado</h1>
    <p class="lede"><?= h($sample['therapy_name']) ?> · <?= h(format_date_es($sample['date'])) ?> · <?= h(format_time_es($sample['time'])) ?></p>
    <section class="panel"><?= $previewHtml ?></section>
  </main>
</body>
</html>
            <?php
            exit;
        }
        try {
            if ($action === 'consent_reset') {
                $name = (string) (turno_consent_sample($therapyId)['therapy_name']);
                turno_consent_save($therapyId, $therapyId === 0 ? TURNO_CONSENT_GENERAL : turno_consent_suggestion($name), 'draft');
                flash('success', $therapyId === 0
                    ? 'Consentimiento general restaurado al texto original.'
                    : 'Se volvió al texto sugerido para ' . $name . '. Quedó como borrador: revisalo y aprobalo.');
            } else {
                $status = match ($action) {
                    'consent_approve' => 'approved',
                    'consent_draft' => 'draft',
                    default => null,
                };
                turno_consent_save($therapyId, $text, $status);
                flash('success', match (true) {
                    $therapyId === 0 => 'Consentimiento general guardado.',
                    $status === 'approved' => 'Consentimiento aprobado: desde ahora se usa en los turnos de esta terapia.',
                    $status === 'draft' => 'Consentimiento guardado como borrador: mientras no lo apruebes se usa el general.',
                    default => 'Consentimiento guardado.',
                });
            }
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect($anchor);
    }
}

$flash = take_flash();
$logged = !empty($_SESSION['turnos_admin']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <?= admin_head('Admin turnos') ?>
</head>
<body class="<?= $logged ? 'has-tabbar' : '' ?>">
  <?= admin_header('turnos', 'Turnos · Admin', $logged) ?>
  <main class="wrap">
    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>" role="status"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$logged): ?>
      <section class="panel">
        <h1>Admin de turnos</h1>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="login">
          <label>Contraseña <input type="password" name="password" required autocomplete="current-password" enterkeyhint="go"></label>
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

        // Próximos turnos: búsqueda y de a 20 («Ver más»), para que la página cargue rápido en el teléfono.
        $today = date('Y-m-d');
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
        $perPage = 20;
        $show = max($perPage, min(400, (int) ($_GET['ver'] ?? $perPage)));
        $where = "a.status IN ('confirmed','pending_deposit') AND a.date >= ?";
        $params = [$today];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where .= " AND (a.patient_name LIKE ? ESCAPE '\\' OR a.code LIKE ? ESCAPE '\\' OR a.patient_phone LIKE ? ESCAPE '\\' OR a.patient_email LIKE ? ESCAPE '\\' OR t.name LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $countStmt = db()->prepare("SELECT COUNT(*) FROM appointments a JOIN therapies t ON t.id = a.therapy_id WHERE $where");
        $countStmt->execute($params);
        $upcomingTotal = (int) $countStmt->fetchColumn();
        $upStmt = db()->prepare("
          SELECT a.*, t.name AS therapy_name
          FROM appointments a
          JOIN therapies t ON t.id = a.therapy_id
          WHERE $where
          ORDER BY a.date, a.time
          LIMIT $show
        ");
        $upStmt->execute($params);
        $upcoming = $upStmt->fetchAll();
        $upcomingByDay = [];
        foreach ($upcoming as $a) {
            $upcomingByDay[(string) $a['date']][] = $a;
        }
        $pageUrl = static fn (array $extra): string => 'admin.php?' . http_build_query(array_filter(['q' => $q] + $extra)) . '#proximos';

        $blocked = db()->query('SELECT * FROM blocked_dates ORDER BY date')->fetchAll();
        $manual = $_SESSION['manual_form'] ?? [];
        unset($_SESSION['manual_form']);
        $manualDate = (string) ($manual['date'] ?? '');
        $manualSlots = preg_match('/^\d{4}-\d{2}-\d{2}$/', $manualDate) ? available_slots_for($manualDate) : [];
        $manualTime = (string) ($manual['time_select'] ?? '');
        $locations = turno_locations();
        $defaultLocationId = $locations ? (string) $locations[0]['id'] : 'other';
        $savedLocationId = static function (array $snapshot) use ($locations): ?string {
            foreach ($locations as $loc) {
                if (trim((string) $loc['name']) === $snapshot['name'] && trim((string) $loc['address']) === $snapshot['address']) {
                    return (string) $loc['id'];
                }
            }
            return null;
        };
        $csrf = csrf_token();
      ?>
      <div class="admin-title">
        <h1>Turnos</h1>
        <span class="muted"><?= $upcomingTotal ?> próximo<?= $upcomingTotal === 1 ? '' : 's' ?><?= $q !== '' ? ' con «' . h($q) . '»' : '' ?></span>
      </div>
      <div class="admin-quick">
        <a class="btn primary" href="#manual">+ Cargar turno</a>
        <?php if ($pendingDeposits): ?>
          <a class="btn ghost" href="#senas">Señas por confirmar <span class="admin-count"><?= count($pendingDeposits) ?></span></a>
        <?php endif; ?>
      </div>

      <?php if ($pendingDeposits): ?>
      <section class="panel" id="senas">
        <h2>Señas por confirmar</h2>
        <?php foreach ($pendingDeposits as $p): ?>
          <article class="turno-card is-waiting">
            <div class="turno-who">
              <strong><?= h($p['patient_name']) ?></strong> · <?= h($p['therapy_name']) ?><br>
              <span class="turno-meta"><?= h(format_date_es((string) $p['date'])) ?> · <?= h(substr((string) $p['time'], 0, 5)) ?> · <?= h(money_ars((int) $p['amount'])) ?> · <?= h($p['method']) ?><br>
              Ref: <?= h($p['transfer_ref'] ?: '—') ?> · código <?= h($p['code']) ?></span>
            </div>
            <div class="turno-actions">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="confirm_deposit">
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn primary" type="submit">Confirmar</button>
              </form>
              <form method="post" data-confirm="¿Rechazar esta seña?">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="reject_deposit">
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn ghost" type="submit">Rechazar</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <details class="panel admin-fold is-primary" id="manual" data-open-wide <?= $manual ? 'open' : '' ?>>
        <summary><h2>Cargar turno manual</h2></summary>
        <p class="hint">Para turnos reservados por WhatsApp, teléfono o en persona. Queda confirmado al instante. El mail le llega con el día, la hora y el lugar (con link a Google Maps), el link para firmar el consentimiento online, las indicaciones previas, los dos PDF y, si ponés seña, un QR para pagarla si quiere.</p>
        <form method="post" class="form-grid" id="manual-form">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="create_manual">
          <label>Nombre y apellido
            <input name="name" required maxlength="120" autocomplete="off" autocapitalize="words" enterkeyhint="next" value="<?= h((string) ($manual['name'] ?? '')) ?>">
          </label>
          <label>Email
            <input type="email" name="email" required maxlength="190" autocomplete="off" inputmode="email" autocapitalize="off" spellcheck="false" enterkeyhint="next" value="<?= h((string) ($manual['email'] ?? '')) ?>">
          </label>
          <label>Teléfono / WhatsApp (opcional)
            <input type="tel" name="phone" maxlength="40" autocomplete="off" inputmode="tel" enterkeyhint="next" placeholder="Ej: 2932 537949" value="<?= h((string) ($manual['phone'] ?? '')) ?>">
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
            <input type="date" name="date" id="manual-date" required min="<?= h($today) ?>" value="<?= h($manualDate) ?>">
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
            <input type="number" name="deposit_amount" min="0" step="100" inputmode="numeric" value="<?= (int) ($manual['deposit_amount'] ?? deposit_amount()) ?>">
          </label>
          <?= location_picker($locations, (string) ($manual['location_id'] ?? $defaultLocationId), [
              'name' => $manual['location_name'] ?? '',
              'address' => $manual['location_address'] ?? '',
              'save' => $manual['location_save'] ?? true,
          ]) ?>
          <label class="span-2">Notas (opcional, no se muestran al paciente)
            <textarea name="notes" rows="2" maxlength="1000"><?= h((string) ($manual['notes'] ?? '')) ?></textarea>
          </label>
          <label class="check span-2">
            <input type="checkbox" name="send_mail" value="1" <?= ($manual['send_mail'] ?? true) ? 'checked' : '' ?>>
            <span>Enviar mail al paciente</span>
          </label>
          <div class="span-2 admin-bar">
            <button class="btn primary" type="submit">Cargar turno</button>
            <span class="hint">Queda confirmado al instante.</span>
          </div>
        </form>
      </details>
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

      <section class="panel" id="proximos">
        <h2>Próximos turnos</h2>
        <form class="admin-search" method="get" action="admin.php#proximos" role="search">
          <label class="sr-only" for="turnos-q">Buscar turnos</label>
          <input type="search" id="turnos-q" name="q" value="<?= h($q) ?>" placeholder="Nombre, teléfono, código…" autocomplete="off" enterkeyhint="search" data-filter="#turnos-list" data-filter-empty="#turnos-none">
          <button class="btn ghost" type="submit">Buscar</button>
        </form>
        <?php if ($q !== ''): ?>
          <p class="hint"><a href="admin.php#proximos">Ver todos los turnos</a></p>
        <?php endif; ?>
        <?php if (!$upcoming): ?>
          <p class="muted"><?= $q !== '' ? 'No hay turnos que coincidan.' : 'No hay turnos a futuro.' ?></p>
        <?php endif; ?>
        <p class="muted is-hidden" id="turnos-none">Ninguno de los turnos de esta lista coincide. Tocá «Buscar» para buscar en todos.</p>
        <div id="turnos-list">
          <?php foreach ($upcomingByDay as $day => $dayItems): ?>
            <div data-group>
              <h3 class="turno-day"><?= $day === $today ? 'Hoy · ' : ($day === date('Y-m-d', strtotime('+1 day')) ? 'Mañana · ' : '') ?><?= h(format_date_es($day)) ?></h3>
              <?php foreach ($dayItems as $a): ?>
                <?php
                  $depAmount = money_ars((int) ($a['deposit_amount'] ?? 0));
                  $depText = match ((string) $a['deposit_status']) {
                      'paid' => 'seña ' . $depAmount . ' pagada',
                      'optional' => 'seña ' . $depAmount . ' opcional, sin pagar',
                      'none' => 'sin seña',
                      default => 'seña ' . $depAmount,
                  };
                  $apptLoc = turno_location_of($a);
                  $phone = trim((string) $a['patient_phone']);
                  $contact = admin_phone_links($phone);
                  $tokenQ = h(urlencode((string) $a['token']));
                  $confirmed = $a['status'] === 'confirmed';
                ?>
                <article class="turno-card<?= $confirmed ? '' : ' is-waiting' ?>" id="turno-<?= (int) $a['id'] ?>"
                  data-search="<?= h(implode(' ', [$a['patient_name'], $a['therapy_name'], $a['code'], $phone, $a['patient_email']])) ?>">
                  <div class="turno-head">
                    <span class="turno-time"><?= h(substr((string) $a['time'], 0, 5)) ?></span>
                    <div class="turno-who"><strong><?= h($a['patient_name']) ?></strong><br><span class="muted"><?= h($a['therapy_name']) ?></span></div>
                  </div>
                  <div class="turno-tags">
                    <?= $confirmed ? '<span class="tag ok">Confirmado</span>' : '<span class="tag warn">Espera seña</span>' ?>
                    <?php if (!empty($a['consent_accepted_at'])): ?>
                      <span class="tag ok">Consentimiento firmado</span>
                    <?php else: ?>
                      <span class="tag warn">Consentimiento pendiente</span>
                    <?php endif; ?>
                    <?php if (($a['source'] ?? '') === 'manual'): ?><span class="tag">Cargado a mano</span><?php endif; ?>
                  </div>
                  <div class="turno-meta">
                    Lugar: <?= h($apptLoc['label']) ?><?php if ($apptLoc['map_link'] !== ''): ?> · <a href="<?= h($apptLoc['map_link']) ?>" target="_blank" rel="noopener">Mapa</a><?php endif; ?><br>
                    <?= h(implode(' · ', array_filter([$phone, trim((string) $a['patient_email'])]))) ?><br>
                    Código <?= h($a['code']) ?> · <?= h($depText) ?>
                    <?php if ($confirmed): ?>
                      <br><?= !empty($a['mail_sent_at']) ? 'Mail con requisitos enviado el ' . h(date('d/m H:i', strtotime((string) $a['mail_sent_at']))) : 'Mail con requisitos sin enviar' ?>
                    <?php endif; ?>
                    <?php if (!empty($a['consent_accepted_at'])): ?>
                      <br>Firmó el <?= h(date('d/m/Y H:i', strtotime((string) $a['consent_accepted_at']))) ?> · <?= h((string) $a['consent_name']) ?> · RUT / DNI <?= h((string) $a['consent_dni']) ?>
                    <?php endif; ?>
                  </div>
                  <div class="turno-actions">
                    <?php if ($contact): ?>
                      <a class="btn wa" href="<?= h($contact['wa']) ?>" target="_blank" rel="noopener">WhatsApp</a>
                      <a class="btn ghost" href="<?= h($contact['tel']) ?>">Llamar</a>
                    <?php endif; ?>
                    <?= mtc_admin_link($a) ?>
                    <?php if ($confirmed && $a['patient_email']): ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="send_mail">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button class="btn ghost" type="submit"><?= !empty($a['mail_sent_at']) ? 'Reenviar mail' : 'Enviar mail' ?></button>
                      </form>
                    <?php elseif (!$confirmed): ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="mark_deposit_paid">
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button class="btn primary" type="submit">Marcar seña paga</button>
                      </form>
                    <?php endif; ?>
                    <details class="turno-more">
                      <summary>Más acciones</summary>
                      <div class="turno-more-actions">
                        <?php if ($confirmed): ?>
                          <a class="btn ghost" href="pdf.php?token=<?= $tokenQ ?>" target="_blank" rel="noopener">Requisitos PDF</a>
                          <a class="btn ghost" href="pdf.php?token=<?= $tokenQ ?>&amp;doc=consentimiento" target="_blank" rel="noopener">Consentimiento PDF</a>
                          <a class="btn ghost" href="consentimiento.php?token=<?= $tokenQ ?>" target="_blank" rel="noopener">Consentimiento online</a>
                          <?php if (turno_deposit_open($a)): ?>
                            <a class="btn ghost" href="pay.php?token=<?= $tokenQ ?>" target="_blank" rel="noopener">Link seña</a>
                            <form method="post">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="action" value="mark_deposit_paid">
                              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                              <button class="btn ghost" type="submit">Marcar seña paga</button>
                            </form>
                          <?php endif; ?>
                        <?php else: ?>
                          <a class="btn ghost" href="pay.php?token=<?= $tokenQ ?>" target="_blank" rel="noopener">Link seña</a>
                        <?php endif; ?>
                        <form method="post" data-confirm="¿Cancelar el turno de <?= h($a['patient_name']) ?>? El horario queda libre.">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="cancel">
                          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                          <button class="btn ghost" type="submit">Cancelar turno</button>
                        </form>
                      </div>
                      <details class="admin-details">
                        <summary>Cambiar lugar</summary>
                        <?php $apptLocId = $savedLocationId($apptLoc); ?>
                        <form method="post" class="form-grid">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="set_location">
                          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                          <?= location_picker($locations, $apptLocId ?? 'other', $apptLocId === null
                              ? ['name' => $apptLoc['name'], 'address' => $apptLoc['address'], 'save' => false]
                              : ['save' => true]) ?>
                          <div class="span-2"><button class="btn primary" type="submit">Guardar lugar del turno</button></div>
                        </form>
                      </details>
                    </details>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($upcomingTotal > count($upcoming)): ?>
          <div class="admin-pager">
            <span class="muted small">Mostrando <?= count($upcoming) ?> de <?= $upcomingTotal ?></span>
            <a class="btn ghost" href="<?= h($pageUrl(['ver' => $show + $perPage])) ?>">Ver <?= min($perPage, $upcomingTotal - count($upcoming)) ?> más</a>
          </div>
        <?php endif; ?>
      </section>

      <h2 class="admin-section-title">Ajustes</h2>

      <details class="panel admin-fold" id="lugares">
        <summary><h2>Lugares de atención</h2><span class="muted"><?= count($locations) ?></span></summary>
        <p class="hint">Las direcciones que aparecen al cargar un turno. El predeterminado se usa en los turnos que reservan los pacientes desde la web (las clases online quedan como “Online”). Si una dirección tiene número de calle, el mail lleva un link a Google Maps; si cargás tu propio link del mapa, se usa ese. Editar o borrar un lugar no cambia los turnos ya dados.</p>
        <?php foreach ($locations as $loc): ?>
          <?php $locLink = turno_location_map_link(turno_location_snapshot($loc)); ?>
          <article class="location-row">
            <div>
              <strong><?= h(turno_location_label($loc)) ?></strong>
              <?php if ((int) $loc['is_default'] === 1): ?><span class="tag ok">Predeterminado</span><?php endif; ?>
              <?php if (trim((string) $loc['notes']) !== ''): ?><br><span class="muted small"><?= h((string) $loc['notes']) ?></span><?php endif; ?>
              <?php if ($locLink !== ''): ?><br><a class="small" href="<?= h($locLink) ?>" target="_blank" rel="noopener">Ver en Google Maps</a><?php endif; ?>
            </div>
            <div class="actions" style="display:flex;gap:.4rem;align-items:start;flex-wrap:wrap">
              <?php if ((int) $loc['is_default'] !== 1): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="location_default">
                  <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">
                  <button class="btn ghost" type="submit">Hacer predeterminado</button>
                </form>
              <?php endif; ?>
              <form method="post" data-confirm="¿Borrar este lugar de la lista? Los turnos ya dados conservan su dirección.">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="location_delete">
                <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">
                <button class="btn ghost" type="submit">Borrar</button>
              </form>
            </div>
            <details class="admin-details">
              <summary>Editar</summary>
              <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="location_update">
                <input type="hidden" name="id" value="<?= (int) $loc['id'] ?>">
                <label>Nombre del lugar (opcional)
                  <input name="name" maxlength="120" value="<?= h((string) $loc['name']) ?>">
                </label>
                <label>Dirección
                  <input name="address" required maxlength="200" value="<?= h((string) $loc['address']) ?>">
                </label>
                <label>Indicaciones para llegar (opcional)
                  <input name="notes" maxlength="300" placeholder="Ej: timbre 2, primer piso" value="<?= h((string) $loc['notes']) ?>">
                </label>
                <label>Link de Google Maps (opcional)
                  <input type="url" name="maps_url" maxlength="500" inputmode="url" autocapitalize="off" placeholder="https://maps.app.goo.gl/…" value="<?= h((string) $loc['maps_url']) ?>">
                </label>
                <div class="span-2"><button class="btn primary" type="submit">Guardar lugar</button></div>
              </form>
            </details>
          </article>
        <?php endforeach; ?>
        <h3 style="margin-top:1rem">Agregar un lugar</h3>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="location_add">
          <label>Nombre del lugar (opcional)
            <input name="name" maxlength="120" placeholder="Ej: Consultorio Punta Alta">
          </label>
          <label>Dirección
            <input name="address" required maxlength="200" placeholder="Calle, número y ciudad">
          </label>
          <label>Indicaciones para llegar (opcional)
            <input name="notes" maxlength="300" placeholder="Ej: timbre 2, primer piso">
          </label>
          <label>Link de Google Maps (opcional)
            <input type="url" name="maps_url" maxlength="500" inputmode="url" autocapitalize="off" placeholder="https://maps.app.goo.gl/…">
          </label>
          <label class="check span-2">
            <input type="checkbox" name="make_default" value="1">
            <span>Usarlo como predeterminado</span>
          </label>
          <div class="span-2"><button class="btn primary" type="submit">Agregar lugar</button></div>
        </form>
      </details>

      <details class="panel admin-fold" id="sena">
        <summary><h2>Seña · monto y transferencia</h2><span class="muted"><?= h(money_ars((int) ($depCfg['amount'] ?? 15000))) ?></span></summary>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_deposit_settings">
          <label>Monto seña (ARS) <input type="number" name="amount" min="1" inputmode="numeric" value="<?= (int) ($depCfg['amount'] ?? 15000) ?>"></label>
          <label>Titular <input name="transfer_holder" autocomplete="off" value="<?= h((string) ($depCfg['transfer_holder'] ?? '')) ?>"></label>
          <label>Banco <input name="transfer_bank" autocomplete="off" value="<?= h((string) ($depCfg['transfer_bank'] ?? '')) ?>"></label>
          <label>Alias <input name="transfer_alias" autocomplete="off" autocapitalize="off" spellcheck="false" value="<?= h((string) ($depCfg['transfer_alias'] ?? '')) ?>"></label>
          <label>CBU/CVU <input name="transfer_cbu" autocomplete="off" inputmode="numeric" value="<?= h((string) ($depCfg['transfer_cbu'] ?? '')) ?>"></label>
          <label>Nota <input name="transfer_note" autocomplete="off" value="<?= h((string) ($depCfg['transfer_note'] ?? '')) ?>"></label>
          <button class="btn primary" type="submit">Guardar seña</button>
        </form>
        <p class="hint">Mercado Pago tarjeta/QR: <?= deposit_mp_enabled() ? 'activo' : 'cargar mp_access_token en turnos/config.php' ?></p>
      </details>

      <details class="panel admin-fold" id="requisitos">
        <summary><h2>Requisitos para la sesión</h2></summary>
        <p class="hint">Horario de turnos: lunes a sábado de 8 a 20 hs (último turno 19 hs), cada una hora. Cuando se confirma el turno (seña acreditada o turno cargado a mano), al paciente le llega un mail con los requisitos y el consentimiento de su terapia en PDF, y el link para firmar el consentimiento online. Si dejás el texto vacío se usa el de por defecto.</p>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="save_docs">
          <label>Requisitos para la sesión (un renglón por punto)
            <textarea name="requisitos_text" rows="9"><?= h(turno_text_setting('requisitos_text', TURNO_REQUISITOS_DEFAULT)) ?></textarea>
          </label>
          <div class="admin-bar"><button class="btn primary" type="submit">Guardar requisitos</button></div>
        </form>
      </details>

      <?php $consentRows = turno_consent_rows(); ?>
      <details class="panel admin-fold" id="consentimientos">
        <summary><h2>Consentimientos informados</h2></summary>
        <p class="hint">
          Cada terapia tiene su consentimiento. Un turno usa el de su terapia <strong>solo si está aprobado</strong>;
          mientras esté como borrador “a revisar”, se usa el <strong>consentimiento general</strong>.
          Los borradores se generaron automáticamente: revisalos antes de aprobarlos. Los consentimientos ya firmados no cambian.
        </p>
        <details class="admin-details">
          <summary>Cómo se escribe (datos que se completan solos y formato)</summary>
          <p class="hint">
            Datos del paciente que se completan solos:
            <code>{nombre}</code> nombre y apellido,
            <code>{NOMBRE}</code> el mismo en mayúsculas,
            <code>{documento}</code> RUT / DNI,
            <code>{email}</code> e-mail del turno,
            <code>{fecha}</code> fecha de firma (dd-mm-aaaa).
            Al firmar online se usan el nombre y el documento que escribe el paciente y la fecha de ese día; en el PDF del mail
            van su nombre y e-mail, y el documento y la fecha quedan como líneas para completar a mano.
            Un párrafo por renglón. Un renglón corto en mayúsculas es el título; los que empiezan con <code>PRIMERO:</code>, <code>SEGUNDO:</code>…
            salen con la etiqueta en negrita y los que empiezan con <code>a)</code> o <code>i.-</code> salen como subítems.
            “Vista previa” muestra lo que está escrito en el cuadro, aunque todavía no lo hayas guardado.
          </p>
        </details>
        <?php
          $consentItems = [['id' => 0, 'name' => 'Consentimiento general']];
          foreach (therapies() as $t) {
              $consentItems[] = ['id' => (int) $t['id'], 'name' => (string) $t['name']];
          }
        ?>
        <?php foreach ($consentItems as $item): ?>
          <?php
            $cid = $item['id'];
            $row = $consentRows[$cid] ?? null;
            $approved = $cid === 0 || ($row['status'] ?? '') === 'approved';
            $consentText = $row ? (string) $row['text'] : ($cid === 0 ? TURNO_CONSENT_GENERAL : turno_consent_suggestion($item['name']));
          ?>
          <details class="admin-details consent-item" id="consent-<?= $cid ?>">
            <summary>
              <?= h($item['name']) ?>
              <?php if ($cid === 0): ?>
                <span class="tag">Se usa en las terapias sin consentimiento aprobado</span>
              <?php elseif ($approved): ?>
                <span class="tag ok">Aprobado · se usa en sus turnos</span>
              <?php else: ?>
                <span class="tag warn">Borrador a revisar · hoy se usa el general</span>
              <?php endif; ?>
            </summary>
            <form method="post" class="stack">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="therapy_id" value="<?= $cid ?>">
              <label>Texto del consentimiento
                <textarea name="text" rows="18" required maxlength="40000"><?= h($consentText) ?></textarea>
              </label>
              <?php if ($row): ?>
                <p class="hint">
                  Última modificación: <?= h(date('d/m/Y H:i', strtotime((string) $row['updated_at']))) ?>
                  <?php if ($cid > 0 && $approved && !empty($row['approved_at'])): ?> · aprobado el <?= h(date('d/m/Y H:i', strtotime((string) $row['approved_at']))) ?><?php endif; ?>
                </p>
              <?php endif; ?>
              <div class="consent-actions admin-bar">
                <?php if ($cid === 0): ?>
                  <button class="btn primary" type="submit" name="action" value="consent_save">Guardar</button>
                <?php elseif ($approved): ?>
                  <button class="btn primary" type="submit" name="action" value="consent_save">Guardar cambios</button>
                  <button class="btn ghost" type="submit" name="action" value="consent_draft">Pasar a borrador</button>
                <?php else: ?>
                  <button class="btn primary" type="submit" name="action" value="consent_approve">Guardar y aprobar</button>
                  <button class="btn ghost" type="submit" name="action" value="consent_save">Guardar borrador</button>
                <?php endif; ?>
                <button class="btn ghost" type="submit" name="action" value="consent_preview_pdf" formtarget="_blank" formnovalidate>Vista previa PDF</button>
                <button class="btn ghost" type="submit" name="action" value="consent_preview_html" formtarget="_blank" formnovalidate>Vista previa online</button>
                <button class="btn ghost" type="submit" name="action" value="consent_reset" formnovalidate
                  data-confirm="<?= $cid === 0 ? '¿Volver al texto original del consentimiento general?' : '¿Reemplazar el texto por el sugerido? Queda como borrador y, hasta que lo apruebes, se usa el general.' ?>"><?= $cid === 0 ? 'Restaurar texto original' : 'Volver al texto sugerido' ?></button>
              </div>
            </form>
          </details>
        <?php endforeach; ?>
      </details>

      <details class="panel admin-fold" id="bloquear">
        <summary><h2>Bloquear un día</h2><span class="muted"><?= $blocked ? count($blocked) . ' bloqueado' . (count($blocked) === 1 ? '' : 's') : '' ?></span></summary>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="block">
          <label>Fecha <input type="date" name="date" required min="<?= h($today) ?>"></label>
          <label>Motivo <input name="reason" placeholder="Ej: feriado / viaje"></label>
          <button class="btn primary" type="submit">Bloquear</button>
        </form>
        <?php if ($blocked): ?>
          <h3 style="margin-top:1rem">Días bloqueados</h3>
          <?php foreach ($blocked as $b): ?>
            <form method="post" class="location-row" style="align-items:center">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="unblock">
              <input type="hidden" name="date" value="<?= h($b['date']) ?>">
              <span><?= h(format_date_es((string) $b['date'])) ?> <?= $b['reason'] ? '· ' . h($b['reason']) : '' ?></span>
              <button class="btn ghost" type="submit">Quitar</button>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </details>
      <script>
        document.querySelectorAll('[data-location-picker]').forEach(function (box) {
          var select = box.querySelector('select');
          var custom = box.querySelector('.location-custom');
          var address = custom.querySelector('[name="location_address"]');
          function toggle() {
            var on = select.value === 'other';
            custom.classList.toggle('is-hidden', !on);
            address.required = on;
          }
          select.addEventListener('change', toggle);
          toggle();
        });
      </script>
    <?php endif; ?>
  </main>
</body>
</html>
