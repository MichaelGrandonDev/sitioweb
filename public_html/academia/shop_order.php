<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$orderId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM shop_orders WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$orderId, (int) $user['id']]);
$order = $stmt->fetch();
if (!$order && $user['role'] !== 'admin') {
    flash('error', 'Pedido no encontrado.');
    redirect('shop.php');
}
if (!$order && $user['role'] === 'admin') {
    $stmt = db()->prepare('SELECT * FROM shop_orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
}
if (!$order) {
    flash('error', 'Pedido no encontrado.');
    redirect('shop.php');
}

$items = db()->prepare('SELECT * FROM shop_order_items WHERE order_id = ?');
$items->execute([$orderId]);
$items = $items->fetchAll();

$pageTitle = 'Pedido #' . $orderId;
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">Tienda</p>
<h1>Pedido #<?= (int) $order['id'] ?></h1>
<p class="lede">
  Estado:
  <span class="badge <?= $order['status'] === 'paid' ? 'done' : '' ?>">
    <?= $order['status'] === 'paid' ? 'Pagado · cursos habilitados' : 'Pendiente de confirmación' ?>
  </span>
</p>
<section class="panel">
  <p><strong>Total:</strong> <?= h(money_ars((int) $order['total'])) ?></p>
  <p class="muted small">Método: <?= h($order['method'] ?: '—') ?> <?= $order['transfer_ref'] ? '· ref ' . h($order['transfer_ref']) : '' ?></p>
  <ul>
    <?php foreach ($items as $it): ?>
      <li><?= h($it['title']) ?> — <?= h(money_ars((int) $it['price'])) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($order['status'] === 'paid'): ?>
    <p><a class="btn primary" href="dashboard.php">Ir a mis cursos</a></p>
  <?php else: ?>
    <p class="muted">Cuando el administrador confirme el pago, vas a ver los cursos en tu campus.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
