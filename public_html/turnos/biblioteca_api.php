<?php

declare(strict_types=1);

/** API JSON de la biblioteca: subida por partes, última página leída y cantidad de páginas. Admin + CSRF. */

require __DIR__ . '/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
if (!db_ready() || empty($_SESSION['turnos_admin'])) {
    json_response(['ok' => false, 'error' => 'Tu sesión se cerró. Volvé a entrar al admin.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método no permitido.'], 405);
}
if (!verify_csrf($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
    json_response(['ok' => false, 'error' => 'Sesión inválida. Recargá la página.'], 403);
}
require_once __DIR__ . '/includes/biblioteca_lector.php';

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$str = static fn (string $key): string => is_string($_POST[$key] ?? null) ? $_POST[$key] : '';

try {
    switch ($action) {
        case 'progress':
            session_write_close();
            $book = bib_book($str('id')) ?? throw new RuntimeException('Documento no encontrado.');
            $page = max(1, min(100000, (int) $str('page')));
            db()->prepare('UPDATE bib_books SET last_page = ?, last_read_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$page, (int) $book['id']]);
            json_response(['ok' => true]);
        case 'pages':
            session_write_close();
            $book = bib_book($str('id')) ?? throw new RuntimeException('Documento no encontrado.');
            $pages = (int) $str('pages');
            if ($pages > 0 && $pages <= 100000 && $pages !== (int) $book['pages']) {
                db()->prepare('UPDATE bib_books SET pages = ? WHERE id = ?')->execute([$pages, (int) $book['id']]);
            }
            json_response(['ok' => true]);
        case 'upload_init':
            json_response(['ok' => true] + bib_upload_init($str('name'), (int) $str('size'), $str('title'), $str('kind'), $str('tags')));
        case 'upload_chunk':
            @set_time_limit(300);
            $res = bib_store_chunk($str('token'), is_array($_FILES['chunk'] ?? null) ? $_FILES['chunk'] : [], (int) $str('offset'), (int) $str('total'));
            json_response($res, $res['ok'] || !empty($res['resync']) ? 200 : 400);
        default:
            json_response(['ok' => false, 'error' => 'Acción desconocida.'], 400);
    }
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
