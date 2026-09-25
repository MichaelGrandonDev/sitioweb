<?php

declare(strict_types=1);

function money_ars(int $amount): string
{
    return '$' . number_format($amount, 0, ',', '.');
}

function deposit_cfg(): array
{
    global $config;
    $base = is_array($config['deposit'] ?? null) ? $config['deposit'] : [];
    try {
        if (!db_ready()) {
            return $base;
        }
        $rows = db()->query('SELECT key, value FROM deposit_settings')->fetchAll();
        foreach ($rows as $row) {
            $base[(string) $row['key']] = (string) $row['value'];
        }
    } catch (Throwable $e) {
        // sin migrar
    }
    if (isset($base['amount'])) {
        $base['amount'] = (int) $base['amount'];
    }
    return $base;
}

function deposit_setting_set(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO deposit_settings (key, value) VALUES (?, ?)')
        ->execute([$key, $value]);
}

function deposit_amount(): int
{
    $cfg = deposit_cfg();
    $n = (int) ($cfg['amount'] ?? 15000);
    return $n > 0 ? $n : 15000;
}

function deposit_mp_enabled(): bool
{
    $token = trim((string) (deposit_cfg()['mp_access_token'] ?? ''));
    return $token !== '';
}

function deposit_mp_api(string $method, string $path, ?array $body = null): array
{
    $token = trim((string) (deposit_cfg()['mp_access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Mercado Pago no configurado'];
    }
    $ch = curl_init('https://api.mercadopago.com' . $path);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . bin2hex(random_bytes(8)),
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => $err ?: 'Error de red', 'http' => $code];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Respuesta inválida', 'http' => $code];
    }
    $data['ok'] = $code >= 200 && $code < 300;
    $data['http'] = $code;
    return $data;
}

function mark_deposit_paid(int $appointmentId, string $note = ''): void
{
    db()->prepare("
      UPDATE appointments
      SET status = 'confirmed',
          deposit_status = 'paid',
          deposit_paid_at = COALESCE(deposit_paid_at, ?)
      WHERE id = ?
    ")->execute([date('Y-m-d H:i:s'), $appointmentId]);
    if ($note !== '') {
        db()->prepare('UPDATE deposit_payments SET note = ? WHERE appointment_id = ? AND note = \'\'')
            ->execute([$note, $appointmentId]);
    }
}

function create_deposit_mp_preference(array $appt): array
{
    global $config;
    $base = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/');
    $turnos = $base . '/turnos';
    $amount = (int) ($appt['deposit_amount'] ?: deposit_amount());
    $title = 'Seña turno ' . ($appt['therapy_name'] ?? 'FluxusTerapia') . ' · ' . ($appt['date'] ?? '');

    $payload = [
        'items' => [[
            'id' => 'deposit-' . (int) $appt['id'],
            'title' => $title,
            'quantity' => 1,
            'currency_id' => (string) (deposit_cfg()['currency'] ?? 'ARS'),
            'unit_price' => $amount,
        ]],
        'payer' => [
            'name' => (string) $appt['patient_name'],
            'email' => (string) ($appt['patient_email'] ?? ''),
        ],
        'external_reference' => 'dep-' . (int) $appt['id'],
        'metadata' => [
            'appointment_id' => (int) $appt['id'],
            'token' => (string) $appt['token'],
        ],
        'back_urls' => [
            'success' => $turnos . '/deposit_return.php?status=success&token=' . urlencode((string) $appt['token']),
            'failure' => $turnos . '/deposit_return.php?status=failure&token=' . urlencode((string) $appt['token']),
            'pending' => $turnos . '/deposit_return.php?status=pending&token=' . urlencode((string) $appt['token']),
        ],
        'auto_return' => 'approved',
        'notification_url' => $turnos . '/deposit_webhook.php',
        'statement_descriptor' => 'SEÑA FLUXUS',
    ];

    $res = deposit_mp_api('POST', '/checkout/preferences', $payload);
    if (empty($res['ok']) || empty($res['id'])) {
        return ['ok' => false, 'error' => $res['message'] ?? $res['error'] ?? 'No se pudo crear el pago'];
    }

    db()->prepare("
      INSERT INTO deposit_payments (appointment_id, amount, method, status, mp_preference_id)
      VALUES (?, ?, 'mercadopago', 'pending', ?)
    ")->execute([(int) $appt['id'], $amount, (string) $res['id']]);

    return [
        'ok' => true,
        'preference_id' => (string) $res['id'],
        'init_point' => (string) ($res['init_point'] ?? $res['sandbox_init_point'] ?? ''),
    ];
}

function apply_deposit_mp_payment(array $paymentData): void
{
    $ext = (string) ($paymentData['external_reference'] ?? '');
    $apptId = 0;
    if (preg_match('/dep-(\d+)/', $ext, $m)) {
        $apptId = (int) $m[1];
    }
    if ($apptId <= 0 && !empty($paymentData['metadata']['appointment_id'])) {
        $apptId = (int) $paymentData['metadata']['appointment_id'];
    }
    if ($apptId <= 0) {
        return;
    }

    $status = (string) ($paymentData['status'] ?? '');
    $mpId = (string) ($paymentData['id'] ?? '');
    $amount = (int) round((float) ($paymentData['transaction_amount'] ?? 0));
    $payStatus = match ($status) {
        'approved' => 'approved',
        'rejected', 'cancelled' => 'rejected',
        default => 'pending',
    };

    $find = db()->prepare('SELECT id FROM deposit_payments WHERE mp_payment_id = ? LIMIT 1');
    $find->execute([$mpId]);
    $existing = $find->fetch();
    if ($existing) {
        db()->prepare('UPDATE deposit_payments SET status = ?, mp_status = ?, amount = ? WHERE id = ?')
            ->execute([$payStatus, $status, $amount ?: deposit_amount(), (int) $existing['id']]);
    } else {
        db()->prepare("
          INSERT INTO deposit_payments (appointment_id, amount, method, status, mp_payment_id, mp_status, confirmed_at)
          VALUES (?, ?, 'mercadopago', ?, ?, ?, ?)
        ")->execute([
            $apptId,
            $amount ?: deposit_amount(),
            $payStatus,
            $mpId,
            $status,
            $payStatus === 'approved' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    if ($payStatus === 'approved') {
        mark_deposit_paid($apptId, 'Mercado Pago #' . $mpId);
    }
}
