<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$orderId = (int) ($_GET['order_id'] ?? 0);
cursos_layout_start('Gracias', 'cursos');
?>
<p class="eyebrow">Listo</p>
<h1>Gracias por tu compra</h1>
<p class="lede">
  <?php if ($orderId > 0): ?>Pedido #<?= $orderId ?>.<?php endif; ?>
  Cuando el pago esté confirmado te llega un email con <strong>usuario</strong>, <strong>contraseña</strong> y el <strong>link</strong> para entrar a cursar.
</p>
<div class="panel" style="margin-top:1rem">
  <p class="muted">Revisá también la carpeta de spam. Si ya tenías cuenta, el mail trae una clave actualizada de acceso.</p>
  <div class="actions" style="margin-top:1rem">
    <a class="btn primary" href="../academia/login.php?as=alumno">Ir al login</a>
    <a class="btn secondary" href="./">Volver al catálogo</a>
  </div>
</div>
<?php cursos_layout_end(); ?>
