<?php

declare(strict_types=1);

/**
 * Piezas comunes de las páginas del admin, pensadas primero para el teléfono: <head> (zona segura, íconos, manifest para
 * «Agregar a pantalla de inicio», fuentes sin bloquear), cabecera fija y navegación que en pantallas chicas es una barra
 * de pestañas abajo. Las páginas que la usan llevan class="has-tabbar" en el <body>.
 */

const UI_FONTS = 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap';

/** URL de un archivo de assets/ con ?v=<hash del contenido>: se puede cachear un año y se renueva solo al cambiar. */
function ui_asset(string $file): string
{
    static $versions = [];
    if (!isset($versions[$file])) {
        $path = __DIR__ . '/../assets/' . $file;
        $versions[$file] = is_file($path) ? substr((string) md5_file($path), 0, 10) : '0';
    }
    return 'assets/' . $file . '?v=' . $versions[$file];
}

/** Google Fonts sin bloquear el primer dibujo de la página. */
function ui_fonts(): string
{
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link rel="stylesheet" href="' . UI_FONTS . '" media="print" onload="this.media=\'all\'">'
        . '<noscript><link rel="stylesheet" href="' . UI_FONTS . '"></noscript>';
}

function admin_head(string $title, array $css = []): string
{
    $html = '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<meta name="theme-color" content="#0f3d36">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-title" content="Fluxus Admin">'
        . '<link rel="manifest" href="manifest.webmanifest">'
        . '<link rel="icon" href="assets/icons/logo-88.png" type="image/png">'
        . '<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">'
        . '<title>' . h($title) . ' · FluxusTerapia</title>'
        . ui_fonts();
    foreach (array_merge(['turnos.css', 'admin.css'], $css) as $file) {
        $html .= '<link rel="stylesheet" href="' . h(ui_asset($file)) . '">';
    }
    return $html . '<script src="' . h(ui_asset('admin.js')) . '" defer></script>';
}

/** <head> y cabecera de las páginas que abre el paciente desde el mail (consentimiento, seña). */
function public_head(string $title): string
{
    return '<meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<meta name="theme-color" content="#0f3d36">'
        . '<link rel="icon" href="assets/icons/logo-88.png" type="image/png">'
        . '<title>' . h($title) . ' · FluxusTerapia</title>'
        . ui_fonts()
        . '<link rel="stylesheet" href="' . h(ui_asset('turnos.css')) . '">';
}

function public_header(): string
{
    return '<header class="top"><a class="brand brand--home" href="../" title="Volver a FluxusTerapia">'
        . '<img class="brand-logo" src="assets/icons/logo-88.png" alt="FluxusTerapia" width="44" height="44">'
        . '<span>Turnos</span></a><nav><a href="../">Inicio</a></nav></header>';
}

function ui_icon(string $name): string
{
    $paths = [
        'turnos' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'pacientes' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.9.7 3.1 2.5 3.5 5.2"/>',
        'plan' => '<path d="M5 19C5 11 10 5 20 4c-1 10-7 15-15 15z"/><path d="M5 19l8-8"/>',
        'biblioteca' => '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H20v15H5.5A1.5 1.5 0 0 0 4 19.5z"/><path d="M4 19.5A1.5 1.5 0 0 0 5.5 21H20v-3"/>',
        'mas' => '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Cabecera fija del admin. $active: turnos | pacientes | plan | biblioteca. Con $nav = false (login) va solo la marca.
 * En el teléfono la <nav> pasa a ser la barra de pestañas de abajo; «Más» abre el resto de las secciones.
 */
function admin_header(string $active, string $label, bool $nav = true): string
{
    $html = '<header class="top admin-top"><a class="brand brand--home" href="admin.php" title="Admin de turnos">'
        . '<img class="brand-logo" src="assets/icons/logo-88.png" alt="FluxusTerapia" width="44" height="44">'
        . '<span>' . h($label) . '</span></a>';
    if (!$nav) {
        return $html . '<a class="admin-site" href="../">Ver el sitio</a></header>';
    }
    $hasPacientes = is_file(__DIR__ . '/../pacientes.php');
    $items = [['turnos', 'admin.php', 'Turnos']];
    if ($hasPacientes) {
        $items[] = ['pacientes', 'pacientes.php', 'Pacientes'];
    }
    $items[] = ['plan', 'plan_mtc.php', 'Plan MTC'];
    $items[] = ['biblioteca', 'biblioteca_lector.php', 'Biblioteca'];
    $html .= '<nav class="admin-nav" aria-label="Secciones del admin">';
    foreach ($items as [$key, $href, $text]) {
        $html .= '<a href="' . $href . '"' . ($key === $active ? ' class="is-on" aria-current="page"' : '') . '>' . ui_icon($key) . '<span>' . $text . '</span></a>';
    }
    $more = [
        ['mtc_catalogo.php', 'Diagnósticos e indicaciones MTC'],
    ];
    if ($hasPacientes && is_file(__DIR__ . '/../biblioteca.php')) {
        $more[] = ['biblioteca.php', 'Textos de la biblioteca (Pacientes)'];
    }
    $more = array_merge($more, [
        null,
        ['admin.php#manual', 'Cargar turno a mano'],
        ['admin.php#lugares', 'Lugares de atención'],
        ['admin.php#sena', 'Seña y transferencia'],
        ['admin.php#requisitos', 'Requisitos para la sesión'],
        ['admin.php#consentimientos', 'Consentimientos'],
        ['admin.php#bloquear', 'Bloquear un día'],
        null,
        ['../', 'Ver el sitio'],
    ]);
    $html .= '<details class="admin-more"><summary>' . ui_icon('mas') . '<span>Más</span></summary><div class="admin-more-menu">';
    foreach ($more as $item) {
        $html .= $item === null ? '<hr>' : '<a href="' . h($item[0]) . '">' . h($item[1]) . '</a>';
    }
    $html .= '<form method="post" action="admin.php"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="logout"><button type="submit">Salir</button></form>';
    return $html . '</div></details></nav></header>';
}

/** Links para escribir por WhatsApp y llamar. Sin código de país se asume celular de Argentina (549…). */
function admin_phone_links(string $phone): array
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 8) {
        return [];
    }
    $wa = preg_replace('/^00/', '', $digits) ?? $digits;
    if (strlen($wa) <= 10) {
        $wa = '549' . ltrim($wa, '0');
    }
    return ['wa' => 'https://wa.me/' . $wa, 'tel' => 'tel:' . (str_starts_with(trim($phone), '+') ? '+' : '') . $digits];
}
