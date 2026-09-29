<?php

declare(strict_types=1);

/** API JSON de la Biblioteca MTC: subida por partes, texto de los PDF (leído con pdf.js) y procesamiento en el servidor. */

require __DIR__ . '/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
if (!db_ready() || empty($_SESSION['turnos_admin'])) {
    json_response(['ok' => false, 'error' => 'Iniciá sesión en el admin de turnos.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    json_response(['ok' => false, 'error' => 'Sesión inválida. Recargá la página.'], 403);
}
require_once __DIR__ . '/includes/pacientes.php';

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
$in = [];
if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $raw = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024);
    $in = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
}
$docFrom = static function (mixed $id): array {
    $doc = pac_doc((int) $id);
    if (!$doc) {
        json_response(['ok' => false, 'error' => 'Documento no encontrado.'], 404);
    }
    return $doc;
};

try {
    switch ($action) {
        case 'doc_create':
            $id = pac_doc_create((string) ($in['title'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['tag'] ?? 'libro'), (int) ($in['size'] ?? 0));
            json_response(['ok' => true, 'id' => $id, 'chunk' => pac_chunk_bytes(), 'kind' => pac_doc($id)['kind']]);
        case 'doc_chunk':
            $doc = $docFrom($_POST['id'] ?? 0);
            if ($doc['status'] !== 'subiendo') {
                json_response(['ok' => false, 'error' => 'Ese documento ya terminó de subir.'], 409);
            }
            $res = pac_doc_store_chunk($doc, $_FILES['chunk'] ?? [], (int) ($_POST['offset'] ?? -1), (int) ($_POST['total'] ?? 0));
            json_response($res, $res['ok'] ? 200 : (!empty($res['resync']) ? 409 : 400));
        case 'doc_pages':
            $doc = $docFrom($in['id'] ?? 0);
            $pages = is_array($in['pages'] ?? null) ? $in['pages'] : [];
            if (count($pages) > 100) {
                json_response(['ok' => false, 'error' => 'Demasiadas páginas en una tanda.'], 400);
            }
            $pdo = db();
            $pdo->beginTransaction();
            foreach ($pages as $page) {
                $n = (int) ($page['n'] ?? 0);
                if ($n >= 1 && $n <= 20000) {
                    pac_doc_insert_page((int) $doc['id'], $n, (string) ($page['text'] ?? ''));
                }
            }
            $pdo->commit();
            json_response(['ok' => true]);
        case 'doc_finish':
            $doc = $docFrom($in['id'] ?? 0);
            $res = pac_doc_finish((int) $doc['id'], max(0, (int) ($in['pages'] ?? 0)));
            json_response(['ok' => true] + $res);
        case 'doc_process':
            $doc = $docFrom($in['id'] ?? 0);
            $res = pac_doc_process($doc);
            json_response(['ok' => true] + $res);
        case 'job_status':
            require_once __DIR__ . '/includes/pac_generate.php';
            $pid = (int) ($in['pid'] ?? 0);
            $token = (string) ($in['token'] ?? '');
            $prev = pac_preview_get($token, $pid);
            $patient = $prev ? pac_patient($pid) : null;
            if (!$prev || !$patient) {
                json_response(['ok' => false, 'error' => 'Esa vista previa ya no existe.'], 404);
            }
            $prev = pac_preview_resolve($token, $prev, $patient);
            $job = $prev['state'] === 'waiting' ? pac_job((int) $prev['job']) : null;
            json_response([
                'ok' => true,
                'state' => $prev['state'],
                'job' => $job ? $job['status'] : null,
                'elapsed' => time() - (int) $prev['created'],
                'limit' => PAC_JOB_WAIT,
            ]);
        case 'doc_fail':
            $doc = $docFrom($in['id'] ?? 0);
            db()->prepare("UPDATE pac_docs SET status = 'error', note = ? WHERE id = ? AND status IN ('subiendo', 'procesando')")
                ->execute([mb_substr('La subida no terminó: ' . (string) ($in['error'] ?? ''), 0, 300), (int) $doc['id']]);
            json_response(['ok' => true]);
    }
    json_response(['ok' => false, 'error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Pacientes API (' . $action . '): ' . get_class($e) . ' en la línea ' . $e->getLine());
    json_response(['ok' => false, 'error' => 'Error del servidor al procesar el documento.'], 500);
}
