<?php

declare(strict_types=1);

/**
 * Optimización de fotos en el servidor (GD; Imagick solo para convertir HEIC).
 * Corrige la orientación, recorta al formato del lugar, genera WebP en varios
 * anchos + un JPG de respaldo y descarta los metadatos.
 */

const SITIO_IMG_MAX_BYTES = 40 * 1024 * 1024;
const SITIO_IMG_MAX_PIXELS = 60_000_000;
const SITIO_IMG_WEBP_Q = 80;
const SITIO_IMG_JPG_Q = 82;

function sitio_img_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La foto es demasiado pesada para subirla. Probá con una más chica (menos de 40 MB).',
        UPLOAD_ERR_PARTIAL => 'La subida se cortó a la mitad. Probá de nuevo.',
        UPLOAD_ERR_NO_FILE => 'No elegiste ninguna foto.',
        default => 'No se pudo subir la foto (error ' . $code . '). Probá de nuevo.',
    };
}

function sitio_img_raise_limits(): void
{
    $limit = (string) ini_get('memory_limit');
    $bytes = (int) $limit;
    $unit = strtolower(substr($limit, -1));
    $bytes *= match ($unit) { 'g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10, default => 1 };
    if ($limit !== '-1' && $bytes < 512 * 1024 * 1024) {
        @ini_set('memory_limit', '512M');
    }
    @set_time_limit(180);
}

/** Abre la imagen como recurso GD, ya derecha según el EXIF. */
function sitio_img_open(string $path, ?string &$error = null): ?GdImage
{
    if (!function_exists('imagecreatefromstring')) {
        $error = 'El servidor no tiene la librería de imágenes (GD) activada.';
        return null;
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $heic = in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true);
    if ($heic) {
        $converted = sitio_img_heic_to_jpeg($path);
        if ($converted === null) {
            $error = 'Las fotos HEIC del iPhone no se pueden procesar en este servidor. En el iPhone andá a Ajustes → Cámara → Formatos y elegí "Más compatible", o subila desde Safari (ahí se convierte sola).';
            return null;
        }
        $path = $converted;
        $mime = 'image/jpeg';
    } elseif (!in_array($mime, $allowed, true)) {
        $error = 'Ese archivo no es una foto válida. Usá JPG, PNG o WEBP.';
        return null;
    }
    $info = @getimagesize($path);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        $error = 'No se pudo leer la foto. Puede estar dañada.';
        return null;
    }
    if ($info[0] * $info[1] > SITIO_IMG_MAX_PIXELS) {
        $error = 'La foto tiene demasiados megapíxeles. Achicala un poco antes de subirla.';
        return null;
    }
    sitio_img_raise_limits();
    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img instanceof GdImage) {
        $error = 'No se pudo abrir la foto. Probá guardarla como JPG.';
        return null;
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $img = sitio_img_orient($img, (int) ($exif['Orientation'] ?? 1));
    }
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    if (isset($converted)) {
        @unlink($converted);
    }
    return $img;
}

function sitio_img_heic_to_jpeg(string $path): ?string
{
    if (!class_exists('Imagick')) {
        return null;
    }
    try {
        $formats = \Imagick::queryFormats('HEI*');
        if (!$formats) {
            return null;
        }
        $im = new \Imagick($path);
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(92);
        $im->stripImage();
        $out = tempnam(sys_get_temp_dir(), 'heic') ?: null;
        if ($out === null || !$im->writeImage($out)) {
            return null;
        }
        $im->clear();
        return $out;
    } catch (\Throwable) {
        return null;
    }
}

function sitio_img_orient(GdImage $img, int $orientation): GdImage
{
    $rotated = match ($orientation) {
        3, 4 => imagerotate($img, 180, 0),
        5, 6 => imagerotate($img, -90, 0),
        7, 8 => imagerotate($img, 90, 0),
        default => $img,
    };
    if ($rotated instanceof GdImage) {
        $img = $rotated;
    }
    if (in_array($orientation, [2, 4, 5, 7], true)) {
        imageflip($img, IMG_FLIP_HORIZONTAL);
    }
    return $img;
}

/** Recorte centrado a la relación ancho/alto pedida. */
function sitio_img_crop(GdImage $img, ?float $ratio): GdImage
{
    if ($ratio === null || $ratio <= 0) {
        return $img;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $current = $w / $h;
    if (abs($current - $ratio) < 0.01) {
        return $img;
    }
    if ($current > $ratio) {
        $cw = (int) round($h * $ratio);
        $rect = ['x' => (int) floor(($w - $cw) / 2), 'y' => 0, 'width' => $cw, 'height' => $h];
    } else {
        $ch = (int) round($w / $ratio);
        $rect = ['x' => 0, 'y' => (int) floor(($h - $ch) / 2), 'width' => $w, 'height' => $ch];
    }
    $cropped = imagecrop($img, $rect);
    return $cropped instanceof GdImage ? $cropped : $img;
}

/** Copia redimensionada sobre fondo blanco (las transparencias no sirven en fotos). */
function sitio_img_resized(GdImage $img, int $width): GdImage
{
    $sw = imagesx($img);
    $sh = imagesy($img);
    $width = min($width, $sw);
    $height = max(1, (int) round($sh * $width / $sw));
    $out = imagecreatetruecolor($width, $height);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $width, $height, $sw, $sh);
    return $out;
}

/**
 * Procesa una foto subida para un lugar de la home.
 * @return array{ok:bool, error?:string, photo?:array}
 */
function sitio_img_process_upload(array $file, string $slot, array $slotDef, bool $trusted = false): array
{
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => sitio_img_upload_error($code)];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || (!$trusted && !is_uploaded_file($tmp))) {
        return ['ok' => false, 'error' => 'Archivo inválido.'];
    }
    if ((int) ($file['size'] ?? filesize($tmp)) > SITIO_IMG_MAX_BYTES) {
        return ['ok' => false, 'error' => 'La foto pesa más de 40 MB. Probá con una más chica.'];
    }
    $error = null;
    $img = sitio_img_open($tmp, $error);
    if (!$img) {
        return ['ok' => false, 'error' => (string) $error];
    }
    $img = sitio_img_crop($img, $slotDef['ratio'] ?? null);
    $srcW = imagesx($img);
    if ($srcW < 400) {
        return ['ok' => false, 'error' => 'La foto es muy chica (' . $srcW . ' px de ancho). Usá una de al menos 1000 px.'];
    }

    $dir = sitio_uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'No se pudo crear la carpeta de fotos en el servidor.'];
    }
    $id = preg_replace('/[^a-z0-9_]/', '', $slot) . '-' . bin2hex(random_bytes(8));

    $widths = [];
    foreach ((array) $slotDef['widths'] as $w) {
        $widths[] = min((int) $w, $srcW);
    }
    $widths = array_values(array_unique($widths));
    sort($widths);

    $webp = [];
    $bytes = 0;
    $maxW = 0;
    $maxH = 0;
    foreach ($widths as $w) {
        $copy = sitio_img_resized($img, $w);
        $name = $id . '-' . $w . '.webp';
        if (!imagewebp($copy, $dir . '/' . $name, SITIO_IMG_WEBP_Q)) {
            return ['ok' => false, 'error' => 'No se pudo guardar la foto optimizada.'];
        }
        @chmod($dir . '/' . $name, 0644);
        $webp[(string) $w] = $name;
        $bytes += (int) filesize($dir . '/' . $name);
        $maxW = imagesx($copy);
        $maxH = imagesy($copy);
    }
    $jpgW = min((int) ($slotDef['jpg'] ?? end($widths)), $srcW);
    $copy = sitio_img_resized($img, $jpgW);
    imageinterlace($copy, true);
    $jpg = $id . '.jpg';
    if (!imagejpeg($copy, $dir . '/' . $jpg, SITIO_IMG_JPG_Q)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la foto de respaldo.'];
    }
    @chmod($dir . '/' . $jpg, 0644);

    return ['ok' => true, 'photo' => [
        'webp' => $webp,
        'jpg' => $jpg,
        'w' => $maxW,
        'h' => $maxH,
        'bytes' => $bytes,
        'uploaded_at' => date('Y-m-d H:i:s'),
    ]];
}

/**
 * Achica en el lugar una foto ya guardada (para la galería): orientación,
 * ancho máximo y recompresión, manteniendo el formato. Devuelve true si la tocó.
 */
function sitio_img_shrink_inplace(string $path, int $maxWidth = 2000): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !is_file($path)) {
        return false;
    }
    $info = @getimagesize($path);
    if (!$info) {
        return false;
    }
    $needsResize = max($info[0], $info[1]) > $maxWidth;
    $big = filesize($path) > 700 * 1024;
    if (!$needsResize && !$big) {
        return false;
    }
    $error = null;
    $img = sitio_img_open($path, $error);
    if (!$img) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    if (max($w, $h) > $maxWidth) {
        $target = $w >= $h ? $maxWidth : (int) round($w * $maxWidth / $h);
        if ($ext === 'png') {
            $out = imagecreatetruecolor($target, (int) round($h * $target / $w));
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
            $img = $out;
        } else {
            $img = sitio_img_resized($img, $target);
        }
    }
    $tmp = $path . '.tmp';
    if ($ext === 'jpg' || $ext === 'jpeg') {
        imageinterlace($img, true);
    }
    $ok = match ($ext) {
        'png' => imagepng($img, $tmp, 8),
        'webp' => imagewebp($img, $tmp, SITIO_IMG_WEBP_Q),
        default => imagejpeg($img, $tmp, SITIO_IMG_JPG_Q),
    };
    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return false;
    }
    if (filesize($tmp) >= filesize($path) && !$needsResize) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0644);
    return @rename($tmp, $path);
}
