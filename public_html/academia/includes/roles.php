<?php

declare(strict_types=1);

/**
 * Roles de la academia:
 * - admin: administrador general (todo el panel)
 * - teacher: profesor / subadministrador (solo cursos asignados y permisos tildados)
 * - student: alumno
 */

// Helpers de portal (también viven en bootstrap.php; acá para tests que solo cargan roles.php).
if (!function_exists('in_admin_dir')) {
    function in_admin_dir(): bool
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        return str_contains($script, '/admin/');
    }
}
if (!function_exists('in_profesor_dir')) {
    function in_profesor_dir(): bool
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        return str_contains($script, '/profesor/');
    }
}
if (!function_exists('in_staff_panel')) {
    function in_staff_panel(): bool
    {
        return in_admin_dir() || in_profesor_dir();
    }
}
if (!function_exists('staff_portal_prefix')) {
    function staff_portal_prefix(): string
    {
        return in_profesor_dir() ? 'profesor/' : 'admin/';
    }
}
if (!function_exists('staff_home_path')) {
    function staff_home_path(): string
    {
        return staff_portal_prefix() . 'index.php';
    }
}

/** Permisos editables para un profesor (defaults sensatos). */
const TEACHER_PERMISSIONS = [
    'manage_course_content' => ['label' => 'Subir y editar clases y materiales', 'default' => 1],
    'edit_course_info' => ['label' => 'Editar título, descripción, portada y datos del profesor del curso', 'default' => 1],
    'view_students' => ['label' => 'Ver alumnos de sus cursos (solo lectura)', 'default' => 1],
    'reply_tickets' => ['label' => 'Responder consultas de sus cursos', 'default' => 1],
    'manage_enrollments' => ['label' => 'Inscribir alumnos en cursos', 'default' => 0],
    'manage_users' => ['label' => 'Crear o borrar alumnos', 'default' => 0],
    'manage_payments' => ['label' => 'Ver o editar pagos y cuotas', 'default' => 0],
    'manage_admins' => ['label' => 'Crear otros administradores o profesores', 'default' => 0],
];

function is_full_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function is_teacher(array $user): bool
{
    return ($user['role'] ?? '') === 'teacher';
}

function is_staff(array $user): bool
{
    return is_full_admin($user) || is_teacher($user);
}

function is_staff_role(string $role): bool
{
    return $role === 'admin' || $role === 'teacher';
}

/** Mensaje amigable y vuelta al panel si no hay permiso. */
function admin_forbidden(string $message = 'No tenés permiso para esta sección.'): never
{
    flash('error', $message);
    $dest = in_staff_panel() ? 'index.php' : academia_path(staff_home_for_role(current_user() ?? ['role' => 'student']));
    http_response_code(403);
    redirect($dest);
}

function staff_home_for_role(?array $user): string
{
    if ($user && is_teacher($user)) {
        return 'profesor/index.php';
    }
    return 'admin/index.php';
}

/**
 * Entrada al panel (admin general o profesor).
 * El profesor usa /profesor/; el admin general, /admin/.
 * Solo el admin general recibe el permiso del sitio (turnos, galería, blogs).
 */
function require_staff(): array
{
    $user = require_login();
    if (!is_staff($user)) {
        redirect(academia_path('dashboard.php'));
    }
    require_once __DIR__ . '/../../includes/site_admin.php';
    if (is_full_admin($user)) {
        site_admin_grant();
        if (in_profesor_dir()) {
            // El portal /profesor/ es solo para cuentas teacher.
            redirect('../admin/index.php');
        }
    } else {
        unset($_SESSION['site_admin']);
        if (in_admin_dir()) {
            $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
            // Páginas solo del admin general: el profesor no tiene wrapper → al portal.
            $teacherPages = ['index.php', 'grabaciones.php', 'courses.php', 'campus.php', 'tickets.php', 'estudiantes.php', 'perfil.php', 'curso.php'];
            redirect('../profesor/' . (in_array($page, $teacherPages, true) ? $page : 'index.php'));
        }
    }
    return $user;
}

/** Solo profesores dentro del portal /profesor/. */
function require_teacher_portal(): array
{
    $user = require_staff();
    if (!is_teacher($user)) {
        redirect('../admin/index.php');
    }
    return $user;
}

/**
 * Solo administrador general. Los profesores que abran la URL a mano vuelven al panel.
 */
function require_full_admin(): array
{
    $user = require_staff();
    if (!is_full_admin($user)) {
        admin_forbidden('Solo el administrador general puede usar esta sección.');
    }
    return $user;
}

/**
 * Compatibilidad: require_admin() = solo administrador general
 * (pagos, alumnos, inscripciones, settings, etc.).
 * Las pantallas del profesor usan require_staff() + require_permission().
 */
function require_admin(): array
{
    return require_full_admin();
}

function require_permission(string $permission): array
{
    $user = require_staff();
    if (!user_has_permission($user, $permission)) {
        admin_forbidden('No tenés permiso para esta acción.');
    }
    return $user;
}

function teacher_permission_defaults(): array
{
    $out = [];
    foreach (TEACHER_PERMISSIONS as $key => $meta) {
        $out[$key] = (int) $meta['default'];
    }
    return $out;
}

function teacher_permissions_of(int $userId, bool $refresh = false): array
{
    static $cache = [];
    if ($refresh) {
        unset($cache[$userId]);
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $defaults = teacher_permission_defaults();
    try {
        $stmt = db()->prepare('SELECT * FROM teacher_permissions WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        $row = false;
    }
    if (!$row) {
        return $cache[$userId] = $defaults;
    }
    foreach ($defaults as $key => $_) {
        if (array_key_exists($key, $row)) {
            $defaults[$key] = (int) $row[$key];
        }
    }
    return $cache[$userId] = $defaults;
}

function user_has_permission(array $user, string $permission): bool
{
    if (is_full_admin($user)) {
        return true;
    }
    if (!is_teacher($user)) {
        return false;
    }
    $perms = teacher_permissions_of((int) $user['id']);
    return !empty($perms[$permission]);
}

/** IDs de cursos asignados al profesor (vacío si no es profesor). */
function teacher_course_ids(array $user, bool $refresh = false): array
{
    if (!is_teacher($user)) {
        return [];
    }
    static $cache = [];
    $uid = (int) $user['id'];
    if ($refresh) {
        unset($cache[$uid]);
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    try {
        $stmt = db()->prepare('SELECT course_id FROM course_teachers WHERE user_id = ? ORDER BY course_id');
        $stmt->execute([$uid]);
        $cache[$uid] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        $cache[$uid] = [];
    }
    return $cache[$uid];
}

function teacher_has_course(array $user, int $courseId): bool
{
    if ($courseId <= 0) {
        return false;
    }
    if (is_full_admin($user)) {
        return true;
    }
    return in_array($courseId, teacher_course_ids($user), true);
}

/** ¿Puede editar contenidos (clases/materiales) de este curso? */
function user_can_manage_course_content(array $user, int $courseId): bool
{
    if (is_full_admin($user)) {
        return true;
    }
    return is_teacher($user)
        && user_has_permission($user, 'manage_course_content')
        && teacher_has_course($user, $courseId);
}

/** ¿Puede editar info/portada/profesor de este curso? */
function user_can_edit_course_info(array $user, int $courseId): bool
{
    if (is_full_admin($user)) {
        return true;
    }
    return is_teacher($user)
        && user_has_permission($user, 'edit_course_info')
        && teacher_has_course($user, $courseId);
}

/**
 * Cursos visibles para el usuario en el panel.
 * Admin: todos. Profesor: solo los asignados.
 *
 * @return list<array>
 */
function staff_courses(array $user): array
{
    if (is_full_admin($user)) {
        return db()->query('SELECT * FROM courses ORDER BY sort_order, title')->fetchAll();
    }
    $ids = teacher_course_ids($user);
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM courses WHERE id IN ($placeholders) ORDER BY sort_order, title");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

function staff_course_ids(array $user): array
{
    if (is_full_admin($user)) {
        $rows = db()->query('SELECT id FROM courses ORDER BY sort_order, title')->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }
    return teacher_course_ids($user);
}

function teacher_permissions_save(int $userId, array $flags): void
{
    $defaults = teacher_permission_defaults();
    $values = [$userId];
    $cols = ['user_id'];
    $placeholders = ['?'];
    $updates = [];
    foreach ($defaults as $key => $default) {
        $cols[] = $key;
        $placeholders[] = '?';
        $values[] = !empty($flags[$key]) ? 1 : 0;
        $updates[] = "$key = excluded.$key";
    }
    $sql = 'INSERT INTO teacher_permissions (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', $placeholders) . ') ON CONFLICT(user_id) DO UPDATE SET '
        . implode(', ', $updates);
    db()->prepare($sql)->execute($values);
    teacher_permissions_of($userId, true);
}

function teacher_courses_set(int $userId, array $courseIds): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM course_teachers WHERE user_id = ?')->execute([$userId]);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO course_teachers (user_id, course_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $courseIds)) as $cid) {
        if ($cid > 0) {
            $ins->execute([$userId, $cid]);
        }
    }
    // Si el curso no tiene nombre de profesor, lo rellenamos con el del usuario.
    $name = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
    $name->execute([$userId]);
    $teacherName = trim((string) ($name->fetchColumn() ?: ''));
    if ($teacherName !== '') {
        $upd = $pdo->prepare("UPDATE courses SET teacher_name = ? WHERE id = ? AND (teacher_name IS NULL OR teacher_name = '')");
        foreach (array_unique(array_map('intval', $courseIds)) as $cid) {
            if ($cid > 0) {
                $upd->execute([$teacherName, $cid]);
            }
        }
    }
    // Invalidar caché de cursos del profesor (si se consulta en el mismo request).
    teacher_course_ids(['id' => $userId, 'role' => 'teacher'], true);
}

function teacher_ensure_permissions_row(int $userId): void
{
    $exists = db()->prepare('SELECT 1 FROM teacher_permissions WHERE user_id = ?');
    $exists->execute([$userId]);
    if (!$exists->fetch()) {
        teacher_permissions_save($userId, teacher_permission_defaults());
    }
}

/**
 * Ítems del menú lateral del panel según rol y permisos.
 *
 * @return list<array{0:string,1:string,2:string,3:int}>
 */
function admin_nav_items(array $user, int $openTickets = 0): array
{
    $p = staff_portal_prefix();

    if (is_full_admin($user)) {
        return [
            [$p . 'index.php', 'Panel', 'grid', 0],
            [$p . 'courses.php', 'Cursos', 'book', 0],
            [$p . 'users.php', 'Alumnos', 'users', 0],
            [$p . 'tickets.php', 'Consultas', 'chat', $openTickets],
            [$p . 'inscriptions.php', 'Inscripciones', 'inbox', 0],
            [$p . 'recordatorios.php', 'Recordatorios', 'mail', 0],
            [$p . 'admins.php', 'Administradores', 'key', 0],
        ];
    }

    // Profesor: primero los cursos (con foto); clases/materiales se abren desde cada curso.
    $items = [
        [$p . 'index.php', 'Panel', 'grid', 0],
        [$p . 'courses.php', 'Mis cursos', 'book', 0],
        [$p . 'perfil.php', 'Mi perfil', 'user', 0],
    ];
    if (user_has_permission($user, 'view_students')) {
        $items[] = [$p . 'estudiantes.php', 'Alumnos', 'users', 0];
    }
    if (user_has_permission($user, 'reply_tickets')) {
        $items[] = [$p . 'tickets.php', 'Consultas', 'chat', $openTickets];
    }
    return $items;
}

function roles_migrate(PDO $pdo): void
{
    // Ampliar el CHECK de role para aceptar 'teacher' (SQLite no permite ALTER CHECK).
    $sql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if ($sql !== '' && !str_contains($sql, "'teacher'")) {
        roles_migrate_users_check($pdo);
    }

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS course_teachers (
        user_id INTEGER NOT NULL,
        course_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, course_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
      )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_course_teachers_course ON course_teachers(course_id)');

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS teacher_permissions (
        user_id INTEGER NOT NULL PRIMARY KEY,
        manage_course_content INTEGER NOT NULL DEFAULT 1,
        edit_course_info INTEGER NOT NULL DEFAULT 1,
        view_students INTEGER NOT NULL DEFAULT 1,
        reply_tickets INTEGER NOT NULL DEFAULT 1,
        manage_enrollments INTEGER NOT NULL DEFAULT 0,
        manage_users INTEGER NOT NULL DEFAULT 0,
        manage_payments INTEGER NOT NULL DEFAULT 0,
        manage_admins INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
      )
    ");

    // Perfil del profesor (cuenta): bio y foto propias del portal.
    $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('bio', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN bio TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('avatar_path', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path TEXT NOT NULL DEFAULT ''");
    }
}

/** Recrea users para incluir role='teacher' en el CHECK. */
function roles_migrate_users_check(PDO $pdo): void
{
    $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $colNames = array_map(static fn ($c) => (string) $c['name'], $cols);
    $selectList = implode(', ', array_map(static fn ($n) => '"' . str_replace('"', '""', $n) . '"', $colNames));

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("
      CREATE TABLE users_roles_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL DEFAULT '',
        role TEXT NOT NULL CHECK(role IN ('admin','teacher','student')),
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at TEXT DEFAULT NULL,
        fee_plan_id INTEGER DEFAULT NULL,
        fee_active INTEGER NOT NULL DEFAULT 0
      )
    ");

    // Copiar solo columnas conocidas (las que existan en la tabla vieja).
    $wanted = ['id', 'username', 'password_hash', 'name', 'email', 'role', 'active', 'created_at', 'last_login_at', 'fee_plan_id', 'fee_active'];
    $have = array_values(array_filter($wanted, static fn ($n) => in_array($n, $colNames, true)));
    $insCols = implode(', ', $have);
    $pdo->exec("INSERT INTO users_roles_new ($insCols) SELECT $insCols FROM users");
    $pdo->exec('DROP TABLE users');
    $pdo->exec('ALTER TABLE users_roles_new RENAME TO users');
    $pdo->exec('PRAGMA foreign_keys = ON');
}
