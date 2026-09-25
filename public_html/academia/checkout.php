<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}

$user = current_user();
if (!$user) {
    flash('error', 'Ingresá como alumno para completar la compra.');
    redirect('login.php?as=alumno');
}
if ($user['role'] === 'admin') {
    flash('error', 'Las compras de cursos son para cuentas de alumno. Usá Vista alumno o una cuenta alumno.');
    redirect('cart.php');
}

$courses = cart_courses();
$total = cart_total($courses);
if (!$courses) {
    flash('error', 'El carrito está vacío.');
    redirect('shop.php');
}

$cfg = payment_cfg();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $pdo = db();
        $pdo->prepare("
          INSERT INTO shop_orders (user_id, total, status, method)
          VALUES (?, ?, 'pending', ?)
        ")->execute([(int) $user['id'], $total, $action === 'pay_mp' ? 'mercadopago' : 'transfer']);
        $orderId = (int) $pdo->lastInsertId();
        $insItem = $pdo->prepare('INSERT INTO shop_order_items (order_id, course_id, title, price) VALUES (?, ?, ?, ?)');
        foreach ($courses as $c) {
            $insItem->execute([$orderId, (int) $c['id'], (string) $c['title'], (int) $c['shop_price']]);
        }
        $order = ['id' => $orderId, 'total' => $total];

        if ($action === 'pay_mp') {
            $items = [];
            foreach ($courses as $c) {
                $items[] = ['course_id' => (int) $c['id'], 'title' => $c['title'], 'price' => (int) $c['shop_price']];
            }
            $pref = create_shop_mp_preference($order, $user, $items);
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
                flash('success', 'Pedido informado. Cuando confirmemos la transferencia, se habilitan tus cursos.');
                cart_clear();
                redirect('shop_order.php?id=' . $orderId);
            }
        }
    }
}

$pageTitle = 'Pagar cursos';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
$alias = (string) ($cfg['transfer_alias'] ?? 'michael.grandon.mp');
?>
<p class="eyebrow">Checkout</p>
<h1>Pagar cursos grabados</h1>
<p class="lede">Total a pagar: <strong><?= h(money_ars($total)) ?></strong></p>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<section class="panel" style="margin-top:1rem">
  <h2>Resumen</h2>
  <ul class="list">
    <?php foreach ($courses as $c): ?>
      <li><?= h($c['title']) ?> — <?= h(money_ars((int) $c['shop_price'])) ?></li>
    <?php endforeach; ?>
  </ul>
</section>

<div class="grid" style="margin-top:1rem">
  <section class="panel">
    <h2>Transferencia</h2>
    <p class="muted small">
      Titular: <?= h((string) ($cfg['transfer_holder'] ?? '')) ?><br>
      Banco: <?= h((string) ($cfg['transfer_bank'] ?? '')) ?><br>
      Alias: <strong><?= h($alias) ?></strong><br>
      <?php if (!empty($cfg['transfer_cbu'])): ?>CBU: <?= h((string) $cfg['transfer_cbu']) ?><br><?php endif; ?>
      Concepto: compra cursos + tu nombre
    </p>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="pay_transfer">
      <label>Nº de operación <input name="transfer_ref" required></label>
      <label>Nota <input name="note" placeholder="Opcional"></label>
      <button class="btn primary" type="submit">Ya transferí</button>
    </form>
  </section>
  <section class="panel">
    <h2>Tarjeta / Mercado Pago</h2>
    <?php if (mp_enabled()): ?>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="pay_mp">
        <button class="btn primary" type="submit" style="background:#009ee3">Pagar con Mercado Pago</button>
      </form>
    <?php else: ?>
      <p class="muted">Pronto habilitamos tarjeta. Por ahora usá transferencia al alias <?= h($alias) ?>.</p>
    <?php endif; ?>
    <p style="margin-top:1rem"><a class="btn secondary" href="cart.php">← Volver al carrito</a></p>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
