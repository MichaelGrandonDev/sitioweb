<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();
$targetId = (int) ($_GET['id'] ?? 0);

if ($targetId <= 0) {
    http_response_code(404);
    exit;
}

// Solo el dueño, un admin general, o staff que comparta contexto puede verla.
$allowed = ((int) $user['id'] === $targetId) || is_full_admin($user) || is_teacher($user);
if (!$allowed) {
    http_response_code(403);
    exit;
}

$stmt = db()->prepare('SELECT avatar_path, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$targetId]);
$row = $stmt->fetch();
if (!$row || !is_staff_role((string) $row['role'])) {
    http_response_code(404);
    exit;
}

$full = resolve_upload_path((string) ($row['avatar_path'] ?? ''));
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

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($full);
