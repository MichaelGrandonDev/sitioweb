<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (!mp_enabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
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
}
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
if ($topic !== '' && !str_contains($topic, 'payment')) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}
$data = mp_api('GET', '/v1/payments/' . rawurlencode($id));
if (empty($data['ok'])) {
    http_response_code(502);
    echo json_encode(['ok' => false]);
    exit;
}
apply_shop_mp_payment($data);
echo json_encode(['ok' => true]);
