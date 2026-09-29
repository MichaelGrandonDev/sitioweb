<?php

declare(strict_types=1);

/** Entrega un archivo de la biblioteca con sesión de admin. Soporta Range (206) para que PDF.js lea por partes. */

require __DIR__ . '/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
if (!db_ready() || empty($_SESSION['turnos_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('Acceso restringido.');
}
// Sin lock de sesión: PDF.js pide varias partes en paralelo.
session_write_close();
require_once __DIR__ . '/includes/biblioteca_lector.php';

$book = bib_book((string) ($_GET['id'] ?? ''));
$path = $book ? bib_file_path($book) : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('No se encontró el archivo.');
}

$size = (int) filesize($path);
$format = (string) $book['format'];
$mime = BIB_FORMATS[$format][1];
$etag = '"' . $book['sha256'] . '"';
$name = (string) ($book['original_name'] !== '' ? $book['original_name'] : $book['title'] . '.' . $format);
$ascii = preg_replace('/[^A-Za-z0-9._\-]+/', '_', (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: 'archivo')) ?: 'archivo';
$inline = ($_GET['dl'] ?? '') !== '1' && in_array($format, ['pdf', 'jpg', 'jpeg', 'png'], true);

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");

$ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $etag)) {
    http_response_code(304);
    exit;
}

$start = 0;
$end = $size - 1;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
$ifRange = (string) ($_SERVER['HTTP_IF_RANGE'] ?? '');
$partial = false;
if ($range !== '' && ($ifRange === '' || $ifRange === $etag)) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) || ($m[1] === '' && $m[2] === '')) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $partial = true;
}

$length = $end - $start + 1;
if ($partial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . $length);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

@set_time_limit(0);
$fh = fopen($path, 'rb');
if ($fh === false) {
    exit;
}
fseek($fh, $start);
$left = $length;
while ($left > 0 && !feof($fh) && connection_status() === CONNECTION_NORMAL) {
    $buf = fread($fh, (int) min(1048576, $left));
    if ($buf === false || $buf === '') {
        break;
    }
    echo $buf;
    flush();
    $left -= strlen($buf);
}
fclose($fh);
