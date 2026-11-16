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

const COURSE_COVER_MAX_SIDE = 1600;
const COURSE_TEACHER_MAX_SIDE = 1000;
const COURSE_COVER_MAX_BYTES = 15 * 1024 * 1024;

/**
 * Valida una foto subida desde el panel (portada o profesor).
 *
 * @return array{ok:bool, path?:string, error?:string}|null null = sin archivo; array con error/ok parcial antes de guardar
 */
function store_course_image_upload(array $file, callable $saver): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => ''];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'La foto supera el límite de subida del hosting.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'La foto no se pudo subir (código ' . $error . ').'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Foto inválida.'];
    }
    if ((int) ($file['size'] ?? 0) > COURSE_COVER_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La foto pesa más de 15 MB.'];
    }
    return $saver($tmp);
}

/**
 * Foto de portada del curso subida desde el panel.
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function store_course_cover(int $courseId, array $file): array
{
    return store_course_image_upload($file, static fn (string $tmp) => save_course_cover($courseId, $tmp));
}

/**
 * Foto del profesor del curso subida desde el panel.
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function store_course_teacher(int $courseId, array $file): array
{
    return store_course_image_upload($file, static fn (string $tmp) => save_course_teacher($courseId, $tmp));
}

/**
 * Guarda una imagen del curso como WebP (sin agrandar fotos chicas), enderezada según EXIF.
 * Sin GD en el hosting se guarda el original. $basename: "portada" o "profesor".
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function save_course_image(int $courseId, string $source, string $basename, int $maxSide): array
{
    $info = @getimagesize($source);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($types[$info[2]])) {
        return ['ok' => false, 'error' => 'Usá una foto JPG, PNG o WebP.'];
    }

    $dir = uploads_root() . '/course_' . $courseId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $base = $basename . '-' . bin2hex(random_bytes(4));

    $image = course_cover_load($source, (int) $info[2]);
    if ($image === null || !function_exists('imagewebp')) {
        $name = $base . '.' . $types[$info[2]];
        if (!copy($source, $dir . '/' . $name)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la foto en el servidor.'];
        }
        @chmod($dir . '/' . $name, 0644);
        return ['ok' => true, 'path' => 'course_' . $courseId . '/' . $name];
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, $maxSide / max($width, $height));
    if ($scale < 1) {
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $image = $resized;
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);

    $name = $base . '.webp';
    if (!imagewebp($image, $dir . '/' . $name, 82)) {
        return ['ok' => false, 'error' => 'No se pudo convertir la foto.'];
    }
    @chmod($dir . '/' . $name, 0644);
    return ['ok' => true, 'path' => 'course_' . $courseId . '/' . $name];
}

/**
 * Guarda la portada como WebP de hasta COURSE_COVER_MAX_SIDE px.
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function save_course_cover(int $courseId, string $source): array
{
    return save_course_image($courseId, $source, 'portada', COURSE_COVER_MAX_SIDE);
}

/**
 * Guarda la foto del profesor como WebP de hasta COURSE_TEACHER_MAX_SIDE px.
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function save_course_teacher(int $courseId, string $source): array
{
    return save_course_image($courseId, $source, 'profesor', COURSE_TEACHER_MAX_SIDE);
}

function course_cover_load(string $source, int $type): ?GdImage
{
    $loader = match ($type) {
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        default => '',
    };
    if ($loader === '' || !function_exists($loader)) {
        return null;
    }
    $image = @$loader($source);
    if (!$image instanceof GdImage) {
        return null;
    }
    if ($type === IMAGETYPE_PNG) {
        imagepalettetotruecolor($image);
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $angle = match ((int) (@exif_read_data($source)['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                $image = $rotated;
            }
        }
    }
    return $image;
}

/** URL de la portada para el campus ('' si el curso no tiene). Cambia con cada foto nueva, así se puede cachear. */
function course_cover_url(array $course): string
{
    $path = trim((string) ($course['cover_path'] ?? ''));
    if ($path === '') {
        return '';
    }
    return academia_path('cover.php') . '?id=' . (int) $course['id'] . '&v=' . substr(md5($path), 0, 10);
}

/** ¿Hay algo del profesor cargado? Si no, el campus no muestra el bloque. */
function course_has_teacher(array $course): bool
{
    return trim((string) ($course['teacher_name'] ?? '')) !== ''
        || trim((string) ($course['teacher_bio'] ?? '')) !== ''
        || trim((string) ($course['teacher_photo_path'] ?? '')) !== '';
}

/** URL de la foto del profesor ('' si no hay). Cambia con cada foto nueva. */
function course_teacher_url(array $course): string
{
    $path = trim((string) ($course['teacher_photo_path'] ?? ''));
    if ($path === '') {
        return '';
    }
    return academia_path('teacher.php') . '?id=' . (int) $course['id'] . '&v=' . substr(md5($path), 0, 10);
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
        $id = (int) ($resource['id'] ?? 0);
        if (($resource['type'] ?? '') === 'video') {
            $exp = time() + VIDEO_LINK_TTL;
            return 'file.php?id=' . $id . '&e=' . $exp . '&s=' . video_link_sig($id, $exp, true);
        }
        return 'file.php?id=' . $id;
    }
    return trim((string) ($resource['url'] ?? ''));
}

/** Los links de video vencen y van firmados con una clave de la sesión: copiados fuera de ella no sirven. */
const VIDEO_LINK_TTL = 6 * 3600;

function video_link_sig(int $resourceId, int $exp, bool $create = false): string
{
    if (empty($_SESSION['video_key'])) {
        if (!$create) {
            return '';
        }
        $_SESSION['video_key'] = bin2hex(random_bytes(16));
    }
    return substr(hash_hmac('sha256', $resourceId . '|' . $exp, (string) $_SESSION['video_key']), 0, 32);
}

function video_link_valid(int $resourceId): bool
{
    $exp = (int) ($_GET['e'] ?? 0);
    $sig = (string) ($_GET['s'] ?? '');
    $expected = video_link_sig($resourceId, $exp);
    return $exp >= time() && $expected !== '' && hash_equals($expected, $sig);
}
