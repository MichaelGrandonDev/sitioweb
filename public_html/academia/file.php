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

if (!user_can_access_course($user, (int) $resource['course_id'])) {
    http_response_code(403);
    echo 'Sin acceso.';
    exit;
}

$relative = trim((string) ($resource['file_path'] ?? ''));
$full = resolve_upload_path($relative);
if ($full === null) {
    http_response_code(404);
    echo 'Archivo no disponible.';
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) ($finfo->file($full) ?: 'application/octet-stream');
$name = basename($full);
$download = isset($_GET['download']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('X-Content-Type-Options: nosniff');
header(
    ($download ? 'Content-Disposition: attachment' : 'Content-Disposition: inline')
    . '; filename="' . rawurlencode($name) . '"'
);
readfile($full);
exit;
