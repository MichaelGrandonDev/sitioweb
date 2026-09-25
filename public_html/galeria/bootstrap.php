<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);
require_once __DIR__ . '/../includes/site_admin.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function db_ready(): bool
{
    global $config;
    return is_file($config['db_path']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function is_admin(): bool
{
    return site_is_admin();
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('admin.php');
    }
}

function migrate_galeria(): void
{
    if (!db_ready()) {
        return;
    }
    $pdo = db();
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS gallery_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        media_type TEXT NOT NULL DEFAULT 'image',
        media_path TEXT NOT NULL DEFAULT '',
        video_url TEXT NOT NULL DEFAULT '',
        label TEXT NOT NULL DEFAULT '',
        alt_text TEXT NOT NULL DEFAULT '',
        link_url TEXT NOT NULL DEFAULT '',
        use_lightbox INTEGER NOT NULL DEFAULT 1,
        width_ratio REAL NOT NULL DEFAULT 1.2,
        sort_order INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
}

function gallery_items(bool $onlyActive = true): array
{
    if (!db_ready()) {
        return [];
    }
    migrate_galeria();
    $sql = 'SELECT * FROM gallery_items';
    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

function media_public_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return $path;
}

function delete_gallery_file(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || str_contains($path, '..')) {
        return;
    }
    if (str_starts_with($path, 'uploads/')) {
        $full = __DIR__ . '/' . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/**
 * @return array{ok:bool, path?:string, error?:string, type?:string}
 */
function store_gallery_upload(array $file): array
{
    global $config;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => ''];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error al subir el archivo.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $orig = (string) ($file['name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Archivo inválido.'];
    }
    if ($size > 45 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Máximo 45 MB. Para videos largos usá un link de YouTube.'];
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $videoExt = ['mp4', 'webm'];
    if (!in_array($ext, array_merge($imageExt, $videoExt), true)) {
        return ['ok' => false, 'error' => 'Formatos: JPG, PNG, WEBP, GIF, MP4 o WEBM.'];
    }
    $dir = rtrim((string) $config['uploads_dir'], '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $safe = preg_replace('~[^a-zA-Z0-9._-]+~', '-', pathinfo($orig, PATHINFO_FILENAME)) ?: 'media';
    $name = trim($safe, '-') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
    }
    @chmod($dest, 0644);
    return [
        'ok' => true,
        'path' => 'uploads/' . $name,
        'type' => in_array($ext, $videoExt, true) ? 'video' : 'image',
    ];
}

if (db_ready()) {
    migrate_galeria();
}
