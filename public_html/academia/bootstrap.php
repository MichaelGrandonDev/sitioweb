<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/shop.php';
require_once __DIR__ . '/includes/uploads.php';
require_once __DIR__ . '/includes/inscriptions.php';

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
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function short_text(string $text, int $max = 100): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    if (strlen($text) <= $max) {
        return $text;
    }
    return rtrim(substr($text, 0, $max - 1)) . '…';
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function in_admin_dir(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($script, '/admin/');
}

function academia_path(string $file): string
{
    return in_admin_dir() ? '../' . ltrim($file, '/') : ltrim($file, '/');
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, name, email, role, active, fee_plan_id, fee_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int) $user['active']) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect(academia_path('login.php'));
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        redirect(academia_path('dashboard.php'));
    }
    require_once __DIR__ . '/../includes/site_admin.php';
    site_admin_grant();
    return $user;
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

function embed_video(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '<p class="muted">Sin video cargado.</p>';
    }

    // YouTube
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
        $id = h($m[1]);
        return '<div class="video-frame"><iframe src="https://www.youtube.com/embed/' . $id . '" title="Clase" allowfullscreen loading="lazy"></iframe></div>';
    }

    // Google Drive file
    if (preg_match('~drive\.google\.com/file/d/([^/]+)~', $url, $m)) {
        $id = h($m[1]);
        return '<div class="video-frame"><iframe src="https://drive.google.com/file/d/' . $id . '/preview" title="Clase" allowfullscreen loading="lazy"></iframe></div>';
    }

    return '<p><a class="btn secondary" href="' . h($url) . '" target="_blank" rel="noopener">Abrir clase en nueva pestaña</a></p>';
}

function db_ready(): bool
{
    global $config;
    return is_file($config['db_path']);
}

/**
 * Migraciones ligeras para instalaciones ya existentes.
 */
function migrate_schema(): void
{
    if (!db_ready()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    global $config;
    $cols = static function (PDO $pdo, string $table): array {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        return array_column($rows, 'name');
    };
    $tableExists = static function (PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetch();
    };

    $userCols = $cols($pdo, 'users');
    if (!in_array('email', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    }

    $courseCols = $cols($pdo, 'courses');
    if (!in_array('meet_url', $courseCols, true)) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN meet_url TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('meet_schedule', $courseCols, true)) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN meet_schedule TEXT NOT NULL DEFAULT ''");
    }

    if (!$tableExists($pdo, 'course_sections')) {
        $pdo->exec("
          CREATE TABLE course_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
          )
        ");
    }
    if (!$tableExists($pdo, 'course_resources')) {
        $pdo->exec("
          CREATE TABLE course_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'link',
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            content TEXT NOT NULL DEFAULT '',
            file_path TEXT NOT NULL DEFAULT '',
            track_completion INTEGER NOT NULL DEFAULT 1,
            lesson_id INTEGER DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
            FOREIGN KEY(section_id) REFERENCES course_sections(id) ON DELETE CASCADE
          )
        ");
    }
    if (!$tableExists($pdo, 'resource_progress')) {
        $pdo->exec("
          CREATE TABLE resource_progress (
            user_id INTEGER NOT NULL,
            resource_id INTEGER NOT NULL,
            completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id, resource_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(resource_id) REFERENCES course_resources(id) ON DELETE CASCADE
          )
        ");
    }
    if (!$tableExists($pdo, 'course_announcements')) {
        $pdo->exec("
          CREATE TABLE course_announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
          )
        ");
    }
    if (!$tableExists($pdo, 'course_events')) {
        $pdo->exec("
          CREATE TABLE course_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            starts_at TEXT NOT NULL,
            location TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
          )
        ");
    }

    // ——— Cuotas / pagos ———
    if (!$tableExists($pdo, 'fee_plans')) {
        $pdo->exec("
          CREATE TABLE fee_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            amount INTEGER NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )
        ");
        global $config;
        $defaultAmount = (int) ($config['payments']['default_monthly_amount'] ?? 25000);
        $pdo->prepare('INSERT INTO fee_plans (name, amount, active) VALUES (?, ?, 1)')
            ->execute(['Cuota mensual AcademiaFluxus', $defaultAmount]);
    }

    $userCols = $cols($pdo, 'users');
    if (!in_array('fee_plan_id', $userCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN fee_plan_id INTEGER DEFAULT NULL');
    }
    if (!in_array('fee_active', $userCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN fee_active INTEGER NOT NULL DEFAULT 0');
    }

    $courseCols = $cols($pdo, 'courses');
    if (!in_array('shop_listed', $courseCols, true)) {
        $pdo->exec('ALTER TABLE courses ADD COLUMN shop_listed INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('shop_price', $courseCols, true)) {
        $pdo->exec('ALTER TABLE courses ADD COLUMN shop_price INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('shop_blurb', $courseCols, true)) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN shop_blurb TEXT NOT NULL DEFAULT ''");
    }

    $resCols = $cols($pdo, 'course_resources');
    if ($tableExists($pdo, 'course_resources') && !in_array('file_path', $resCols, true)) {
        $pdo->exec("ALTER TABLE course_resources ADD COLUMN file_path TEXT NOT NULL DEFAULT ''");
    }

    if (!$tableExists($pdo, 'shop_orders')) {
        $pdo->exec("
          CREATE TABLE shop_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            total INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            method TEXT NOT NULL DEFAULT '',
            transfer_ref TEXT NOT NULL DEFAULT '',
            mp_preference_id TEXT NOT NULL DEFAULT '',
            mp_payment_id TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at TEXT DEFAULT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
          )
        ");
    }
    if (!$tableExists($pdo, 'shop_order_items')) {
        $pdo->exec("
          CREATE TABLE shop_order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            course_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            price INTEGER NOT NULL,
            FOREIGN KEY(order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
          )
        ");
    }

    $shopCols = $cols($pdo, 'shop_orders');
    if ($tableExists($pdo, 'shop_orders')) {
        if (!in_array('buyer_name', $shopCols, true)) {
            $pdo->exec("ALTER TABLE shop_orders ADD COLUMN buyer_name TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('buyer_email', $shopCols, true)) {
            $pdo->exec("ALTER TABLE shop_orders ADD COLUMN buyer_email TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('access_sent', $shopCols, true)) {
            $pdo->exec('ALTER TABLE shop_orders ADD COLUMN access_sent INTEGER NOT NULL DEFAULT 0');
        }
    }

    if (!$tableExists($pdo, 'inscriptions')) {
        $pdo->exec("
          CREATE TABLE inscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            course_id INTEGER DEFAULT NULL,
            amount INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'pending_approval',
            method TEXT NOT NULL DEFAULT '',
            transfer_ref TEXT NOT NULL DEFAULT '',
            mp_preference_id TEXT NOT NULL DEFAULT '',
            mp_payment_id TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TEXT DEFAULT NULL,
            FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE SET NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
          )
        ");
    }

    $insSetDefaults = $pdo->prepare('INSERT OR IGNORE INTO payment_settings (key, value) VALUES (?, ?)');
    $insSetDefaults->execute(['inscription_amount', '0']);
    $insSetDefaults->execute(['admin_notify_email', (string) ($config['mail_from'] ?? 'hola@fluxusterapia.com')]);

    if (!$tableExists($pdo, 'fee_invoices')) {
        $pdo->exec("
          CREATE TABLE fee_invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            plan_id INTEGER,
            period_ym TEXT NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            amount INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            due_date TEXT NOT NULL DEFAULT '',
            notes TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, period_ym),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
          )
        ");
    }

    if (!$tableExists($pdo, 'payments')) {
        $pdo->exec("
          CREATE TABLE payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            method TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            transfer_ref TEXT NOT NULL DEFAULT '',
            mp_preference_id TEXT NOT NULL DEFAULT '',
            mp_payment_id TEXT NOT NULL DEFAULT '',
            mp_status TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at TEXT DEFAULT NULL,
            confirmed_by INTEGER DEFAULT NULL,
            FOREIGN KEY(invoice_id) REFERENCES fee_invoices(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
          )
        ");
    }

    if (!$tableExists($pdo, 'payment_settings')) {
        $pdo->exec("
          CREATE TABLE payment_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
          )
        ");
        global $config;
        $pay = is_array($config['payments'] ?? null) ? $config['payments'] : [];
        $seed = [
            'transfer_holder' => (string) ($pay['transfer_holder'] ?? 'Michael Grandon'),
            'transfer_bank' => (string) ($pay['transfer_bank'] ?? 'Mercado Pago'),
            'transfer_cbu' => (string) ($pay['transfer_cbu'] ?? ''),
            'transfer_alias' => (string) ($pay['transfer_alias'] ?? 'michael.grandon.mp'),
            'transfer_note' => (string) ($pay['transfer_note'] ?? 'En el concepto poné tu nombre y el mes.'),
            'default_monthly_amount' => (string) ((int) ($pay['default_monthly_amount'] ?? 25000)),
            'currency' => (string) ($pay['currency'] ?? 'ARS'),
        ];
        $insSet = $pdo->prepare('INSERT OR IGNORE INTO payment_settings (key, value) VALUES (?, ?)');
        foreach ($seed as $k => $v) {
            $insSet->execute([$k, $v]);
        }
    }

    // Una sola vez: publicar un curso grabado de ejemplo en la tienda si no hay ninguno.
    $shopSeeded = $pdo->query("SELECT value FROM payment_settings WHERE key = 'shop_seeded' LIMIT 1")->fetchColumn();
    if ($shopSeeded === false) {
        $listed = (int) $pdo->query('SELECT COUNT(*) FROM courses WHERE shop_listed = 1')->fetchColumn();
        if ($listed === 0) {
            $first = $pdo->query('SELECT id FROM courses WHERE published = 1 ORDER BY sort_order, id LIMIT 1')->fetch();
            if ($first) {
                $pdo->prepare("UPDATE courses SET shop_listed = 1, shop_price = ?, shop_blurb = ? WHERE id = ?")
                    ->execute([
                        25000,
                        'Curso grabado · acceso completo en el campus cuando completes la compra.',
                        (int) $first['id'],
                    ]);
            }
        }
        $pdo->prepare('INSERT OR IGNORE INTO payment_settings (key, value) VALUES (?, ?)')
            ->execute(['shop_seeded', '1']);
    }

    migrate_lessons_into_campus($pdo);
}

/**
 * Lleva las clases (lessons) al modelo campus de secciones/recursos.
 */
function migrate_lessons_into_campus(PDO $pdo): void
{
    $courses = $pdo->query('SELECT id, title, meet_url, meet_schedule FROM courses')->fetchAll();
    $findSection = $pdo->prepare('SELECT id FROM course_sections WHERE course_id = ? AND title = ? LIMIT 1');
    $insSection = $pdo->prepare('INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, ?, ?)');
    $findRes = $pdo->prepare('SELECT id FROM course_resources WHERE lesson_id = ? LIMIT 1');
    $insRes = $pdo->prepare("
      INSERT INTO course_resources
        (course_id, section_id, type, title, description, url, track_completion, lesson_id, sort_order)
      VALUES (?, ?, 'video', ?, ?, ?, 1, ?, ?)
    ");
    $countRes = $pdo->prepare('SELECT COUNT(*) FROM course_resources WHERE course_id = ?');
    $insGeneral = $pdo->prepare("
      INSERT INTO course_resources
        (course_id, section_id, type, title, description, url, track_completion, sort_order)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($courses as $course) {
        $courseId = (int) $course['id'];

        $findSection->execute([$courseId, 'General']);
        $generalId = $findSection->fetchColumn();
        if (!$generalId) {
            $insSection->execute([$courseId, 'General', 0]);
            $generalId = (int) $pdo->lastInsertId();
            $countRes->execute([$courseId]);
            if ((int) $countRes->fetchColumn() === 0) {
                $insGeneral->execute([
                    $courseId, $generalId, 'forum', 'Avisos del curso',
                    'Novedades y comunicados de la academia.', '', 0, 1,
                ]);
                $insGeneral->execute([
                    $courseId, $generalId, 'forum', 'Foro de consultas',
                    'Espacio para dudas con el docente.', '', 0, 2,
                ]);
                $meetUrl = trim((string) ($course['meet_url'] ?? ''));
                if ($meetUrl !== '') {
                    $insGeneral->execute([
                        $courseId, $generalId, 'meet', 'Clase en vivo (Meet)',
                        (string) ($course['meet_schedule'] ?? ''), $meetUrl, 0, 3,
                    ]);
                }
            }
        }

        $findSection->execute([$courseId, 'Grabación de clases']);
        $videoSectionId = $findSection->fetchColumn();
        if (!$videoSectionId) {
            $insSection->execute([$courseId, 'Grabación de clases', 10]);
            $videoSectionId = (int) $pdo->lastInsertId();
        }

        $lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order, id');
        $lessons->execute([$courseId]);
        foreach ($lessons->fetchAll() as $i => $lesson) {
            $findRes->execute([(int) $lesson['id']]);
            if ($findRes->fetch()) {
                continue;
            }
            $insRes->execute([
                $courseId,
                (int) $videoSectionId,
                (string) $lesson['title'],
                (string) $lesson['description'],
                (string) $lesson['video_url'],
                (int) $lesson['id'],
                (int) ($lesson['sort_order'] ?: ($i + 1)),
            ]);
        }
    }
}

function resource_type_meta(string $type): array
{
    return match ($type) {
        'video' => ['label' => 'Video', 'icon' => '▶'],
        'pdf' => ['label' => 'PDF', 'icon' => 'PDF'],
        'file' => ['label' => 'Archivo', 'icon' => '↓'],
        'link' => ['label' => 'Enlace', 'icon' => '↗'],
        'page' => ['label' => 'Página', 'icon' => '≡'],
        'forum' => ['label' => 'Foro', 'icon' => '💬'],
        'folder' => ['label' => 'Carpeta', 'icon' => '📁'],
        'meet' => ['label' => 'Meet', 'icon' => '●'],
        default => ['label' => 'Recurso', 'icon' => '•'],
    };
}

function user_can_access_course(array $user, int $courseId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    $stmt = db()->prepare("
      SELECT 1
      FROM enrollments e
      JOIN courses c ON c.id = e.course_id
      WHERE e.user_id = ? AND e.course_id = ? AND c.published = 1
      LIMIT 1
    ");
    $stmt->execute([(int) $user['id'], $courseId]);
    return (bool) $stmt->fetch();
}

if (db_ready()) {
    migrate_schema();
}
