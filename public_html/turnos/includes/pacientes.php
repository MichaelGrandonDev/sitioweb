<?php

declare(strict_types=1);

/**
 * Pacientes: datos, antecedentes, historia clínica, archivos y evaluaciones MTC (diagnóstico + tratamiento).
 * Datos de salud: solo admin, sin URLs públicas y sin datos del paciente en logs.
 * Requiere bootstrap.php (db, h, csrf, turno_*).
 */

require_once __DIR__ . '/pac_library.php';
require_once __DIR__ . '/pac_cursor.php';
require_once __DIR__ . '/pac_diagnosis.php';

const PAC_TEXT_MAX = 20000;
const PAC_FILE_MAX = 20 * 1024 * 1024;
const PAC_FILE_TYPES = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'webp' => ['image/webp'],
    'heic' => ['image/heic', 'image/heif'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
];

const PAC_PATIENT_FIELDS = [
    'nombre' => 120, 'documento' => 40, 'fecha_nac' => 10, 'sexo' => 1, 'email' => 190, 'telefono' => 40,
    'direccion' => 200, 'ocupacion' => 120, 'emergencia' => 200,
    'enfermedades' => 4000, 'cirugias' => 4000, 'medicacion' => 4000, 'alergias' => 2000, 'otros_riesgos' => 2000, 'notas' => 8000,
];
const PAC_FLAGS = ['embarazo' => 'Embarazo', 'marcapasos' => 'Marcapasos', 'anticoagulantes' => 'Anticoagulantes'];
const PAC_SEXES = ['' => 'Sin indicar', 'F' => 'Mujer', 'M' => 'Varón', 'X' => 'Otro'];

/**
 * Campos de una evaluación MTC: lo observado (la glosodiagnosis es la base; el pulso es opcional y solo pesa si se cargó)
 * + el borrador generado (todos editables). Sin agujas: los puntos se proponen para moxar o para acupresión.
 */
const PAC_EVAL_INPUTS = [
    'lengua' => 'Glosodiagnosis (lengua)',
    'interrogatorio' => 'Información del paciente e interrogatorio',
    'pulso' => 'Pulso (opcional)',
];
const PAC_EVAL_OUTPUTS = [
    'resumen' => 'Resumen de historia clínica',
    'diagnostico' => 'Diagnóstico MTC (patrones probables: signos de lengua + datos del paciente + citas)',
    'diferencial' => 'Diagnóstico diferencial (patrones considerados y por qué no)',
    'patrones' => 'Diagnóstico desde la MTC (patrones combinados en una frase; así va en el PDF del paciente)',
    'principio' => 'Principio de tratamiento',
    'meridianos' => 'Meridianos a tratar',
    'tuina' => 'Tuina (maniobras, zonas y meridianos, acupresión, duración)',
    'chikung' => 'Chi kung (Ba Duan Jin, respiración, sonido Liu Zi Jue, en sesión y en casa)',
    'puntos' => 'Moxibustión: puntos para moxar (método, tiempo, precaución, alternativa)',
    'ventosas' => 'Ventosas (zonas, tipo, tiempo, contraindicaciones)',
    'auriculoterapia' => 'Auriculoterapia (semillas, puntos, presión en casa, oreja)',
    'tecnicas' => 'Integración de las técnicas (sin agujas) y precauciones',
    'sesiones' => 'Plan de sesiones (técnicas por sesión)',
    'paciente_texto' => 'Texto para el paciente (explicación y recomendaciones)',
    'fuentes' => 'Fuentes citadas',
    'avisos' => 'Avisos y contraindicaciones',
    'notas' => 'Notas internas del terapeuta',
];
/** Campos del plan terapéutico que revisan los filtros de seguridad. */
const PAC_PLAN_FIELDS = ['principio', 'tuina', 'chikung', 'puntos', 'ventosas', 'auriculoterapia', 'tecnicas', 'sesiones', 'paciente_texto'];
const PAC_EVAL_STATUS = ['borrador' => 'Borrador', 'revisado' => 'Revisado'];
const PAC_DISCLAIMER = 'Sugerencia de apoyo: la decisión clínica es del terapeuta; no reemplaza el diagnóstico médico.';

function pac_eval_fields(): array
{
    return array_merge(array_keys(PAC_EVAL_INPUTS), array_keys(PAC_EVAL_OUTPUTS));
}

function pac_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $text = static fn (array $cols): string => implode(",\n", array_map(static fn ($c) => "$c TEXT NOT NULL DEFAULT ''", $cols));
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_patients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        " . $text(array_keys(PAC_PATIENT_FIELDS)) . ",
        embarazo INTEGER NOT NULL DEFAULT 0,
        marcapasos INTEGER NOT NULL DEFAULT 0,
        anticoagulantes INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL REFERENCES pac_patients(id) ON DELETE CASCADE,
        fecha TEXT NOT NULL DEFAULT '',
        motivo TEXT NOT NULL DEFAULT '',
        evolucion TEXT NOT NULL DEFAULT '',
        notas TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL REFERENCES pac_patients(id) ON DELETE CASCADE,
        history_id INTEGER DEFAULT NULL,
        original_name TEXT NOT NULL DEFAULT '',
        stored TEXT NOT NULL,
        mime TEXT NOT NULL DEFAULT '',
        size INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_evals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL REFERENCES pac_patients(id) ON DELETE CASCADE,
        fecha TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'borrador',
        mode TEXT NOT NULL DEFAULT 'manual',
        version INTEGER NOT NULL DEFAULT 1,
        " . $text(pac_eval_fields()) . ",
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_eval_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        eval_id INTEGER NOT NULL REFERENCES pac_evals(id) ON DELETE CASCADE,
        version INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'borrador',
        mode TEXT NOT NULL DEFAULT 'manual',
        data TEXT NOT NULL DEFAULT '{}',
        note TEXT NOT NULL DEFAULT '',
        saved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pac_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pac_history_patient ON pac_history(patient_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pac_files_patient ON pac_files(patient_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pac_evals_patient ON pac_evals(patient_id)');
    $add = static function (string $table, array $cols) use ($pdo): void {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($cols as $col => $def) {
            if (!in_array($col, $have, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
            }
        }
    };
    $add('pac_evals', array_fill_keys(pac_eval_fields(), "TEXT NOT NULL DEFAULT ''") + ['entrada' => "TEXT NOT NULL DEFAULT ''"]);
    $add('pac_files', ['kind' => "TEXT NOT NULL DEFAULT ''", 'fecha' => "TEXT NOT NULL DEFAULT ''", 'caption' => "TEXT NOT NULL DEFAULT ''"]);
    pac_library_schema($pdo);
    pac_cursor_schema($pdo);
}

/* ---------- Página ---------- */

/** Cabeceras de privacidad para todas las páginas con datos de pacientes. */
function pac_private_headers(): void
{
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function pac_page_start(string $title, string $section): void
{
    pac_private_headers();
    $flash = take_flash();
    $nav = [
        'pacientes' => ['pacientes.php', 'Pacientes'],
        'biblioteca' => ['biblioteca.php', 'Biblioteca MTC'],
        'planes' => ['plan_mtc.php', 'Planes MTC'],
        'admin' => ['admin.php', 'Admin turnos'],
    ];
    if (is_file(__DIR__ . '/../biblioteca_lector.php')) {
        $nav = array_slice($nav, 0, 2, true) + ['lector' => ['biblioteca_lector.php', 'Lector PDF']] + array_slice($nav, 2, null, true);
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <title><?= h($title) ?> · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css?v=20260929e">
  <link rel="stylesheet" href="assets/pacientes.css?v=20260929c">
</head>
<body class="pac-page">
  <header class="top">
    <a class="brand brand--home" href="pacientes.php" title="Pacientes">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span>Turnos · Pacientes</span>
    </a>
    <nav>
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= h($href) ?>"<?= $key === $section ? ' aria-current="page" class="is-current"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main class="wrap">
    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php
}

function pac_page_end(array $scripts = []): void
{
    ?>
  </main>
  <?php foreach ($scripts as $src): ?>
    <script src="<?= h($src) ?>"></script>
  <?php endforeach; ?>
</body>
</html>
    <?php
}

/** Guardia de POST: admin logueado + CSRF. Redirige a $back si falla. */
function pac_require_post(string $back): void
{
    require_admin();
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida. Volvé a intentar.');
        redirect($back);
    }
}

function pac_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function pac_post_str(string $key, int $max = PAC_TEXT_MAX): string
{
    $v = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    $v = trim(str_replace(["\r\n", "\r"], "\n", $v));
    return mb_substr($v, 0, $max);
}

/* ---------- Ajustes ---------- */

function pac_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM pac_settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function pac_set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO pac_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

/* ---------- Pacientes ---------- */

function pac_patient(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_patients WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function pac_patients_list(string $q = '', int $limit = 200): array
{
    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM pac_evals e WHERE e.patient_id = p.id) AS evals,
                   (SELECT COUNT(*) FROM pac_history hh WHERE hh.patient_id = p.id) AS entries
            FROM pac_patients p';
    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $sql .= ' WHERE lower(p.nombre) LIKE ? OR lower(p.documento) LIKE ? OR lower(p.email) LIKE ? OR p.telefono LIKE ?';
        $like = '%' . mb_strtolower($q) . '%';
        $params = [$like, $like, $like, $like];
    }
    $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . max(1, $limit);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Valida y normaliza los datos del formulario del paciente. */
function pac_patient_from_input(array $in): array
{
    $out = [];
    foreach (PAC_PATIENT_FIELDS as $key => $max) {
        $v = is_string($in[$key] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", $in[$key])) : '';
        if (mb_strlen($v) > $max) {
            throw new RuntimeException('El campo «' . $key . '» es demasiado largo.');
        }
        $out[$key] = $v;
    }
    if ($out['nombre'] === '') {
        throw new RuntimeException('Escribí el nombre y apellido del paciente.');
    }
    if ($out['email'] !== '' && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El email no es válido.');
    }
    if ($out['fecha_nac'] !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $out['fecha_nac']);
        if (!$d || $d->format('Y-m-d') !== $out['fecha_nac'] || $d > new DateTimeImmutable('today')) {
            throw new RuntimeException('La fecha de nacimiento no es válida.');
        }
    }
    if (!array_key_exists($out['sexo'], PAC_SEXES)) {
        $out['sexo'] = '';
    }
    foreach (array_keys(PAC_FLAGS) as $flag) {
        $out[$flag] = ($in[$flag] ?? '') === '1' ? 1 : 0;
    }
    return $out;
}

function pac_patient_save(array $data, int $id = 0): int
{
    $cols = array_merge(array_keys(PAC_PATIENT_FIELDS), array_keys(PAC_FLAGS));
    $vals = array_map(static fn ($c) => $data[$c] ?? '', $cols);
    $pdo = db();
    if ($id > 0) {
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", $cols));
        $pdo->prepare("UPDATE pac_patients SET $set, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([...$vals, $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO pac_patients (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute($vals);
    return (int) $pdo->lastInsertId();
}

function pac_touch(int $patientId): void
{
    db()->prepare('UPDATE pac_patients SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$patientId]);
}

function pac_patient_delete(int $id): void
{
    $files = db()->prepare('SELECT stored FROM pac_files WHERE patient_id = ?');
    $files->execute([$id]);
    foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        pac_delete_stored((string) $stored);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM pac_eval_versions WHERE eval_id IN (SELECT id FROM pac_evals WHERE patient_id = ?)')->execute([$id]);
    foreach (['pac_evals', 'pac_files', 'pac_history'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE patient_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM pac_patients WHERE id = ?')->execute([$id]);
    $pdo->commit();
    $dir = pac_storage_path('archivos/p' . $id);
    if (is_dir($dir)) {
        @rmdir($dir);
    }
}

function pac_age(array $p): ?int
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($p['fecha_nac'] ?? ''));
    return $d ? (int) $d->diff(new DateTimeImmutable('today'))->y : null;
}

/** Rango de edad para el caso anonimizado ("40 a 49 años"). */
function pac_age_range(array $p): string
{
    $age = pac_age($p);
    if ($age === null) {
        return 'edad no indicada';
    }
    if ($age < 18) {
        return 'menor de 18 años';
    }
    $low = intdiv($age, 10) * 10;
    return $low . ' a ' . ($low + 9) . ' años';
}

function pac_flags(array $p): array
{
    return array_keys(array_filter(PAC_FLAGS, static fn ($label, $key) => (int) ($p[$key] ?? 0) === 1, ARRAY_FILTER_USE_BOTH));
}

/* ---------- Historia clínica ---------- */

function pac_history(int $patientId): array
{
    $stmt = db()->prepare('SELECT * FROM pac_history WHERE patient_id = ? ORDER BY fecha DESC, id DESC');
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function pac_history_save(int $patientId, array $in, int $id = 0): int
{
    $fecha = is_string($in['fecha'] ?? null) ? trim($in['fecha']) : '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if (!$d || $d->format('Y-m-d') !== $fecha) {
        $fecha = date('Y-m-d');
    }
    $vals = [];
    foreach (['motivo' => 2000, 'evolucion' => PAC_TEXT_MAX, 'notas' => PAC_TEXT_MAX] as $k => $max) {
        $vals[$k] = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", is_string($in[$k] ?? null) ? $in[$k] : '')), 0, $max);
    }
    if ($vals['motivo'] === '' && $vals['evolucion'] === '' && $vals['notas'] === '') {
        throw new RuntimeException('Escribí al menos el motivo, la evolución o una nota.');
    }
    $pdo = db();
    if ($id > 0) {
        $pdo->prepare('UPDATE pac_history SET fecha = ?, motivo = ?, evolucion = ?, notas = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND patient_id = ?')
            ->execute([$fecha, $vals['motivo'], $vals['evolucion'], $vals['notas'], $id, $patientId]);
    } else {
        $pdo->prepare('INSERT INTO pac_history (patient_id, fecha, motivo, evolucion, notas) VALUES (?, ?, ?, ?, ?)')
            ->execute([$patientId, $fecha, $vals['motivo'], $vals['evolucion'], $vals['notas']]);
        $id = (int) $pdo->lastInsertId();
    }
    pac_touch($patientId);
    return $id;
}

function pac_history_delete(int $patientId, int $id): void
{
    db()->prepare('UPDATE pac_files SET history_id = NULL WHERE history_id = ? AND patient_id = ?')->execute([$id, $patientId]);
    db()->prepare('DELETE FROM pac_history WHERE id = ? AND patient_id = ?')->execute([$id, $patientId]);
}

/* ---------- Archivos del paciente (fuera del acceso web: data/ tiene Deny from all) ---------- */

function pac_storage_path(string $relative = ''): string
{
    global $config;
    return dirname((string) $config['db_path']) . '/pacientes' . ($relative !== '' ? '/' . $relative : '');
}

function pac_delete_stored(string $relative): void
{
    if ($relative === '' || str_contains($relative, '..')) {
        return;
    }
    $full = pac_storage_path($relative);
    if (is_file($full)) {
        @unlink($full);
    }
}

function pac_files(int $patientId): array
{
    $stmt = db()->prepare("SELECT * FROM pac_files WHERE patient_id = ? AND kind <> 'lengua' ORDER BY created_at DESC, id DESC");
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function pac_file(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_files WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Normaliza $_FILES[x] de uno o varios archivos a una lista. */
function pac_upload_list(mixed $f): array
{
    if (!is_array($f) || !isset($f['name'])) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
}

function pac_store_file(int $patientId, array $file, ?int $historyId = null): int
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return 0;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Un archivo no llegó completo al servidor (¿demasiado grande?).');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Archivo inválido.');
    }
    $size = (int) filesize($tmp);
    if ($size < 1 || $size > PAC_FILE_MAX) {
        throw new RuntimeException('Cada archivo puede pesar hasta ' . (PAC_FILE_MAX / 1048576) . ' MB.');
    }
    $original = pac_clean_filename((string) ($file['name'] ?? 'archivo'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!isset(PAC_FILE_TYPES[$ext]) || !in_array($mime, PAC_FILE_TYPES[$ext], true)) {
        throw new RuntimeException('«' . $original . '»: formato no admitido. Usá PDF, JPG, PNG, WEBP, HEIC o DOCX.');
    }
    if ($ext === 'docx' && !pac_is_docx($tmp)) {
        throw new RuntimeException('«' . $original . '» no es un documento de Word válido.');
    }
    $dir = pac_storage_path('archivos/p' . $patientId);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de archivos en el servidor.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        throw new RuntimeException('No se pudo guardar el archivo.');
    }
    @chmod($dir . '/' . $name, 0640);
    $stored = 'archivos/p' . $patientId . '/' . $name;
    db()->prepare('INSERT INTO pac_files (patient_id, history_id, original_name, stored, mime, size) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$patientId, $historyId, $original, $stored, $ext === 'docx' ? PAC_FILE_TYPES['docx'][0] : $mime, $size]);
    pac_touch($patientId);
    return (int) db()->lastInsertId();
}

function pac_is_docx(string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return true;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $ok = $zip->locateName('word/document.xml') !== false;
    $zip->close();
    return $ok;
}

function pac_clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._ ()\-]+/u', '_', $name) ?? 'archivo';
    $name = trim($name, ' ._');
    return mb_substr($name !== '' ? $name : 'archivo', 0, 150);
}

function pac_file_delete(int $patientId, int $id): void
{
    $f = pac_file($id);
    if (!$f || (int) $f['patient_id'] !== $patientId) {
        return;
    }
    pac_delete_stored((string) $f['stored']);
    db()->prepare('DELETE FROM pac_files WHERE id = ?')->execute([$id]);
}

function pac_size_label(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/* ---------- Turnos, consentimientos y Planes MTC del paciente ---------- */

function pac_digits(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    return strlen($d) >= 8 ? substr($d, -8) : '';
}

function pac_norm_name(string $name): string
{
    return trim(preg_replace('/\s+/u', ' ', pac_fold($name)) ?? '');
}

/** Turnos que coinciden con el paciente por email, teléfono (últimos 8 dígitos) o nombre exacto. */
function pac_appointments(array $p): array
{
    $email = mb_strtolower(trim((string) $p['email']));
    $phone = pac_digits((string) $p['telefono']);
    $name = pac_norm_name((string) $p['nombre']);
    $ids = [];
    foreach (db()->query('SELECT id, patient_name, patient_email, patient_phone FROM appointments')->fetchAll() as $a) {
        if (($email !== '' && mb_strtolower(trim((string) $a['patient_email'])) === $email)
            || ($phone !== '' && pac_digits((string) $a['patient_phone']) === $phone)
            || ($name !== '' && pac_norm_name((string) $a['patient_name']) === $name)) {
            $ids[] = (int) $a['id'];
        }
    }
    if (!$ids) {
        return [];
    }
    $stmt = db()->query('
      SELECT a.*, t.name AS therapy_name, t.duration_min
      FROM appointments a JOIN therapies t ON t.id = a.therapy_id
      WHERE a.id IN (' . implode(',', $ids) . ')
      ORDER BY a.date DESC, a.time DESC
    ');
    return $stmt->fetchAll();
}

/** Planes MTC (plan_mtc.php) cuyo turno de consulta 1 es de este paciente. */
function pac_mtc_plans(array $appointments): array
{
    if (!$appointments || !function_exists('mtc_schema')) {
        return [];
    }
    mtc_schema();
    $ids = array_map(static fn ($a) => (int) $a['id'], $appointments);
    $rows = db()->query('SELECT id, appointment_id, status, updated_at FROM mtc_plans WHERE appointment_id IN (' . implode(',', $ids) . ') ORDER BY updated_at DESC')->fetchAll();
    $byId = [];
    foreach ($appointments as $a) {
        $byId[(int) $a['id']] = $a;
    }
    foreach ($rows as &$r) {
        $r['appt'] = $byId[(int) $r['appointment_id']] ?? null;
    }
    unset($r);
    return $rows;
}

function pac_appt_status(array $a): string
{
    return match ((string) $a['status']) {
        'confirmed' => 'Confirmado',
        'pending_deposit' => 'Espera seña',
        'cancelled' => 'Cancelado',
        default => (string) $a['status'],
    };
}

/** Personas de los turnos que todavía no están cargadas como pacientes (para "Importar"). */
function pac_import_candidates(int $limit = 80): array
{
    $patients = db()->query('SELECT email, telefono, nombre FROM pac_patients')->fetchAll();
    $emails = $phones = $names = [];
    foreach ($patients as $p) {
        if (trim((string) $p['email']) !== '') {
            $emails[mb_strtolower(trim((string) $p['email']))] = true;
        }
        if (pac_digits((string) $p['telefono']) !== '') {
            $phones[pac_digits((string) $p['telefono'])] = true;
        }
        $names[pac_norm_name((string) $p['nombre'])] = true;
    }
    $rows = db()->query("
      SELECT a.id, a.patient_name, a.patient_email, a.patient_phone, a.date, a.consent_dni, t.name AS therapy_name
      FROM appointments a JOIN therapies t ON t.id = a.therapy_id
      ORDER BY a.date DESC, a.id DESC
    ")->fetchAll();
    $seen = [];
    $out = [];
    foreach ($rows as $a) {
        $email = mb_strtolower(trim((string) $a['patient_email']));
        $phone = pac_digits((string) $a['patient_phone']);
        $name = pac_norm_name((string) $a['patient_name']);
        $key = $email !== '' ? 'e:' . $email : ($phone !== '' ? 't:' . $phone : 'n:' . $name);
        if (isset($seen[$key]) || ($email !== '' && isset($emails[$email])) || ($phone !== '' && isset($phones[$phone])) || isset($names[$name])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $a;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function pac_import_from_appointment(int $apptId): int
{
    $a = turno_by_id($apptId);
    if (!$a) {
        throw new RuntimeException('No se encontró ese turno.');
    }
    $data = array_fill_keys(array_keys(PAC_PATIENT_FIELDS), '') + array_fill_keys(array_keys(PAC_FLAGS), 0);
    $data['nombre'] = mb_substr(trim((string) ($a['consent_name'] ?: $a['patient_name'])), 0, 120);
    $data['email'] = trim((string) $a['patient_email']);
    $data['telefono'] = trim((string) $a['patient_phone']);
    $data['documento'] = mb_substr(trim((string) ($a['consent_dni'] ?? '')), 0, 40);
    if (trim((string) $a['notes']) !== '') {
        $data['notas'] = 'Nota del turno del ' . format_date_es((string) $a['date']) . ': ' . trim((string) $a['notes']);
    }
    return pac_patient_save($data);
}

/* ---------- Evaluaciones MTC (versionadas) ---------- */

function pac_evals(int $patientId): array
{
    $stmt = db()->prepare('SELECT id, fecha, status, mode, version, patrones, updated_at FROM pac_evals WHERE patient_id = ? ORDER BY fecha DESC, id DESC');
    $stmt->execute([$patientId]);
    return $stmt->fetchAll();
}

function pac_eval(int $id, int $patientId): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_evals WHERE id = ? AND patient_id = ?');
    $stmt->execute([$id, $patientId]);
    return $stmt->fetch() ?: null;
}

function pac_eval_versions(int $evalId): array
{
    $stmt = db()->prepare('SELECT * FROM pac_eval_versions WHERE eval_id = ? ORDER BY version DESC');
    $stmt->execute([$evalId]);
    return $stmt->fetchAll();
}

function pac_eval_data(array $row): array
{
    $out = [];
    foreach (pac_eval_fields() as $f) {
        $out[$f] = (string) ($row[$f] ?? '');
    }
    return $out;
}

function pac_eval_create(int $patientId, array $data, string $mode, string $fecha = ''): int
{
    $fields = pac_eval_fields();
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : date('Y-m-d');
    $vals = array_map(static fn ($f) => mb_substr((string) ($data[$f] ?? ''), 0, PAC_TEXT_MAX), $fields);
    $pdo = db();
    $pdo->prepare('INSERT INTO pac_evals (patient_id, fecha, status, mode, version, ' . implode(', ', $fields) . ') VALUES (?, ?, ?, ?, 1, '
        . implode(', ', array_fill(0, count($fields), '?')) . ')')
        ->execute([$patientId, $fecha, 'borrador', $mode, ...$vals]);
    $id = (int) $pdo->lastInsertId();
    pac_eval_snapshot($id, 1, 'borrador', $mode, $data, $mode === 'manual' ? 'Creada en blanco' : 'Generada');
    pac_touch($patientId);
    return $id;
}

function pac_eval_snapshot(int $evalId, int $version, string $status, string $mode, array $data, string $note): void
{
    $clean = [];
    foreach (pac_eval_fields() as $f) {
        $clean[$f] = (string) ($data[$f] ?? '');
    }
    db()->prepare('INSERT INTO pac_eval_versions (eval_id, version, status, mode, data, note) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$evalId, $version, $status, $mode, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), mb_substr($note, 0, 200)]);
}

/** Guarda una nueva versión (la anterior queda en el historial de versiones). */
function pac_eval_update(array $eval, array $data, string $status, ?string $mode, string $note, string $fecha = ''): void
{
    $fields = pac_eval_fields();
    $status = array_key_exists($status, PAC_EVAL_STATUS) ? $status : 'borrador';
    $mode ??= (string) $eval['mode'];
    $version = (int) $eval['version'] + 1;
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : (string) $eval['fecha'];
    $vals = array_map(static fn ($f) => mb_substr((string) ($data[$f] ?? ''), 0, PAC_TEXT_MAX), $fields);
    $set = implode(', ', array_map(static fn ($f) => "$f = ?", $fields));
    db()->prepare("UPDATE pac_evals SET $set, status = ?, mode = ?, version = ?, fecha = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([...$vals, $status, $mode, $version, $fecha, (int) $eval['id']]);
    pac_eval_snapshot((int) $eval['id'], $version, $status, $mode, $data, $note);
    pac_touch((int) $eval['patient_id']);
}

/** Datos estructurados con los que se generó (lengua, interrogatorio, foto) para volver a cargarlos. */
function pac_eval_set_entrada(int $evalId, array $entrada): void
{
    db()->prepare('UPDATE pac_evals SET entrada = ? WHERE id = ?')
        ->execute([json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $evalId]);
}

function pac_eval_entrada(?array $eval): array
{
    $e = $eval ? json_decode((string) ($eval['entrada'] ?? ''), true) : null;
    return is_array($e) ? $e : [];
}

function pac_eval_delete(int $patientId, int $id): void
{
    if (!pac_eval($id, $patientId)) {
        return;
    }
    db()->prepare('DELETE FROM pac_eval_versions WHERE eval_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM pac_evals WHERE id = ?')->execute([$id]);
}

function pac_mode_label(string $mode): string
{
    return match ($mode) {
        'cursor' => 'Generada con Cursor + tu biblioteca',
        'ia' => 'Generada con IA + biblioteca',
        'reglas' => 'Generada sin IA (protocolos + biblioteca)',
        default => 'Escrita a mano',
    };
}

/* ---------- Seguridad clínica ---------- */

/**
 * En embarazo no se usan: Sanyinjiao y Hegu, los clásicamente prohibidos (Jianjing, Kunlun, Zhiyin, puntos sacros)
 * y los del bajo abdomen.
 */
const PAC_PREGNANCY_AVOID = ['B6', 'IG4', 'VB21', 'V60', 'V67', 'V31', 'V32', 'V33', 'V34', 'RM3', 'RM4', 'RM5', 'RM6', 'RM7'];

/** Quita de un texto los puntos indicados ("B6", "B-6", "Sanyinjiao (B6)"). Devuelve [texto, quitados]. */
function pac_strip_points(string $text, array $codes): array
{
    $removed = [];
    foreach ($codes as $code) {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $code, $m)) {
            continue;
        }
        $pattern = '/(?:\b\p{Lu}[\p{Ll}]+(?:\s\p{Lu}?[\p{Ll}]+)?\s*\(\s*)?\b' . $m[1] . '\s?-?\s?' . $m[2] . '\b(?:\s*\))?(?:\s+bilateral)?/u';
        $new = preg_replace($pattern, '', $text, -1, $count) ?? $text;
        if ($count > 0) {
            $removed[] = $code;
            $text = $new;
        }
    }
    if ($removed) {
        $text = preg_replace('/\(\s*[,;+y]*\s*\)/u', '', $text) ?? $text;
        $text = preg_replace('/\s*([,;+])\s*(?:[,;+]\s*)+/u', '$1 ', $text) ?? $text;
        $text = preg_replace('/(^|\n|:)\s*[,;+]\s*/u', '$1 ', $text) ?? $text;
        $text = preg_replace('/\s*[,;+]\s*(\n|$|\.)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
    }
    return [trim($text), $removed];
}

function pac_has_point(string $text, string $code): bool
{
    return preg_match('/^([A-Z]+)(\d+)$/', $code, $m) === 1
        && preg_match('/\b' . $m[1] . '\s?-?\s?' . $m[2] . '\b/u', $text) === 1;
}

/** El texto indica agujas o punturas (no cuenta «sin agujas», «no usar agujas»…). */
function pac_mentions_needles(string $text): bool
{
    $text = preg_replace('/\b(?:sin|no|nada de|ni|nunca)\s+(?:usar\s+|se usan\s+|hay\s+)?(?:agujas?|punturas?|electroacupuntura|acupuntura)\b/iu', '', $text) ?? $text;
    $text = preg_replace('/\b(?:puntos?|canales?|meridianos?|textos?|libros?|manual(?:es)?) de acupuntura\b/iu', '', $text) ?? $text;
    return preg_match('/\b(?:agujas?|punturas?|punzar|puncionar|insertar|electroacupuntura|acupuntura)\b/iu', $text) === 1;
}

/** Avisos visibles según antecedentes y lo escrito en la evaluación. */
function pac_safety_warnings(array $p, array $data = []): array
{
    $w = [];
    $plan = implode("\n", array_map(static fn ($k) => (string) ($data[$k] ?? ''), PAC_PLAN_FIELDS));
    if ((int) $p['embarazo'] === 1) {
        $w[] = 'Embarazo: no usar ' . pac_points_text(PAC_PREGNANCY_AVOID) . ' (ni en moxa ni en acupresión); nada de moxa ni ventosas en abdomen o zona lumbosacra.';
        $found = array_values(array_filter(PAC_PREGNANCY_AVOID, static fn ($c) => pac_has_point($plan, $c)));
        if ($found) {
            $w[] = 'ATENCIÓN: en la evaluación todavía aparece ' . pac_points_text($found) . '. Revisalo (embarazo).';
        }
    }
    if ((int) $p['marcapasos'] === 1) {
        $w[] = 'Marcapasos: sin equipos eléctricos (electroestimulación, TENS, detectores de puntos eléctricos).';
        if (preg_match('/electro|tens\b/iu', $plan)) {
            $w[] = 'ATENCIÓN: en el plan figura estimulación eléctrica y el paciente tiene marcapasos.';
        }
    }
    if ((int) $p['anticoagulantes'] === 1) {
        $w[] = 'Anticoagulantes: sin ventosas ni sangría; tuina y acupresión suaves (riesgo de hematomas).';
        $cupping = preg_replace('/ventosas?\s*:?\s*no\b[^\n·]*|sin ventosas|no aplicar ventosas[^\n]*/iu', '', $plan) ?? $plan;
        if (preg_match('/ventosa|sangr[ií]a/iu', $cupping)) {
            $w[] = 'ATENCIÓN: en el plan figuran ventosas o sangría y el paciente toma anticoagulantes.';
        }
    }
    if (pac_mentions_needles($plan)) {
        $w[] = 'ATENCIÓN: el plan menciona agujas o punturas; esta propuesta es sin agujas (moxa, acupresión, tuina, ventosas, semillas).';
    }
    if (trim((string) $p['alergias']) !== '') {
        $w[] = 'Alergias registradas: ' . trim((string) $p['alergias']) . ' (revisar materiales: adhesivos de las semillas, aceites del tuina y las ventosas, látex).';
    }
    return $w;
}

/* ---------- Correo ---------- */

/** Manda el PDF para el paciente a su email. Devuelve 'sent', 'no_email' o 'failed'. */
function pac_send_pdf(array $p, string $pdfBytes, string $filename): string
{
    global $config;
    $to = trim((string) $p['email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no_email';
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $first = explode(' ', trim((string) $p['nombre']))[0] ?: (string) $p['nombre'];
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) ($config['whatsapp'] ?? ''));
    $html = '<!DOCTYPE html><html lang="es"><body style="margin:0;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px"><tr><td style="padding:24px">'
        . '<p style="margin:0 0 12px;font-size:18px"><strong>Hola, ' . $e($first) . '</strong></p>'
        . '<p style="margin:0 0 12px">Te adjuntamos tu plan de tratamiento de Medicina Tradicional China en PDF.</p>'
        . '<p style="margin:0 0 18px">Si tenés dudas, escribinos por WhatsApp.</p>'
        . '<p style="margin:0 0 18px"><a href="' . $e($wa) . '" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">Consultas por WhatsApp</a></p>'
        . '<p style="margin:0;color:#4f635a">Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $text = 'Hola, ' . $first . "\n\nTe adjuntamos tu plan de tratamiento de Medicina Tradicional China en PDF.\n\nConsultas por WhatsApp: " . $wa . "\n\nFluxusTerapia\n";
    return turno_send_mail($to, 'Tu plan de tratamiento · FluxusTerapia', $html, $text, [$filename => $pdfBytes]) ? 'sent' : 'failed';
}

if (db_ready()) {
    pac_schema();
}
