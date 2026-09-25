<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$status = (string) ($_GET['status'] ?? 'pending');
$orderId = (int) ($_GET['order_id'] ?? 0);
$paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');

if ($paymentId !== '' && $paymentId !== 'null' && mp_enabled()) {
    $data = mp_api('GET', '/v1/payments/' . rawurlencode($paymentId));
    if (!empty($data['ok'])) {
        apply_shop_mp_payment($data);
    }
}

flash($status === 'failure' ? 'error' : 'success', match ($status) {
    'success' => 'Pago recibido. Si está aprobado, ya tenés los cursos en tu campus.',
    'failure' => 'El pago no se completó.',
    default => 'Pago pendiente de acreditación.',
});
redirect($orderId > 0 ? ('shop_order.php?id=' . $orderId) : 'shop.php');
