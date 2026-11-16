<?php
/**
 * Tests del perfil del profesor por curso (foto + helpers de visibilidad).
 *
 * Uso: php qa/test_teacher_profile.php   (sale con código 1 si algo falla)
 */

declare(strict_types=1);

require __DIR__ . '/../public_html/academia/includes/uploads.php';

if (!function_exists('academia_path')) {
    function academia_path(string $file): string
    {
        return $file;
    }
}

if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('short_text')) {
    function short_text(string $text, int $max = 120): string
    {
        $text = trim($text);
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return rtrim(mb_substr($text, 0, $max - 1)) . '…';
        }
        if (strlen($text) > $max) {
            return rtrim(substr($text, 0, $max - 1)) . '…';
        }
        return $text;
    }
}

require __DIR__ . '/../public_html/academia/includes/ui.php';

const TEST_COURSE_ID = 990002;

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
    imagefill($image, 0, 0, imagecolorallocate($image, 15, 61, 54));
    $path = tempnam(sys_get_temp_dir(), 'teacher') . '.' . $type;
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

echo "Perfil del profesor\n";

// Foto grande: se achica a 1000 px de lado mayor y se guarda como WebP.
$big = make_image('jpg', 2400, 1800);
$result = save_course_teacher(TEST_COURSE_ID, $big);
check('foto grande se guarda', $result['ok'], $result['error'] ?? '');
$full = $result['ok'] ? resolve_upload_path($result['path']) : null;
check('queda dentro de data/uploads', $full !== null);
if ($full !== null) {
    $info = getimagesize($full);
    check('se convierte a WebP', $info[2] === IMAGETYPE_WEBP, (string) $info['mime']);
    check('lado mayor = 1000 px', $info[0] === 1000, $info[0] . 'x' . $info[1]);
    check('mantiene la proporción', $info[1] === 750, $info[0] . 'x' . $info[1]);
    check('nombre de archivo con prefijo profesor', str_contains((string) $result['path'], '/profesor-'));
}

// Foto chica: no se agranda.
$small = make_image('png', 320, 320);
$result = save_course_teacher(TEST_COURSE_ID, $small);
$full = $result['ok'] ? resolve_upload_path($result['path']) : null;
check('foto chica se guarda', $full !== null, $result['error'] ?? '');
if ($full !== null) {
    $info = getimagesize($full);
    check('foto chica no se agranda', $info[0] === 320 && $info[1] === 320, $info[0] . 'x' . $info[1]);
}

// Archivo que no es imagen.
$fake = tempnam(sys_get_temp_dir(), 'teacher');
file_put_contents($fake, '<?php echo "no soy una foto";');
$result = save_course_teacher(TEST_COURSE_ID, $fake);
check('rechaza archivos que no son imagen', !$result['ok'] && str_contains($result['error'] ?? '', 'JPG'));

// Errores de subida.
$result = store_course_teacher(TEST_COURSE_ID, ['error' => UPLOAD_ERR_NO_FILE]);
check('sin archivo: no es error', !$result['ok'] && ($result['error'] ?? 'x') === '');
$result = store_course_teacher(TEST_COURSE_ID, ['error' => UPLOAD_ERR_INI_SIZE]);
check('archivo muy pesado: avisa', !$result['ok'] && str_contains($result['error'] ?? '', 'límite'));
$result = store_course_teacher(TEST_COURSE_ID, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $big, 'size' => 10]);
check('rechaza archivos que no llegaron por formulario', !$result['ok'] && ($result['error'] ?? '') === 'Foto inválida.');

// URL y visibilidad.
$empty = ['id' => 7, 'teacher_name' => '', 'teacher_bio' => '', 'teacher_photo_path' => ''];
check('curso sin profesor: no hay datos', !course_has_teacher($empty));
check('curso sin profesor: URL vacía', course_teacher_url($empty) === '');
check('curso sin profesor: bloque vacío', aca_teacher_block($empty) === '');

$withName = ['id' => 7, 'teacher_name' => 'Ana Pérez', 'teacher_bio' => 'Docente de chikung', 'teacher_photo_path' => ''];
check('con nombre: hay profesor', course_has_teacher($withName));
$block = aca_teacher_block($withName, 'full');
check('bloque muestra el nombre', str_contains($block, 'Ana Pérez'));
check('bloque muestra la etiqueta Tu profesor', str_contains($block, 'Tu profesor'));
check('sin foto: muestra inicial', str_contains($block, 'teacher-avatar') && str_contains($block, '>A<'));

$urlA = course_teacher_url(['id' => 7, 'teacher_photo_path' => 'course_7/profesor-aaaa.webp']);
$urlB = course_teacher_url(['id' => 7, 'teacher_photo_path' => 'course_7/profesor-bbbb.webp']);
check('URL apunta a teacher.php del curso', str_starts_with($urlA, 'teacher.php?id=7&v='), $urlA);
check('foto nueva = URL nueva (no queda en caché)', $urlA !== $urlB);

// La portada no se mezcla con la foto del profesor.
$cover = save_course_cover(TEST_COURSE_ID, $big);
check('portada sigue guardándose aparte', $cover['ok'] && str_contains((string) ($cover['path'] ?? ''), '/portada-'));

foreach ([$big, $small, $fake] as $tmp) {
    @unlink($tmp);
}
cleanup();

echo "\n{$passed} ok · {$failures} fallas\n";
exit($failures > 0 ? 1 : 0);
