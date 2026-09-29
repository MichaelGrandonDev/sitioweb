<?php

declare(strict_types=1);

/**
 * Biblioteca privada de libros (lector PDF) del admin de turnos.
 *
 * Cada archivo se guarda como data/biblioteca/<sha256>.<ext> (carpeta denegada por .htaccess) y solo se entrega
 * por biblioteca_file.php con sesión de admin. El sha256 del archivo original es la clave estable: otros módulos
 * (p. ej. el índice de texto de Pacientes) enlazan una página con biblioteca_link($sha256, $pagina).
 *
 * Archivos subidos por FTP a data/biblioteca/ se registran solos al abrir la biblioteca; sus datos (título, tipo,
 * etiquetas, páginas, nombre original) se toman de data/biblioteca/import.json si están ahí.
 */

const BIB_MAX_BYTES = 300 * 1024 * 1024;
const BIB_FORMATS = [
    'pdf' => ['PDF', 'application/pdf'],
    'pptx' => ['PowerPoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'ppt' => ['PowerPoint antiguo', 'application/vnd.ms-powerpoint'],
    'jpg' => ['Imagen', 'image/jpeg'],
    'jpeg' => ['Imagen', 'image/jpeg'],
    'png' => ['Imagen', 'image/png'],
];
const BIB_KINDS = [
    'libro' => 'Libro',
    'atlas' => 'Atlas',
    'apuntes' => 'Apuntes',
    'protocolo' => 'Protocolo',
    'presentacion' => 'Presentación',
    'imagen' => 'Imagen',
    'otro' => 'Otro',
];

function bib_schema(): void
{
    static $done = false;
    if ($done || !db_ready()) {
        return;
    }
    $done = true;
    db()->exec("
      CREATE TABLE IF NOT EXISTS bib_books (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sha256 TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'libro',
        tags TEXT NOT NULL DEFAULT '',
        format TEXT NOT NULL DEFAULT 'pdf',
        original_name TEXT NOT NULL DEFAULT '',
        file_size INTEGER NOT NULL DEFAULT 0,
        pages INTEGER NOT NULL DEFAULT 0,
        last_page INTEGER NOT NULL DEFAULT 0,
        last_read_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
}

function bib_dir(): string
{
    global $config;
    return dirname((string) $config['db_path']) . '/biblioteca';
}

function bib_file_path(array $book): string
{
    $sha = (string) $book['sha256'];
    $format = (string) $book['format'];
    if (!preg_match('/^[a-f0-9]{64}$/', $sha) || !isset(BIB_FORMATS[$format])) {
        return '';
    }
    return bib_dir() . '/' . $sha . '.' . $format;
}

/** Libro por sha256 (64 hex) o por id numérico. */
function bib_book(string $key): ?array
{
    $key = strtolower(trim($key));
    if (preg_match('/^[a-f0-9]{64}$/', $key)) {
        $stmt = db()->prepare('SELECT * FROM bib_books WHERE sha256 = ?');
        $stmt->execute([$key]);
    } elseif (ctype_digit($key) && $key !== '') {
        $stmt = db()->prepare('SELECT * FROM bib_books WHERE id = ?');
        $stmt->execute([(int) $key]);
    } else {
        return null;
    }
    return $stmt->fetch() ?: null;
}

function bib_books(): array
{
    return db()->query('SELECT * FROM bib_books ORDER BY title COLLATE NOCASE')->fetchAll();
}

/**
 * Link al visor en una página: "biblioteca_ver.php?id=<sha256>&page=N" (relativo a /turnos/).
 * Acepta sha256 o id numérico; con id se devuelve igual el link por sha256 si el libro existe.
 */
function biblioteca_link(string $sha256OrId, int $page = 1): string
{
    $key = strtolower(trim($sha256OrId));
    if (!preg_match('/^[a-f0-9]{64}$/', $key) && db_ready()) {
        bib_schema();
        $book = bib_book($key);
        $key = $book ? (string) $book['sha256'] : $key;
    }
    return 'biblioteca_ver.php?id=' . rawurlencode($key) . ($page > 1 ? '&page=' . $page : '');
}

/** Link al visor buscando el libro por su nombre de archivo original (sin importar acentos, espacios ni signos). */
function biblioteca_link_by_name(string $originalName, int $page = 1): ?string
{
    if (!db_ready()) {
        return null;
    }
    bib_schema();
    $want = bib_name_key($originalName);
    if ($want === '') {
        return null;
    }
    foreach (db()->query('SELECT sha256, original_name, title FROM bib_books')->fetchAll() as $row) {
        if (bib_name_key((string) $row['original_name']) === $want || bib_name_key((string) $row['title']) === $want) {
            return biblioteca_link((string) $row['sha256'], $page);
        }
    }
    return null;
}

function bib_name_key(string $name): string
{
    $name = preg_replace('/\.(pdf|pptx?|jpe?g|png)$/i', '', basename(str_replace('\\', '/', $name))) ?? $name;
    return preg_replace('/[^a-z0-9]+/', '', bib_fold($name)) ?? '';
}

function bib_fold(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : false;
    return is_string($ascii) && $ascii !== '' ? strtolower($ascii) : $text;
}

/** @return list<string> */
function bib_tags(string $csv): array
{
    $out = [];
    foreach (explode(',', $csv) as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if ($t !== '') {
            $out[mb_strtolower($t)] = mb_substr($t, 0, 40);
        }
    }
    return array_values(array_slice($out, 0, 12));
}

function bib_clean_tags(string $csv): string
{
    return implode(', ', bib_tags($csv));
}

function bib_kind_label(string $kind): string
{
    return BIB_KINDS[$kind] ?? ($kind !== '' ? mb_convert_case($kind, MB_CASE_TITLE) : 'Otro');
}

function bib_size_label(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

function bib_title_from(string $filename): string
{
    $t = preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename($filename)) ?? $filename;
    $t = trim(preg_replace('/[_\s]+/u', ' ', $t) ?? $t);
    return mb_substr($t !== '' ? $t : 'Documento', 0, 200);
}

/** Registra archivos subidos por FTP a data/biblioteca/ que todavía no están en la base. Devuelve cuántos agregó. */
function bib_sync(): int
{
    $dir = bib_dir();
    if (!is_dir($dir)) {
        return 0;
    }
    $known = array_flip(db()->query('SELECT sha256 FROM bib_books')->fetchAll(PDO::FETCH_COLUMN));
    $meta = null;
    $added = 0;
    foreach (scandir($dir) ?: [] as $name) {
        if (str_starts_with($name, 'up-') && str_ends_with($name, '.part') && filemtime($dir . '/' . $name) < time() - 86400) {
            @unlink($dir . '/' . $name);
            continue;
        }
        if (!preg_match('/^([a-f0-9]{64})\.(pdf|pptx|ppt|jpg|jpeg|png)$/', $name, $m) || isset($known[$m[1]])) {
            continue;
        }
        if ($meta === null) {
            $raw = is_file($dir . '/import.json') ? json_decode((string) file_get_contents($dir . '/import.json'), true) : null;
            $meta = is_array($raw) ? $raw : [];
        }
        $info = is_array($meta[$m[1]] ?? null) ? $meta[$m[1]] : [];
        $original = (string) ($info['original_name'] ?? '');
        $kind = (string) ($info['kind'] ?? '');
        db()->prepare('INSERT OR IGNORE INTO bib_books (sha256, title, kind, tags, format, original_name, file_size, pages) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $m[1],
                mb_substr(trim((string) ($info['title'] ?? '')) ?: ($original !== '' ? bib_title_from($original) : 'Documento ' . substr($m[1], 0, 8)), 0, 200),
                $kind !== '' ? mb_substr($kind, 0, 30) : match ($m[2]) {
                    'jpg', 'jpeg', 'png' => 'imagen',
                    'pptx', 'ppt' => 'presentacion',
                    default => 'libro',
                },
                bib_clean_tags(is_array($info['tags'] ?? null) ? implode(',', $info['tags']) : (string) ($info['tags'] ?? '')),
                $m[2],
                mb_substr($original, 0, 250),
                (int) filesize($dir . '/' . $name),
                max(0, (int) ($info['pages'] ?? 0)),
            ]);
        $added++;
    }
    return $added;
}

function bib_update(int $id, string $title, string $kind, string $tags): void
{
    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $title) ?? ''), 0, 200);
    if ($title === '') {
        throw new RuntimeException('Escribí un título.');
    }
    db()->prepare('UPDATE bib_books SET title = ?, kind = ?, tags = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$title, isset(BIB_KINDS[$kind]) ? $kind : 'otro', bib_clean_tags($tags), $id]);
}

function bib_delete(int $id): void
{
    $book = bib_book((string) $id);
    if (!$book) {
        return;
    }
    $path = bib_file_path($book);
    db()->prepare('DELETE FROM bib_books WHERE id = ?')->execute([$id]);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

/* ---------- Subida por partes desde el admin ---------- */

function bib_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $num = (float) $value;
    return (int) match (strtolower(substr($value, -1))) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
}

/** Tamaño de cada parte: chico para no chocar con los límites ni los tiempos del hosting. */
function bib_chunk_bytes(): int
{
    $limits = [8 * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $bytes = bib_ini_bytes((string) ini_get($key));
        if ($bytes > 0) {
            $limits[] = $bytes - 256 * 1024;
        }
    }
    return max(256 * 1024, min($limits));
}

/** Empieza una subida: guarda en la sesión qué archivo se espera. @return array{token:string, chunk:int} */
function bib_upload_init(string $name, int $size, string $title, string $kind, string $tags): array
{
    $format = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(BIB_FORMATS[$format])) {
        throw new RuntimeException('Formato no admitido. Subí PDF (también se aceptan PPTX, PPT, JPG y PNG).');
    }
    if ($size < 1 || $size > BIB_MAX_BYTES) {
        throw new RuntimeException('Cada archivo puede pesar hasta ' . (BIB_MAX_BYTES / 1048576) . ' MB.');
    }
    $token = bin2hex(random_bytes(12));
    $uploads = is_array($_SESSION['bib_uploads'] ?? null) ? array_slice($_SESSION['bib_uploads'], -20, null, true) : [];
    $uploads[$token] = [
        'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 250),
        'size' => $size,
        'format' => $format === 'jpeg' ? 'jpg' : $format,
        'title' => mb_substr(trim($title) !== '' ? trim($title) : bib_title_from($name), 0, 200),
        'kind' => isset(BIB_KINDS[$kind]) ? $kind : (in_array($format, ['pptx', 'ppt'], true) ? 'presentacion' : (in_array($format, ['jpg', 'jpeg', 'png'], true) ? 'imagen' : 'libro')),
        'tags' => bib_clean_tags($tags),
    ];
    $_SESSION['bib_uploads'] = $uploads;
    return ['token' => $token, 'chunk' => bib_chunk_bytes()];
}

/**
 * Agrega una parte del archivo; al completar valida el formato, calcula el sha256 y lo deja como <sha256>.<ext>.
 * @return array{ok:bool, error?:string, received?:int, done?:bool, resync?:bool, id?:int, sha256?:string, duplicate?:bool}
 */
function bib_store_chunk(string $token, array $file, int $offset, int $total): array
{
    $up = $_SESSION['bib_uploads'][$token] ?? null;
    if (!preg_match('/^[a-f0-9]{24}$/', $token) || !is_array($up)) {
        return ['ok' => false, 'error' => 'La subida expiró. Volvé a elegir el archivo.'];
    }
    if ($total !== (int) $up['size']) {
        return ['ok' => false, 'error' => 'Tamaño de archivo inválido.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Una parte del archivo no llegó al servidor. Reintentá.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Archivo inválido.'];
    }
    $size = (int) filesize($tmp);
    $dir = bib_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'No se pudo crear la carpeta de la biblioteca en el servidor.'];
    }
    $part = $dir . '/up-' . $token . '.part';
    $out = fopen($part, 'cb');
    if ($out === false || !flock($out, LOCK_EX)) {
        return ['ok' => false, 'error' => 'No se pudo escribir el archivo en el servidor.'];
    }
    $received = (int) fstat($out)['size'];
    if ($offset !== $received) {
        fclose($out);
        return ['ok' => false, 'error' => 'La subida se desfasó.', 'received' => $received, 'resync' => true];
    }
    if ($size < 1 || $received + $size > $total) {
        fclose($out);
        return ['ok' => false, 'error' => 'Parte del archivo con tamaño inválido.', 'received' => $received];
    }
    fseek($out, 0, SEEK_END);
    $in = fopen($tmp, 'rb');
    $copied = $in !== false ? (int) stream_copy_to_stream($in, $out) : 0;
    if ($in !== false) {
        fclose($in);
    }
    fflush($out);
    if ($copied !== $size) {
        ftruncate($out, $received);
        fclose($out);
        return ['ok' => false, 'error' => 'No se pudo escribir el archivo (¿espacio en el hosting?).', 'received' => $received];
    }
    $received += $size;
    flock($out, LOCK_UN);
    fclose($out);
    if ($received < $total) {
        return ['ok' => true, 'done' => false, 'received' => $received];
    }

    unset($_SESSION['bib_uploads'][$token]);
    $problem = bib_validate($part, (string) $up['format']);
    if ($problem !== '') {
        @unlink($part);
        return ['ok' => false, 'error' => $problem];
    }
    $sha = (string) hash_file('sha256', $part);
    $existing = bib_book($sha);
    if ($existing) {
        @unlink($part);
        return ['ok' => true, 'done' => true, 'duplicate' => true, 'id' => (int) $existing['id'], 'sha256' => $sha, 'received' => $received];
    }
    $final = $dir . '/' . $sha . '.' . $up['format'];
    if (!rename($part, $final)) {
        @unlink($part);
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    }
    @chmod($final, 0640);
    db()->prepare('INSERT INTO bib_books (sha256, title, kind, tags, format, original_name, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$sha, $up['title'], $up['kind'], $up['tags'], $up['format'], $up['name'], $received]);
    return ['ok' => true, 'done' => true, 'id' => (int) db()->lastInsertId(), 'sha256' => $sha, 'received' => $received];
}

/** '' si el archivo coincide con su formato; si no, el problema. */
function bib_validate(string $path, string $format): string
{
    $head = (string) file_get_contents($path, false, null, 0, 8);
    return match ($format) {
        'pdf' => str_starts_with($head, '%PDF') ? '' : 'El archivo no es un PDF.',
        'pptx' => str_starts_with($head, "PK\x03\x04") ? '' : 'El archivo no es un PPTX válido.',
        'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0") ? '' : 'El archivo no es una presentación PPT válida.',
        'jpg' => str_starts_with($head, "\xFF\xD8\xFF") ? '' : 'El archivo no es una imagen JPG.',
        'png' => str_starts_with($head, "\x89PNG") ? '' : 'El archivo no es una imagen PNG.',
        default => 'Formato no admitido.',
    };
}

/* ---------- Página ---------- */

function bib_private_headers(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: same-origin');
}

function bib_page_start(string $title, string $bodyClass = ''): void
{
    bib_private_headers();
    $nav = [['admin.php', 'Admin turnos'], ['biblioteca_lector.php', 'Biblioteca']];
    if (is_file(__DIR__ . '/../pacientes.php')) {
        array_splice($nav, 1, 0, [['pacientes.php', 'Pacientes']]);
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <meta name="color-scheme" content="light dark">
  <title><?= h($title) ?> · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css?v=20260929e">
  <link rel="stylesheet" href="assets/biblioteca.css?v=20260929a">
</head>
<body class="bib-page <?= h($bodyClass) ?>">
  <header class="top">
    <a class="brand brand--home" href="biblioteca_lector.php" title="Biblioteca">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span>Biblioteca</span>
    </a>
    <nav>
      <?php foreach ($nav as [$href, $label]): ?>
        <a href="<?= h($href) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>
    <?php
}

if (db_ready()) {
    bib_schema();
}
