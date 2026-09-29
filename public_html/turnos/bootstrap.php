<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);
require_once __DIR__ . '/../includes/session.php';

fluxus_session_start();

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function db_ready(): bool
{
    global $config;
    return is_file($config['db_path']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function json_response(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_admin(): void
{
    if (empty($_SESSION['turnos_admin'])) {
        redirect('admin.php');
    }
}

function weekday_es(int $n): string
{
    return ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][$n] ?? '';
}

function month_es(int $n): string
{
    return ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$n] ?? '';
}

function format_date_es(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt) {
        return $ymd;
    }
    return weekday_es((int) $dt->format('w')) . ' ' . $dt->format('j') . ' de ' . month_es((int) $dt->format('n')) . ' de ' . $dt->format('Y');
}

function format_time_es(string $hm): string
{
    return substr($hm, 0, 5) . ' hs';
}

function therapies(): array
{
    return db()->query('SELECT * FROM therapies WHERE active = 1 ORDER BY sort_order, name')->fetchAll();
}

function weekly_rules(): array
{
    $rows = db()->query('SELECT * FROM weekly_hours WHERE active = 1 ORDER BY weekday, start_time')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['weekday']][] = $row;
    }
    return $map;
}

function blocked_dates(): array
{
    $rows = db()->query('SELECT date FROM blocked_dates')->fetchAll();
    return array_column($rows, 'date');
}

function booked_slots(string $date): array
{
    $stmt = db()->prepare("SELECT time FROM appointments WHERE date = ? AND status IN ('confirmed','pending_deposit')");
    $stmt->execute([$date]);
    return array_column($stmt->fetchAll(), 'time');
}

/**
 * Genera horarios disponibles para una fecha Y-m-d.
 */
function available_slots_for(string $date): array
{
    global $config;
    $today = new DateTimeImmutable('today');
    $day = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$day || $day < $today) {
        return [];
    }
    $horizon = $today->modify('+' . (int) $config['booking_horizon_days'] . ' days');
    if ($day > $horizon) {
        return [];
    }
    if (in_array($date, blocked_dates(), true)) {
        return [];
    }

    $weekday = (int) $day->format('w'); // 0=Dom
    $rules = weekly_rules()[$weekday] ?? [];
    if (!$rules) {
        return [];
    }

    $slotMin = (int) $config['slot_minutes'];
    $booked = booked_slots($date);
    $now = new DateTimeImmutable('now');
    $slots = [];

    foreach ($rules as $rule) {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . substr((string) $rule['start_time'], 0, 5));
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . substr((string) $rule['end_time'], 0, 5));
        if (!$start || !$end) {
            continue;
        }
        for ($t = $start; $t < $end; $t = $t->modify("+{$slotMin} minutes")) {
            $hm = $t->format('H:i');
            if ($day->format('Y-m-d') === $now->format('Y-m-d') && $t <= $now->modify('+2 hours')) {
                continue;
            }
            if (in_array($hm, $booked, true) || in_array($hm . ':00', $booked, true)) {
                continue;
            }
            // normalize booked compare
            $taken = false;
            foreach ($booked as $b) {
                if (substr((string) $b, 0, 5) === $hm) {
                    $taken = true;
                    break;
                }
            }
            if ($taken) {
                continue;
            }
            $slots[] = $hm;
        }
    }

    return array_values(array_unique($slots));
}

function available_days(int $year, int $month): array
{
    global $config;
    $days = [];
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $end = $start->modify('last day of this month');
    $today = new DateTimeImmutable('today');
    $horizon = $today->modify('+' . (int) $config['booking_horizon_days'] . ' days');

    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        if ($d < $today || $d > $horizon) {
            continue;
        }
        $ymd = $d->format('Y-m-d');
        if (available_slots_for($ymd)) {
            $days[] = $ymd;
        }
    }
    return $days;
}

function create_appointment(array $data): array
{
    $therapyId = (int) ($data['therapy_id'] ?? 0);
    $date = trim((string) ($data['date'] ?? ''));
    $time = trim((string) ($data['time'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($therapyId < 1 || $date === '' || $time === '' || $name === '' || $phone === '' || $email === '') {
        throw new RuntimeException('Completá terapia, día, horario, nombre, teléfono y email.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Revisá el email: ahí te mandamos la confirmación y los requisitos.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Fecha inválida.');
    }
    $time = substr($time, 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        throw new RuntimeException('Horario inválido.');
    }

    $slots = available_slots_for($date);
    if (!in_array($time, $slots, true)) {
        throw new RuntimeException('Ese horario ya no está disponible. Elegí otro.');
    }

    $stmt = db()->prepare('SELECT id, name, duration_min FROM therapies WHERE id = ? AND active = 1');
    $stmt->execute([$therapyId]);
    $therapy = $stmt->fetch();
    if (!$therapy) {
        throw new RuntimeException('Terapia no encontrada.');
    }

    $code = strtoupper(bin2hex(random_bytes(4)));
    $token = bin2hex(random_bytes(16));
    $deposit = deposit_amount();

    try {
        $ins = db()->prepare("
          INSERT INTO appointments
            (code, token, therapy_id, date, time, patient_name, patient_phone, patient_email, notes,
             status, deposit_amount, deposit_status)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_deposit', ?, 'pending')
        ");
        $ins->execute([
            $code,
            $token,
            $therapyId,
            $date,
            $time,
            $name,
            $phone,
            $email,
            $notes,
            $deposit,
        ]);
    } catch (Throwable $e) {
        throw new RuntimeException('No se pudo reservar (¿horario tomado?). Probá otro horario.');
    }

    return [
        'id' => (int) db()->lastInsertId(),
        'code' => $code,
        'token' => $token,
        'therapy' => $therapy['name'],
        'date' => $date,
        'time' => $time,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'deposit_amount' => $deposit,
        'deposit_status' => 'pending',
        'pay_url' => 'pay.php?token=' . urlencode($token),
    ];
}

/**
 * Turno cargado a mano por el admin (reservado por WhatsApp, teléfono, en persona).
 * Queda confirmado; la seña es opcional y se ofrece en el mail con un QR.
 * Puede usar un horario fuera de la grilla, pero nunca uno ya tomado.
 */
function create_manual_appointment(array $data): array
{
    $therapyId = (int) ($data['therapy_id'] ?? 0);
    $date = trim((string) ($data['date'] ?? ''));
    $time = substr(trim((string) ($data['time'] ?? '')), 0, 5);
    $name = trim((string) ($data['name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $deposit = array_key_exists('deposit_amount', $data) ? (int) $data['deposit_amount'] : deposit_amount();

    if ($therapyId < 1 || $date === '' || $time === '' || $name === '' || $email === '') {
        throw new RuntimeException('Completá nombre, email, terapia, día y horario.');
    }
    if (mb_strlen($name) > 120 || mb_strlen($phone) > 40 || strlen($email) > 190 || mb_strlen($notes) > 1000) {
        throw new RuntimeException('Algún dato es demasiado largo.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El email no es válido.');
    }
    if ($phone !== '' && preg_match_all('/\d/', $phone) < 6) {
        throw new RuntimeException('Revisá el teléfono (o dejalo vacío).');
    }
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date) {
        throw new RuntimeException('Fecha inválida.');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || $time < '06:00' || $time > '22:00') {
        throw new RuntimeException('Elegí un horario entre las 06:00 y las 22:00.');
    }
    $when = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$when || $when < new DateTimeImmutable('now')) {
        throw new RuntimeException('Ese día y horario ya pasaron.');
    }
    if ($day > new DateTimeImmutable('today +1 year')) {
        throw new RuntimeException('La fecha está a más de un año.');
    }
    if ($deposit < 0 || $deposit > 10000000) {
        throw new RuntimeException('Monto de seña inválido.');
    }

    $stmt = db()->prepare('SELECT id, name FROM therapies WHERE id = ? AND active = 1');
    $stmt->execute([$therapyId]);
    $therapy = $stmt->fetch();
    if (!$therapy) {
        throw new RuntimeException('Terapia no encontrada.');
    }

    $holder = static function () use ($date, $time): string|false {
        $stmt = db()->prepare("
          SELECT patient_name FROM appointments
          WHERE date = ? AND substr(time, 1, 5) = ? AND status IN ('confirmed', 'pending_deposit')
          LIMIT 1
        ");
        $stmt->execute([$date, $time]);
        return $stmt->fetchColumn();
    };
    $taken = static fn (string|false $who): RuntimeException => new RuntimeException(
        'Ya hay un turno el ' . format_date_es($date) . ' a las ' . format_time_es($time)
        . ($who !== false ? ' (' . $who . ')' : '') . '. Elegí otro horario o cancelá ese turno primero.'
    );
    $who = $holder();
    if ($who !== false) {
        throw $taken($who);
    }

    $code = strtoupper(bin2hex(random_bytes(4)));
    $token = bin2hex(random_bytes(16));
    $depositStatus = $deposit > 0 ? 'optional' : 'none';
    try {
        db()->prepare("
          INSERT INTO appointments
            (code, token, therapy_id, date, time, patient_name, patient_phone, patient_email, notes,
             status, deposit_amount, deposit_status, source)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, 'manual')
        ")->execute([$code, $token, $therapyId, $date, $time, $name, $phone, $email, $notes, $deposit, $depositStatus]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            throw $taken($holder());
        }
        throw new RuntimeException('No se pudo guardar el turno.');
    }

    return [
        'id' => (int) db()->lastInsertId(),
        'code' => $code,
        'token' => $token,
        'therapy' => $therapy['name'],
        'date' => $date,
        'time' => $time,
        'deposit_amount' => $deposit,
        'deposit_status' => $depositStatus,
    ];
}

function appointment_by_token(string $token, bool $confirmedOnly = true): ?array
{
    $sql = "
      SELECT a.*, t.name AS therapy_name, t.duration_min
      FROM appointments a
      JOIN therapies t ON t.id = a.therapy_id
      WHERE a.token = ?
    ";
    if ($confirmedOnly) {
        $sql .= " AND a.status = 'confirmed'";
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function migrate_turnos_schema(): void
{
    if (!db_ready()) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $tableExists = static function (PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetch();
    };
    $cols = static function (PDO $pdo, string $table): array {
        return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
    };

    $apptCols = $cols($pdo, 'appointments');
    if (!in_array('deposit_amount', $apptCols, true)) {
        $pdo->exec('ALTER TABLE appointments ADD COLUMN deposit_amount INTEGER NOT NULL DEFAULT 15000');
    }
    if (!in_array('deposit_status', $apptCols, true)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN deposit_status TEXT NOT NULL DEFAULT 'paid'");
    }
    if (!in_array('deposit_paid_at', $apptCols, true)) {
        $pdo->exec('ALTER TABLE appointments ADD COLUMN deposit_paid_at TEXT DEFAULT NULL');
    }
    if (!in_array('mail_sent_at', $apptCols, true)) {
        $pdo->exec('ALTER TABLE appointments ADD COLUMN mail_sent_at TEXT DEFAULT NULL');
    }
    // web = reservado online (seña obligatoria) · manual = cargado por el admin (seña opcional)
    if (!in_array('source', $apptCols, true)) {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN source TEXT NOT NULL DEFAULT 'web'");
    }
    // Consentimiento informado aceptado online (consentimiento.php). consent_text guarda el texto exacto aceptado.
    foreach (['consent_accepted_at', 'consent_name', 'consent_dni', 'consent_ip', 'consent_text'] as $col) {
        if (!in_array($col, $apptCols, true)) {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN ' . $col . ' TEXT DEFAULT NULL');
        }
    }

    // Un solo turno activo por día y horario: dos reservas simultáneas no pueden tomar el mismo.
    try {
        $pdo->exec("
          CREATE UNIQUE INDEX IF NOT EXISTS uniq_appointments_active_slot
          ON appointments(date, substr(time, 1, 5))
          WHERE status IN ('confirmed', 'pending_deposit')
        ");
    } catch (Throwable $e) {
        // Si ya hay turnos duplicados cargados, el índice no se puede crear hasta que se cancele uno.
    }

    if (!$tableExists($pdo, 'deposit_payments')) {
        $pdo->exec("
          CREATE TABLE deposit_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appointment_id INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            method TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            transfer_ref TEXT NOT NULL DEFAULT '',
            mp_preference_id TEXT NOT NULL DEFAULT '',
            mp_payment_id TEXT NOT NULL DEFAULT '',
            mp_status TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at TEXT DEFAULT NULL,
            FOREIGN KEY(appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
          )
        ");
    }

    if (!$tableExists($pdo, 'deposit_settings')) {
        $pdo->exec("CREATE TABLE deposit_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
        global $config;
        $dep = is_array($config['deposit'] ?? null) ? $config['deposit'] : [];
        $seed = [
            'amount' => (string) ((int) ($dep['amount'] ?? 15000)),
            'transfer_holder' => (string) ($dep['transfer_holder'] ?? 'Michael Grandon'),
            'transfer_bank' => (string) ($dep['transfer_bank'] ?? 'Mercado Pago'),
            'transfer_alias' => (string) ($dep['transfer_alias'] ?? 'michael.grandon.mp'),
            'transfer_cbu' => (string) ($dep['transfer_cbu'] ?? ''),
            'transfer_note' => (string) ($dep['transfer_note'] ?? 'Concepto: Seña turno + tu nombre + fecha.'),
            'currency' => (string) ($dep['currency'] ?? 'ARS'),
        ];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO deposit_settings (key, value) VALUES (?, ?)');
        foreach ($seed as $k => $v) {
            $ins->execute([$k, $v]);
        }
    }

    // Horario 2026-09: lunes a sábado de 8 a 20 (último turno 19 hs), turnos de 1 hora.
    $ver = $pdo->query("SELECT value FROM deposit_settings WHERE key = 'schedule_version'")->fetchColumn();
    if ($ver !== '2') {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM weekly_hours');
        $hours = $pdo->prepare("INSERT INTO weekly_hours (weekday, start_time, end_time) VALUES (?, '08:00', '20:00')");
        foreach ([1, 2, 3, 4, 5, 6] as $wd) {
            $hours->execute([$wd]);
        }
        $pdo->prepare("INSERT OR REPLACE INTO deposit_settings (key, value) VALUES ('schedule_version', '2')")->execute();
        $pdo->commit();
    }

    // Consentimiento 2026-09 (acupuntura, con datos del paciente): un texto viejo guardado desde el admin taparía el
    // nuevo por defecto. Se guarda como copia en consentimiento_text_anterior y se vuelve al de por defecto.
    $consentVer = $pdo->query("SELECT value FROM deposit_settings WHERE key = 'consent_version'")->fetchColumn();
    if ($consentVer !== '2') {
        $pdo->beginTransaction();
        $old = $pdo->query("SELECT value FROM deposit_settings WHERE key = 'consentimiento_text'")->fetchColumn();
        if (is_string($old) && trim($old) !== '' && !str_contains($old, '{nombre}')) {
            $pdo->prepare("INSERT OR REPLACE INTO deposit_settings (key, value) VALUES ('consentimiento_text_anterior', ?)")->execute([$old]);
            $pdo->prepare("UPDATE deposit_settings SET value = '' WHERE key = 'consentimiento_text'")->execute();
        }
        $pdo->prepare("INSERT OR REPLACE INTO deposit_settings (key, value) VALUES ('consent_version', '2')")->execute();
        $pdo->commit();
    }
}

if (db_ready()) {
    require_once __DIR__ . '/includes/deposit.php';
    require_once __DIR__ . '/includes/turno_docs.php';
    migrate_turnos_schema();
}
