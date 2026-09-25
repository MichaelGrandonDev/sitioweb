<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    flash('error', 'La tienda aún no está disponible.');
    header('Location: ../');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        header('Location: index.php');
        exit;
    }
    $courseId = (int) ($_POST['course_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'add' && $courseId > 0) {
        $check = db()->prepare('SELECT id FROM courses WHERE id = ? AND shop_listed = 1 AND published = 1 AND shop_price > 0');
        $check->execute([$courseId]);
        if ($check->fetch()) {
            cart_add($courseId);
            flash('success', 'Curso agregado al carrito.');
            header('Location: cart.php');
            exit;
        }
    }
    header('Location: index.php');
    exit;
}

$courses = db()->query("
  SELECT * FROM courses
  WHERE shop_listed = 1 AND published = 1 AND shop_price > 0
  ORDER BY sort_order, title
")->fetchAll();

cursos_layout_start('Cursos grabados', 'cursos');
?>
<p class="eyebrow">Tienda online</p>
<h1>Cursos grabados</h1>
<p class="lede">Comprá el acceso a cursos grabados, pagá online o por transferencia, y recibí el mail con usuario, contraseña y link para cursar. Esta sección es independiente de AcademiaFluxus (clases en vivo).</p>
<p class="actions">
  <a class="btn primary" href="cart.php">Ver carrito (<?= cart_count() ?>)</a>
  <a class="btn secondary" href="../academia/inscripcion.php">Inscribirme a la academia en vivo</a>
</p>

<?php if (!$courses): ?>
  <div class="panel" style="margin-top:1rem">
    <p class="muted">Pronto vas a encontrar aquí los cursos grabados a la venta.</p>
  </div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($courses as $c): ?>
      <article class="course-card">
        <p class="eyebrow"><?= cursos_h($c['category'] ?: 'Curso grabado') ?></p>
        <h2><?= cursos_h($c['title']) ?></h2>
        <p class="muted"><?= cursos_h($c['shop_blurb'] !== '' ? $c['shop_blurb'] : $c['description']) ?></p>
        <p class="price"><?= cursos_h(money_ars((int) $c['shop_price'])) ?></p>
        <form method="post" style="margin-top:.85rem">
          <input type="hidden" name="csrf" value="<?= cursos_h(csrf_token()) ?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
          <button class="btn primary" type="submit">Agregar al carrito</button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php cursos_layout_end(); ?>
