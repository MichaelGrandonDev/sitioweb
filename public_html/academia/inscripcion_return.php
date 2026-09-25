<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$status = (string) ($_GET['status'] ?? 'pending');
$id = (int) ($_GET['id'] ?? 0);
$paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
if ($paymentId !== '' && $paymentId !== 'null' && mp_enabled()) {
    $data = mp_api('GET', '/v1/payments/' . rawurlencode($paymentId));
    if (!empty($data['ok'])) {
        apply_inscription_mp_payment($data);
    }
}

flash($status === 'failure' ? 'error' : 'success', match ($status) {
    'success' => 'Pago de inscripción recibido. El administrador revisará tu cupo y te llegará el mail de acceso al aprobarlo.',
    'failure' => 'El pago no se completó.',
    default => 'Pago pendiente. Cuando se acredite, el administrador podrá aprobar tu cupo.',
});
redirect('inscripcion.php');
