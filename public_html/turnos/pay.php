<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/admin_ui.php';

if (!db_ready()) {
    redirect('install.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$appt = $token !== '' ? appointment_by_token($token, false) : null;
if (!$appt || $appt['status'] === 'cancelled') {
    http_response_code(404);
    echo 'Turno no encontrado.';
    exit;
}

$cfg = deposit_cfg();
$amount = (int) ($appt['deposit_amount'] ?: deposit_amount());
// Turno cargado a mano: ya confirmado, la seña se puede pagar si el paciente quiere.
$optional = turno_deposit_open($appt);
$paid = !$optional && ($appt['deposit_status'] === 'paid' || $appt['status'] === 'confirmed');
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
                flash('success', $optional
                    ? 'Transferencia informada. ¡Gracias! La verificamos y queda registrada.'
                    : 'Transferencia informada. Cuando la confirmemos, tu turno queda asegurado.');
                redirect('pay.php?token=' . urlencode($token));
            }
        }
    }
}

$alias = (string) ($cfg['transfer_alias'] ?? 'michael.grandon.mp');
try {
    $qrSvg = $alias !== '' ? FluxusQr::svg($alias, 220) : '';
} catch (Throwable $e) {
    $qrSvg = '';
}
$consentPending = empty($appt['consent_accepted_at']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <?= public_head('Seña del turno') ?>
</head>
<body>
  <?= public_header() ?>
  <main class="wrap">
    <p class="eyebrow">Reserva · Seña</p>
    <h1><?= $optional ? 'Seña del turno (opcional)' : ($paid ? ($appt['deposit_status'] === 'paid' ? 'Seña acreditada' : 'Turno confirmado') : 'Pagá la seña para confirmar') ?></h1>
    <p class="lede">
      <?= h($appt['therapy_name']) ?> ·
      <?= h(format_date_es($appt['date'])) ?> ·
      <?= h(format_time_es($appt['time'])) ?> ·
      código <?= h($appt['code']) ?>
      <?php $loc = turno_location_of($appt); ?>
      <br>Lugar: <?= h($loc['label']) ?>
      <?php if ($loc['map_link'] !== ''): ?> · <a href="<?= h($loc['map_link']) ?>" target="_blank" rel="noopener">Cómo llegar</a><?php endif; ?>
    </p>

    <?php if ($flash): ?>
      <div class="alert ok">
        <?= h($flash['message']) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$paid): ?>
    <section class="panel">
      <p style="margin:0 0 .35rem;font-size:.9rem;color:var(--muted)"><?= $optional ? 'Seña opcional' : 'Seña requerida' ?></p>
      <p style="margin:0;font-size:2rem;font-weight:700;color:var(--brand)"><?= h(money_ars($amount)) ?></p>
      <?php if ($optional): ?>
        <p class="hint">Tu turno ya está confirmado. Si querés, podés dejar paga la seña desde acá.</p>
      <?php else: ?>
        <p class="hint">El horario queda reservado. Al acreditar la seña te llega un mail con el comprobante, los requisitos y el consentimiento informado en PDF.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($optional || $paid): ?>
      <section class="panel success">
        <?php if ($paid): ?>
          <h2>¡Listo!</h2>
          <p>
            <?= $appt['deposit_status'] === 'paid' ? 'Tu seña está paga y el turno confirmado.' : 'Tu turno está confirmado.' ?>
            Te mandamos un mail a <strong><?= h((string) $appt['patient_email']) ?></strong> con estos dos PDF:
          </p>
        <?php else: ?>
          <h2>Tu turno</h2>
        <?php endif; ?>
        <?php if ($consentPending): ?>
          <a class="btn primary" href="consentimiento.php?token=<?= h(urlencode($token)) ?>">Firmar consentimiento online</a>
        <?php endif; ?>
        <a class="btn <?= $paid ? 'primary' : 'ghost' ?>" href="pdf.php?token=<?= h(urlencode($token)) ?>">Comprobante y requisitos (PDF)</a>
        <a class="btn <?= $paid ? 'primary' : 'ghost' ?>" href="pdf.php?token=<?= h(urlencode($token)) ?>&amp;doc=consentimiento">Consentimiento informado (PDF)</a>
        <?php if ($paid): ?>
          <a class="btn ghost" href="../">Volver al inicio</a>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (!$paid): ?>
      <div class="pay-grid">
        <section class="panel">
          <h2>1. Transferencia</h2>
          <p class="muted small">
            Titular: <?= h((string) ($cfg['transfer_holder'] ?? '')) ?><br>
            Banco: <?= h((string) ($cfg['transfer_bank'] ?? '')) ?><br>
            Alias: <strong><?= h($alias) ?></strong>
            <button type="button" class="btn ghost btn-copy" data-copy="<?= h($alias) ?>">Copiar alias</button><br>
            <?php if (!empty($cfg['transfer_cbu'])): ?>CBU/CVU: <?= h((string) $cfg['transfer_cbu']) ?><br><?php endif; ?>
            <?= h((string) ($cfg['transfer_note'] ?? '')) ?>
          </p>
          <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="pay_transfer">
            <label>Nº de operación
              <input name="transfer_ref" required placeholder="Ej: 12345678" autocomplete="off" autocapitalize="off" enterkeyhint="next">
            </label>
            <label>Nota (opcional)
              <input name="note" placeholder="Desde qué banco transferiste" autocomplete="off" enterkeyhint="send">
            </label>
            <button class="btn primary" type="submit">Ya transferí · informar seña</button>
          </form>
        </section>

        <section class="panel">
          <h2>2. QR del alias</h2>
          <p class="muted small">Escaneá e ingresá <?= h(money_ars($amount)) ?> a <strong><?= h($alias) ?></strong>.</p>
          <?php if ($qrSvg !== ''): ?>
            <p class="qr-box" aria-label="QR alias <?= h($alias) ?>"><?= $qrSvg ?></p>
          <?php endif; ?>
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
  <script>
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-copy]');
      if (!btn || !navigator.clipboard) return;
      navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
        var text = btn.textContent;
        btn.textContent = 'Copiado';
        setTimeout(function () { btn.textContent = text; }, 1600);
      });
    });
  </script>
</body>
</html>
