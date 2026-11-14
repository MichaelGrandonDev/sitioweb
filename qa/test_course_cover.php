<?php
/**
 * Tests de la foto de portada de los cursos (academia/includes/uploads.php).
 *
 * Uso: php qa/test_course_cover.php   (sale con código 1 si algo falla)
 */

declare(strict_types=1);

require __DIR__ . '/../public_html/academia/includes/uploads.php';

if (!function_exists('academia_path')) {
    function academia_path(string $file): string
    {
        return $file;
    }
}

const TEST_COURSE_ID = 990001;

$failures = 0;
$passed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function make_image(string $type, int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 47, 143, 107));
    $path = tempnam(sys_get_temp_dir(), 'cover') . '.' . $type;
    $type === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 90);
    return $path;
}

function cleanup(): void
{
    $dir = uploads_root() . '/course_' . TEST_COURSE_ID;
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

if (!function_exists('imagewebp')) {
    echo "GD con WebP no está disponible en este PHP: no se pueden correr los tests.\n";
    exit(1);
}

echo "Portada de curso\n";

// Foto grande: se achica a 1600 px de lado mayor y se guarda como WebP.
$big = make_image('jpg', 3000, 2000);
$result = save_course_cover(TEST_COURSE_ID, $big);
check('foto grande se guarda', $result['ok'], $result['error'] ?? '');
$full = $result['ok'] ? resolve_upload_path($result['path']) : null;
check('queda dentro de data/uploads', $full !== null);
if ($full !== null) {
    $info = getimagesize($full);
    check('se convierte a WebP', $info[2] === IMAGETYPE_WEBP, (string) $info['mime']);
    check('lado mayor = 1600 px', $info[0] === 1600, $info[0] . 'x' . $info[1]);
    check('mantiene la proporción', $info[1] === 1067, $info[0] . 'x' . $info[1]);
}

// Foto chica: no se agranda.
$small = make_image('png', 400, 300);
$result = save_course_cover(TEST_COURSE_ID, $small);
$full = $result['ok'] ? resolve_upload_path($result['path']) : null;
check('foto chica se guarda', $full !== null, $result['error'] ?? '');
if ($full !== null) {
    $info = getimagesize($full);
    check('foto chica no se agranda', $info[0] === 400 && $info[1] === 300, $info[0] . 'x' . $info[1]);
}

// Límite exacto: 1600 px queda igual.
$edge = make_image('jpg', 1600, 900);
$result = save_course_cover(TEST_COURSE_ID, $edge);
$full = $result['ok'] ? resolve_upload_path($result['path']) : null;
check('1600 px exactos no se tocan', $full !== null && getimagesize($full)[0] === 1600);

// Archivo que no es imagen.
$fake = tempnam(sys_get_temp_dir(), 'cover');
file_put_contents($fake, '<?php echo "no soy una foto";');
$result = save_course_cover(TEST_COURSE_ID, $fake);
check('rechaza archivos que no son imagen', !$result['ok'] && str_contains($result['error'] ?? '', 'JPG'));

// Errores de subida.
$result = store_course_cover(TEST_COURSE_ID, ['error' => UPLOAD_ERR_NO_FILE]);
check('sin archivo: no es error', !$result['ok'] && ($result['error'] ?? 'x') === '');
$result = store_course_cover(TEST_COURSE_ID, ['error' => UPLOAD_ERR_INI_SIZE]);
check('archivo muy pesado: avisa', !$result['ok'] && str_contains($result['error'] ?? '', 'límite'));
$result = store_course_cover(TEST_COURSE_ID, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $big, 'size' => 10]);
check('rechaza archivos que no llegaron por formulario', !$result['ok'] && ($result['error'] ?? '') === 'Foto inválida.');

// URL para el campus.
check('curso sin foto: URL vacía', course_cover_url(['id' => 5, 'cover_path' => '']) === '');
$urlA = course_cover_url(['id' => 5, 'cover_path' => 'course_5/portada-aaaa.webp']);
$urlB = course_cover_url(['id' => 5, 'cover_path' => 'course_5/portada-bbbb.webp']);
check('URL apunta a cover.php del curso', str_starts_with($urlA, 'cover.php?id=5&v='), $urlA);
check('foto nueva = URL nueva (no queda en caché)', $urlA !== $urlB);

// Ruta con ../ no sale de data/uploads.
check('bloquea rutas fuera de uploads', resolve_upload_path('../config.php') === null);

foreach ([$big, $small, $edge, $fake] as $tmp) {
    @unlink($tmp);
}
cleanup();

echo "\n{$passed} ok · {$failures} fallas\n";
exit($failures > 0 ? 1 : 0);
