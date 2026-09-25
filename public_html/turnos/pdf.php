<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/pdf_lib.php';

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

$stmt = db()->prepare('SELECT prep_notes FROM therapies WHERE id = ?');
$stmt->execute([(int) $appt['therapy_id']]);
$prep = (string) ($stmt->fetchColumn() ?: '');

$pdf = new FluxusPdf();
$pdf->addPage();
$pdf->title('FluxusTerapia');
$pdf->subtitle('Comprobante oficial de turno');
$pdf->rule();

$pdf->paragraph('Hola, ' . $appt['patient_name'] . ':');
$pdf->paragraph(
    'Confirmamos tu reserva en FluxusTerapia. Te esperamos con presencia y calma '
    . 'para acompañarte en este espacio de bienestar.'
);

$pdf->heading('Detalle del turno');
$pdf->paragraph('Código: ' . $appt['code']);
$pdf->paragraph('Terapia: ' . $appt['therapy_name']);
$pdf->paragraph('Día: ' . format_date_es($appt['date']));
$pdf->paragraph('Hora: ' . format_time_es($appt['time']));
$pdf->paragraph('Duración aproximada: ' . (int) $appt['duration_min'] . ' minutos');
$pdf->paragraph('Lugar: ' . $config['place_name'] . ' · ' . $config['place_city']);

$pdf->heading('Indicaciones importantes');
if (trim($prep) === '') {
    $prep = "No comas ni bebas (salvo agua) 1 hora antes del turno.\n"
        . "Evitá alcohol y comidas muy pesadas el día previo.\n"
        . "Llegá 5–10 minutos antes.\n"
        . "Usá ropa cómoda.\n"
        . "Si no podés asistir, avisá con anticipación.";
}
foreach (preg_split("/\n+/", $prep) as $line) {
    $line = trim((string) $line);
    if ($line !== '') {
        $pdf->bullet($line);
    }
}

$pdf->heading('Contacto');
$pdf->paragraph('WhatsApp: +' . $config['whatsapp']);
$pdf->paragraph('Web: ' . $config['site_url']);
$pdf->paragraph('Instagram: @fluxusterapia');
$pdf->rule();
$pdf->paragraph('Gracias por confiar en FluxusTerapia. Michael Grandon te espera.');

$pdf->output('Turno-FluxusTerapia-' . $appt['code'] . '.pdf');
