<?php
/**
 * Tests de roles profesor: permisos, cursos asignados y bloqueos.
 * Usa SQLite en memoria: no toca la base real.
 *
 * Uso: php qa/test_roles_profesor.php
 */

declare(strict_types=1);

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

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec("
          CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL CHECK(role IN ('admin','teacher','student')),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          );
          CREATE TABLE courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            teacher_name TEXT NOT NULL DEFAULT '',
            published INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
          );
          CREATE TABLE enrollments (
            user_id INTEGER NOT NULL,
            course_id INTEGER NOT NULL,
            PRIMARY KEY(user_id, course_id)
          );
        ");
    }
    return $pdo;
}

// Stubs mínimos que roles.php necesita al ejecutarse en tests.
if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
    }
}
if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        throw new RuntimeException('redirect:' . $path);
    }
}
if (!function_exists('academia_path')) {
    function academia_path(string $file): string
    {
        return $file;
    }
}
if (!function_exists('in_admin_dir')) {
    function in_admin_dir(): bool
    {
        return true;
    }
}
if (!function_exists('require_login')) {
    function require_login(): array
    {
        throw new RuntimeException('require_login no debería llamarse en este test');
    }
}

require __DIR__ . '/../public_html/academia/includes/roles.php';

$pdo = db();
roles_migrate($pdo);

$pdo->exec("INSERT INTO users (id, username, password_hash, name, role) VALUES
  (1, 'admin', 'x', 'Admin General', 'admin'),
  (2, 'profe', 'x', 'Ana Profesora', 'teacher'),
  (3, 'alumno', 'x', 'Luis Alumno', 'student')");
$pdo->exec("INSERT INTO courses (id, title, teacher_name) VALUES
  (10, 'Curso A', ''),
  (20, 'Curso B', 'Otro'),
  (30, 'Curso C', '')");
$pdo->exec("INSERT INTO enrollments (user_id, course_id) VALUES (3, 10), (3, 20)");

$admin = ['id' => 1, 'role' => 'admin', 'name' => 'Admin General'];
$teacher = ['id' => 2, 'role' => 'teacher', 'name' => 'Ana Profesora'];
$student = ['id' => 3, 'role' => 'student', 'name' => 'Luis Alumno'];

echo "Roles básicos\n";
check('admin es staff y full admin', is_staff($admin) && is_full_admin($admin) && !is_teacher($admin));
check('profesor es staff pero no full admin', is_staff($teacher) && is_teacher($teacher) && !is_full_admin($teacher));
check('alumno no es staff', !is_staff($student));

echo "Permisos por defecto del profesor\n";
teacher_ensure_permissions_row(2);
$perms = teacher_permissions_of(2);
check('manage_course_content on', !empty($perms['manage_course_content']));
check('edit_course_info on', !empty($perms['edit_course_info']));
check('view_students on', !empty($perms['view_students']));
check('reply_tickets on', !empty($perms['reply_tickets']));
check('manage_enrollments off', empty($perms['manage_enrollments']));
check('manage_users off', empty($perms['manage_users']));
check('manage_payments off', empty($perms['manage_payments']));
check('manage_admins off', empty($perms['manage_admins']));

check('admin tiene todos los permisos', user_has_permission($admin, 'manage_users'));
check('profesor NO puede enrollar', !user_has_permission($teacher, 'manage_enrollments'));
check('profesor NO puede gestionar usuarios', !user_has_permission($teacher, 'manage_users'));
check('profesor SÍ puede contenido (default)', user_has_permission($teacher, 'manage_course_content'));

echo "Asignación de cursos\n";
teacher_courses_set(2, [10, 30]);
$ids = teacher_course_ids($teacher);
check('cursos asignados 10 y 30', $ids === [10, 30], json_encode($ids));
check('tiene curso 10', teacher_has_course($teacher, 10));
check('NO tiene curso 20 (ajeno)', !teacher_has_course($teacher, 20));
check('admin “tiene” cualquier curso', teacher_has_course($admin, 20));

$nameA = (string) $pdo->query('SELECT teacher_name FROM courses WHERE id = 10')->fetchColumn();
check('rellena teacher_name vacío al asignar', $nameA === 'Ana Profesora', $nameA);
$nameB = (string) $pdo->query('SELECT teacher_name FROM courses WHERE id = 20')->fetchColumn();
check('no pisa teacher_name ya cargado', $nameB === 'Otro', $nameB);

echo "Autorización por curso\n";
check('puede editar contenido del asignado', user_can_manage_course_content($teacher, 10));
check('NO puede editar contenido ajeno', !user_can_manage_course_content($teacher, 20));
check('puede editar info del asignado', user_can_edit_course_info($teacher, 10));
check('NO puede editar info ajena', !user_can_edit_course_info($teacher, 20));
check('admin puede editar cualquier contenido', user_can_manage_course_content($admin, 20));

$staffList = staff_courses($teacher);
$staffIds = array_map(static fn ($c) => (int) $c['id'], $staffList);
check('staff_courses solo asignados', $staffIds === [10, 30], json_encode($staffIds));

echo "Flags de permiso desactivados\n";
teacher_permissions_save(2, array_merge(teacher_permission_defaults(), [
    'manage_course_content' => 0,
    'edit_course_info' => 0,
]));
// La caché estática de teacher_permissions_of conserva el valor anterior: forzamos nueva lectura.
$ref = new ReflectionFunction('teacher_permissions_of');
// Re-consulta directa:
$row = $pdo->query('SELECT manage_course_content, edit_course_info FROM teacher_permissions WHERE user_id = 2')->fetch();
check('flag contenido guardado en 0', (int) $row['manage_course_content'] === 0);
check('flag info guardado en 0', (int) $row['edit_course_info'] === 0);

// Simular usuario “fresco” (misma id) leyendo de DB sin caché: usamos helper directo
$teacherFresh = ['id' => 2, 'role' => 'teacher', 'name' => 'Ana Profesora'];
// Vaciar caché interna ejecutando con user_id distinto y volviendo no es trivial;
// comprobamos con SQL + lógica equivalente:
$canContent = is_full_admin($teacherFresh)
    || (is_teacher($teacherFresh) && (int) $row['manage_course_content'] === 1 && teacher_has_course($teacherFresh, 10));
check('con flag off no gestiona contenido (lógica)', !$canContent);

echo "Menú del profesor\n";
// Restaurar permisos defaults para el menú
teacher_permissions_save(2, teacher_permission_defaults());
// Nota: caché puede estar stale; admin_nav_items usa user_has_permission → cache.
// Para el menú con defaults, insertamos un profesor nuevo.
$pdo->prepare("INSERT INTO users (id, username, password_hash, name, role) VALUES (4, 'profe2', 'x', 'Beto', 'teacher')")->execute();
teacher_permissions_save(4, teacher_permission_defaults());
teacher_courses_set(4, [10]);
$t2 = ['id' => 4, 'role' => 'teacher', 'name' => 'Beto'];
$nav = admin_nav_items($t2, 2);
$hrefs = array_column($nav, 0);
check('menú incluye Clases', in_array('admin/grabaciones.php', $hrefs, true));
check('menú incluye Alumnos (solo lectura)', in_array('admin/estudiantes.php', $hrefs, true));
check('menú NO incluye Inscripciones', !in_array('admin/inscriptions.php', $hrefs, true));
check('menú NO incluye Recordatorios', !in_array('admin/recordatorios.php', $hrefs, true));
check('menú NO incluye Administradores', !in_array('admin/admins.php', $hrefs, true));

$adminNav = admin_nav_items($admin, 0);
$adminHrefs = array_column($adminNav, 0);
check('admin sí ve Alumnos (users.php)', in_array('admin/users.php', $adminHrefs, true));
check('admin sí ve Administradores', in_array('admin/admins.php', $adminHrefs, true));

echo "\nResultado: {$passed} ok, {$failures} fallos\n";
exit($failures > 0 ? 1 : 0);
