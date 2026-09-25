<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}

$user = current_user();
if ($user && $user['role'] === 'student') {
    redirect('dashboard.php');
}

$amount = inscription_amount();
$courses = db()->query('SELECT id, title FROM courses WHERE published = 1 ORDER BY sort_order, title')->fetchAll();
$cfg = payment_cfg();
$error = '';
$okMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? 'submit');
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Completá nombre y email válido.';
        } else {
            $status = $amount > 0 ? 'pending_payment' : 'pending_approval';
            db()->prepare("
              INSERT INTO inscriptions (name, email, phone, course_id, amount, status, note)
              VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$name, $email, $phone, $courseId > 0 ? $courseId : null, $amount, $status, $note]);
            $id = (int) db()->lastInsertId();

            if ($amount <= 0) {
                mark_inscription_awaiting_approval($id);
                $okMsg = 'Recibimos tu solicitud. El administrador revisará el cupo y, al aprobarlo, te llegará el mail con usuario, contraseña y link.';
            } elseif ($action === 'pay_mp' && mp_enabled()) {
                global $config;
                $site = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/');
                $payload = [
                    'items' => [[
                        'id' => 'inscription-' . $id,
                        'title' => 'Inscripción AcademiaFluxus',
                        'quantity' => 1,
                        'currency_id' => (string) ($cfg['currency'] ?? 'ARS'),
                        'unit_price' => $amount,
                    ]],
                    'payer' => ['name' => $name, 'email' => $email],
                    'external_reference' => 'insc-' . $id,
                    'metadata' => ['inscription_id' => $id],
                    'back_urls' => [
                        'success' => $site . '/academia/inscripcion_return.php?status=success&id=' . $id,
                        'failure' => $site . '/academia/inscripcion_return.php?status=failure&id=' . $id,
                        'pending' => $site . '/academia/inscripcion_return.php?status=pending&id=' . $id,
                    ],
                    'auto_return' => 'approved',
                    'notification_url' => $site . '/academia/inscripcion_webhook.php',
                    'statement_descriptor' => 'INSCRIPCIONFLUXUS',
                ];
                $res = mp_api('POST', '/checkout/preferences', $payload);
                if (!empty($res['ok']) && !empty($res['id'])) {
                    db()->prepare('UPDATE inscriptions SET mp_preference_id = ?, method = ? WHERE id = ?')
                        ->execute([(string) $res['id'], 'mercadopago', $id]);
                    header('Location: ' . (string) ($res['init_point'] ?? $res['sandbox_init_point'] ?? ''));
                    exit;
                }
                $error = 'No se pudo iniciar Mercado Pago. Probá transferencia.';
            } elseif ($action === 'pay_transfer') {
                $ref = trim((string) ($_POST['transfer_ref'] ?? ''));
                if ($ref === '') {
                    $error = 'Indicá el número de operación de la transferencia.';
                    db()->prepare('DELETE FROM inscriptions WHERE id = ?')->execute([$id]);
                } else {
                    db()->prepare("UPDATE inscriptions SET method = 'transfer', transfer_ref = ?, status = 'pending_approval' WHERE id = ?")
                        ->execute([$ref, $id]);
                    mark_inscription_awaiting_approval($id);
                    $okMsg = 'Transferencia informada. Cuando el administrador confirme el cupo y el pago, te llega el mail de acceso.';
                }
            } else {
                // paid amount but chose submit without pay method — keep pending_payment and show pay options again
                $okMsg = '';
                $error = 'Elegí transferir o pagar con Mercado Pago para continuar.';
            }
        }
    }
}

$pageTitle = 'Inscripción';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
$alias = (string) ($cfg['transfer_alias'] ?? 'michael.grandon.mp');
?>
<p class="eyebrow">AcademiaFluxus</p>
<h1>Inscripción</h1>
<p class="lede">
  Pedí tu cupo en la academia (clases en vivo / campus).
  <?php if ($amount > 0): ?>
    La inscripción tiene un costo de <strong><?= h(money_ars($amount)) ?></strong>.
  <?php else: ?>
    La inscripción es <strong>gratuita</strong>; el administrador debe aprobar tu cupo.
  <?php endif; ?>
  Cuando se apruebe, te llega el mail con usuario, contraseña y link.
</p>
<p class="actions">
  <a class="btn secondary" href="login.php?as=alumno">Ya tengo usuario</a>
  <a class="btn secondary" href="../cursos/">Prefiero un curso grabado</a>
</p>

<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
<?php if ($okMsg): ?>
  <div class="alert success"><?= h($okMsg) ?></div>
<?php else: ?>
<section class="panel" style="margin-top:1rem">
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="form-grid two">
      <label>Nombre completo <input name="name" required value="<?= h((string) ($_POST['name'] ?? '')) ?>"></label>
      <label>Email <input type="email" name="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Teléfono / WhatsApp <input name="phone" value="<?= h((string) ($_POST['phone'] ?? '')) ?>"></label>
      <label>Curso de interés
        <select name="course_id">
          <option value="0">Academia en general</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) ($_POST['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Nota <input name="note" placeholder="Opcional" value="<?= h((string) ($_POST['note'] ?? '')) ?>"></label>

    <?php if ($amount > 0): ?>
      <div class="grid">
        <div class="panel" style="padding:1rem">
          <h2 style="font-size:1.2rem">Transferencia <?= h(money_ars($amount)) ?></h2>
          <p class="muted small">Alias: <strong><?= h($alias) ?></strong> · Titular: <?= h((string) ($cfg['transfer_holder'] ?? '')) ?></p>
          <label>Nº de operación <input name="transfer_ref"></label>
          <button class="btn primary" type="submit" name="action" value="pay_transfer">Ya transferí · pedir cupo</button>
        </div>
        <div class="panel" style="padding:1rem">
          <h2 style="font-size:1.2rem">Mercado Pago</h2>
          <?php if (mp_enabled()): ?>
            <button class="btn primary" type="submit" name="action" value="pay_mp" style="background:#009ee3">Pagar inscripción</button>
          <?php else: ?>
            <p class="muted">Usá transferencia por ahora.</p>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <button class="btn primary" type="submit" name="action" value="submit">Solicitar cupo gratuito</button>
    <?php endif; ?>
  </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
