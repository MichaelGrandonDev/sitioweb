<?php
$user = current_user();
$flash = take_flash();
// Logo / botón FluxusTerapia → home del sitio público
$fluxusHome = function_exists('aca_site_home_url')
    ? aca_site_home_url()
    : (((isset($basePath) && $basePath === '../') ? '../../' : '../'));
$fluxusLogoRel = (isset($basePath) && $basePath === '../') ? '../../' : '../';
$fluxusLogo = $fluxusLogoRel . 'img/logo-circle.png';
$base = $basePath ?? (in_staff_panel() ? '../' : '');
$useShell = $user !== null;

if ($useShell) {
    $openTickets = tickets_pending_count($user);
    $isAdminArea = is_staff($user) && in_staff_panel();
    $portalPrefix = in_profesor_dir() ? 'profesor/' : (in_admin_dir() ? 'admin/' : '');
    $current = $portalPrefix . basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $aliases = [
        'resource.php' => 'cursos.php',
        'course.php' => 'cursos.php',
        'mi-curso.php' => 'cursos.php',
        'clases.php' => 'cursos.php',
        'ticket.php' => 'tickets.php',
        'admin/estudiantes.php' => 'admin/estudiantes.php',
        'profesor/estudiantes.php' => 'profesor/estudiantes.php',
        'admin/curso.php' => 'admin/courses.php',
        'profesor/curso.php' => 'profesor/courses.php',
        'admin/grabaciones.php' => 'admin/courses.php',
        'profesor/grabaciones.php' => 'profesor/courses.php',
        'admin/campus.php' => 'admin/courses.php',
        'profesor/campus.php' => 'profesor/courses.php',
    ];
    $current = $aliases[$current] ?? $current;

    if ($isAdminArea) {
        $navItems = admin_nav_items($user, $openTickets);
        if (is_full_admin($user)) {
            $webItems = [
                ['admin/sitio_textos.php', 'Textos', 'pen', 0],
                ['admin/sitio_fotos.php', 'Fotos', 'image', 0],
                ['admin/sitio_contacto.php', 'Contacto y WhatsApp', 'whatsapp', 0],
                ['admin/sitio_estilo.php', 'Letra y colores', 'type', 0],
            ];
            $siteItems = [
                ['../turnos/admin.php', 'Turnos y pacientes', 'calendar'],
                ['../galeria/admin.php', 'Galería', 'image'],
                ['../blogs/admin.php', 'Blogs', 'pen'],
                ['fluxus/', 'Generador de clases', 'spark'],
            ];
        } else {
            $webItems = [];
            $siteItems = [];
        }
        if ($current === 'admin/sitio_historial.php') {
            $current = 'admin/sitio_textos.php';
        }
        $footLink = is_full_admin($user) ? ['dashboard.php', 'Ver como alumno', 'eye'] : null;
        $mobileItems = array_slice($navItems, 0, 4);
        $showFluxusHomeBtn = is_teacher($user);
    } else {
        $navItems = [
            ['dashboard.php', 'Inicio', 'home', 0],
            ['cursos.php', 'Mis cursos', 'book', 0],
            ['tickets.php', 'Consultas', 'chat', $openTickets],
            ['cuenta.php', 'Mi cuenta', 'user', 0],
        ];
        $siteItems = [];
        $webItems = [];
        $footLink = is_staff($user)
            ? [staff_home_for_role($user), is_teacher($user) ? 'Portal profesores' : 'Volver al panel', 'grid']
            : null;
        $mobileItems = $navItems;
        $showFluxusHomeBtn = true;
    }
}
$showFluxusHomeBtn = !empty($showFluxusHomeBtn);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#0f3d36">
  <title><?= h(($pageTitle ?? 'Academia') . ' · AcademiaFluxus') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= h(($assetPrefix ?? $base) . 'assets/css/academia.css') ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/academia.css') ?>">
  <link rel="icon" href="<?= h($fluxusHome) ?>img/logo.png" type="image/png">
</head>
<body class="<?= trim(
    (!empty($campusMode) ? 'is-campus ' : '')
    . (!empty($minimalShell) && !$useShell ? 'is-minimal ' : '')
    . ($useShell ? 'has-shell ' : '')
    . ($useShell && empty($isAdminArea) ? 'is-student' : '')
) ?>">
<?php if ($useShell): ?>
<div class="shell">
  <aside class="side" id="side">
    <a class="side-brand" href="<?= h($fluxusHome) ?>" title="Volver a FluxusTerapia">
      <img src="<?= h($fluxusLogo) ?>" alt="" width="40" height="40">
      <span>AcademiaFluxus<em><?= $isAdminArea
        ? (is_teacher($user) || in_profesor_dir() ? 'Portal profesores' : 'Administración')
        : 'Campus alumno' ?></em></span>
    </a>
    <nav class="side-nav" aria-label="Secciones">
      <?php foreach ($navItems as [$href, $label, $icon, $badge]): ?>
        <a class="side-link<?= $current === $href ? ' is-active' : '' ?>" href="<?= h($base . $href) ?>"<?= $current === $href ? ' aria-current="page"' : '' ?>>
          <?= aca_icon($icon) ?><span><?= h($label) ?></span>
          <?php if ($badge > 0): ?><b class="side-badge"><?= (int) $badge ?></b><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($webItems): ?>
        <p class="side-label">Sitio web</p>
        <?php foreach ($webItems as [$href, $label, $icon]): ?>
          <a class="side-link<?= $current === $href ? ' is-active' : '' ?>" href="<?= h($base . $href) ?>"<?= $current === $href ? ' aria-current="page"' : '' ?>><?= aca_icon($icon) ?><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($siteItems): ?>
        <p class="side-label">Más secciones</p>
        <?php foreach ($siteItems as [$href, $label, $icon]): ?>
          <a class="side-link is-quiet" href="<?= h($base . $href) ?>"><?= aca_icon($icon) ?><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <?php if ($footLink): ?>
      <a class="side-switch" href="<?= h($base . $footLink[0]) ?>"><?= aca_icon($footLink[2]) ?><span><?= h($footLink[1]) ?></span></a>
    <?php endif; ?>
    <?php if ($showFluxusHomeBtn): ?>
      <a class="side-home-site" href="<?= h($fluxusHome) ?>" target="_blank" rel="noopener noreferrer" title="Ir al sitio de FluxusTerapia">
        <?= aca_icon('globe') ?><span>FluxusTerapia</span>
      </a>
    <?php endif; ?>
    <div class="side-user">
      <span class="avatar"><?= h(aca_initial((string) $user['name'])) ?></span>
      <span class="side-user-text">
        <strong><?= h($user['name']) ?></strong>
        <em><?= h((string) ($user['email'] ?: $user['username'])) ?></em>
      </span>
      <a class="icon-btn" href="<?= h($base . 'logout.php') ?>" title="Cerrar sesión" aria-label="Cerrar sesión"><?= aca_icon('logout') ?></a>
    </div>
  </aside>
  <div class="side-scrim" data-side-close></div>

  <div class="shell-main">
    <header class="mobile-top">
      <img src="<?= h($fluxusLogo) ?>" alt="" width="34" height="34">
      <p>Hola, <?= h(aca_first_name((string) $user['name'])) ?></p>
      <?php if ($showFluxusHomeBtn): ?>
        <a class="mobile-home-site" href="<?= h($fluxusHome) ?>" target="_blank" rel="noopener noreferrer">FluxusTerapia</a>
      <?php endif; ?>
      <?php if ($isAdminArea): ?>
        <button class="icon-btn" type="button" data-side-open aria-label="Abrir menú"><?= aca_icon('menu') ?></button>
      <?php else: ?>
        <a class="icon-btn" href="<?= h($base . 'logout.php') ?>" aria-label="Cerrar sesión"><?= aca_icon('logout') ?></a>
      <?php endif; ?>
    </header>
    <main class="shell-content<?= !empty($campusMode) ? ' is-wide' : '' ?>">
<?php else: ?>
  <?php if (!empty($minimalShell)): ?>
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
      <a href="<?= h($base . 'inscripcion.php') ?>">Inscribirme</a>
      <a href="<?= h($base . 'login.php') ?>">Ingresar</a>
    </nav>
  </header>
  <?php endif; ?>
  <main class="<?= !empty($campusMode) ? 'campus-wrap' : 'wrap' ?>">
<?php endif; ?>
    <?php if ($flash): ?>
      <div class="alert <?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
    <?php endif; ?>
