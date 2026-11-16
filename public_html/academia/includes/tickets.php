<?php

declare(strict_types=1);

/**
 * Consultas (tickets) de alumnos al profesor o a administración.
 * status: open = espera respuesta del equipo · answered = respondida · closed = cerrada.
 */

const TICKET_TO = [
    'teacher' => 'Profesor',
    'admin' => 'Administración',
];

const TICKET_STATUS = [
    'open' => 'Sin responder',
    'answered' => 'Respondida',
    'closed' => 'Cerrada',
];

function tickets_migrate(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        to_role TEXT NOT NULL DEFAULT 'teacher',
        course_id INTEGER DEFAULT NULL,
        resource_id INTEGER DEFAULT NULL,
        subject TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        student_unread INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE SET NULL,
        FOREIGN KEY(resource_id) REFERENCES course_resources(id) ON DELETE SET NULL
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS ticket_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        body TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
      )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tickets_user ON tickets(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages(ticket_id)');
}

/** Admin/profesor: consultas sin responder. Alumno: consultas con respuesta que todavía no leyó. */
function tickets_pending_count(array $user): int
{
    try {
        if (is_full_admin($user)) {
            return (int) db()->query("SELECT COUNT(*) FROM tickets WHERE status = 'open'")->fetchColumn();
        }
        if (is_teacher($user) && user_has_permission($user, 'reply_tickets')) {
            $ids = teacher_course_ids($user);
            if ($ids === []) {
                return 0;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT COUNT(*) FROM tickets WHERE status = 'open' AND course_id IN ($ph)");
            $stmt->execute($ids);
            return (int) $stmt->fetchColumn();
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM tickets WHERE user_id = ? AND student_unread = 1');
        $stmt->execute([(int) $user['id']]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/** ¿Puede el staff ver/responder esta consulta? */
function ticket_staff_can_access(array $user, array $ticket): bool
{
    if (is_full_admin($user)) {
        return true;
    }
    if (!is_teacher($user) || !user_has_permission($user, 'reply_tickets')) {
        return false;
    }
    $courseId = (int) ($ticket['course_id'] ?? 0);
    return $courseId > 0 && teacher_has_course($user, $courseId);
}

function ticket_create(array $user, string $to, ?int $courseId, ?int $resourceId, string $subject, string $body): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO tickets (user_id, to_role, course_id, resource_id, subject) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int) $user['id'], $to, $courseId, $resourceId, $subject]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ticket_messages (ticket_id, user_id, body) VALUES (?, ?, ?)')
        ->execute([$id, (int) $user['id'], $body]);
    $pdo->commit();
    ticket_notify($id, $user, $body, true);
    return $id;
}

function ticket_reply(array $ticket, array $user, string $body): void
{
    $pdo = db();
    $staff = $user['role'] === 'admin';
    $pdo->prepare('INSERT INTO ticket_messages (ticket_id, user_id, body) VALUES (?, ?, ?)')
        ->execute([(int) $ticket['id'], (int) $user['id'], $body]);
    $pdo->prepare("UPDATE tickets SET status = ?, student_unread = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$staff ? 'answered' : 'open', $staff ? 1 : 0, (int) $ticket['id']]);
    ticket_notify((int) $ticket['id'], $user, $body, false);
}

function ticket_find(int $id): ?array
{
    $stmt = db()->prepare("
      SELECT t.*, u.name AS student_name, u.email AS student_email,
        c.title AS course_title, r.title AS resource_title
      FROM tickets t
      JOIN users u ON u.id = t.user_id
      LEFT JOIN courses c ON c.id = t.course_id
      LEFT JOIN course_resources r ON r.id = t.resource_id
      WHERE t.id = ?
      LIMIT 1
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Aviso por email: al equipo si escribe el alumno, al alumno si responde el equipo. */
function ticket_notify(int $ticketId, array $author, string $body, bool $isNew): void
{
    global $config;
    $ticket = ticket_find($ticketId);
    if (!$ticket) {
        return;
    }
    $site = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/');
    $subject = (string) $ticket['subject'];
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    if ($author['role'] === 'admin') {
        $to = (string) $ticket['student_email'];
        $link = $site . '/academia/ticket.php?id=' . $ticketId;
        $mailSubject = 'Respuesta a tu consulta: ' . $subject;
        $intro = 'Hola, ' . $ticket['student_name'] . '. Te respondimos tu consulta en AcademiaFluxus:';
    } else {
        $to = admin_notify_email();
        $link = $site . '/academia/ticket.php?id=' . $ticketId;
        $dest = TICKET_TO[$ticket['to_role']] ?? 'Profesor';
        $mailSubject = ($isNew ? 'Nueva consulta' : 'Nuevo mensaje') . ' para ' . $dest . ': ' . $subject;
        $intro = $ticket['student_name'] . ' (' . $ticket['student_email'] . ') escribió'
            . ($ticket['course_title'] ? ' sobre el curso ' . $ticket['course_title'] : '') . ':';
    }

    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#12201c">'
        . '<p>' . $esc($intro) . '</p>'
        . '<blockquote style="margin:0 0 16px;padding:12px 16px;background:#eef3ef;border-left:4px solid #2f8f6b">'
        . nl2br($esc($body)) . '</blockquote>'
        . '<p><a href="' . $esc($link) . '" style="display:inline-block;background:#2f8f6b;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:700">Ver la consulta</a></p>'
        . '<p style="color:#4f635a;font-size:13px">AcademiaFluxus · FluxusTerapia</p></div>';
    $text = $intro . "\n\n" . $body . "\n\nVer la consulta: " . $link . "\n";
    send_mail($to, $mailSubject, $html, $text);
}
