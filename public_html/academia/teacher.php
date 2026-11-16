<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$courseId = (int) ($_GET['id'] ?? 0);

if ($courseId <= 0 || !user_can_access_course($user, $courseId)) {
    http_response_code(403);
    exit;
}

$stmt = db()->prepare('SELECT teacher_photo_path FROM courses WHERE id = ? LIMIT 1');
$stmt->execute([$courseId]);
$full = resolve_upload_path((string) ($stmt->fetchColumn() ?: ''));
if ($full === null) {
    http_response_code(404);
    exit;
}

session_write_close();

$mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($full) ?: '');
if (!str_starts_with($mime, 'image/')) {
    http_response_code(404);
    exit;
}

// La URL lleva ?v=<hash de la ruta>: cada foto nueva tiene otra URL, así que se puede cachear sin vencer.
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($full);
