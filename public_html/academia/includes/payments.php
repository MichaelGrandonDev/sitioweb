<?php

declare(strict_types=1);

function money_ars(int $amount): string
{
    return '$' . number_format($amount, 0, ',', '.');
}

function period_label(string $ym): string
{
    $months = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
        '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
        '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre',
    ];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    return ($months[$m[2]] ?? $m[2]) . ' ' . $m[1];
}

function payment_cfg(): array
{
    global $config;
    $base = is_array($config['payments'] ?? null) ? $config['payments'] : [];
    try {
        if (!db_ready()) {
            return $base;
        }
        $rows = db()->query('SELECT key, value FROM payment_settings')->fetchAll();
        foreach ($rows as $row) {
            $base[(string) $row['key']] = (string) $row['value'];
        }
    } catch (Throwable $e) {
        // tabla aún no migrada
    }
    return $base;
}

function payment_setting_set(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO payment_settings (key, value) VALUES (?, ?)')
        ->execute([$key, $value]);
}

function payment_settings_save(array $data): void
{
    $allowed = [
        'transfer_holder',
        'transfer_bank',
        'transfer_cbu',
        'transfer_alias',
        'transfer_note',
        'default_monthly_amount',
        'currency',
        'inscription_amount',
        'admin_notify_email',
    ];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        payment_setting_set($key, trim((string) $data[$key]));
    }
}

function default_fee_plan(): ?array
{
    $stmt = db()->query('SELECT * FROM fee_plans WHERE active = 1 ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch();
    return $row ?: null;
}

function invoice_status_label(string $status): string
{
    return match ($status) {
        'paid' => 'Pagada',
        'pending' => 'Pendiente',
        'overdue' => 'Vencida',
        'waived' => 'Bonificada',
        default => $status,
    };
}

function payment_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Aprobado',
        'pending' => 'En revisión',
        'rejected' => 'Rechazado',
        default => $status,
    };
}

/**
 * Genera cuotas del mes para alumnos con fee_active=1.
 * @return int cantidad de cuotas nuevas
 */
function generate_monthly_invoices(?string $periodYm = null): int
{
    $periodYm = $periodYm ?: date('Y-m');
    $due = $periodYm . '-10';
    $plan = default_fee_plan();
    $defaultAmount = $plan ? (int) $plan['amount'] : (int) (payment_cfg()['default_monthly_amount'] ?? 25000);
    $planId = $plan ? (int) $plan['id'] : null;

    $students = db()->query("
      SELECT id, fee_plan_id FROM users
      WHERE role = 'student' AND active = 1 AND fee_active = 1
    ")->fetchAll();

    $exists = db()->prepare('SELECT id FROM fee_invoices WHERE user_id = ? AND period_ym = ? LIMIT 1');
    $ins = db()->prepare("
      INSERT INTO fee_invoices (user_id, plan_id, period_ym, label, amount, status, due_date)
      VALUES (?, ?, ?, ?, ?, 'pending', ?)
    ");
    $getPlan = db()->prepare('SELECT amount FROM fee_plans WHERE id = ? LIMIT 1');

    $created = 0;
    foreach ($students as $s) {
        $uid = (int) $s['id'];
        $exists->execute([$uid, $periodYm]);
        if ($exists->fetch()) {
            continue;
        }
        $amount = $defaultAmount;
        $usePlan = $planId;
        if (!empty($s['fee_plan_id'])) {
            $getPlan->execute([(int) $s['fee_plan_id']]);
            $p = $getPlan->fetch();
            if ($p) {
                $amount = (int) $p['amount'];
                $usePlan = (int) $s['fee_plan_id'];
            }
        }
        $ins->execute([$uid, $usePlan, $periodYm, 'Cuota ' . period_label($periodYm), $amount, $due]);
        $created++;
    }
    return $created;
}

function mark_invoice_paid(int $invoiceId, string $note = ''): void
{
    db()->prepare("UPDATE fee_invoices SET status = 'paid', notes = CASE WHEN ? = '' THEN notes ELSE ? END WHERE id = ?")
        ->execute([$note, $note, $invoiceId]);
}

function ensure_invoice_for_user(int $userId, ?string $periodYm = null): ?array
{
    $periodYm = $periodYm ?: date('Y-m');
    $stmt = db()->prepare('SELECT * FROM fee_invoices WHERE user_id = ? AND period_ym = ? LIMIT 1');
    $stmt->execute([$userId, $periodYm]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $user = db()->prepare('SELECT id, fee_active, fee_plan_id FROM users WHERE id = ? AND role = ? LIMIT 1');
    $user->execute([$userId, 'student']);
    $u = $user->fetch();
    if (!$u || !(int) $u['fee_active']) {
        return null;
    }
    generate_monthly_invoices($periodYm);
    $stmt->execute([$userId, $periodYm]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mp_enabled(): bool
{
    $token = trim((string) (payment_cfg()['mp_access_token'] ?? ''));
    return $token !== '' && !str_starts_with($token, 'TEST_REPLACE');
}

function mp_api(string $method, string $path, ?array $body = null): array
{
    $token = trim((string) (payment_cfg()['mp_access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Mercado Pago no configurado'];
    }
    $url = 'https://api.mercadopago.com' . $path;
    $ch = curl_init($url);
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
        return ['ok' => false, 'error' => 'Respuesta inválida', 'http' => $code, 'raw' => $raw];
    }
    $data['ok'] = $code >= 200 && $code < 300;
    $data['http'] = $code;
    return $data;
}

function create_mp_preference(array $invoice, array $user): array
{
    global $config;
    $base = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/');
    $academia = $base . '/academia';
    $invoiceId = (int) $invoice['id'];
    $amount = (int) $invoice['amount'];
    $title = (string) ($invoice['label'] ?: ('Cuota ' . period_label((string) $invoice['period_ym'])));

    $payload = [
        'items' => [[
            'id' => 'invoice-' . $invoiceId,
            'title' => $title,
            'quantity' => 1,
            'currency_id' => (string) (payment_cfg()['currency'] ?? 'ARS'),
            'unit_price' => $amount,
        ]],
        'payer' => [
            'name' => (string) $user['name'],
            'email' => (string) ($user['email'] ?? ''),
        ],
        'external_reference' => 'inv-' . $invoiceId,
        'metadata' => [
            'invoice_id' => $invoiceId,
            'user_id' => (int) $user['id'],
            'period_ym' => (string) $invoice['period_ym'],
        ],
        'back_urls' => [
            'success' => $academia . '/payments_return.php?status=success&invoice_id=' . $invoiceId,
            'failure' => $academia . '/payments_return.php?status=failure&invoice_id=' . $invoiceId,
            'pending' => $academia . '/payments_return.php?status=pending&invoice_id=' . $invoiceId,
        ],
        'auto_return' => 'approved',
        'notification_url' => $academia . '/payments_webhook.php',
        'statement_descriptor' => 'ACADEMIAFLUXUS',
    ];

    $res = mp_api('POST', '/checkout/preferences', $payload);
    if (empty($res['ok']) || empty($res['id'])) {
        return ['ok' => false, 'error' => $res['message'] ?? $res['error'] ?? 'No se pudo crear el pago'];
    }

    db()->prepare("
      INSERT INTO payments (invoice_id, user_id, amount, method, status, mp_preference_id)
      VALUES (?, ?, ?, 'mercadopago', 'pending', ?)
    ")->execute([$invoiceId, (int) $user['id'], $amount, (string) $res['id']]);

    return [
        'ok' => true,
        'preference_id' => (string) $res['id'],
        'init_point' => (string) ($res['init_point'] ?? $res['sandbox_init_point'] ?? ''),
    ];
}

function apply_mp_payment(array $paymentData): void
{
    $ext = (string) ($paymentData['external_reference'] ?? '');
    $invoiceId = 0;
    if (preg_match('/inv-(\d+)/', $ext, $m)) {
        $invoiceId = (int) $m[1];
    }
    if ($invoiceId <= 0 && !empty($paymentData['metadata']['invoice_id'])) {
        $invoiceId = (int) $paymentData['metadata']['invoice_id'];
    }
    if ($invoiceId <= 0) {
        return;
    }

    $status = (string) ($paymentData['status'] ?? '');
    $mpId = (string) ($paymentData['id'] ?? '');
    $amount = (int) round((float) ($paymentData['transaction_amount'] ?? 0));

    $inv = db()->prepare('SELECT * FROM fee_invoices WHERE id = ? LIMIT 1');
    $inv->execute([$invoiceId]);
    $invoice = $inv->fetch();
    if (!$invoice) {
        return;
    }

    $payStatus = match ($status) {
        'approved' => 'approved',
        'rejected', 'cancelled' => 'rejected',
        default => 'pending',
    };

    // Upsert payment row by mp_payment_id
    $find = db()->prepare('SELECT id FROM payments WHERE mp_payment_id = ? LIMIT 1');
    $find->execute([$mpId]);
    $existing = $find->fetch();
    if ($existing) {
        db()->prepare('UPDATE payments SET status = ?, mp_status = ?, amount = ? WHERE id = ?')
            ->execute([$payStatus, $status, $amount ?: (int) $invoice['amount'], (int) $existing['id']]);
    } else {
        db()->prepare("
          INSERT INTO payments (invoice_id, user_id, amount, method, status, mp_payment_id, mp_status, confirmed_at)
          VALUES (?, ?, ?, 'mercadopago', ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            (int) $invoice['user_id'],
            $amount ?: (int) $invoice['amount'],
            $payStatus,
            $mpId,
            $status,
            $payStatus === 'approved' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    if ($payStatus === 'approved') {
        mark_invoice_paid($invoiceId, 'Mercado Pago #' . $mpId);
    }
}
