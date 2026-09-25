<?php

declare(strict_types=1);

function uploads_root(): string
{
    $dir = __DIR__ . '/../data/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        file_put_contents($ht, "Deny from all\n");
    }
    return $dir;
}

/**
 * @return array{ok:bool, path?:string, error?:string, mime?:string, name?:string}
 */
function store_course_upload(int $courseId, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => ''];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error al subir el archivo (código ' . (int) $file['error'] . ').'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $orig = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Archivo inválido.'];
    }
    $maxBytes = 45 * 1024 * 1024; // ~45 MB (HostGator típico)
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'error' => 'El archivo supera el límite de 45 MB. Usá un enlace a Drive/YouTube para videos pesados.'];
    }

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
    ];
    if ($ext === '' || !isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'Formato no permitido. Usá PDF, Office, imagen, MP4/WebM, audio o ZIP.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($tmp) ?: $allowed[$ext]);

    $courseDir = uploads_root() . '/course_' . $courseId;
    if (!is_dir($courseDir)) {
        mkdir($courseDir, 0755, true);
    }
    $safeBase = preg_replace('~[^a-zA-Z0-9._-]+~', '-', pathinfo($orig, PATHINFO_FILENAME)) ?: 'archivo';
    $safeBase = trim($safeBase, '-');
    if ($safeBase === '') {
        $safeBase = 'archivo';
    }
    $filename = $safeBase . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $courseDir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.'];
    }
    @chmod($dest, 0644);

    return [
        'ok' => true,
        'path' => 'course_' . $courseId . '/' . $filename,
        'mime' => $mime,
        'name' => $orig,
    ];
}

function guess_resource_type_from_upload(string $path, string $fallback = 'pdf'): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'pdf',
        'mp4', 'webm' => 'video',
        'png', 'jpg', 'jpeg', 'webp', 'gif' => 'file',
        'mp3', 'm4a' => 'file',
        'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip' => 'file',
        default => $fallback,
    };
}

function resolve_upload_path(string $relative): ?string
{
    $relative = str_replace(['\\', "\0"], '', $relative);
    $relative = ltrim($relative, '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return null;
    }
    $full = uploads_root() . '/' . $relative;
    $rootReal = realpath(uploads_root());
    $fullReal = realpath($full);
    if ($rootReal === false || $fullReal === false) {
        return null;
    }
    if (!str_starts_with($fullReal, $rootReal . DIRECTORY_SEPARATOR) && $fullReal !== $rootReal) {
        return null;
    }
    return is_file($fullReal) ? $fullReal : null;
}

function delete_upload(?string $relative): void
{
    if ($relative === null || $relative === '') {
        return;
    }
    $full = resolve_upload_path($relative);
    if ($full !== null) {
        @unlink($full);
    }
}

function resource_file_url(array $resource): string
{
    $path = trim((string) ($resource['file_path'] ?? ''));
    if ($path !== '') {
        return 'file.php?id=' . (int) ($resource['id'] ?? 0);
    }
    return trim((string) ($resource['url'] ?? ''));
}
