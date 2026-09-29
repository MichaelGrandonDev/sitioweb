<?php

declare(strict_types=1);

require_once __DIR__ . '/../pdf_lib.php';

const TURNO_REQUISITOS_DEFAULT = "No comer nada 1 hora antes del turno (agua sí podés tomar).\n"
    . "Venir aseado/a (bañado/a) y sin cremas ni aceites en la piel.\n"
    . "Venir con short (o traerlo) y una remera o musculosa cómoda.\n"
    . "Llegar 10 minutos antes para empezar a horario.\n"
    . "Evitar el alcohol y las comidas muy pesadas las 24 horas previas.\n"
    . "Si tomás medicación, estás embarazada o tenés alguna condición de salud (marcapasos, anticoagulantes, diabetes, epilepsia, cirugías recientes, etc.), avisanos antes de la sesión.\n"
    . "Si tenés estudios médicos recientes (radiografías, ecografías, resonancias), traelos.\n"
    . "Leé el consentimiento informado adjunto: traelo firmado o lo firmás al llegar.\n"
    . "Si no podés venir, avisá con al menos 24 horas de anticipación por WhatsApp.";

const TURNO_CONSENTIMIENTO_DEFAULT = "Fui informado/a de manera clara sobre la terapia que voy a recibir en FluxusTerapia (por ejemplo masoterapia, rehabilitación kinésica, técnicas de medicina tradicional china como acupuntura, ventosas o moxibustión, u otras prácticas complementarias): sus objetivos, cómo se realiza y su duración aproximada.\n"
    . "Entiendo que estas prácticas son complementarias y no reemplazan el diagnóstico ni el tratamiento médico. Me comprometo a continuar con las indicaciones de mi médico.\n"
    . "Conozco las posibles molestias o efectos transitorios: dolor o sensibilidad en la zona tratada, enrojecimiento, pequeños hematomas o marcas (sobre todo con ventosas), mareo leve, cansancio o somnolencia. En acupuntura puede haber un pequeño sangrado en el punto de punción; se usan agujas estériles y descartables.\n"
    . "Informé con veracidad mi estado de salud: enfermedades, medicación (en especial anticoagulantes), alergias, embarazo o posibilidad de estarlo, marcapasos u otros dispositivos, cirugías recientes, problemas de piel, diabetes, epilepsia u otras condiciones. Me comprometo a avisar cualquier cambio.\n"
    . "Puedo hacer todas las preguntas que necesite, pedir que se modifique o se detenga la sesión en cualquier momento y retirar este consentimiento cuando quiera.\n"
    . "Mis datos personales y de salud se tratan de forma confidencial y solo se usan para mi atención (Ley 25.326 de Protección de Datos Personales y Ley 26.529 de Derechos del Paciente).\n"
    . "Leí y acepto las indicaciones previas a la sesión.";

/** Renglones de preparación que venían por defecto en cada terapia (ya cubiertos por los requisitos generales). */
const TURNO_PREP_GENERIC = [
    'no comas ni bebas (salvo agua) 1 hora antes del turno.',
    'evitá alcohol y comidas muy pesadas el día previo.',
    'llegá 5–10 minutos antes.',
    'usá ropa cómoda.',
    'si no podés asistir, avisá con la mayor anticipación posible.',
];

function turno_text_setting(string $key, string $default): string
{
    $value = trim((string) (deposit_cfg()[$key] ?? ''));
    return $value !== '' ? $value : $default;
}

function turno_lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
        $line = trim(ltrim(trim($line), "•-*\t "));
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function turno_is_online(array $appt): bool
{
    return (bool) preg_match('/online/i', (string) ($appt['therapy_name'] ?? ''));
}

/** Indicaciones propias de la terapia que no están en la lista general. */
function turno_therapy_extras(array $appt): array
{
    $stmt = db()->prepare('SELECT prep_notes FROM therapies WHERE id = ?');
    $stmt->execute([(int) $appt['therapy_id']]);
    $lines = turno_lines((string) ($stmt->fetchColumn() ?: ''));
    if (turno_is_online($appt)) {
        return $lines;
    }
    return array_values(array_filter($lines, static fn ($l) => !in_array(mb_strtolower($l), TURNO_PREP_GENERIC, true)));
}

function turno_doc_url(array $appt, string $doc): string
{
    global $config;
    $base = rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/') . '/turnos/pdf.php';
    return $base . '?token=' . urlencode((string) $appt['token']) . ($doc !== '' ? '&doc=' . $doc : '') . '&ver=1';
}

function turno_pdf_header(FluxusPdf $pdf, string $subtitle): void
{
    $pdf->addPage();
    $pdf->title('FluxusTerapia');
    $pdf->subtitle($subtitle);
    $pdf->rule();
}

function turno_pdf_details(FluxusPdf $pdf, array $appt): void
{
    global $config;
    $pdf->heading('Detalle del turno');
    $pdf->paragraph('Código: ' . $appt['code']);
    $pdf->paragraph('Terapia: ' . $appt['therapy_name']);
    $pdf->paragraph('Día: ' . format_date_es((string) $appt['date']));
    $pdf->paragraph('Hora: ' . format_time_es((string) $appt['time']));
    $pdf->paragraph('Duración aproximada: ' . (int) $appt['duration_min'] . ' minutos');
    $pdf->paragraph('Lugar: ' . $config['place_name'] . ' · ' . $config['place_city']);
}

/** Comprobante + requisitos para la sesión. */
function turno_requisitos_pdf(array $appt): string
{
    global $config;
    $pdf = new FluxusPdf();
    turno_pdf_header($pdf, 'Comprobante de turno y requisitos para tu sesión');
    $pdf->paragraph('Hola, ' . $appt['patient_name'] . ':');
    $pdf->paragraph('Tu turno en FluxusTerapia está confirmado. Te esperamos con presencia y calma para acompañarte en este espacio de bienestar.');
    turno_pdf_details($pdf, $appt);

    $extras = turno_therapy_extras($appt);
    if (turno_is_online($appt)) {
        $pdf->heading('Para tu clase online');
        foreach ($extras as $line) {
            $pdf->bullet($line);
        }
    } else {
        $pdf->heading('Requisitos para tu sesión');
        foreach (turno_lines(turno_text_setting('requisitos_text', TURNO_REQUISITOS_DEFAULT)) as $line) {
            $pdf->bullet($line);
        }
        if ($extras) {
            $pdf->heading('Además, para ' . $appt['therapy_name']);
            foreach ($extras as $line) {
                $pdf->bullet($line);
            }
        }
    }

    $pdf->heading('Contacto');
    $pdf->paragraph('WhatsApp: +' . $config['whatsapp']);
    $pdf->paragraph('Web: ' . $config['site_url']);
    $pdf->paragraph('Instagram: @fluxusterapia');
    $pdf->rule();
    $pdf->paragraph('Gracias por confiar en FluxusTerapia.');
    return $pdf->render();
}

function turno_consentimiento_pdf(array $appt): string
{
    $pdf = new FluxusPdf();
    turno_pdf_header($pdf, 'Consentimiento informado');
    $pdf->heading('Datos');
    $pdf->paragraph('Paciente: ' . $appt['patient_name'] . ' · Teléfono: ' . $appt['patient_phone']);
    $pdf->paragraph('Terapia: ' . $appt['therapy_name'] . ' · ' . format_date_es((string) $appt['date']) . ' · ' . format_time_es((string) $appt['time']) . ' · Código ' . $appt['code']);
    $pdf->heading('Declaro que');
    foreach (turno_lines(turno_text_setting('consentimiento_text', TURNO_CONSENTIMIENTO_DEFAULT)) as $i => $line) {
        $pdf->paragraph(($i + 1) . '. ' . $line);
    }
    $pdf->paragraph('Por todo lo expuesto, doy mi consentimiento libre y voluntario para recibir la terapia indicada.');
    $pdf->signatureRow('Firma del paciente', 'Aclaración y DNI');
    $pdf->signatureRow('Fecha', 'Firma y sello del profesional');
    $pdf->small('Si el paciente es menor de edad o no puede firmar, firma su madre, padre, tutor o representante indicando el vínculo.');
    return $pdf->render();
}

/** Correo con los dos PDF adjuntos. Devuelve true si mail() lo aceptó. */
function turno_send_mail(string $to, string $subject, string $html, string $text, array $attachments): bool
{
    global $config;
    $fromEmail = (string) ($config['mail_from'] ?? 'hola@fluxusterapia.com');
    $fromName = (string) ($config['mail_from_name'] ?? 'FluxusTerapia');
    $enc = static fn (string $v): string => preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;

    $mixed = 'fx_mix_' . bin2hex(random_bytes(8));
    $alt = 'fx_alt_' . bin2hex(random_bytes(8));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . $enc($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
        'X-Mailer: FluxusTerapia Turnos',
    ];
    $body = "--{$mixed}\r\nContent-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n"
        . "--{$alt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--{$alt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . "--{$alt}--\r\n";
    foreach ($attachments as $name => $bytes) {
        $body .= "--{$mixed}\r\nContent-Type: application/pdf; name=\"{$name}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
            . chunk_split(base64_encode($bytes)) . "\r\n";
    }
    $body .= "--{$mixed}--\r\n";

    if (!empty($config['mail_dump_dir'])) {
        file_put_contents(rtrim((string) $config['mail_dump_dir'], '/') . '/turno-' . time() . '.eml', 'To: ' . $to . "\r\nSubject: " . $enc($subject) . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
        return true;
    }
    return @mail($to, $enc($subject), $body, implode("\r\n", $headers), '-f' . $fromEmail);
}

function turno_by_id(int $id): ?array
{
    $stmt = db()->prepare('
      SELECT a.*, t.name AS therapy_name, t.duration_min
      FROM appointments a JOIN therapies t ON t.id = a.therapy_id
      WHERE a.id = ? LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Mail de turno confirmado con requisitos y consentimiento en PDF.
 * Devuelve 'sent', 'already', 'no_email' o 'failed'.
 */
function send_turno_confirmation(int $appointmentId, bool $force = false): string
{
    global $config;
    $appt = turno_by_id($appointmentId);
    if (!$appt || $appt['status'] !== 'confirmed') {
        return 'failed';
    }
    if (!$force && !empty($appt['mail_sent_at'])) {
        return 'already';
    }
    $to = trim((string) $appt['patient_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no_email';
    }

    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $when = format_date_es((string) $appt['date']) . ' · ' . format_time_es((string) $appt['time']);
    $place = $config['place_name'] . ' · ' . $config['place_city'];
    $reqUrl = turno_doc_url($appt, 'requisitos');
    $conUrl = turno_doc_url($appt, 'consentimiento');
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $config['whatsapp']);
    $subject = 'Turno confirmado · ' . $appt['therapy_name'] . ' · ' . format_date_es((string) $appt['date']);
    $online = turno_is_online($appt);

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid rgba(15,61,54,.12)">'
        . '<tr><td style="background:#0f3d36;color:#fff;padding:20px 26px;font-family:Georgia,serif;font-size:24px">FluxusTerapia</td></tr>'
        . '<tr><td style="padding:26px;font-size:15px;line-height:1.6">'
        . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#2f8f6b;font-weight:700">Turno confirmado</p>'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px">Hola, ' . $e($appt['patient_name']) . '</h1>'
        . '<p style="margin:0 0 16px;color:#4f635a">Tu turno quedó confirmado. Te esperamos:</p>'
        . '<table role="presentation" width="100%" style="background:#eef3ef;border-radius:8px;margin:0 0 18px"><tr><td style="padding:14px 18px">'
        . '<strong>' . $e($appt['therapy_name']) . '</strong><br>' . $e($when) . '<br>' . $e($place) . '<br>Código: <strong>' . $e($appt['code']) . '</strong>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 10px">Te adjuntamos dos PDF para que los leas antes de venir:</p>'
        . '<ul style="margin:0 0 18px;padding-left:20px">'
        . '<li>' . ($online ? '<strong>Indicaciones para tu clase online</strong>' : '<strong>Requisitos para tu sesión</strong> (no comer 1 hora antes, venir aseado/a, con short, etc.)') . '. <a href="' . $e($reqUrl) . '" style="color:#0f3d36">Ver PDF</a></li>'
        . '<li><strong>Consentimiento informado</strong>: leelo y traelo firmado, o lo firmás al llegar. <a href="' . $e($conUrl) . '" style="color:#0f3d36">Ver PDF</a></li>'
        . '</ul>'
        . '<p style="margin:0 0 18px"><a href="' . $e($wa) . '" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">Consultas por WhatsApp</a></p>'
        . '<p style="margin:0;color:#4f635a">Si no podés venir, avisanos con al menos 24 horas de anticipación.<br><br>Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $text = 'Hola, ' . $appt['patient_name'] . "\n\n"
        . "Tu turno quedó confirmado:\n"
        . $appt['therapy_name'] . "\n" . $when . "\n" . $place . "\nCódigo: " . $appt['code'] . "\n\n"
        . "Te adjuntamos dos PDF para que los leas antes de venir:\n"
        . ($online ? '- Indicaciones para tu clase online: ' : '- Requisitos para tu sesión: ') . $reqUrl . "\n"
        . '- Consentimiento informado (traelo firmado o lo firmás al llegar): ' . $conUrl . "\n\n"
        . 'Consultas por WhatsApp: ' . $wa . "\n"
        . "Si no podés venir, avisanos con al menos 24 horas de anticipación.\n\nFluxusTerapia\n";

    $ok = turno_send_mail($to, $subject, $html, $text, [
        'Turno-' . $appt['code'] . '-requisitos.pdf' => turno_requisitos_pdf($appt),
        'Consentimiento-informado-' . $appt['code'] . '.pdf' => turno_consentimiento_pdf($appt),
    ]);
    if (!$ok) {
        return 'failed';
    }
    db()->prepare('UPDATE appointments SET mail_sent_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $appointmentId]);
    return 'sent';
}
