<?php

declare(strict_types=1);

/**
 * Puente Pacientes ↔ Cursor en la Mac del terapeuta (tools/cursor_bridge).
 * Solo POST por HTTPS con «Authorization: Bearer <token>». Nunca se registran los datos que pasan por acá.
 */

require __DIR__ . '/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$local = in_array(preg_replace('/:\d+$/', '', $host), ['127.0.0.1', 'localhost'], true) && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!$https && !$local) {
    json_response(['ok' => false, 'error' => 'Solo HTTPS.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !db_ready()) {
    json_response(['ok' => false, 'error' => 'No disponible.'], 405);
}
require_once __DIR__ . '/includes/pacientes.php';

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (pac_bridge_blocked($ip)) {
    json_response(['ok' => false, 'error' => 'Demasiados intentos. Esperá unos minutos.'], 429);
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? $m[1] : trim((string) ($_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''));
if (!pac_bridge_auth($token)) {
    pac_bridge_rate($ip, true);
    usleep(400000);
    json_response(['ok' => false, 'error' => 'No autorizado.'], 401);
}
if (!pac_bridge_rate($ip)) {
    json_response(['ok' => false, 'error' => 'Demasiados pedidos.'], 429);
}
if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    json_response(['ok' => false, 'error' => 'Se espera JSON.'], 415);
}
$raw = file_get_contents('php://input', false, null, 0, 16 * 1024 * 1024);
$in = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($in)) {
    json_response(['ok' => false, 'error' => 'JSON inválido.'], 400);
}
$action = is_string($in['action'] ?? null) ? $in['action'] : '';

try {
    switch ($action) {
        case 'heartbeat':
            pac_bridge_heartbeat((string) ($in['info'] ?? ''));
            json_response(['ok' => true, 'time' => time()]);
        case 'claim':
            pac_bridge_heartbeat((string) ($in['info'] ?? ''));
            json_response(['ok' => true, 'job' => pac_job_claim()]);
        case 'photo':
            $photo = pac_job_photo((int) ($in['id'] ?? 0), (string) ($in['claim'] ?? ''));
            json_response(['ok' => $photo !== null, 'photo' => $photo], $photo !== null ? 200 : 404);
        case 'result':
            pac_bridge_heartbeat((string) ($in['info'] ?? ''));
            $result = is_array($in['result'] ?? null) ? $in['result'] : null;
            $saved = pac_job_finish((int) ($in['id'] ?? 0), (string) ($in['claim'] ?? ''), $result, $result === null ? (string) ($in['error'] ?? 'error') : '');
            json_response(['ok' => $saved, 'error' => $saved ? null : 'Ese trabajo ya no está disponible (venció o se usó el borrador por reglas).'], $saved ? 200 : 409);
        case 'import_begin':
            json_response(['ok' => true] + pac_import_begin(is_array($in['doc'] ?? null) ? $in['doc'] : []));
        case 'import_pages':
            $n = pac_import_pages((int) ($in['doc_id'] ?? 0), is_array($in['pages'] ?? null) ? $in['pages'] : []);
            json_response(['ok' => true, 'saved' => $n]);
        case 'import_finish':
            json_response(['ok' => true] + pac_import_finish((int) ($in['doc_id'] ?? 0), (int) ($in['pages'] ?? 0)));
        case 'library':
            $rows = db()->query("SELECT sha256, status, pages FROM pac_docs WHERE sha256 != ''")->fetchAll();
            json_response(['ok' => true, 'docs' => $rows]);
    }
    json_response(['ok' => false, 'error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Pacientes puente (' . $action . '): ' . get_class($e) . ' en la línea ' . $e->getLine());
    json_response(['ok' => false, 'error' => 'Error del servidor.'], 500);
}
