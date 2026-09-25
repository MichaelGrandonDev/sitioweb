<?php

declare(strict_types=1);

require_once __DIR__ . '/../academia/bootstrap.php';

function cursos_h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function cursos_layout_start(string $title, string $active = 'cursos'): void
{
    $flash = take_flash();
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= cursos_h($title) ?> · Cursos grabados</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/cursos.css?v=1">
  <link rel="icon" href="../img/logo.png" type="image/png">
</head>
<body>
  <header class="cursos-top">
    <a class="cursos-brand brand--home" href="../" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span class="brand-text">Cursos <em>grabados</em></span>
    </a>
    <nav>
      <a href="./" class="<?= $active === 'cursos' ? 'is-active' : '' ?>">Catálogo</a>
      <a href="cart.php" class="<?= $active === 'cart' ? 'is-active' : '' ?>">Carrito<?= cart_count() > 0 ? ' (' . cart_count() . ')' : '' ?></a>
      <a href="../academia/login.php?as=alumno">Ya tengo acceso</a>
      <a href="../academia/">AcademiaFluxus</a>
    </nav>
  </header>
  <main class="cursos-wrap">
    <?php if ($flash): ?>
      <div class="cursos-alert <?= cursos_h($flash['type']) ?>"><?= cursos_h($flash['message']) ?></div>
    <?php endif; ?>
    <?php
}

function cursos_layout_end(): void
{
    ?>
  </main>
  <footer class="cursos-foot">
    <p>Cursos grabados · FluxusTerapia · Acceso independiente de la academia en vivo</p>
  </footer>
</body>
</html>
    <?php
}
