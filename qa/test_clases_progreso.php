<?php
/**
 * Tests de las clases grabadas: numeración y orden, progreso sin contar bonus,
 * curso completo, límite de bonus y bonus bloqueadas/desbloqueadas.
 * Usa una base SQLite temporal en memoria: nunca toca la base real.
 *
 * Uso: php qa/test_clases_progreso.php   (sale con código 1 si algo falla)
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("
          CREATE TABLE courses (id INTEGER PRIMARY KEY, title TEXT NOT NULL);
          CREATE TABLE course_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER NOT NULL,
            title TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0
          );
          CREATE TABLE course_resources (
            id INTEGER PRIMARY KEY AUTOINCREMENT, course_id INTEGER NOT NULL, section_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'link', title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', content TEXT NOT NULL DEFAULT '', file_path TEXT NOT NULL DEFAULT '',
            track_completion INTEGER NOT NULL DEFAULT 1, lesson_id INTEGER DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, is_bonus INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          );
          CREATE TABLE resource_progress (
            user_id INTEGER NOT NULL, resource_id INTEGER NOT NULL,
            completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id, resource_id)
          );
          INSERT INTO courses (id, title) VALUES (1, 'Curso A'), (2, 'Curso B');
        ");
    }
    return $pdo;
}

require __DIR__ . '/../public_html/academia/includes/recordings.php';

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

function titles(array $items): string
{
    return implode(',', array_map(static fn ($r) => $r['title'], $items));
}

$student = ['id' => 7, 'role' => 'student'];
$admin = ['id' => 1, 'role' => 'admin'];

echo "Numeración y orden\n";
$c1 = recording_add(1, 'C1', '', 'https://youtu.be/aaaaaaa', '');
$c2 = recording_add(1, 'C2', '', 'https://youtu.be/bbbbbbb', '');
$c3 = recording_add(1, 'C3', '', 'https://youtu.be/ccccccc', '');
$c4 = recording_add(1, 'C4', '', 'https://youtu.be/ddddddd', '');
$plan = recording_course_plan(1, $student);
check('se numeran en orden de carga', titles($plan['classes']) === 'C1,C2,C3,C4', titles($plan['classes']));
check('números 1..4', array_column($plan['classes'], 'num') === [1, 2, 3, 4]);
check('etiqueta "Clase 3"', recording_label($plan['classes'][2]) === 'Clase 3');

recording_move_to($c4, 1);
$plan = recording_course_plan(1, $student);
check('mover la 4 al primer lugar', titles($plan['classes']) === 'C4,C1,C2,C3', titles($plan['classes']));
recording_move_to($c4, 99);
$plan = recording_course_plan(1, $student);
check('posición fuera de rango va al final', titles($plan['classes']) === 'C1,C2,C3,C4', titles($plan['classes']));
check('reorder_ids sube un lugar', recording_reorder_ids([1, 2, 3], 3, 2) === [1, 3, 2]);
check('reorder_ids con posición 0 va primero', recording_reorder_ids([1, 2, 3], 2, 0) === [2, 1, 3]);
check('reorder_ids ignora ids ajenos', recording_reorder_ids([1, 2], 9, 1) === [1, 2]);

$unordered = [
    ['id' => 10, 'sort_order' => 2, 'is_bonus' => 0, 'title' => 'B'],
    ['id' => 11, 'sort_order' => 1, 'is_bonus' => 0, 'title' => 'A'],
    ['id' => 9, 'sort_order' => 2, 'is_bonus' => 0, 'title' => 'B0'],
];
check('plan ordena por sort_order y desempata por id', titles(recording_plan($unordered, [])['classes']) === 'A,B0,B');

echo "\nBonus: límite de " . RECORDING_MAX_BONUS . "\n";
$b1 = recording_add(1, 'B1', '', 'https://youtu.be/eeeeeee', '', true);
$b2 = recording_add(1, 'B2', '', 'https://youtu.be/fffffff', '', true);
check('se crean bonus directamente', $b1 > 0 && $b2 > 0);
$r = recording_set_bonus($c4, true);
check('marcar una clase existente como bonus (3.ª)', $r['ok'], $r['error'] ?? '');
check('hay 3 bonus', recording_bonus_count(1) === 3);
$r = recording_set_bonus($c3, true);
check('la 4.ª bonus se rechaza', !$r['ok'] && str_contains($r['error'] ?? '', 'máximo'), $r['error'] ?? '');
check('crear una 4.ª bonus devuelve 0', recording_add(1, 'B4', '', 'https://youtu.be/ggggggg', '', true) === 0);
check('sigue habiendo 3 bonus', recording_bonus_count(1) === 3);
check('el límite es por curso', recording_add(2, 'Otro curso', '', 'https://youtu.be/hhhhhhh', '', true) > 0);

$plan = recording_course_plan(1, $student);
check('las bonus salen de la lista de clases', titles($plan['classes']) === 'C1,C2,C3', titles($plan['classes']));
check('bonus numeradas aparte', titles($plan['bonus']) === 'B1,B2,C4' && recording_label($plan['bonus'][0]) === 'Bonus 1', titles($plan['bonus']));

echo "\nProgreso y curso completo\n";
check('sin ver nada: 0 de 3', $plan['seen'] === 0 && $plan['total'] === 3 && $plan['pct'] === 0);
recording_set_seen(7, $b1, true);
$plan = recording_course_plan(1, $student);
check('ver una bonus no suma progreso', $plan['seen'] === 0);
recording_set_seen(7, $c1, true);
recording_set_seen(7, $c2, true);
$plan = recording_course_plan(1, $student);
check('2 de 3 = 67%', $plan['seen'] === 2 && $plan['pct'] === 67, $plan['seen'] . ' / ' . $plan['pct']);
check('incompleto', !$plan['complete']);
recording_set_seen(7, $c1, true);
check('marcar vista dos veces no duplica', recording_course_plan(1, $student)['seen'] === 2);

echo "\nBonus bloqueadas / desbloqueadas\n";
$bonusRow = ['id' => $b1, 'type' => 'video', 'is_bonus' => 1, 'course_id' => 1];
check('curso incompleto: bonus bloqueadas', !$plan['bonus_unlocked'] && $plan['bonus'][0]['locked']);
check('alumno no puede abrir la bonus', recording_is_locked($bonusRow, $student));
check('admin siempre puede', !recording_is_locked($bonusRow, $admin) && recording_course_plan(1, $admin)['bonus_unlocked']);
check('clases comunes nunca bloqueadas', !recording_is_locked(['id' => $c3, 'type' => 'video', 'is_bonus' => 0, 'course_id' => 1], $student));
$nb = recording_neighbors($plan, $c3);
check('última clase: no ofrece bonus bloqueada como siguiente', $nb['next'] === null && (int) $nb['prev']['id'] === $c2);
check('ajuste "siempre visibles" desbloquea', recording_plan(recordings_of_course(1), [], false, false)['bonus_unlocked']);

recording_set_seen(7, $c3, true);
$plan = recording_course_plan(1, $student);
check('3 de 3: curso completo', $plan['complete'] && $plan['pct'] === 100);
check('al completar se desbloquean', $plan['bonus_unlocked'] && !$plan['bonus'][0]['locked']);
check('alumno ya puede abrir la bonus', !recording_is_locked($bonusRow, $student));
$nb = recording_neighbors($plan, $c3);
check('después de la última clase sigue la Bonus 1', $nb['next'] !== null && (int) $nb['next']['id'] === $b1);

recording_set_seen(7, $c2, false);
$plan = recording_course_plan(1, $student);
check('desmarcar una clase vuelve a bloquear', !$plan['complete'] && !$plan['bonus_unlocked']);

echo "\nQuitar de bonus\n";
$r = recording_set_bonus($c4, false);
$plan = recording_course_plan(1, $student);
check('vuelve como última clase', $r['ok'] && titles($plan['classes']) === 'C1,C2,C3,C4', titles($plan['classes']));
check('ahora hay lugar para otra bonus', recording_set_bonus($c3, true)['ok']);
check('curso sin clases no está completo', !recording_plan([], [])['complete']);

echo "\n{$passed} ok · {$failures} fallas\n";
exit($failures > 0 ? 1 : 0);
