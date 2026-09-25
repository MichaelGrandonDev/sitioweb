<?php
$user = current_user();
$flash = take_flash();
// Logo FluxusTerapia → home del sitio
$fluxusHome = (isset($basePath) && $basePath === '../') ? '../../' : '../';
$fluxusLogo = $fluxusHome . 'img/logo-circle.png';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h(($pageTitle ?? 'Academia') . ' · AcademiaFluxus') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(($assetPrefix ?? '') . 'assets/css/academia.css') ?>">
  <link rel="icon" href="<?= h($fluxusHome) ?>img/logo.png" type="image/png">
</head>
<body class="<?= trim((!empty($campusMode) ? 'is-campus ' : '') . (!empty($minimalShell) ? 'is-minimal' : '')) ?>">
  <?php if (!empty($minimalShell) && !$user): ?>
  <header class="topbar topbar--minimal">
    <a class="brand brand--home" href="<?= h($fluxusHome) ?>" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="<?= h($fluxusLogo) ?>" alt="FluxusTerapia" width="48" height="48">
      <span class="brand-text">AcademiaFluxus</span>
    </a>
  </header>
  <?php else: ?>
  <header class="topbar">
    <a class="brand brand--home" href="<?= h($fluxusHome) ?>" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="<?= h($fluxusLogo) ?>" alt="FluxusTerapia" width="44" height="44">
      <span class="brand-text">AcademiaFluxus <em>Campus</em></span>
    </a>
    <nav>
      <?php if ($user): ?>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="<?= h(($basePath ?? '') . 'admin/index.php') ?>">Admin</a>
          <a href="<?= h(($basePath ?? '') . 'admin/shop.php') ?>">Tienda</a>
          <a href="<?= h(($basePath ?? '') . 'admin/admins.php') ?>">Admins</a>
          <a href="<?= h(($basePath ?? '') . 'admin/campus.php') ?>">Unidades</a>
          <a href="<?= h(($basePath ?? '') . 'admin/payments.php') ?>">Cuotas</a>
          <a href="<?= h(($basePath ?? '') . 'dashboard.php') ?>">Vista alumno</a>
        <?php else: ?>
          <a href="<?= h(($basePath ?? '') . 'dashboard.php') ?>">Mis cursos</a>
          <a href="<?= h(($basePath ?? '') . '../cursos/') ?>">Cursos grabados</a>
          <a href="<?= h(($basePath ?? '') . 'payments.php') ?>">Mis cuotas</a>
        <?php endif; ?>
        <a href="<?= h(($basePath ?? '') . 'cart.php') ?>" class="cart-link">Carrito<?= cart_count() > 0 ? ' (' . cart_count() . ')' : '' ?></a>
        <span class="topbar-user"><?= h($user['name']) ?><?= $user['role'] === 'admin' ? ' · Admin' : ' · Alumno' ?></span>
        <a href="<?= h(($basePath ?? '') . 'logout.php') ?>">Salir</a>
      <?php else: ?>
        <a href="<?= h(($basePath ?? '') . '../cursos/') ?>">Cursos grabados</a>
        <a href="<?= h(($basePath ?? '') . '../cursos/cart.php') ?>" class="cart-link">Carrito<?= cart_count() > 0 ? ' (' . cart_count() . ')' : '' ?></a>
        <a href="<?= h(($basePath ?? '') . 'inscripcion.php') ?>">Inscribirme</a>
        <a href="<?= h(($basePath ?? '') . 'login.php') ?>">Ingresar</a>
      <?php endif; ?>
    </nav>
  </header>
  <?php endif; ?>
  <main class="<?= !empty($campusMode) ? 'campus-wrap' : 'wrap' ?>">
    <?php if ($flash): ?>
      <div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
