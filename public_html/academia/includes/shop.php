<?php

declare(strict_types=1);

function cart_items(): array
{
    if (empty($_SESSION['shop_cart']) || !is_array($_SESSION['shop_cart'])) {
        $_SESSION['shop_cart'] = [];
    }
    return $_SESSION['shop_cart'];
}

function cart_count(): int
{
    return count(cart_items());
}

function cart_add(int $courseId): void
{
    $items = cart_items();
    $items[$courseId] = 1;
    $_SESSION['shop_cart'] = $items;
}

function cart_remove(int $courseId): void
{
    $items = cart_items();
    unset($items[$courseId]);
    $_SESSION['shop_cart'] = $items;
}

function cart_clear(): void
{
    $_SESSION['shop_cart'] = [];
}

function cart_courses(): array
{
    $ids = array_keys(cart_items());
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("
      SELECT * FROM courses
      WHERE id IN ($placeholders) AND shop_listed = 1 AND published = 1 AND shop_price > 0
      ORDER BY sort_order, title
    ");
    $stmt->execute(array_values($ids));
    return $stmt->fetchAll();
}

function cart_total(array $courses): int
{
    $sum = 0;
    foreach ($courses as $c) {
        $sum += (int) $c['shop_price'];
    }
    return $sum;
}

function fulfill_shop_order(int $orderId): void
{
    $order = db()->prepare('SELECT * FROM shop_orders WHERE id = ? LIMIT 1');
    $order->execute([$orderId]);
    $o = $order->fetch();
    if (!$o || $o['status'] === 'paid') {
        return;
    }
    db()->prepare("UPDATE shop_orders SET status = 'paid', paid_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $orderId]);

    $items = db()->prepare('SELECT course_id, title FROM shop_order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $itemRows = $items->fetchAll();
    $enroll = db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');
    $titles = [];
    foreach ($itemRows as $item) {
        $enroll->execute([(int) $o['user_id'], (int) $item['course_id']]);
        $titles[] = (string) $item['title'];
    }

    $userStmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([(int) $o['user_id']]);
    $user = $userStmt->fetch();
    if (!$user) {
        return;
    }

    if ((int) ($o['access_sent'] ?? 0) === 1) {
        db()->prepare('UPDATE users SET active = 1 WHERE id = ?')->execute([(int) $user['id']]);
        return;
    }

    $password = generate_temp_password();
    db()->prepare('UPDATE users SET password_hash = ?, active = 1 WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);

    $email = (string) ($user['email'] ?: ($o['buyer_email'] ?? ''));
    $name = (string) ($user['name'] ?: ($o['buyer_name'] ?? 'Alumno'));
    if ($email !== '' && $titles) {
        send_course_access_email($email, $name, (string) $user['username'], $password, $titles);
        db()->prepare('UPDATE shop_orders SET access_sent = 1 WHERE id = ?')->execute([$orderId]);
    }
}

function create_shop_mp_preference(array $order, array $user, array $items, string $returnBase = ''): array
{
    global $config;
    if (!mp_enabled()) {
        return ['ok' => false, 'error' => 'Mercado Pago no configurado'];
    }
    $site = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/');
    $base = $returnBase !== '' ? rtrim($returnBase, '/') : ($site . '/cursos');
    $mpItems = [];
    foreach ($items as $it) {
        $mpItems[] = [
            'id' => 'course-' . (int) $it['course_id'],
            'title' => (string) $it['title'],
            'quantity' => 1,
            'currency_id' => (string) (payment_cfg()['currency'] ?? 'ARS'),
            'unit_price' => (int) $it['price'],
        ];
    }
    $payload = [
        'items' => $mpItems,
        'payer' => [
            'name' => (string) $user['name'],
            'email' => (string) ($user['email'] ?? ''),
        ],
        'external_reference' => 'shop-' . (int) $order['id'],
        'metadata' => ['order_id' => (int) $order['id'], 'user_id' => (int) $user['id']],
        'back_urls' => [
            'success' => $base . '/return.php?status=success&order_id=' . (int) $order['id'],
            'failure' => $base . '/return.php?status=failure&order_id=' . (int) $order['id'],
            'pending' => $base . '/return.php?status=pending&order_id=' . (int) $order['id'],
        ],
        'auto_return' => 'approved',
        'notification_url' => $site . '/cursos/webhook.php',
        'statement_descriptor' => 'CURSOSFLUXUS',
    ];
    $res = mp_api('POST', '/checkout/preferences', $payload);
    if (empty($res['ok']) || empty($res['id'])) {
        return ['ok' => false, 'error' => $res['message'] ?? $res['error'] ?? 'No se pudo crear el pago'];
    }
    db()->prepare('UPDATE shop_orders SET mp_preference_id = ?, method = ? WHERE id = ?')
        ->execute([(string) $res['id'], 'mercadopago', (int) $order['id']]);
    return [
        'ok' => true,
        'init_point' => (string) ($res['init_point'] ?? $res['sandbox_init_point'] ?? ''),
    ];
}

function apply_shop_mp_payment(array $paymentData): void
{
    $ext = (string) ($paymentData['external_reference'] ?? '');
    $orderId = 0;
    if (preg_match('/shop-(\d+)/', $ext, $m)) {
        $orderId = (int) $m[1];
    }
    if ($orderId <= 0 && !empty($paymentData['metadata']['order_id'])) {
        $orderId = (int) $paymentData['metadata']['order_id'];
    }
    if ($orderId <= 0) {
        return;
    }
    $status = (string) ($paymentData['status'] ?? '');
    $mpId = (string) ($paymentData['id'] ?? '');
    if ($status === 'approved') {
        db()->prepare('UPDATE shop_orders SET mp_payment_id = ? WHERE id = ?')->execute([$mpId, $orderId]);
        fulfill_shop_order($orderId);
        cart_clear();
    }
}
