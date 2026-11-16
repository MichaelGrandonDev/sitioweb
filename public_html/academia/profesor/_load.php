<?php

/**
 * Carga una pantalla del panel admin dentro del portal de profesores.
 * La URL queda en /profesor/…; los permisos y el menú ya distinguen el portal.
 */
declare(strict_types=1);

$page = basename((string) ($profesorPage ?? ''));
$allowed = [
    'grabaciones.php',
    'courses.php',
    'campus.php',
    'tickets.php',
    'estudiantes.php',
];
if (!in_array($page, $allowed, true)) {
    http_response_code(404);
    echo 'Página no disponible en el portal de profesores.';
    exit;
}

require __DIR__ . '/../admin/' . $page;
