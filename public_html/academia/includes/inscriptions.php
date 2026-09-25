<?php

declare(strict_types=1);

function inscription_amount(): int
{
    return max(0, (int) (payment_cfg()['inscription_amount'] ?? 0));
}

function inscription_is_free(): bool
{
    return inscription_amount() <= 0;
}

function unique_username_from_email(string $email): string
{
    $base = strtolower((string) preg_replace('~[^a-zA-Z0-9]+~', '.', explode('@', $email)[0] ?? 'alumno'));
    $base = trim($base, '.') ?: 'alumno';
    $candidate = $base;
    $i = 2;
    while (true) {
        $check = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
        $candidate = $base . $i;
        $i++;
    }
}

function generate_temp_password(int $bytes = 4): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Crea o reutiliza cuenta alumno. Devuelve user + password en claro si se creó/reseteó.
 *
 * @return array{user:array, password:?string, created:bool}
 */
function ensure_student_account(string $name, string $email, bool $resetPasswordIfNew = true): array
{
    $email = strtolower(trim($email));
    $name = trim($name);
    $stmt = db()->prepare("SELECT * FROM users WHERE lower(email) = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    if ($existing) {
        return ['user' => $existing, 'password' => null, 'created' => false];
    }
    $username = unique_username_from_email($email);
    $password = generate_temp_password();
    db()->prepare("
      INSERT INTO users (username, password_hash, name, email, role, active)
      VALUES (?, ?, ?, ?, 'student', 0)
    ")->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $email]);
    $id = (int) db()->lastInsertId();
    $row = db()->prepare('SELECT * FROM users WHERE id = ?');
    $row->execute([$id]);
    return ['user' => $row->fetch(), 'password' => $password, 'created' => true];
}

function approve_inscription(int $inscriptionId): array
{
    $stmt = db()->prepare('SELECT * FROM inscriptions WHERE id = ? LIMIT 1');
    $stmt->execute([$inscriptionId]);
    $ins = $stmt->fetch();
    if (!$ins) {
        return ['ok' => false, 'error' => 'Inscripción no encontrada'];
    }
    if ($ins['status'] === 'approved') {
        return ['ok' => true, 'already' => true];
    }
    if (!in_array($ins['status'], ['pending_approval', 'pending_payment'], true) && $ins['status'] !== 'paid') {
        // allow approve from pending_approval primarily
    }
    if ($ins['status'] === 'pending_payment') {
        return ['ok' => false, 'error' => 'Todavía falta el pago de la inscripción'];
    }

    $account = ensure_student_account((string) $ins['name'], (string) $ins['email'], true);
    $user = $account['user'];
    $password = $account['password'];
    if ($password === null) {
        $password = generate_temp_password();
        db()->prepare('UPDATE users SET password_hash = ?, active = 1, name = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (string) $ins['name'], (int) $user['id']]);
    } else {
        db()->prepare('UPDATE users SET active = 1 WHERE id = ?')->execute([(int) $user['id']]);
    }

    $courseId = (int) ($ins['course_id'] ?? 0);
    if ($courseId > 0) {
        db()->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)')
            ->execute([(int) $user['id'], $courseId]);
    }

    db()->prepare("UPDATE inscriptions SET status = 'approved', user_id = ?, approved_at = ? WHERE id = ?")
        ->execute([(int) $user['id'], date('Y-m-d H:i:s'), $inscriptionId]);

    $mailOk = send_welcome_email((string) $ins['email'], (string) $ins['name'], (string) $user['username'], $password);
    return ['ok' => true, 'mail' => $mailOk, 'user' => $user, 'password' => $password];
}

function mark_inscription_awaiting_approval(int $id): void
{
    db()->prepare("UPDATE inscriptions SET status = 'pending_approval' WHERE id = ? AND status IN ('pending_payment','pending_approval')")
        ->execute([$id]);
    $stmt = db()->prepare("
      SELECT i.*, c.title AS course_title
      FROM inscriptions i
      LEFT JOIN courses c ON c.id = i.course_id
      WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        send_admin_inscription_notice($row);
    }
}

function apply_inscription_mp_payment(array $paymentData): void
{
    $ext = (string) ($paymentData['external_reference'] ?? '');
    $id = 0;
    if (preg_match('/insc-(\d+)/', $ext, $m)) {
        $id = (int) $m[1];
    }
    if ($id <= 0 && !empty($paymentData['metadata']['inscription_id'])) {
        $id = (int) $paymentData['metadata']['inscription_id'];
    }
    if ($id <= 0 || (string) ($paymentData['status'] ?? '') !== 'approved') {
        return;
    }
    db()->prepare('UPDATE inscriptions SET mp_payment_id = ?, method = ? WHERE id = ?')
        ->execute([(string) ($paymentData['id'] ?? ''), 'mercadopago', $id]);
    mark_inscription_awaiting_approval($id);
}
