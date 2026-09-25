<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('shop.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_course_shop') {
        $id = (int) ($_POST['course_id'] ?? 0);
        $listed = !empty($_POST['shop_listed']) ? 1 : 0;
        $price = max(0, (int) ($_POST['shop_price'] ?? 0));
        $blurb = trim((string) ($_POST['shop_blurb'] ?? ''));
        if ($id > 0) {
            $pdo->prepare('UPDATE courses SET shop_listed = ?, shop_price = ?, shop_blurb = ? WHERE id = ?')
                ->execute([$listed, $price, $blurb, $id]);
            flash('success', 'Tienda del curso actualizada.');
        }
    }

    if ($action === 'confirm_order') {
        $oid = (int) ($_POST['order_id'] ?? 0);
        if ($oid > 0) {
            fulfill_shop_order($oid);
            flash('success', 'Pedido confirmado. Cursos habilitados al alumno.');
        }
    }

    if ($action === 'reject_order') {
        $oid = (int) ($_POST['order_id'] ?? 0);
        if ($oid > 0) {
            $pdo->prepare("UPDATE shop_orders SET status = 'rejected' WHERE id = ?")->execute([$oid]);
            flash('success', 'Pedido rechazado.');
        }
    }

    redirect('shop.php');
}

$courses = $pdo->query('SELECT * FROM courses ORDER BY sort_order, title')->fetchAll();
$orders = $pdo->query("
  SELECT o.*, u.name AS student_name, u.username
  FROM shop_orders o
  JOIN users u ON u.id = o.user_id
  ORDER BY o.created_at DESC
  LIMIT 60
")->fetchAll();

$pageTitle = 'Tienda cursos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Tienda · cursos grabados</h1>
<p class="lede">Publicá cursos en la tienda, definí precio y confirmá compras. Los materiales de cada curso se cargan en Unidades y materiales (mismo contenido que el campus).</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="courses.php">Cursos</a>
  <a class="btn secondary" href="campus.php">Unidades / materiales</a>
  <a class="btn primary" href="../cursos/" target="_blank">Ver tienda pública</a>
</nav>

<section class="panel" style="margin-bottom:1rem">
  <h2>Cursos en venta</h2>
  <?php foreach ($courses as $c): ?>
    <form method="post" class="stack" style="margin:1rem 0;padding-top:1rem;border-top:1px solid var(--line)">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_course_shop">
      <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
      <strong><?= h($c['title']) ?></strong>
      <label class="check">
        <input type="checkbox" name="shop_listed" value="1" <?= (int) ($c['shop_listed'] ?? 0) ? 'checked' : '' ?>>
        Mostrar en tienda (curso grabado)
      </label>
      <div class="form-grid two">
        <label>Precio (ARS)
          <input type="number" name="shop_price" min="0" value="<?= (int) ($c['shop_price'] ?? 0) ?>">
        </label>
        <label>Texto corto tienda
          <input name="shop_blurb" value="<?= h((string) ($c['shop_blurb'] ?? '')) ?>" placeholder="Breve descripción de venta">
        </label>
      </div>
      <button class="btn secondary" type="submit">Guardar</button>
    </form>
  <?php endforeach; ?>
</section>

<section class="panel">
  <h2>Pedidos</h2>
  <?php if (!$orders): ?>
    <p class="muted">Todavía no hay compras.</p>
  <?php else: ?>
    <?php foreach ($orders as $o): ?>
      <article class="lesson-card" style="margin-bottom:.65rem;align-items:flex-start">
        <div>
          <strong>#<?= (int) $o['id'] ?> · <?= h($o['student_name']) ?></strong>
          <span class="badge <?= $o['status'] === 'paid' ? 'done' : '' ?>"><?= h($o['status']) ?></span>
          <p class="muted small">
            <?= h(money_ars((int) $o['total'])) ?> · <?= h($o['method'] ?: '—') ?>
            <?= $o['transfer_ref'] ? ' · ref ' . h($o['transfer_ref']) : '' ?>
            · <?= h($o['created_at']) ?>
          </p>
        </div>
        <div class="actions" style="flex-direction:column">
          <?php if ($o['status'] === 'pending'): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="confirm_order">
              <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
              <button class="btn primary small-btn" type="submit">Confirmar y habilitar</button>
            </form>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="reject_order">
              <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
              <button class="btn danger small-btn" type="submit">Rechazar</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
