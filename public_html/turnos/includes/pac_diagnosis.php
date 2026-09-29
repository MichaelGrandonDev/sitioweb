<?php

declare(strict_types=1);

/**
 * Entradas del diagnóstico: glosodiagnosis estructurada (sección central), información del paciente
 * (interrogatorio) y fotos de la lengua por consulta. Reglas de las técnicas sin agujas (moxa por punto).
 * Los valores de lengua e interrogatorio coinciden con los del Plan MTC (mtc_plan.php) para el traspaso.
 * Requiere includes/pacientes.php.
 */

/** [etiqueta, tipo (one|multi|text), opciones, grupo]. Claves y valores iguales a los del Plan MTC (MTC_OPTIONS / MTC_MULTI). */
const PAC_TONGUE = [
    'lengua_color' => ['Color del cuerpo', 'one', ['Rosada (normal)', 'Pálida', 'Roja', 'Roja en la punta', 'Bordes rojos', 'Violácea', 'Rojo oscuro / carmesí', 'Azulada'], 'cuerpo'],
    'lengua_forma' => ['Forma y signos', 'multi', ['Hinchada', 'Fina', 'Marcas dentales', 'Grietas', 'Puntos rojos / petequias', 'Manchas violáceas'], 'cuerpo'],
    'lengua_tamano' => ['Tamaño', 'one', ['Normal', 'Grande / ancha', 'Pequeña / delgada'], 'cuerpo'],
    'lengua_movilidad' => ['Movilidad', 'one', ['Normal', 'Temblorosa', 'Desviada', 'Rígida', 'Flácida'], 'cuerpo'],
    'lengua_humedad' => ['Humedad del cuerpo', 'one', ['Normal', 'Seca', 'Muy húmeda'], 'cuerpo'],
    'lengua_venas' => ['Venas sublinguales', 'one', ['Normales', 'Dilatadas / oscuras', 'Pálidas / poco visibles'], 'cuerpo'],
    'grietas' => ['Grietas: dónde y cómo', 'text', [], 'cuerpo'],
    'saburra_color' => ['Color', 'one', ['Blanca', 'Amarilla', 'Gris / oscura', 'Sin saburra (pelada)'], 'saburra'],
    'saburra_espesor' => ['Espesor', 'one', ['Fina', 'Gruesa'], 'saburra'],
    'saburra_humedad' => ['Humedad', 'one', ['Normal', 'Seca', 'Húmeda', 'Pegajosa / grasosa'], 'saburra'],
    'saburra_distribucion' => ['Distribución', 'one', ['Pareja', 'Más en la raíz', 'Más en el centro', 'A parches (geográfica)'], 'saburra'],
    'saburra_raiz' => ['Raíz', 'one', ['Con raíz (firme)', 'Sin raíz (se desprende)'], 'saburra'],
    'zonas' => ['Zonas por órgano alteradas', 'multi', ['Punta (Corazón / Pulmón)', 'Centro (Bazo / Estómago)', 'Laterales (Hígado / Vesícula)', 'Raíz (Riñón)'], 'zonas'],
    'zonas_detalle' => ['Qué se ve en esas zonas', 'text', [], 'zonas'],
    'lengua_notas' => ['Otras observaciones', 'text', [], 'zonas'],
];
const PAC_TONGUE_GROUPS = ['cuerpo' => 'Cuerpo', 'saburra' => 'Saburra', 'zonas' => 'Zonas por órgano'];

/** Información del paciente para esta consulta: interrogatorio (mismas claves que MTC_INTERVIEW del Plan MTC). */
const PAC_INTERROG = [
    'frio_calor' => 'Frío / calor',
    'sudor' => 'Sudoración',
    'dolor' => 'Cabeza y cuerpo (dolor: zona, tipo, 0–10)',
    'orina' => 'Orina',
    'digestion' => 'Digestión y heces',
    'apetito' => 'Apetito y sabor',
    'torax' => 'Tórax y costados',
    'sentidos' => 'Oídos y ojos',
    'sed' => 'Sed',
    'sueno' => 'Sueño',
    'animo' => 'Ánimo',
    'ciclo' => 'Ciclo menstrual',
];
/** Sugerencias si el Plan MTC no está cargado (mismos textos que MTC_OPTIONS). */
const PAC_INTERROG_DEFAULTS = [
    'sueno' => ['Bueno', 'Le cuesta dormirse', 'Se despierta seguido', 'Sueño liviano / muchos sueños', 'Somnolencia de día'],
    'digestion' => ['Normal', 'Lenta / pesadez', 'Distensión / gases', 'Acidez / reflujo', 'Heces blandas', 'Constipación'],
    'sed' => ['Normal', 'Mucha sed (bebidas frías)', 'Poca sed', 'Boca seca sin sed'],
    'frio_calor' => ['Equilibrado', 'Friolento / manos y pies fríos', 'Caluroso', 'Calores / sudor nocturno', 'Alterna'],
    'animo' => ['Estable', 'Estrés / irritabilidad', 'Ansiedad / preocupación', 'Tristeza / desgano', 'Cambios de humor'],
    'ciclo' => ['No corresponde', 'Regular', 'Irregular', 'Dolor menstrual', 'Abundante', 'Escaso', 'Menopausia'],
    'sudor' => ['Normal', 'Sudor espontáneo de día', 'Sudor nocturno', 'Casi no transpira'],
    'dolor' => ['Sin dolor', 'Cabeza', 'Fijo / punzante', 'Se mueve de lugar', 'Pesadez en el cuerpo', 'Lumbar / rodillas débiles'],
    'orina' => ['Normal', 'Clara y abundante', 'Oscura / escasa', 'Se levanta a orinar de noche'],
    'apetito' => ['Normal', 'Poco apetito', 'Mucho apetito', 'Sabor amargo', 'Boca pastosa / dulce'],
    'torax' => ['Sin molestias', 'Opresión en el pecho', 'Suspira seguido', 'Tensión en los costados', 'Palpitaciones'],
    'sentidos' => ['Sin molestias', 'Zumbidos', 'Ojos secos / vista cansada', 'Mareos', 'Ojos rojos'],
];

const PAC_PHOTO_MAX = 15 * 1024 * 1024;
const PAC_PHOTO_SIDE = 1600;
const PAC_PHOTO_CURSOR_MAX = 4 * 1024 * 1024;

/** Precauciones de moxa (siempre al pie de «Puntos para moxar»). */
const PAC_MOXA_CAUTIONS = 'Precauciones de moxa: no moxar con fiebre, calor o calor por vacío de Yin; evitar cara y ojos, mucosas, heridas, várices y zonas con pérdida de sensibilidad; embarazo: nada en abdomen ni zona lumbosacra, ni en los puntos prohibidos en el embarazo; diabetes o neuropatía: más distancia, menos tiempo y revisar la piel.';

/** Puntos de cara y cabeza donde no se moxa (se trabajan con acupresión). */
const PAC_NO_MOXA = ['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'IG19', 'IG20', 'VB1', 'VB2', 'VB3', 'VB14', 'V1', 'V2', 'DU24', 'DU25', 'DU26', 'DU27', 'DU28', 'RM23', 'RM24', 'ID18', 'ID19', 'SJ21', 'SJ22', 'SJ23', 'P9'];
/** Pies y tobillos: con diabetes o neuropatía, mejor acupresión. */
const PAC_FOOT_POINTS = ['R1', 'R2', 'R3', 'R6', 'R7', 'H2', 'H3', 'B3', 'B4', 'V60', 'V62', 'V67', 'E41', 'E44', 'VB40', 'VB41'];

/* ---------- Glosodiagnosis ---------- */

function pac_tongue_blank(): array
{
    $out = [];
    foreach (PAC_TONGUE as $k => [, $type]) {
        $out[$k] = $type === 'multi' ? [] : '';
    }
    return $out;
}

/** Normaliza la lengua enviada (solo valores de la lista; textos cortos). */
function pac_tongue_from(mixed $in): array
{
    $in = is_array($in) ? $in : [];
    $out = pac_tongue_blank();
    foreach (PAC_TONGUE as $k => [, $type, $opts]) {
        $v = $in[$k] ?? null;
        if ($type === 'multi') {
            $out[$k] = array_values(array_intersect($opts, is_array($v) ? array_map('strval', $v) : []));
        } elseif ($type === 'one') {
            $out[$k] = is_string($v) && in_array($v, $opts, true) ? $v : '';
        } else {
            $out[$k] = is_string($v) ? mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $v)), 0, 800) : '';
        }
    }
    return $out;
}

function pac_tongue_filled(array $t): bool
{
    foreach ($t as $v) {
        if ((is_array($v) && $v) || (is_string($v) && $v !== '')) {
            return true;
        }
    }
    return false;
}

/** Texto legible de la glosodiagnosis ("Cuerpo: pálida; hinchada, marcas dentales… Saburra: …"). */
function pac_tongue_text(array $t): string
{
    $lines = [];
    foreach (PAC_TONGUE_GROUPS as $group => $title) {
        $parts = [];
        foreach (PAC_TONGUE as $k => [$label, $type, , $g]) {
            if ($g !== $group) {
                continue;
            }
            $v = $t[$k] ?? ($type === 'multi' ? [] : '');
            $v = is_array($v) ? implode(', ', $v) : (string) $v;
            if ($v === '') {
                continue;
            }
            $parts[] = in_array($k, ['lengua_color', 'lengua_forma', 'saburra_color', 'zonas'], true) ? mb_strtolower($v) : mb_strtolower($label) . ': ' . $v;
        }
        if ($parts) {
            $lines[] = $title . ': ' . implode('; ', $parts) . '.';
        }
    }
    return implode("\n", $lines);
}

/* ---------- Información del paciente (interrogatorio) ---------- */

function pac_interrog_options(string $key): array
{
    if (defined('MTC_OPTIONS') && isset(MTC_OPTIONS[$key])) {
        return MTC_OPTIONS[$key];
    }
    return PAC_INTERROG_DEFAULTS[$key] ?? [];
}

function pac_interrog_from(mixed $in): array
{
    $in = is_array($in) ? $in : [];
    $out = [];
    foreach (array_keys(PAC_INTERROG) as $k) {
        $v = $in[$k] ?? '';
        $out[$k] = is_string($v) ? mb_substr(trim(str_replace(["\r\n", "\r"], ' ', $v)), 0, 600) : '';
    }
    return $out;
}

function pac_interrog_text(string $motivo, array $q): string
{
    $lines = [];
    if (trim($motivo) !== '') {
        $lines[] = 'Motivo y evolución: ' . trim($motivo);
    }
    foreach (PAC_INTERROG as $k => $label) {
        if (($q[$k] ?? '') !== '') {
            $lines[] = '- ' . preg_replace('/\s*\(.*\)$/u', '', $label) . ': ' . $q[$k];
        }
    }
    return implode("\n", $lines);
}

/* ---------- Signos de calor (definen si se moxa) ---------- */

/** Motivo para NO moxar (calor, fiebre, vacío de Yin) o '' si no hay. */
function pac_heat_reason(string $tongue, string $interrog, array $protocols = []): string
{
    $t = pac_fold($tongue);
    $i = pac_fold($interrog);
    $why = [];
    if (preg_match('/\b(?:rojo oscuro|carmesi)\b/u', $t) || preg_match('/\broja\b(?!\s+en\s+la\s+punta)/u', $t)) {
        $why[] = 'lengua roja';
    }
    if (str_contains($t, 'amarilla')) {
        $why[] = 'saburra amarilla';
    }
    if (preg_match('/sin saburra|pelada/u', $t)) {
        $why[] = 'lengua sin saburra (vacío de Yin)';
    }
    if (preg_match('/\bfiebre|febril/u', $i)) {
        $why[] = 'fiebre';
    }
    foreach ($protocols as $proto) {
        if (preg_match('/^\s*no\s+moxa/iu', (string) ($proto['moxibustion'] ?? ''))) {
            $why[] = 'el protocolo «' . $proto['nombre'] . '» indica no moxar';
        }
    }
    return implode(', ', array_unique($why));
}

/* ---------- Moxa por punto ---------- */

/** Zona de un punto para elegir el método. */
function pac_point_zone(string $code): string
{
    if (!preg_match('/^([A-Z]+)(\d+)$/', $code, $m)) {
        return 'otro';
    }
    [$ch, $n] = [$m[1], (int) $m[2]];
    return match (true) {
        $code === 'DU20' => 'cabeza',
        $ch === 'RM' && $n >= 15 => 'torax',
        $ch === 'RM' => 'abdomen',
        $ch === 'DU' && $n <= 5, $ch === 'V' && $n >= 22 && $n <= 35 => 'lumbar',
        $ch === 'DU', $ch === 'V' && $n >= 10 && $n < 22 => 'dorso',
        $ch === 'E' && $n >= 19 && $n <= 30, $ch === 'R' && $n >= 11 && $n <= 21, $ch === 'H' && $n >= 13 => 'abdomen',
        default => 'miembro',
    };
}

/**
 * Método, tiempo, precaución y alternativa para moxar un punto.
 * $ctx: heat (motivo o ''), move (bool: mover Qi/Sangre), embarazo, diabetes (bool).
 */
function pac_moxa_point(string $code, array $ctx): array
{
    $zone = pac_point_zone($code);
    $row = ['punto' => pac_point_label($code), 'metodo' => '', 'tiempo' => '', 'precaucion' => '', 'alternativa' => ''];
    if (in_array($code, PAC_NO_MOXA, true)) {
        $row['alternativa'] = 'No moxar (' . ($code === 'P9' ? 'sobre la arteria radial' : 'cara') . '): acupresión suave dentro del tuina.';
        return $row;
    }
    if (!empty($ctx['embarazo']) && (in_array($zone, ['abdomen', 'lumbar'], true) || in_array($code, PAC_PREGNANCY_AVOID, true))) {
        $row['alternativa'] = 'Embarazo: no moxar ni presionar fuerte esta zona; omitir.';
        return $row;
    }
    if (($ctx['heat'] ?? '') !== '') {
        $row['alternativa'] = 'No moxar por ahora (' . $ctx['heat'] . '): acupresión suave en el tuina.';
        return $row;
    }
    [$row['metodo'], $row['tiempo']] = match ($zone) {
        'abdomen' => ['caja de moxa o conos sobre jengibre', '15–20 min con caja o 3–5 conos'],
        'lumbar', 'dorso' => ['caja de moxa o bastón indirecta', '10–15 min'],
        'torax' => ['bastón indirecta', '5–10 min'],
        'cabeza' => ['bastón indirecta a distancia, breve', '3–5 min'],
        default => !empty($ctx['move']) ? ['bastón en picoteo', '5–8 min'] : ['bastón indirecta', '10–15 min'],
    };
    $prec = ['a 2–3 cm de la piel, retirar si el calor molesta'];
    if ($zone === 'cabeza') {
        $prec[] = 'proteger el cabello';
    }
    if ($code === 'V40') {
        $prec[] = 'hueco poplíteo: breve y a distancia; no sobre várices';
    } elseif ($zone === 'miembro' && preg_match('/^(B|H|R|E|VB)\d+$/', $code)) {
        $prec[] = 'no sobre várices';
    }
    if (!empty($ctx['diabetes'])) {
        $prec[] = 'diabetes/neuropatía: más distancia y menos tiempo, revisar la piel';
        if (in_array($code, PAC_FOOT_POINTS, true)) {
            $row['alternativa'] = 'en pies con neuropatía, mejor acupresión';
        }
    }
    $row['precaucion'] = implode('; ', $prec);
    return $row;
}

function pac_moxa_line(array $row, string $ref = ''): string
{
    if ($row['metodo'] === '') {
        return '- ' . $row['punto'] . ': ' . $row['alternativa'] . $ref;
    }
    return '- ' . $row['punto'] . ': ' . $row['metodo'] . ' · ' . $row['tiempo'] . ' · precaución: ' . $row['precaucion']
        . ($row['alternativa'] !== '' ? ' · alternativa: ' . $row['alternativa'] : '') . $ref;
}

/** Contexto clínico para moxa y ventosas a partir del paciente y el caso. */
function pac_technique_ctx(array $p, array $case, array $protocols): array
{
    $ante = pac_fold(implode(' ', $case['antecedentes'] ?? []));
    return [
        'heat' => pac_heat_reason((string) $case['lengua'], (string) $case['interrogatorio'], $protocols),
        'embarazo' => (int) $p['embarazo'] === 1,
        'anticoagulantes' => (int) $p['anticoagulantes'] === 1,
        'diabetes' => (bool) preg_match('/diabet|neuropat/u', $ante . ' ' . pac_fold((string) $case['interrogatorio'])),
        'move' => false,
    ];
}

/* ---------- Fotos de la lengua (privadas, sin metadatos) ---------- */

function pac_tongue_photos(int $patientId): array
{
    $stmt = db()->prepare("SELECT * FROM pac_files WHERE patient_id = ? AND kind = 'lengua' ORDER BY fecha DESC, id DESC");
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function pac_tongue_photo(int $patientId, int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM pac_files WHERE id = ? AND patient_id = ? AND kind = 'lengua'");
    $stmt->execute([$id, $patientId]);
    return $stmt->fetch() ?: null;
}

/**
 * Guarda una foto de lengua: se vuelve a codificar (sin EXIF ni GPS), se endereza y se achica a 1600 px.
 */
function pac_tongue_photo_store(int $patientId, array $file, string $fecha, string $caption): int
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return 0;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('La foto no llegó completa al servidor (¿demasiado grande?).');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Foto inválida.');
    }
    $size = (int) filesize($tmp);
    if ($size < 1 || $size > PAC_PHOTO_MAX) {
        throw new RuntimeException('La foto puede pesar hasta ' . (PAC_PHOTO_MAX / 1048576) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('La foto tiene que ser JPG, PNG o WEBP (si es HEIC del iPhone, compartila como JPG).');
    }
    $bytes = pac_image_clean($tmp, $mime);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    $fecha = $d && $d->format('Y-m-d') === $fecha ? $fecha : date('Y-m-d');
    $dir = pac_storage_path('archivos/p' . $patientId);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de archivos en el servidor.');
    }
    $name = 'lengua-' . bin2hex(random_bytes(10)) . '.jpg';
    if (file_put_contents($dir . '/' . $name, $bytes) === false) {
        throw new RuntimeException('No se pudo guardar la foto.');
    }
    @chmod($dir . '/' . $name, 0640);
    db()->prepare("INSERT INTO pac_files (patient_id, original_name, stored, mime, size, kind, fecha, caption) VALUES (?, ?, ?, 'image/jpeg', ?, 'lengua', ?, ?)")
        ->execute([$patientId, 'lengua-' . $fecha . '.jpg', 'archivos/p' . $patientId . '/' . $name, strlen($bytes), $fecha, mb_substr(trim($caption), 0, 200)]);
    pac_touch($patientId);
    return (int) db()->lastInsertId();
}

/** JPEG limpio (sin metadatos), orientado y como máximo de PAC_PHOTO_SIDE px. */
function pac_image_clean(string $path, string $mime): string
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('El servidor no puede procesar imágenes (falta GD).');
    }
    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img) {
        throw new RuntimeException('No se pudo leer la imagen.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $rot = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($rot !== 0 && ($r = imagerotate($img, $rot, 0))) {
            imagedestroy($img);
            $img = $r;
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, PAC_PHOTO_SIDE / max($w, $h));
    if ($scale < 1) {
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    } elseif (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    ob_start();
    imagejpeg($img, null, 86);
    imagedestroy($img);
    return (string) ob_get_clean();
}

function pac_tongue_photo_delete(int $patientId, int $id): void
{
    $f = pac_tongue_photo($patientId, $id);
    if ($f) {
        pac_delete_stored((string) $f['stored']);
        db()->prepare('DELETE FROM pac_files WHERE id = ?')->execute([$id]);
    }
}

/* ---------- Enlaces al lector de la biblioteca ---------- */

/** Link al lector PDF en esa página (si el libro está en el lector), o null. */
function pac_reader_link(string $sha256, string $originalName, int $page): ?string
{
    static $known = null;
    if (!function_exists('biblioteca_link')) {
        $file = __DIR__ . '/biblioteca_lector.php';
        if (!is_file($file)) {
            return null;
        }
        require_once $file;
        if (!function_exists('biblioteca_link')) {
            return null;
        }
    }
    try {
        if ($known === null) {
            if (function_exists('bib_schema')) {
                bib_schema();
            }
            $known = array_flip(db()->query('SELECT sha256 FROM bib_books')->fetchAll(PDO::FETCH_COLUMN));
        }
        $sha = strtolower(trim($sha256));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) && isset($known[$sha])) {
            return biblioteca_link($sha, max(1, $page));
        }
        if ($originalName !== '' && function_exists('biblioteca_link_by_name')) {
            return biblioteca_link_by_name($originalName, max(1, $page));
        }
    } catch (Throwable) {
        return null;
    }
    return null;
}

/** Enlaces del lector escritos en un texto de fuentes: [[etiqueta, url]]. */
function pac_reader_links_in(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('#(biblioteca_ver\.php\?id=[a-f0-9]{64}(?:&page=\d+)?)#', $line, $m)) {
            $label = trim(preg_replace('#\s*(?:→|->)?\s*biblioteca_ver\.php\S*#', '', $line) ?? $line);
            $out[] = [pac_trim($label, 160), $m[1]];
        }
    }
    return array_slice($out, 0, 40);
}
