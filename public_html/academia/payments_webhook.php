<?php

declare(strict_types=1);

/**
 * Webhook / IPN de Mercado Pago.
 * URL: https://fluxusterapia.com/academia/payments_webhook.php
 */
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!mp_enabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'mp disabled']);
    exit;
}

$topic = (string) ($_GET['topic'] ?? $_GET['type'] ?? '');
$id = (string) ($_GET['id'] ?? $_GET['data_id'] ?? '');

$raw = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
if (is_array($json)) {
    if ($id === '' && isset($json['data']['id'])) {
        $id = (string) $json['data']['id'];
    }
    if ($topic === '' && isset($json['type'])) {
        $topic = (string) $json['type'];
    }
    if ($topic === '' && isset($json['action'])) {
        $topic = (string) $json['action'];
    }
}

if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// Solo nos interesan pagos
if ($topic !== '' && !str_contains($topic, 'payment')) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$data = mp_api('GET', '/v1/payments/' . rawurlencode($id));
if (empty($data['ok'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'fetch failed']);
    exit;
}

apply_mp_payment($data);
echo json_encode(['ok' => true]);
