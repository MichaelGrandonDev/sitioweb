<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    header('Location: ../');
    exit;
}

$courses = cart_courses();
$total = cart_total($courses);
if (!$courses) {
    flash('error', 'El carrito está vacío.');
    header('Location: index.php');
    exit;
}

$cfg = payment_cfg();
$error = '';
$user = current_user();
if ($user && $user['role'] === 'admin') {
    flash('error', 'Usá una cuenta de alumno o comprá como visitante (nombre y email).');
    header('Location: cart.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $name = trim((string) ($_POST['buyer_name'] ?? ($user['name'] ?? '')));
        $email = strtolower(trim((string) ($_POST['buyer_email'] ?? ($user['email'] ?? ''))));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Completá nombre y un email válido para enviarte el acceso.';
        } else {
            if ($user && $user['role'] === 'student') {
                $buyer = $user;
                $accountPassword = null;
            } else {
                $account = ensure_student_account($name, $email, true);
                $buyer = $account['user'];
                $accountPassword = $account['password'];
                // password will be reset again on fulfill and emailed — ok
            }

            $pdo = db();
            $pdo->prepare("
              INSERT INTO shop_orders (user_id, total, status, method, buyer_name, buyer_email)
              VALUES (?, ?, 'pending', ?, ?, ?)
            ")->execute([
                (int) $buyer['id'],
                $total,
                $action === 'pay_mp' ? 'mercadopago' : 'transfer',
                $name,
                $email,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $insItem = $pdo->prepare('INSERT INTO shop_order_items (order_id, course_id, title, price) VALUES (?, ?, ?, ?)');
            foreach ($courses as $c) {
                $insItem->execute([$orderId, (int) $c['id'], (string) $c['title'], (int) $c['shop_price']]);
            }

            if ($action === 'pay_mp') {
                $items = [];
                foreach ($courses as $c) {
                    $items[] = ['course_id' => (int) $c['id'], 'title' => $c['title'], 'price' => (int) $c['shop_price']];
                }
                $pref = create_shop_mp_preference(
                    ['id' => $orderId, 'total' => $total],
                    ['id' => (int) $buyer['id'], 'name' => $name, 'email' => $email],
                    $items
                );
                if (!empty($pref['ok']) && !empty($pref['init_point'])) {
                    header('Location: ' . $pref['init_point']);
                    exit;
                }
                $error = 'No se pudo iniciar Mercado Pago: ' . ($pref['error'] ?? '');
            }

            if ($action === 'pay_transfer') {
                $ref = trim((string) ($_POST['transfer_ref'] ?? ''));
                $note = trim((string) ($_POST['note'] ?? ''));
                if ($ref === '') {
                    $error = 'Indicá el número de operación.';
                    $pdo->prepare('DELETE FROM shop_orders WHERE id = ?')->execute([$orderId]);
                } else {
                    $pdo->prepare('UPDATE shop_orders SET transfer_ref = ?, note = ?, method = ? WHERE id = ?')
                        ->execute([$ref, $note, 'transfer', $orderId]);
                    // Aviso admin
                    $admin = admin_notify_email();
                    send_mail(
                        $admin,
                        'Nueva compra curso grabado (transferencia) #' . $orderId,
                        '<p>Pedido #'.$orderId.' de '.htmlspecialchars($name).' · '.htmlspecialchars($email).'</p><p>Ref: '.htmlspecialchars($ref).'</p><p>Confirmá en Admin → Tienda.</p>',
                        "Pedido #{$orderId}\n{$name}\n{$email}\nRef {$ref}"
                    );
                    cart_clear();
                    flash('success', 'Informamos tu transferencia. Cuando se confirme el pago te llega el mail con el acceso.');
                    header('Location: gracias.php?order_id=' . $orderId);
                    exit;
                }
            }
        }
    }
}

cursos_layout_start('Pagar', 'cart');
$alias = (string) ($cfg['transfer_alias'] ?? 'michael.grandon.mp');
?>
<p class="eyebrow">Checkout</p>
<h1>Pagar cursos grabados</h1>
<p class="lede">Total: <strong><?= cursos_h(money_ars($total)) ?></strong>. Al acreditarse el pago te enviamos el mail con usuario, contraseña y link.</p>
<?php if ($error): ?><div class="cursos-alert error"><?= cursos_h($error) ?></div><?php endif; ?>

<section class="panel" style="margin-top:1rem">
  <h2>Resumen</h2>
  <ul>
    <?php foreach ($courses as $c): ?>
      <li><?= cursos_h($c['title']) ?> — <?= cursos_h(money_ars((int) $c['shop_price'])) ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<form method="post" class="stack" style="margin-top:1rem">
  <input type="hidden" name="csrf" value="<?= cursos_h(csrf_token()) ?>">
  <div class="panel stack">
    <h2>Tus datos (para el mail de acceso)</h2>
    <div class="form-grid two">
      <label>Nombre completo
        <input name="buyer_name" required value="<?= cursos_h((string) ($user['name'] ?? $_POST['buyer_name'] ?? '')) ?>">
      </label>
      <label>Email
        <input type="email" name="buyer_email" required value="<?= cursos_h((string) ($user['email'] ?? $_POST['buyer_email'] ?? '')) ?>">
      </label>
    </div>
  </div>

  <div class="grid">
    <section class="panel stack">
      <h2>Transferencia</h2>
      <p class="muted small">
        Titular: <?= cursos_h((string) ($cfg['transfer_holder'] ?? '')) ?><br>
        Banco: <?= cursos_h((string) ($cfg['transfer_bank'] ?? '')) ?><br>
        Alias: <strong><?= cursos_h($alias) ?></strong><br>
        Concepto: cursos grabados + tu nombre
      </p>
      <label>Nº de operación <input name="transfer_ref"></label>
      <label>Nota <input name="note" placeholder="Opcional"></label>
      <button class="btn primary" type="submit" name="action" value="pay_transfer">Ya transferí</button>
    </section>
    <section class="panel stack">
      <h2>Tarjeta / Mercado Pago</h2>
      <?php if (mp_enabled()): ?>
        <button class="btn primary" type="submit" name="action" value="pay_mp" style="background:#009ee3">Pagar con Mercado Pago</button>
      <?php else: ?>
        <p class="muted">Por ahora usá transferencia al alias <?= cursos_h($alias) ?>.</p>
      <?php endif; ?>
      <a class="btn secondary" href="cart.php">← Volver al carrito</a>
    </section>
  </div>
</form>
<?php cursos_layout_end(); ?>
