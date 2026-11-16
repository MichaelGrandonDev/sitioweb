<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$resourceId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare("
  SELECT r.*, c.id AS course_id, c.published
  FROM course_resources r
  JOIN courses c ON c.id = r.course_id
  WHERE r.id = ?
  LIMIT 1
");
$stmt->execute([$resourceId]);
$resource = $stmt->fetch();
if (!$resource) {
    http_response_code(404);
    echo 'Archivo no encontrado.';
    exit;
}

if (!user_can_access_course($user, (int) $resource['course_id']) || recording_is_locked($resource, $user)) {
    http_response_code(403);
    echo 'Sin acceso.';
    exit;
}

$isVideo = (string) $resource['type'] === 'video';
$isAdmin = is_staff($user);
if ($isVideo && !$isAdmin) {
    // Solo se sirve al reproductor: abrir el link en una pestaña o "guardar enlace" pide un documento.
    // Safari/iOS viejos no mandan Sec-Fetch-Dest, por eso la ausencia del header se permite.
    $dest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    if ($dest === 'document' || $dest === 'iframe' || $dest === 'embed' || $dest === 'object' || !video_link_valid($resourceId)) {
        http_response_code(403);
        header('Cache-Control: no-store');
        echo 'Este video solo se puede ver desde el campus.';
        exit;
    }
}

$relative = trim((string) ($resource['file_path'] ?? ''));
$full = resolve_upload_path($relative);
if ($full === null) {
    http_response_code(404);
    echo 'Archivo no disponible.';
    exit;
}

// Los videos largos tardan: sin esto, la sesión queda bloqueada y el resto del campus no carga.
session_write_close();
@set_time_limit(0);

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = match ($ext) {
    // Chrome y Firefox no reproducen "video/quicktime" aunque el .MOV sea H.264.
    'mp4', 'm4v', 'mov' => 'video/mp4',
    'webm' => 'video/webm',
    default => (string) ((new finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream'),
};
$name = basename($full);
$download = isset($_GET['download']) && (!$isVideo || $isAdmin);
$size = (int) filesize($full);
$start = 0;
$end = $size - 1;

header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header($isVideo ? 'Cache-Control: private, no-store' : 'Cache-Control: private, max-age=3600');
if ($isVideo) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cross-Origin-Resource-Policy: same-origin');
}
header(
    ($download ? 'Content-Disposition: attachment' : 'Content-Disposition: inline')
    . '; filename="' . rawurlencode($name) . '"'
);

$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min((int) $m[2], $size - 1);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) ($end - $start + 1));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
$fp = fopen($full, 'rb');
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp) && !connection_aborted()) {
    $buf = fread($fp, (int) min(1048576, $left));
    if ($buf === false || $buf === '') {
        break;
    }
    echo $buf;
    flush();
    $left -= strlen($buf);
}
fclose($fp);
exit;
