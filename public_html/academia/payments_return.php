<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();

$status = (string) ($_GET['status'] ?? 'pending');
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');

// Intentar sincronizar con MP si vino payment_id
if ($paymentId !== '' && $paymentId !== 'null' && mp_enabled()) {
    $data = mp_api('GET', '/v1/payments/' . rawurlencode($paymentId));
    if (!empty($data['ok'])) {
        apply_mp_payment($data);
    }
}

$msg = match ($status) {
    'success' => 'Pago recibido. Si está aprobado, la cuota ya figura como pagada.',
    'failure' => 'El pago no se completó. Podés reintentar o informar una transferencia.',
    default => 'Pago pendiente de acreditación. Lo vas a ver reflejado cuando Mercado Pago lo confirme.',
};

flash($status === 'failure' ? 'error' : 'success', $msg);
redirect('payments.php');
