<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    http_response_code(503);
    echo 'Instalá el sistema de turnos primero.';
    exit;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$appt = preg_match('/^[a-f0-9]{32}$/', $token) ? appointment_by_token($token, false) : null;
if (!$appt || !in_array($appt['status'], ['confirmed', 'pending_deposit'], true)) {
    http_response_code(404);
    echo 'Turno no encontrado.';
    exit;
}

$self = 'consentimiento.php?token=' . urlencode($token);
$signed = !empty($appt['consent_accepted_at']);
$error = '';
$form = ['name' => (string) $appt['patient_name'], 'dni' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$signed) {
    $tries = &$_SESSION['consent_tries'][$token];
    $tries = (int) $tries + 1;
    $form['name'] = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
    $form['dni'] = trim((string) ($_POST['dni'] ?? ''));
    // DNI argentino (7–9 números) o RUT chileno (con o sin dígito verificador, que puede ser K).
    $dni = strtoupper(preg_replace('/[\s.]/', '', $form['dni']) ?? '');

    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión venció. Recargá la página y volvé a intentar.';
    } elseif ($tries > 10) {
        $error = 'Demasiados intentos. Escribinos por WhatsApp y lo resolvemos.';
    } elseif (mb_strlen($form['name']) < 3 || mb_strlen($form['name']) > 120 || !preg_match('/\p{L}/u', $form['name'])) {
        $error = 'Escribí tu nombre y apellido completos.';
    } elseif (!preg_match('/^\d{6,9}(-?[0-9K])?$/', $dni)) {
        $error = 'Revisá el RUT o DNI: solo números (el RUT puede terminar en guion y dígito verificador, ej: 12345678-K).';
    } elseif (($_POST['accept'] ?? '') !== '1') {
        $error = 'Para firmar tenés que marcar “Leí y acepto”.';
    } else {
        if (turno_accept_consent($appt, $form['name'], $dni, (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            flash('success', '¡Gracias! Tu consentimiento quedó firmado.');
        }
        redirect($self);
    }
}

$flash = take_flash();
$consentHtml = $signed && !empty($appt['consent_text'])
    ? turno_consent_html((string) $appt['consent_text'])
    : turno_consent_html(turno_consent_template(), turno_consent_values($appt, $form['name'], $form['dni'], date('d-m-Y')));
$prep = turno_prep_lines($appt);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title>Consentimiento informado · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css?v=20260929c">
</head>
<body>
  <header class="top">
    <a class="brand brand--home" href="../" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span>Turnos</span>
    </a>
    <nav><a href="../">Inicio</a></nav>
  </header>
  <main class="wrap">
    <p class="eyebrow">Tu turno · Consentimiento informado</p>
    <h1><?= $signed ? 'Consentimiento firmado' : 'Consentimiento informado' ?></h1>
    <p class="lede">
      <?= h($appt['therapy_name']) ?> ·
      <?= h(format_date_es($appt['date'])) ?> ·
      <?= h(format_time_es($appt['time'])) ?> ·
      código <?= h($appt['code']) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert" style="background:#d9f0e4;color:#164b36;padding:.8rem 1rem;border-radius:8px;margin:1rem 0"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert error" style="margin:1rem 0"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($signed): ?>
      <section class="panel consent-done">
        <h2>¡Listo!</h2>
        <p>
          Firmado el <strong><?= h(date('d/m/Y \a \l\a\s H:i', strtotime((string) $appt['consent_accepted_at']))) ?></strong>
          por <strong><?= h((string) $appt['consent_name']) ?></strong>
          (RUT / DNI terminado en <?= h(substr((string) $appt['consent_dni'], -3)) ?>).
        </p>
        <p class="hint">No hace falta que traigas el consentimiento impreso. Si querés cambiar algo, escribinos por WhatsApp.</p>
      </section>
    <?php endif; ?>

    <section class="panel">
      <?= $consentHtml ?>
      <?php if (!$signed): ?>
        <p class="hint">Tu nombre, tu RUT o DNI y la fecha de hoy se completan en el texto con los datos que ponés al firmar.</p>
      <?php endif; ?>
      <p class="hint">Si el paciente es menor de edad o no puede firmar, firma su madre, padre, tutor o representante indicando el vínculo.</p>
    </section>

    <?php if ($prep): ?>
      <section class="panel">
        <h2><?= turno_is_online($appt) ? 'Para tu clase online' : 'Indicaciones antes de venir' ?></h2>
        <ul class="consent-list">
          <?php foreach ($prep as $line): ?>
            <li><?= h($line) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if (!$signed): ?>
      <section class="panel">
        <h2>Firmar</h2>
        <form method="post" action="<?= h($self) ?>" class="stack">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <label>Nombre y apellido
            <input name="name" required minlength="3" maxlength="120" autocomplete="name" value="<?= h($form['name']) ?>">
          </label>
          <label>RUT / DNI
            <input name="dni" required maxlength="14" pattern="[0-9 .\-kK]{6,14}" autocapitalize="characters" placeholder="Ej: 30123456 o 12345678-K" value="<?= h($form['dni']) ?>">
          </label>
          <label class="check">
            <input type="checkbox" name="accept" value="1" required>
            <span>Leí y acepto el consentimiento informado y las indicaciones previas a la sesión.</span>
          </label>
          <button class="btn primary" type="submit">Firmar consentimiento</button>
          <p class="hint">Guardamos tu nombre, RUT / DNI, la fecha y la IP desde la que firmás como constancia (Ley 25.326).</p>
        </form>
      </section>
      <script>
        (function () {
          var form = document.querySelector('form.stack');
          if (!form) return;
          var blank = { nombre: <?= json_encode(TURNO_CONSENT_BLANKS['nombre']) ?>, documento: <?= json_encode(TURNO_CONSENT_BLANKS['documento']) ?> };
          function set(key, value) {
            document.querySelectorAll('.consent-fill[data-fill="' + key + '"]').forEach(function (el) { el.textContent = value; });
          }
          var nameInput = form.querySelector('[name="name"]');
          var docInput = form.querySelector('[name="dni"]');
          function sync() {
            var name = nameInput.value.trim().replace(/\s+/g, ' ');
            var doc = docInput.value.trim().replace(/[\s.]/g, '').toUpperCase();
            set('nombre', name || blank.nombre);
            set('NOMBRE', name ? name.toLocaleUpperCase('es') : blank.nombre);
            set('documento', doc || blank.documento);
          }
          nameInput.addEventListener('input', sync);
          docInput.addEventListener('input', sync);
        })();
      </script>
    <?php endif; ?>

    <?php if ($appt['status'] === 'confirmed'): ?>
      <p style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn ghost" href="pdf.php?token=<?= h(urlencode($token)) ?>&amp;doc=consentimiento&amp;ver=1">Consentimiento (PDF)</a>
        <a class="btn ghost" href="pdf.php?token=<?= h(urlencode($token)) ?>&amp;ver=1">Comprobante y requisitos (PDF)</a>
        <?php if (turno_deposit_open($appt)): ?>
          <a class="btn ghost" href="pay.php?token=<?= h(urlencode($token)) ?>">Pagar seña (opcional)</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </main>
</body>
</html>
