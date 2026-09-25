<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    json_response(['ok' => false, 'error' => 'Sistema no instalado. Abrí /turnos/install.php'], 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'month') {
    $year = (int) ($_GET['year'] ?? date('Y'));
    $month = (int) ($_GET['month'] ?? date('n'));
    if ($month < 1 || $month > 12) {
        json_response(['ok' => false, 'error' => 'Mes inválido'], 400);
    }
    json_response([
        'ok' => true,
        'year' => $year,
        'month' => $month,
        'available_days' => available_days($year, $month),
        'month_label' => month_es($month) . ' ' . $year,
    ]);
}

if ($action === 'slots') {
    $date = trim((string) ($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['ok' => false, 'error' => 'Fecha inválida'], 400);
    }
    json_response([
        'ok' => true,
        'date' => $date,
        'date_label' => format_date_es($date),
        'slots' => available_slots_for($date),
    ]);
}

if ($action === 'therapies') {
    json_response(['ok' => true, 'therapies' => therapies()]);
}

if ($action === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!verify_csrf($payload['csrf'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Sesión inválida. Recargá la página.'], 403);
    }
    try {
        $appt = create_appointment($payload);
        json_response([
            'ok' => true,
            'appointment' => $appt,
            'pay_url' => $appt['pay_url'],
            'deposit_amount' => $appt['deposit_amount'],
            'message' => 'Turno reservado. Pagá la seña para confirmarlo.',
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

json_response(['ok' => false, 'error' => 'Acción no válida'], 400);
