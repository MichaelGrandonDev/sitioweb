<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    http_response_code(503);
    echo 'Instalá el sistema de turnos primero.';
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
$appt = $token !== '' ? appointment_by_token($token) : null;
if (!$appt) {
    http_response_code(404);
    echo 'Turno no encontrado.';
    exit;
}

$consent = ($_GET['doc'] ?? '') === 'consentimiento';
$name = $consent
    ? 'Consentimiento-informado-' . $appt['code'] . '.pdf'
    : 'Turno-' . $appt['code'] . '-requisitos.pdf';
$pdf = $consent ? turno_consentimiento_pdf($appt) : turno_requisitos_pdf($appt);

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $pdf;
