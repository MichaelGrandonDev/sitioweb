<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('cart.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'remove') {
        cart_remove((int) ($_POST['course_id'] ?? 0));
        flash('success', 'Curso quitado del carrito.');
    }
    if ($action === 'clear') {
        cart_clear();
        flash('success', 'Carrito vacío.');
    }
    redirect('cart.php');
}

$courses = cart_courses();
$total = cart_total($courses);

$pageTitle = 'Carrito';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<p class="eyebrow">Tienda</p>
<h1>Tu carrito</h1>
<p class="actions">
  <a class="btn secondary" href="shop.php">← Seguir mirando cursos</a>
</p>

<?php if (!$courses): ?>
  <div class="panel" style="margin-top:1rem">
    <p class="muted">El carrito está vacío.</p>
    <p><a class="btn primary" href="shop.php">Ver cursos grabados</a></p>
  </div>
<?php else: ?>
  <section class="panel" style="margin-top:1rem">
    <?php foreach ($courses as $c): ?>
      <article class="lesson-card" style="margin-bottom:.65rem">
        <div>
          <h3 style="margin:0;font-size:1.15rem"><?= h($c['title']) ?></h3>
          <p class="muted small"><?= h($c['category'] ?: 'Curso grabado') ?></p>
        </div>
        <div class="actions" style="align-items:center">
          <strong><?= h(money_ars((int) $c['shop_price'])) ?></strong>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
            <button class="btn danger small-btn" type="submit">Quitar</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <p style="margin:1rem 0 0;font-size:1.35rem;font-weight:700">Total: <?= h(money_ars($total)) ?></p>
    <div class="actions" style="margin-top:1rem">
      <a class="btn primary" href="checkout.php">Ir a pagar</a>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear">
        <button class="btn secondary" type="submit">Vaciar carrito</button>
      </form>
    </div>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
