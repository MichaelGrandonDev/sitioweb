<?php

declare(strict_types=1);

/**
 * Clases grabadas: videos de cada curso (archivo subido o link de YouTube/Drive),
 * guardados como recursos 'video' en la sección "Grabación de clases" del campus.
 *
 * Los archivos se suben por partes porque HostGator corta cada pedido en ~45 MB.
 */

const RECORDING_SECTION = 'Grabación de clases';
const RECORDING_CHUNK_BYTES = 8 * 1024 * 1024;
const RECORDING_VIDEO_EXTS = ['mp4', 'm4v', 'mov', 'webm'];

function recording_section_id(int $courseId): int
{
    $pdo = db();
    $find = $pdo->prepare('SELECT id FROM course_sections WHERE course_id = ? AND title = ? LIMIT 1');
    $find->execute([$courseId, RECORDING_SECTION]);
    $id = $find->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, ?, 10)')
        ->execute([$courseId, RECORDING_SECTION]);
    return (int) $pdo->lastInsertId();
}

/**
 * Pasa una clase grabada a otro curso (el progreso de los alumnos se conserva).
 * El video subido se mueve a la carpeta del curso nuevo; si no se puede, queda donde está y sigue funcionando.
 */
function recording_move(int $resourceId, int $targetCourseId): bool
{
    $pdo = db();
    $st = $pdo->prepare("SELECT course_id, file_path FROM course_resources WHERE id = ? AND type = 'video'");
    $st->execute([$resourceId]);
    $row = $st->fetch();
    if (!$row || (int) $row['course_id'] === $targetCourseId) {
        return false;
    }

    $path = trim((string) $row['file_path']);
    $full = $path !== '' ? resolve_upload_path($path) : null;
    if ($full !== null) {
        $dir = uploads_root() . '/course_' . $targetCourseId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = basename($full);
        if (is_file($dir . '/' . $name)) {
            $name = bin2hex(random_bytes(4)) . '-' . $name;
        }
        if (@rename($full, $dir . '/' . $name)) {
            $path = 'course_' . $targetCourseId . '/' . $name;
        }
    }

    $pdo->prepare('UPDATE course_resources SET course_id = ?, section_id = ?, file_path = ? WHERE id = ?')
        ->execute([$targetCourseId, recording_section_id($targetCourseId), $path, $resourceId]);
    return true;
}

function recording_add(int $courseId, string $title, string $description, string $url, string $filePath): int
{
    $pdo = db();
    $sectionId = recording_section_id($courseId);
    $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM course_resources WHERE section_id = ?');
    $next->execute([$sectionId]);
    $pdo->prepare("
      INSERT INTO course_resources
        (course_id, section_id, type, title, description, url, content, file_path, track_completion, sort_order)
      VALUES (?, ?, 'video', ?, ?, ?, '', ?, 1, ?)
    ")->execute([$courseId, $sectionId, $title, $description, $url, $filePath, (int) $next->fetchColumn()]);
    return (int) $pdo->lastInsertId();
}

function recording_url_supported(string $url): bool
{
    return (bool) preg_match('~(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/live/|drive\.google\.com/file/d/)~', $url);
}

function recording_thumbnail(array $resource): string
{
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/live/)([A-Za-z0-9_-]{6,})~', (string) $resource['url'], $m)) {
        return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
    }
    return '';
}

function recording_partial_dir(): string
{
    $dir = uploads_root() . '/_partial';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    foreach (glob($dir . '/*.part') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    return $dir;
}

/** @return array{ok:bool, token?:string, error?:string} */
function recording_upload_start(int $courseId, string $filename, int $size): array
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, RECORDING_VIDEO_EXTS, true)) {
        return ['ok' => false, 'error' => 'Formato no permitido. Subí un video MP4, MOV, M4V o WebM.'];
    }
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'El archivo está vacío.'];
    }
    $dir = recording_partial_dir();
    $free = @disk_free_space($dir);
    if ($free !== false && $size > $free - 200 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'No hay espacio suficiente en el hosting para este video. Subilo a YouTube o Drive y pegá el link.'];
    }
    $token = bin2hex(random_bytes(16));
    touch($dir . '/' . $token . '.part');
    $_SESSION['recording_uploads'][$token] = [
        'course_id' => $courseId,
        'name' => $filename,
        'ext' => $ext,
        'size' => $size,
    ];
    return ['ok' => true, 'token' => $token];
}

/** @return array{ok:bool, received?:int, error?:string} */
function recording_upload_chunk(string $token, int $offset, array $file): array
{
    $meta = $_SESSION['recording_uploads'][$token] ?? null;
    if (!$meta || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return ['ok' => false, 'error' => 'La subida expiró. Empezá de nuevo.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return ['ok' => false, 'error' => 'Se cortó una parte del video (código ' . (int) ($file['error'] ?? -1) . ').'];
    }
    $part = recording_partial_dir() . '/' . $token . '.part';
    clearstatcache(true, $part);
    $current = is_file($part) ? (int) filesize($part) : 0;
    $len = (int) $file['size'];

    if ($offset + $len <= $current) {
        return ['ok' => true, 'received' => $current];
    }
    if ($offset !== $current) {
        return ['ok' => false, 'error' => 'Las partes llegaron desordenadas.', 'received' => $current];
    }
    if ($current + $len > (int) $meta['size']) {
        return ['ok' => false, 'error' => 'El archivo recibido es más grande que el original.'];
    }
    $in = fopen((string) $file['tmp_name'], 'rb');
    $out = fopen($part, 'ab');
    if (!$in || !$out) {
        return ['ok' => false, 'error' => 'No se pudo guardar la parte en el servidor.'];
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    clearstatcache(true, $part);
    return ['ok' => true, 'received' => (int) filesize($part)];
}

/** @return array{ok:bool, path?:string, error?:string} */
function recording_upload_finish(string $token): array
{
    $meta = $_SESSION['recording_uploads'][$token] ?? null;
    if (!$meta || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return ['ok' => false, 'error' => 'La subida expiró. Empezá de nuevo.'];
    }
    $part = recording_partial_dir() . '/' . $token . '.part';
    clearstatcache(true, $part);
    if (!is_file($part) || (int) filesize($part) !== (int) $meta['size']) {
        return ['ok' => false, 'error' => 'El video no llegó completo. Probá subirlo de nuevo.'];
    }
    $courseId = (int) $meta['course_id'];
    $courseDir = uploads_root() . '/course_' . $courseId;
    if (!is_dir($courseDir)) {
        mkdir($courseDir, 0755, true);
    }
    $safeBase = trim((string) preg_replace('~[^a-zA-Z0-9._-]+~', '-', pathinfo((string) $meta['name'], PATHINFO_FILENAME)), '-') ?: 'clase';
    $filename = $safeBase . '-' . bin2hex(random_bytes(4)) . '.' . $meta['ext'];
    if (!rename($part, $courseDir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el video en el servidor.'];
    }
    @chmod($courseDir . '/' . $filename, 0644);
    unset($_SESSION['recording_uploads'][$token]);
    return ['ok' => true, 'path' => 'course_' . $courseId . '/' . $filename, 'course_id' => $courseId];
}

function recording_upload_cancel(string $token): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $token)) {
        @unlink(recording_partial_dir() . '/' . $token . '.part');
    }
    unset($_SESSION['recording_uploads'][$token]);
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }
    return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
}

/** @return int[] ids de recursos que el usuario ya marcó como vistos */
function recording_done_ids(int $userId): array
{
    $stmt = db()->prepare('SELECT resource_id FROM resource_progress WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'resource_id'));
}

function recording_kind(array $resource): string
{
    if (trim((string) ($resource['file_path'] ?? '')) !== '') {
        return 'Video';
    }
    return str_contains((string) $resource['url'], 'drive.google') ? 'Drive' : 'YouTube';
}

/** Clases de un curso en orden de publicación (para la lista del reproductor). */
function recordings_of_course(int $courseId): array
{
    $stmt = db()->prepare("SELECT id, title, created_at FROM course_resources WHERE course_id = ? AND type = 'video' ORDER BY datetime(created_at), id");
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

/** Clases grabadas visibles para el usuario (admin: todas), las más nuevas primero. */
function recordings_for_user(array $user, int $courseId = 0): array
{
    $params = [];
    $sql = "
      SELECT r.*, c.title AS course_title, c.category AS course_category
      FROM course_resources r
      JOIN courses c ON c.id = r.course_id
      WHERE r.type = 'video'
    ";
    if ($user['role'] !== 'admin') {
        $sql .= ' AND c.published = 1 AND r.course_id IN (SELECT course_id FROM enrollments WHERE user_id = ?)';
        $params[] = (int) $user['id'];
    }
    if ($courseId > 0) {
        $sql .= ' AND r.course_id = ?';
        $params[] = $courseId;
    }
    $sql .= ' ORDER BY datetime(r.created_at) DESC, r.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Una clase en vivo se considera "ahora" desde 15 min antes hasta 2 h después del inicio. */
const LIVE_EARLY_SECONDS = 15 * 60;
const LIVE_LENGTH_SECONDS = 2 * 3600;

function live_url_ok(string $url): bool
{
    return $url === '' || (bool) preg_match('~^https://[^\s]+$~i', $url);
}

/** "Hoy · 19:00", "Mañana · 19:00" o "Jueves 20/11 · 19:00" (hora de Argentina). */
function live_when(int $ts): string
{
    $days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) {
        $label = 'Hoy';
    } elseif ($day === date('Y-m-d', strtotime('+1 day'))) {
        $label = 'Mañana';
    } else {
        $label = $days[(int) date('w', $ts)] . ' ' . date('d/m', $ts);
    }
    return $label . ' · ' . date('H:i', $ts) . ' hs';
}

/** Próximas fechas en vivo de un curso (las fechas se guardan como hora local "Y-m-d\TH:i"). */
function live_sessions(int $courseId, bool $includePast = false): array
{
    $stmt = db()->prepare('SELECT * FROM course_events WHERE course_id = ?');
    $stmt->execute([$courseId]);
    $out = [];
    foreach ($stmt->fetchAll() as $ev) {
        $ts = strtotime((string) $ev['starts_at']);
        if ($ts === false || (!$includePast && $ts + LIVE_LENGTH_SECONDS < time())) {
            continue;
        }
        $ev['ts'] = $ts;
        $out[] = $ev;
    }
    usort($out, static fn ($a, $b) => $a['ts'] <=> $b['ts']);
    return $out;
}

/**
 * Clases en vivo de los cursos del usuario: link de Meet + horario semanal + próxima fecha.
 * status: live (ahora) · today · upcoming · weekly (sin fecha cargada, solo horario).
 */
function live_classes_for_user(array $user, int $courseId = 0): array
{
    $params = [];
    $sql = 'SELECT c.id, c.title, c.meet_url, c.meet_schedule FROM courses c';
    if ($user['role'] !== 'admin') {
        $sql .= ' JOIN enrollments e ON e.course_id = c.id AND e.user_id = ? WHERE c.published = 1';
        $params[] = (int) $user['id'];
    } else {
        $sql .= ' WHERE 1 = 1';
    }
    if ($courseId > 0) {
        $sql .= ' AND c.id = ?';
        $params[] = $courseId;
    }
    $stmt = db()->prepare($sql . ' ORDER BY c.sort_order, c.title');
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        $meet = trim((string) ($c['meet_url'] ?? ''));
        $sessions = live_sessions((int) $c['id']);
        if ($meet === '' && !$sessions) {
            continue;
        }
        $next = $sessions[0] ?? null;
        $status = 'weekly';
        if ($next) {
            $now = time();
            if ($now >= $next['ts'] - LIVE_EARLY_SECONDS && $now <= $next['ts'] + LIVE_LENGTH_SECONDS) {
                $status = 'live';
            } elseif (date('Y-m-d', $next['ts']) === date('Y-m-d')) {
                $status = 'today';
            } else {
                $status = 'upcoming';
            }
        }
        $out[] = [
            'course_id' => (int) $c['id'],
            'course_title' => (string) $c['title'],
            'schedule' => trim((string) ($c['meet_schedule'] ?? '')),
            'url' => $next && trim((string) $next['url']) !== '' ? trim((string) $next['url']) : $meet,
            'next' => $next,
            'later' => array_slice($sessions, 1, 3),
            'status' => $status,
        ];
    }
    $rank = ['live' => 0, 'today' => 1, 'upcoming' => 2, 'weekly' => 3];
    usort($out, static fn ($a, $b) => [$rank[$a['status']], $a['next']['ts'] ?? PHP_INT_MAX] <=> [$rank[$b['status']], $b['next']['ts'] ?? PHP_INT_MAX]);
    return $out;
}
