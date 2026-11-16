<?php

declare(strict_types=1);

/**
 * Recordatorios por mail a alumnos con clases pendientes (sin contar bonus).
 * El admin activa/pausa por alumno; el cron respeta intervalo y automatización global.
 */

const REMINDER_DEFAULT_INTERVAL_DAYS = 7;
const REMINDER_DEFAULT_SUBJECT = 'Recordatorio · tenés clases esperándote en AcademiaFluxus';
const REMINDER_DEFAULT_INTRO = 'Te escribimos para recordarte que ya tenés acceso al campus de AcademiaFluxus y que todavía hay clases por ver.';
const REMINDER_CRON_MAX_SENDS = 40;

function reminders_migrate(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS student_reminders (
        user_id INTEGER PRIMARY KEY,
        enabled INTEGER NOT NULL DEFAULT 0,
        last_sent_at TEXT DEFAULT NULL,
        next_due_at TEXT DEFAULT NULL,
        stop_reason TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS reminder_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        status TEXT NOT NULL,
        detail TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");

    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='payment_settings'")->fetch()) {
        return;
    }
    $ins = $pdo->prepare('INSERT OR IGNORE INTO payment_settings (key, value) VALUES (?, ?)');
    $ins->execute(['reminder_global_enabled', '0']);
    $ins->execute(['reminder_interval_days', (string) REMINDER_DEFAULT_INTERVAL_DAYS]);
    $ins->execute(['reminder_subject', REMINDER_DEFAULT_SUBJECT]);
    $ins->execute(['reminder_intro', REMINDER_DEFAULT_INTRO]);
}

/** @return array{global_enabled:bool, interval_days:int, subject:string, intro:string} */
function reminder_settings(): array
{
    $cfg = function_exists('payment_cfg') ? payment_cfg() : [];
    $days = (int) ($cfg['reminder_interval_days'] ?? REMINDER_DEFAULT_INTERVAL_DAYS);
    if ($days < 1) {
        $days = REMINDER_DEFAULT_INTERVAL_DAYS;
    }
    if ($days > 60) {
        $days = 60;
    }
    $subject = trim((string) ($cfg['reminder_subject'] ?? ''));
    $intro = trim((string) ($cfg['reminder_intro'] ?? ''));
    return [
        'global_enabled' => ((string) ($cfg['reminder_global_enabled'] ?? '0')) === '1',
        'interval_days' => $days,
        'subject' => $subject !== '' ? $subject : REMINDER_DEFAULT_SUBJECT,
        'intro' => $intro !== '' ? $intro : REMINDER_DEFAULT_INTRO,
    ];
}

function reminder_settings_save(array $data): void
{
    payment_setting_set('reminder_global_enabled', !empty($data['global_enabled']) ? '1' : '0');
    $days = max(1, min(60, (int) ($data['interval_days'] ?? REMINDER_DEFAULT_INTERVAL_DAYS)));
    payment_setting_set('reminder_interval_days', (string) $days);
    $subject = trim((string) ($data['subject'] ?? ''));
    $intro = trim((string) ($data['intro'] ?? ''));
    payment_setting_set('reminder_subject', $subject !== '' ? $subject : REMINDER_DEFAULT_SUBJECT);
    payment_setting_set('reminder_intro', $intro !== '' ? $intro : REMINDER_DEFAULT_INTRO);
}

/** @return array{user_id:int, enabled:int, last_sent_at:?string, next_due_at:?string, stop_reason:string} */
function reminder_row(int $userId): array
{
    $st = db()->prepare('SELECT * FROM student_reminders WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    if ($row) {
        return [
            'user_id' => (int) $row['user_id'],
            'enabled' => (int) $row['enabled'],
            'last_sent_at' => $row['last_sent_at'] !== null ? (string) $row['last_sent_at'] : null,
            'next_due_at' => $row['next_due_at'] !== null ? (string) $row['next_due_at'] : null,
            'stop_reason' => (string) ($row['stop_reason'] ?? ''),
        ];
    }
    return [
        'user_id' => $userId,
        'enabled' => 0,
        'last_sent_at' => null,
        'next_due_at' => null,
        'stop_reason' => '',
    ];
}

function reminder_set_enabled(int $userId, bool $enabled, string $stopReason = ''): void
{
    $now = gmdate('Y-m-d H:i:s');
    $reason = $enabled ? '' : ($stopReason !== '' ? $stopReason : 'admin');
    db()->prepare("
      INSERT INTO student_reminders (user_id, enabled, stop_reason, updated_at)
      VALUES (?, ?, ?, ?)
      ON CONFLICT(user_id) DO UPDATE SET
        enabled = excluded.enabled,
        stop_reason = excluded.stop_reason,
        updated_at = excluded.updated_at
    ")->execute([$userId, $enabled ? 1 : 0, $reason, $now]);
}

function reminder_mark_completed(int $userId): void
{
    $now = gmdate('Y-m-d H:i:s');
    db()->prepare("
      INSERT INTO student_reminders (user_id, enabled, stop_reason, updated_at)
      VALUES (?, 0, 'completed', ?)
      ON CONFLICT(user_id) DO UPDATE SET
        enabled = 0,
        stop_reason = 'completed',
        updated_at = excluded.updated_at
    ")->execute([$userId, $now]);
}

function reminder_mark_sent(int $userId, int $intervalDays): void
{
    $now = gmdate('Y-m-d H:i:s');
    $next = gmdate('Y-m-d H:i:s', time() + max(1, $intervalDays) * 86400);
    db()->prepare("
      INSERT INTO student_reminders (user_id, enabled, last_sent_at, next_due_at, stop_reason, updated_at)
      VALUES (?, 1, ?, ?, '', ?)
      ON CONFLICT(user_id) DO UPDATE SET
        last_sent_at = excluded.last_sent_at,
        next_due_at = excluded.next_due_at,
        updated_at = excluded.updated_at
    ")->execute([$userId, $now, $next, $now]);
}

function reminder_log(?int $userId, string $status, string $detail = ''): void
{
    // Sin email ni nombre: solo id y motivo corto
    $safe = preg_replace('/[^\p{L}\p{N}\s\-_,.:#()]/u', '', $detail) ?? '';
    $safe = trim($safe);
    $safe = function_exists('mb_substr') ? mb_substr($safe, 0, 180) : substr($safe, 0, 180);
    db()->prepare('INSERT INTO reminder_logs (user_id, status, detail) VALUES (?, ?, ?)')
        ->execute([$userId, $status, $safe]);
}

/**
 * Clases no vistas del alumno (excluye bonus) en cursos publicados asignados.
 *
 * @return array<int, array{id:int, title:string, course_title:string}>
 */
function reminder_pending_classes(int $userId): array
{
    $courses = db()->prepare("
      SELECT c.id, c.title
      FROM enrollments e
      JOIN courses c ON c.id = e.course_id
      WHERE e.user_id = ? AND c.published = 1
      ORDER BY c.sort_order, c.id
    ");
    $courses->execute([$userId]);
    $doneIds = recording_done_ids($userId);
    $pending = [];
    foreach ($courses->fetchAll() as $course) {
        $plan = recording_plan(recordings_of_course((int) $course['id']), $doneIds, false);
        foreach ($plan['classes'] as $row) {
            if (!empty($row['done'])) {
                continue;
            }
            $pending[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'course_title' => (string) $course['title'],
            ];
        }
    }
    return $pending;
}

/**
 * Lógica pura: ¿corresponde enviar un recordatorio ahora?
 *
 * @param array{
 *   global_enabled?:bool,
 *   enabled?:bool,
 *   email_ok?:bool,
 *   pending_count?:int,
 *   stop_reason?:string,
 *   last_sent_at?:?string,
 *   interval_days?:int,
 *   now?:int
 * } $ctx
 * @return array{ok:bool, reason:string}
 */
function reminder_should_send(array $ctx): array
{
    if (empty($ctx['global_enabled'])) {
        return ['ok' => false, 'reason' => 'global_off'];
    }
    if (empty($ctx['enabled'])) {
        $stop = (string) ($ctx['stop_reason'] ?? '');
        if ($stop === 'completed') {
            return ['ok' => false, 'reason' => 'completed'];
        }
        if ($stop === 'admin' || $stop !== '') {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        return ['ok' => false, 'reason' => 'disabled'];
    }
    if (empty($ctx['email_ok'])) {
        return ['ok' => false, 'reason' => 'no_email'];
    }
    $pending = (int) ($ctx['pending_count'] ?? 0);
    if ($pending < 1) {
        return ['ok' => false, 'reason' => 'completed'];
    }
    $interval = max(1, (int) ($ctx['interval_days'] ?? REMINDER_DEFAULT_INTERVAL_DAYS));
    $last = $ctx['last_sent_at'] ?? null;
    if (is_string($last) && trim($last) !== '') {
        $ts = strtotime(trim($last) . (str_contains($last, 'UTC') ? '' : ' UTC'));
        $now = (int) ($ctx['now'] ?? time());
        if ($ts !== false && ($now - $ts) < ($interval * 86400)) {
            return ['ok' => false, 'reason' => 'too_soon'];
        }
    }
    return ['ok' => true, 'reason' => 'ready'];
}

/**
 * Estado legible para el panel admin.
 *
 * @return array{key:string, label:string}
 */
function reminder_status_label(array $row, string $email, int $pendingCount): array
{
    $emailOk = $email !== '' && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    if (!$emailOk) {
        return ['key' => 'no_email', 'label' => 'Sin email'];
    }
    if ($pendingCount < 1 || (string) ($row['stop_reason'] ?? '') === 'completed') {
        return ['key' => 'completed', 'label' => 'Completó (ya no se envía)'];
    }
    if ((int) ($row['enabled'] ?? 0) === 1) {
        return ['key' => 'active', 'label' => 'Activo'];
    }
    return ['key' => 'paused', 'label' => 'Pausado'];
}

function reminder_pending_summary(array $pending, int $limit = 4): string
{
    if ($pending === []) {
        return '';
    }
    $titles = [];
    foreach (array_slice($pending, 0, $limit) as $p) {
        $t = trim((string) ($p['title'] ?? ''));
        if ($t !== '') {
            $titles[] = $t;
        }
    }
    $extra = count($pending) - count($titles);
    $list = implode(', ', $titles);
    if ($extra > 0) {
        $list .= ' y ' . $extra . ' más';
    }
    return $list;
}

function reminder_email_html(string $name, string $intro, string $loginUrl, int $pendingCount, string $pendingList): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeIntro = nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'));
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $countLabel = $pendingCount === 1
        ? '1 clase pendiente'
        : $pendingCount . ' clases pendientes';
    $listHtml = $pendingList !== ''
        ? '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">Por ejemplo: <em>'
          . htmlspecialchars($pendingList, ENT_QUOTES, 'UTF-8') . '</em>.</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Recordatorio AcademiaFluxus</title></head>
<body style="margin:0;padding:0;background:#eef3ef;font-family:Georgia,'Times New Roman',serif;color:#12201c;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3ef;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#f8fbf8;border:1px solid rgba(15,61,54,.12);border-radius:12px;overflow:hidden;">
          <tr>
            <td style="background:#0f3d36;color:#fff;padding:22px 28px;font-family:Georgia,serif;font-size:26px;">
              Fluxus<span style="opacity:.75;font-style:italic;">Academia</span>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 12px;font-size:15px;letter-spacing:.12em;text-transform:uppercase;color:#2f8f6b;font-family:Arial,sans-serif;font-weight:700;">Recordatorio</p>
              <h1 style="margin:0 0 14px;font-size:26px;line-height:1.25;">Hola, {$safeName}</h1>
              <p style="margin:0 0 14px;font-size:16px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">{$safeIntro}</p>
              <p style="margin:0 0 10px;font-size:16px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">
                Tenés <strong>{$countLabel}</strong> por visualizar en el campus.
              </p>
              {$listHtml}
              <p style="margin:0 0 22px;font-family:Arial,sans-serif;">
                <a href="{$safeUrl}" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:12px 18px;border-radius:6px;font-weight:700;">
                  Entrar al campus
                </a>
              </p>
              <p style="margin:0;font-size:14px;line-height:1.6;font-family:Arial,sans-serif;color:#4f635a;">
                Link de ingreso: <a href="{$safeUrl}" style="color:#0f3d36;">{$safeUrl}</a><br><br>
                Con cariño,<br>
                <strong>Michael · FluxusTerapia</strong>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function reminder_send_to_student(array $user, array $pending, array $settings): bool
{
    $email = trim((string) ($user['email'] ?? ''));
    $name = trim((string) ($user['name'] ?? '')) ?: 'alumno/a';
    $loginUrl = function_exists('aca_login_url')
        ? aca_login_url()
        : (rtrim((string) (($GLOBALS['config']['site_url'] ?? 'https://fluxusterapia.com')), '/') . '/academia/login.php?as=alumno');
    $count = count($pending);
    $list = reminder_pending_summary($pending);
    $html = reminder_email_html($name, (string) $settings['intro'], $loginUrl, $count, $list);
    $text = "Hola, {$name}\n\n"
        . (string) $settings['intro'] . "\n\n"
        . "Tenés {$count} clase(s) pendiente(s)"
        . ($list !== '' ? ": {$list}" : '') . ".\n"
        . "Ingreso: {$loginUrl}\n\n"
        . "Con cariño,\nMichael · FluxusTerapia\n";
    return send_mail($email, (string) $settings['subject'], $html, $text);
}

/**
 * Recorre candidatos y envía respetando reglas. Pensado para el cron.
 *
 * @return array{checked:int, sent:int, skipped:int, completed:int, errors:int}
 */
function reminder_run_batch(bool $dryRun = false, int $maxSends = REMINDER_CRON_MAX_SENDS): array
{
    $settings = reminder_settings();
    $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'completed' => 0, 'errors' => 0];

    if (!$settings['global_enabled']) {
        reminder_log(null, 'skip', 'automatizacion global apagada');
        return $stats;
    }

    $students = db()->query("
      SELECT u.id, u.name, u.email, u.username,
             COALESCE(r.enabled, 0) AS enabled,
             r.last_sent_at, r.next_due_at, COALESCE(r.stop_reason, '') AS stop_reason
      FROM users u
      LEFT JOIN student_reminders r ON r.user_id = u.id
      WHERE u.role = 'student'
      ORDER BY u.id
    ")->fetchAll();

    foreach ($students as $user) {
        if ($stats['sent'] >= $maxSends) {
            break;
        }
        $stats['checked']++;
        $uid = (int) $user['id'];
        $email = trim((string) ($user['email'] ?? ''));
        $emailOk = $email !== '' && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        $pending = reminder_pending_classes($uid);
        $pendingCount = count($pending);

        // Si ya no hay clases pendientes, dejar de enviar (aunque estuviera activo).
        if ($pendingCount < 1 && (int) $user['enabled'] === 1) {
            if (!$dryRun) {
                reminder_mark_completed($uid);
                reminder_log($uid, 'completed', 'curso completo sin pendientes');
            }
            $stats['completed']++;
            continue;
        }

        $decision = reminder_should_send([
            'global_enabled' => true,
            'enabled' => (int) $user['enabled'] === 1,
            'email_ok' => $emailOk,
            'pending_count' => $pendingCount,
            'stop_reason' => (string) $user['stop_reason'],
            'last_sent_at' => $user['last_sent_at'] !== null ? (string) $user['last_sent_at'] : null,
            'interval_days' => (int) $settings['interval_days'],
            'now' => time(),
        ]);

        if (!$decision['ok']) {
            $stats['skipped']++;
            continue;
        }

        if ($dryRun) {
            $stats['sent']++;
            reminder_log($uid, 'dry_run', 'candidato #' . $pendingCount . ' pendientes');
            continue;
        }

        $ok = reminder_send_to_student($user, $pending, $settings);
        if ($ok) {
            reminder_mark_sent($uid, (int) $settings['interval_days']);
            reminder_log($uid, 'sent', $pendingCount . ' pendientes');
            $stats['sent']++;
        } else {
            reminder_log($uid, 'error', 'fallo envio mail');
            $stats['errors']++;
        }
        // Pequeña pausa para no saturar mail() en HostGator
        usleep(120000);
    }

    return $stats;
}

function reminder_cron_key_ok(?string $provided): bool
{
    global $config;
    $expected = (string) ($config['reminder_cron_key'] ?? '');
    if ($expected === '' || $expected === 'CHANGE_ME') {
        return false;
    }
    $key = (string) ($provided ?? '');
    if ($key === '' && isset($_SERVER['HTTP_X_REMINDER_KEY'])) {
        $key = (string) $_SERVER['HTTP_X_REMINDER_KEY'];
    }
    return $key !== '' && hash_equals($expected, $key);
}
