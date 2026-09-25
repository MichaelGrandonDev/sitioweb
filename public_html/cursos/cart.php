<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    header('Location: ../');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        header('Location: cart.php');
        exit;
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
    header('Location: cart.php');
    exit;
}

$courses = cart_courses();
$total = cart_total($courses);
cursos_layout_start('Carrito', 'cart');
?>
<p class="eyebrow">Compra</p>
<h1>Tu carrito</h1>
<p class="actions"><a class="btn secondary" href="./">← Seguir mirando cursos</a></p>

<?php if (!$courses): ?>
  <div class="panel" style="margin-top:1rem">
    <p class="muted">El carrito está vacío.</p>
    <p><a class="btn primary" href="./">Ver cursos grabados</a></p>
  </div>
<?php else: ?>
  <section class="panel" style="margin-top:1rem">
    <?php foreach ($courses as $c): ?>
      <article class="lesson-card">
        <div>
          <strong><?= cursos_h($c['title']) ?></strong>
          <p class="muted small"><?= cursos_h($c['category'] ?: 'Curso grabado') ?></p>
        </div>
        <div class="actions">
          <strong><?= cursos_h(money_ars((int) $c['shop_price'])) ?></strong>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= cursos_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
            <button class="btn secondary" type="submit">Quitar</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <p class="price" style="margin-top:1rem">Total: <?= cursos_h(money_ars($total)) ?></p>
    <div class="actions" style="margin-top:1rem">
      <a class="btn primary" href="checkout.php">Ir a pagar</a>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= cursos_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="clear">
        <button class="btn secondary" type="submit">Vaciar</button>
      </form>
    </div>
  </section>
<?php endif; ?>
<?php cursos_layout_end(); ?>
