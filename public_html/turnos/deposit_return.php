<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? ''));
$status = (string) ($_GET['status'] ?? 'pending');
$paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');

if ($paymentId !== '' && $paymentId !== 'null' && deposit_mp_enabled()) {
    $data = deposit_mp_api('GET', '/v1/payments/' . rawurlencode($paymentId));
    if (!empty($data['ok'])) {
        apply_deposit_mp_payment($data);
    }
}

$msg = match ($status) {
    'success' => 'Pago recibido. Si está aprobado, ya podés descargar el PDF.',
    'failure' => 'El pago no se completó. Podés reintentar o transferir la seña.',
    default => 'Pago pendiente de acreditación.',
};
flash($status === 'failure' ? 'error' : 'success', $msg);
redirect('pay.php?token=' . urlencode($token));
