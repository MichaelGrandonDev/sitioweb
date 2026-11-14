<?php

declare(strict_types=1);

/*
 * Home con contenido editable desde el admin (Sitio web).
 * Si falta la librería o algo falla, sirve index.html tal cual para que la home nunca se caiga.
 */
$sitioStatic = static function (): void {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/index.html');
    exit;
};

if (!is_file(__DIR__ . '/includes/sitio.php') || !is_file(__DIR__ . '/includes/sitio_home.php')) {
    $sitioStatic();
}

try {
    require_once __DIR__ . '/includes/sitio.php';
    ob_start();
    require __DIR__ . '/includes/sitio_home.php';
    $html = (string) ob_get_clean();
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Home editable: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    $sitioStatic();
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache');
echo $html;
