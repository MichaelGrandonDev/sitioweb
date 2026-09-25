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

function migrate_blogs(): void
{
    if (!db_ready()) {
        return;
    }
    $pdo = db();
    $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    if (!in_array('cover_path', $cols, true)) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN cover_path TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('video_url', $cols, true)) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN video_url TEXT NOT NULL DEFAULT ''");
    }
}

if (db_ready()) {
    migrate_blogs();
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower(trim($text));
    $text = preg_replace('~[^a-z0-9]+~', '-', $text) ?? '';
    return trim($text, '-') ?: 'nota';
}

function published_posts(int $limit = 50): array
{
    if (!db_ready()) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT * FROM posts WHERE active = 1 ORDER BY published_at DESC, id DESC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function find_post(string $slug): ?array
{
    if (!db_ready() || $slug === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM posts WHERE slug = ? AND active = 1 LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function format_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    return date('d/m/Y', $ts);
}
