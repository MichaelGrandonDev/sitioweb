<?php

declare(strict_types=1);

/** Foto de la lengua de un plan MTC (solo admin). Las fotos viven en data/, que no se sirve directo. */

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    http_response_code(404);
    exit;
}
require_admin();
require_once __DIR__ . '/includes/mtc_plan.php';

$row = mtc_plan_for_appointment((int) ($_GET['id'] ?? 0));
$n = (int) ($_GET['n'] ?? 0);
$file = $row && $n >= 1 && $n <= MTC_TOTAL ? mtc_photo_path((int) $row['id'], $n) : null;
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Foto no encontrada.');
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="lengua-consulta-' . $n . '.jpg"');
readfile($file);
