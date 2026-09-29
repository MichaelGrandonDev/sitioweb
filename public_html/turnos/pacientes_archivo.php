<?php

declare(strict_types=1);

/** Descarga de archivos de pacientes y documentos de la Biblioteca MTC: solo con sesión de admin. */

require __DIR__ . '/bootstrap.php';

if (!db_ready() || empty($_SESSION['turnos_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    exit('Acceso restringido.');
}
require_once __DIR__ . '/includes/pacientes.php';

$id = (int) ($_GET['id'] ?? 0);
if (($_GET['tipo'] ?? '') === 'doc') {
    $doc = pac_doc($id);
    $path = $doc ? pac_doc_file($doc) : '';
    $name = $doc ? (string) $doc['original_name'] : '';
    $mime = match ((string) ($doc['kind'] ?? '')) {
        'pdf' => 'application/pdf',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        default => 'text/plain; charset=utf-8',
    };
} else {
    $file = pac_file($id);
    $path = $file ? pac_storage_path((string) $file['stored']) : '';
    $name = $file ? (string) $file['original_name'] : '';
    $mime = $file ? (string) $file['mime'] : '';
    if ($file && str_contains((string) $file['stored'], '..')) {
        $path = '';
    }
}
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se encontró el archivo.');
}

$inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
$ascii = preg_replace('/[^A-Za-z0-9._\-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: 'archivo') ?: 'archivo';
pac_private_headers();
header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
