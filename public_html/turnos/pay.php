<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$appt = $token !== '' ? appointment_by_token($token, false) : null;
if (!$appt) {
    http_response_code(404);
    echo 'Turno no encontrado.';
    exit;
}

$cfg = deposit_cfg();
$amount = (int) ($appt['deposit_amount'] ?: deposit_amount());
$paid = ($appt['deposit_status'] === 'paid' || $appt['status'] === 'confirmed');
$flash = take_flash();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$paid) {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida. Recargá la página.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'pay_mp') {
            if (!deposit_mp_enabled()) {
                $error = 'El pago con tarjeta/QR aún no está habilitado. Usá transferencia.';
            } else {
                $pref = create_deposit_mp_preference($appt);
                if (!empty($pref['ok']) && !empty($pref['init_point'])) {
                    header('Location: ' . $pref['init_point']);
                    exit;
                }
                $error = 'No se pudo iniciar Mercado Pago: ' . ($pref['error'] ?? '');
            }
        }
        if ($action === 'pay_transfer') {
            $ref = trim((string) ($_POST['transfer_ref'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($ref === '') {
                $error = 'Indicá el número de operación de la transferencia.';
            } else {
                db()->prepare("
                  INSERT INTO deposit_payments (appointment_id, amount, method, status, transfer_ref, note)
                  VALUES (?, ?, 'transfer', 'pending', ?, ?)
                ")->execute([(int) $appt['id'], $amount, $ref, $note]);
                flash('success', 'Transferencia informada. Cuando la confirmemos, tu turno queda asegurado.');
                redirect('pay.php?token=' . urlencode($token));
            }
        }
    }
}

$alias = (string) ($cfg['transfer_alias'] ?? 'michael.grandon.mp');
$qrData = rawurlencode($alias);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . $qrData;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seña del turno · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css">
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
    <p class="eyebrow">Reserva · Seña</p>
    <h1><?= $paid ? 'Seña acreditada' : 'Pagá la seña para confirmar' ?></h1>
    <p class="lede">
      <?= h($appt['therapy_name']) ?> ·
      <?= h(format_date_es($appt['date'])) ?> ·
      <?= h(format_time_es($appt['time'])) ?> ·
      código <?= h($appt['code']) ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert" style="background:#d9f0e4;color:#164b36;padding:.8rem 1rem;border-radius:8px;margin:1rem 0">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="panel">
      <p style="margin:0 0 .35rem;font-size:.9rem;color:var(--muted)">Seña requerida</p>
      <p style="margin:0;font-size:2rem;font-weight:700;color:var(--brand)"><?= h(money_ars($amount)) ?></p>
      <p class="hint">El horario queda reservado. Al acreditar la seña descargás el comprobante PDF.</p>
    </section>

    <?php if ($paid): ?>
      <section class="panel success">
        <h2>¡Listo!</h2>
        <p>Tu seña está paga y el turno confirmado.</p>
        <a class="btn primary" href="pdf.php?token=<?= h(urlencode($token)) ?>">Descargar comprobante PDF</a>
        <a class="btn ghost" href="../">Volver al inicio</a>
      </section>
    <?php else: ?>
      <div class="pay-grid">
        <section class="panel">
          <h2>1. Transferencia</h2>
          <p class="muted small">
            Titular: <?= h((string) ($cfg['transfer_holder'] ?? '')) ?><br>
            Banco: <?= h((string) ($cfg['transfer_bank'] ?? '')) ?><br>
            Alias: <strong><?= h($alias) ?></strong><br>
            <?php if (!empty($cfg['transfer_cbu'])): ?>CBU/CVU: <?= h((string) $cfg['transfer_cbu']) ?><br><?php endif; ?>
            <?= h((string) ($cfg['transfer_note'] ?? '')) ?>
          </p>
          <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="pay_transfer">
            <label>Nº de operación
              <input name="transfer_ref" required placeholder="Ej: 12345678">
            </label>
            <label>Nota (opcional)
              <input name="note" placeholder="Desde qué banco transferiste">
            </label>
            <button class="btn primary" type="submit">Ya transferí · informar seña</button>
          </form>
        </section>

        <section class="panel">
          <h2>2. QR del alias</h2>
          <p class="muted small">Escaneá e ingresá <?= h(money_ars($amount)) ?> a <strong><?= h($alias) ?></strong>.</p>
          <p style="text-align:center;margin:1rem 0">
            <img src="<?= h($qrUrl) ?>" width="220" height="220" alt="QR alias <?= h($alias) ?>" style="border-radius:12px;border:1px solid var(--line)">
          </p>
          <p class="hint">Después de pagar, informá el nº de operación en el formulario de transferencia.</p>
        </section>

        <section class="panel">
          <h2>3. Tarjeta o QR Mercado Pago</h2>
          <p class="muted small">Pago con tarjeta de crédito/débito o dinero en cuenta MP (incluye QR de Checkout).</p>
          <?php if (deposit_mp_enabled()): ?>
            <form method="post" class="stack">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="token" value="<?= h($token) ?>">
              <input type="hidden" name="action" value="pay_mp">
              <button class="btn primary" type="submit" style="background:#009ee3">Pagar con Mercado Pago</button>
            </form>
          <?php else: ?>
            <p class="hint">Pronto habilitamos tarjeta online. Por ahora usá transferencia o el QR del alias.</p>
          <?php endif; ?>
        </section>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
