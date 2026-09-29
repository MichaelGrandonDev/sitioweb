<?php

declare(strict_types=1);

require_once __DIR__ . '/../pdf_lib.php';
require_once __DIR__ . '/qr.php';

const TURNO_REQUISITOS_DEFAULT = "No comer nada 1 hora antes del turno (agua sí podés tomar).\n"
    . "Venir aseado/a (bañado/a) y sin cremas ni aceites en la piel.\n"
    . "Venir con short (o traerlo) y una remera o musculosa cómoda.\n"
    . "Llegar 10 minutos antes para empezar a horario.\n"
    . "Evitar el alcohol y las comidas muy pesadas las 24 horas previas.\n"
    . "Si tomás medicación, estás embarazada o tenés alguna condición de salud (marcapasos, anticoagulantes, diabetes, epilepsia, cirugías recientes, etc.), avisanos antes de la sesión.\n"
    . "Si tenés estudios médicos recientes (radiografías, ecografías, resonancias), traelos.\n"
    . "Leé el consentimiento informado adjunto: traelo firmado o lo firmás al llegar.\n"
    . "Si no podés venir, avisá con al menos 24 horas de anticipación por WhatsApp.";

/**
 * Un párrafo por renglón. Marcadores: {nombre}, {NOMBRE} (en mayúsculas), {documento}, {email}, {fecha}.
 * Renglones "PRIMERO: …" van con la etiqueta en negrita; "a) …" / "i.- …" como subítems con sangría.
 */
const TURNO_CONSENTIMIENTO_DEFAULT = <<<'TXT'
- CONSENTIMIENTO INFORMADO -
Yo, {nombre}, RUT N° {documento}, e-mail {email}; mediante la presente declaro que hoy {fecha} he sido informado(a) adecuadamente, en conformidad con lo dispuesto en el artículo 4° del decreto N° 123 del año 2006 del Ministerio de Salud; sobre las bases, aplicación, indicaciones, contraindicaciones, riesgos y resultados esperados en la terapia de Medicina Tradicional China (Acupuntura) y sus distintas técnicas asociadas. Así mismo declaro en este acto estar consciente y aceptar que:

PRIMERO: La Medicina Tradicional China es el conjunto de teorías, especialidades, técnicas y procedimientos, de las que se vale la cultura china para equilibrar, mantener e incrementar el bienestar físico y mental del ser humano, considerado éste como un todo inseparable.

SEGUNDO: La Acupuntura es una especialidad de la Medicina Tradicional China que consiste en la inserción de agujas sólidas, estériles, de preferencia desechables, en puntos específicos de la superficie corporal, lo que permite equilibrar, mantener e incrementar el bienestar físico y mental de las personas.

TERCERO: El tratamiento a través de la Acupuntura se basa en la teoría dinámica del flujo de energía vital (Qi) que fluye en forma continua por todo el cuerpo. En toda dolencia existe una alteración de esta dinámica del flujo, la cual con la aplicación de agujas en puntos específicos del cuerpo -'Puntos de Acupuntura'- se puede influenciar positivamente, contribuyendo así a la restitución del equilibrio energético del organismo.

CUARTO: Forman parte de la Acupuntura los siguientes Microsistemas:

a) Cráneo Puntura: Sistema de inserción de agujas de acupuntura en la superficie craneal, utilizando puntos y líneas específicas.

b) Aurículo Puntura: Sistema de inserción de agujas de acupuntura y estímulo en puntos específicos de la oreja.

c) Mano Puntura: Sistema de inserción de agujas de acupuntura en puntos específicos de la superficie de las manos.

d) Acupuntura Podal: Sistema de inserción de agujas de acupuntura en puntos específicos de la superficie de los pies.

QUINTO: Junto con la Acupuntura, podrán ser empleadas las siguientes Técnicas Asociadas:

i.- Moxibustión: Aplicación de calor en los puntos de acupuntura y sobre las agujas de acupuntura, a través de la utilización de yerbas chinas (Moxa).

ii.- Ventosas: Utilización de vasos de succión de aire sobre zonas y puntos de Acupuntura, confeccionados de material de vidrio, bambú, cerámica, plástico, etc.

iii.- Láser Puntura: Técnica de estímulo de los puntos de Acupuntura con equipos de Láser especialmente diseñados para Acupuntura.

iv.- Electro acupuntura: Técnica de estímulo de los puntos de Acupuntura con equipos de Electro-Acupuntura diseñados para Acupuntura.

v.- Magnetos: Técnica de estímulo de los puntos de Acupuntura con magnetos especialmente diseñados para Acupuntura.

SEXTO: La Acupuntura se encuentra indicada principalmente como tratamiento complementario para el manejo del dolor crónico (como lumbalgia, cervicalgia o osteoartritis), cefaleas y migrañas, así como para aliviar náuseas (incluyendo las asociadas a quimioterapia o embarazo), trastornos funcionales como la infertilidad, el insomnio, la ansiedad y el síndrome del intestino irritable. Su uso se basa en la evidencia de organismos como la Organización Mundial de la Salud, y aunque la Acupuntura puede emplearse para tratar casi la gran mayoría de las enfermedades ésta no reemplaza los tratamientos médicos convencionales sino que sólo los complementa.

SÉPTIMO: La Acupuntura está contraindicada en presencia de infecciones cutáneas en la zona de punción o cuando no se garantizan condiciones de higiene adecuadas. Además, requiere precaución en personas embarazadas (por riesgo de estimular contracciones), pacientes con trastornos de coagulación o que usan anticoagulantes, personas con marcapasos (especialmente en electroacupuntura), inmunosuprimidos o con fobia intensa a las agujas. No debe utilizarse como tratamiento único en enfermedades graves, urgencias médicas o condiciones que requieren intervenciones farmacológicas o quirúrgicas.

OCTAVO: Aunque la Acupuntura suele ser una técnica bastante segura e inocua para la salud, es importante reconocer que, como cualquier procedimiento terapéutico, no está exenta de riesgos potenciales. Entre estos se incluyen molestias locales, dolor leve, sangrado o hematomas en los sitios de punción, así como, en raras ocasiones, infecciones si no se utilizan materiales estériles o no se siguen adecuadas normas de asepsia. Asimismo, técnicas asociadas como la moxibustión pueden generar quemaduras o irritación cutánea, la electroacupuntura podría provocar molestias eléctricas o interferencias en pacientes con dispositivos médicos implantados, y el uso de láser puede ocasionar efectos adversos si no se aplica correctamente. En casos excepcionales, pueden producirse reacciones vasovagales como mareos o desmayos.

NOVENO: Declaro estar en conocimiento y aceptar que, en el contexto de formación académica y práctica clínica supervisada de la Medicina Tradicional China, los procedimientos de Acupuntura y de sus técnicas asociadas podrían ser realizados por alumnos de la Escuela Neidan en proceso de formación bajo la supervisión de un profesional calificado, por lo que autorizo expresamente la participación de alumnos de dicha Casa de Estudios en mi atención, comprendiendo la naturaleza docente de la instancia y consintiendo de manera libre e informada en dichas condiciones.

DÉCIMO: Declaro estar en conocimiento y aceptar de que el diagnóstico en medicina tradicional china se expresa siempre mediante un lenguaje metafórico y simbólico, en el cual términos como “riñón” o “hígado”, entre otros, no corresponden necesariamente a órganos anatómicos ni a enfermedades en el sentido de la medicina occidental, sino a funciones y desequilibrios de carácter energético. En consecuencia, comprendo que expresiones como “deficiencia de riñón”, “insuficiencia del corazón” o “estancamiento de sangre” no implican necesariamente la existencia de una patología clínica, ni sustituyen el diagnóstico o tratamiento médico convencional.

En conformidad con todo lo anterior, YO, {NOMBRE}, RUT N° {documento}, ACEPTO libre y voluntariamente someterme al tratamiento de Medicina Tradicional China – Acupuntura, asumiendo los riesgos inherentes a su aplicación. En este acto, libero de toda responsabilidad al profesional tratante y a la institución por los resultados obtenidos y por cualquier efecto secundario que pudiera derivarse del tratamiento. Asimismo, me comprometo a entregar información veraz sobre mi estado de salud y a seguir las indicaciones del profesional tratante.
TXT;

/** Líneas para completar a mano cuando todavía no hay dato (PDF antes de firmar). */
const TURNO_CONSENT_BLANKS = [
    'nombre' => '______________________________',
    'documento' => '________________',
    'email' => '______________________________',
    'fecha' => '____-____-________',
];

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

/** Link absoluto a una página del turno (pay.php, consentimiento.php). */
function turno_page_url(array $appt, string $page): string
{
    global $config;
    return rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/') . '/turnos/' . $page
        . '?token=' . urlencode((string) $appt['token']);
}

/** Turno confirmado con seña sin pagar que se puede ofrecer (turnos cargados a mano). */
function turno_deposit_open(array $appt): bool
{
    return ($appt['status'] ?? '') === 'confirmed'
        && in_array((string) ($appt['deposit_status'] ?? ''), ['optional', 'pending'], true)
        && (int) ($appt['deposit_amount'] ?? 0) > 0;
}

/** Líneas de "antes de venir": requisitos generales + las propias de la terapia (o las de la clase online). */
function turno_prep_lines(array $appt): array
{
    $extras = turno_therapy_extras($appt);
    if (turno_is_online($appt)) {
        return $extras;
    }
    return array_merge(turno_lines(turno_text_setting('requisitos_text', TURNO_REQUISITOS_DEFAULT)), $extras);
}

/** Texto del consentimiento vigente (con marcadores sin completar). */
function turno_consent_template(): string
{
    return turno_text_setting('consentimiento_text', TURNO_CONSENTIMIENTO_DEFAULT);
}

/**
 * Valores de los marcadores. Lo que falta queda como línea para completar a mano
 * (documento y fecha en el PDF que se manda antes de firmar).
 */
function turno_consent_values(array $appt, string $name = '', string $document = '', string $date = ''): array
{
    $name = trim($name !== '' ? $name : (string) ($appt['patient_name'] ?? ''));
    $email = trim((string) ($appt['patient_email'] ?? ''));
    return [
        'nombre' => $name !== '' ? $name : TURNO_CONSENT_BLANKS['nombre'],
        'NOMBRE' => $name !== '' ? mb_strtoupper($name, 'UTF-8') : TURNO_CONSENT_BLANKS['nombre'],
        'documento' => trim($document) !== '' ? trim($document) : TURNO_CONSENT_BLANKS['documento'],
        'email' => $email !== '' ? $email : TURNO_CONSENT_BLANKS['email'],
        'fecha' => $date !== '' ? $date : TURNO_CONSENT_BLANKS['fecha'],
    ];
}

function turno_consent_fill(string $text, array $values): string
{
    $map = [];
    foreach ($values as $key => $value) {
        $map['{' . $key . '}'] = (string) $value;
    }
    return strtr($text, $map);
}

/**
 * Bloques del consentimiento, un párrafo por renglón:
 * title ("- CONSENTIMIENTO INFORMADO -"), clause ("PRIMERO: …", con label), item ("a) …", "iv.- …", con label
 * y term = lo que va antes de los dos puntos) o para.
 *
 * @return list<array{type: string, label: string, term: string, text: string}>
 */
function turno_consent_blocks(string $text): array
{
    $blocks = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $block = ['type' => 'para', 'label' => '', 'term' => '', 'text' => $line];
        if (!str_contains($line, ':') && mb_strlen($line) <= 80 && preg_match('/\p{L}/u', $line) && mb_strtoupper($line, 'UTF-8') === $line) {
            $block['type'] = 'title';
        } elseif (preg_match('/^(\p{Lu}{4,}:)\s*(.+)$/u', $line, $m)) {
            $block = ['type' => 'clause', 'label' => $m[1], 'term' => '', 'text' => $m[2]];
        } elseif (preg_match('/^([a-z]\)|[ivxl]+\.-|[ivxl]+\)|\d{1,2}[.)])\s+(.+)$/u', $line, $m)) {
            $block = ['type' => 'item', 'label' => $m[1], 'term' => '', 'text' => $m[2]];
            if (preg_match('/^([^:]{2,40}:)\s+(.+)$/u', $m[2], $t)) {
                $block['term'] = $t[1];
                $block['text'] = $t[2];
            }
        }
        $blocks[] = $block;
    }
    return $blocks;
}

/** Consentimientos firmados antes de 2026-09: una declaración por renglón, sin título ni cláusulas. */
function turno_consent_is_legacy(string $text): bool
{
    foreach (turno_consent_blocks($text) as $block) {
        if ($block['type'] === 'title' || $block['type'] === 'clause') {
            return false;
        }
    }
    return true;
}

/**
 * HTML del consentimiento. $values = null para un texto ya completado (firmado); si no, los marcadores se
 * reemplazan por <span data-fill="…"> para que la página los actualice mientras el paciente completa sus datos.
 */
function turno_consent_html(string $text, ?array $values = null): string
{
    $fill = static function (string $raw) use ($values): string {
        if ($values === null) {
            return h($raw);
        }
        $out = '';
        foreach (preg_split('/\{(nombre|NOMBRE|documento|email|fecha)\}/u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
            $out .= $i % 2 === 0
                ? h($part)
                : '<span class="consent-fill" data-fill="' . h($part) . '">' . h((string) ($values[$part] ?? '')) . '</span>';
        }
        return $out;
    };

    if ($values === null && turno_consent_is_legacy($text)) {
        $html = '<h2>Declaro que</h2><ol class="consent-list">';
        foreach (turno_lines($text) as $line) {
            $html .= '<li>' . h($line) . '</li>';
        }
        return $html . '</ol><p>Por todo lo expuesto, doy mi consentimiento libre y voluntario para recibir la terapia indicada.</p>';
    }

    $html = '<div class="consent-doc">';
    $inItems = false;
    foreach (turno_consent_blocks($text) as $b) {
        if ($b['type'] === 'item' && !$inItems) {
            $html .= '<ul class="consent-items">';
            $inItems = true;
        } elseif ($b['type'] !== 'item' && $inItems) {
            $html .= '</ul>';
            $inItems = false;
        }
        $html .= match ($b['type']) {
            'title' => '<h2 class="consent-title">' . $fill($b['text']) . '</h2>',
            'clause' => '<p><strong>' . $fill($b['label']) . '</strong> ' . $fill($b['text']) . '</p>',
            'item' => '<li><span class="consent-item-label">' . h($b['label']) . '</span> '
                . ($b['term'] !== '' ? '<strong>' . $fill($b['term']) . '</strong> ' : '') . $fill($b['text']) . '</li>',
            default => '<p>' . $fill($b['text']) . '</p>',
        };
    }
    return $html . ($inItems ? '</ul>' : '') . '</div>';
}

/** Escribe el consentimiento (ya completado) en el PDF. */
function turno_consent_pdf_body(FluxusPdf $pdf, string $text): void
{
    if (turno_consent_is_legacy($text)) {
        $pdf->heading('Declaro que');
        foreach (turno_lines($text) as $i => $line) {
            $pdf->richParagraph([[$line, false]], ($i + 1) . '.', 0, 16);
        }
        $pdf->richParagraph([['Por todo lo expuesto, doy mi consentimiento libre y voluntario para recibir la terapia indicada.', false]]);
        return;
    }
    foreach (turno_consent_blocks($text) as $b) {
        match ($b['type']) {
            'title' => $pdf->centered($b['text'], 13),
            'clause' => $pdf->richParagraph([[$b['label'], true], [$b['text'], false]]),
            'item' => $pdf->richParagraph(array_values(array_filter([[$b['term'], true], [$b['text'], false]], static fn ($r) => $r[0] !== '')), $b['label'], 18, 26),
            default => $pdf->richParagraph([[$b['text'], false]]),
        };
    }
}

/**
 * Guarda el consentimiento aceptado online, con nombre, documento, e-mail y fecha ya completados en el texto.
 * Solo la primera vez: no pisa uno ya firmado. Devuelve true si quedó guardado ahora.
 */
function turno_accept_consent(array $appt, string $name, string $dni, string $ip): bool
{
    $text = turno_consent_fill(turno_consent_template(), turno_consent_values($appt, $name, $dni, date('d-m-Y')));
    $stmt = db()->prepare("
      UPDATE appointments
      SET consent_accepted_at = ?, consent_name = ?, consent_dni = ?, consent_ip = ?, consent_text = ?
      WHERE id = ? AND consent_accepted_at IS NULL AND status IN ('confirmed', 'pending_deposit')
    ");
    $stmt->execute([
        date('Y-m-d H:i:s'),
        $name,
        $dni,
        substr($ip, 0, 45),
        $text,
        (int) $appt['id'],
    ]);
    return $stmt->rowCount() === 1;
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
    $loc = turno_location_of($appt);
    $pdf->heading('Detalle del turno');
    $pdf->paragraph('Código: ' . $appt['code']);
    $pdf->paragraph('Terapia: ' . $appt['therapy_name']);
    $pdf->paragraph('Día: ' . format_date_es((string) $appt['date']));
    $pdf->paragraph('Hora: ' . format_time_es((string) $appt['time']));
    $pdf->paragraph('Duración aproximada: ' . (int) $appt['duration_min'] . ' minutos');
    $pdf->paragraph('Lugar: ' . $loc['label']);
    if ($loc['notes'] !== '') {
        $pdf->paragraph('Indicaciones para llegar: ' . $loc['notes']);
    }
    // El PDF no corta palabras: un link más largo que el renglón se saldría de la hoja.
    if ($loc['map_link'] !== '' && strlen($loc['map_link']) <= 88) {
        $pdf->paragraph('Mapa: ' . $loc['map_link']);
    }
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

/**
 * Consentimiento en PDF. Sin firmar: con el nombre y el e-mail del paciente, y líneas para completar a mano
 * el documento y la fecha (se firma en papel el día de la sesión). Firmado online: el texto exacto aceptado.
 */
function turno_consentimiento_pdf(array $appt): string
{
    $signed = !empty($appt['consent_accepted_at']) && trim((string) ($appt['consent_text'] ?? '')) !== '';
    $text = $signed
        ? (string) $appt['consent_text']
        : turno_consent_fill(turno_consent_template(), turno_consent_values($appt));

    $pdf = new FluxusPdf();
    $pdf->setFooter('FluxusTerapia · Consentimiento informado · Turno ' . $appt['code']);
    turno_pdf_header($pdf, 'Turno ' . $appt['code']);
    $pdf->richParagraph([
        ['Turno:', true],
        [$appt['therapy_name'] . ' · ' . format_date_es((string) $appt['date']) . ' · ' . format_time_es((string) $appt['time'])
            . (trim((string) ($appt['patient_phone'] ?? '')) !== '' ? ' · Teléfono: ' . $appt['patient_phone'] : ''), false],
    ], '', 0, 0, 9.5);
    turno_consent_pdf_body($pdf, $text);

    if ($signed) {
        $pdf->keepTogether(110);
        $pdf->rule();
        $pdf->richParagraph([
            ['Aceptado online:', true],
            [date('d/m/Y H:i', strtotime((string) $appt['consent_accepted_at'])) . ' hs por ' . $appt['consent_name']
                . ' (RUT / DNI ' . $appt['consent_dni'] . ').', false],
        ]);
        $pdf->signatureRow('Firma y sello del profesional');
    } else {
        $pdf->keepTogether(150);
        $pdf->signatureRow('Firma del paciente', 'Aclaración y RUT / DNI');
        $pdf->signatureRow('Fecha', 'Firma y sello del profesional');
        $pdf->small('Si el paciente es menor de edad o no puede firmar, firma su madre, padre, tutor o representante indicando el vínculo.');
    }
    return $pdf->render();
}

/** Evento de calendario (iCalendar, RFC 5545) para agregar el turno a Google/Apple/Outlook. */
function turno_ics(array $appt): string
{
    global $config;
    $tz = new DateTimeZone((string) ($config['timezone'] ?? 'America/Argentina/Buenos_Aires'));
    $utc = new DateTimeZone('UTC');
    $start = new DateTimeImmutable($appt['date'] . ' ' . substr((string) $appt['time'], 0, 5), $tz);
    $end = $start->modify('+' . max(15, (int) ($appt['duration_min'] ?? 60)) . ' minutes');
    $stamp = static fn (DateTimeImmutable $d): string => $d->setTimezone($utc)->format('Ymd\THis\Z');
    $esc = static fn (string $v): string => str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $v);
    $fold = static function (string $line): string {
        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $limit = 74;
        }
        return $out . $line;
    };

    $loc = turno_location_of($appt);
    $desc = 'Código: ' . $appt['code'];
    if ($loc['notes'] !== '') {
        $desc .= "\nIndicaciones para llegar: " . $loc['notes'];
    }
    if ($loc['map_link'] !== '') {
        $desc .= "\nCómo llegar: " . $loc['map_link'];
    }
    if (empty($appt['consent_accepted_at'])) {
        $desc .= "\nFirmá el consentimiento online: " . turno_page_url($appt, 'consentimiento.php');
    }
    if (turno_deposit_open($appt)) {
        $desc .= "\nSeña opcional (" . money_ars((int) $appt['deposit_amount']) . '): ' . turno_page_url($appt, 'pay.php');
    }
    $desc .= "\nRequisitos: " . turno_doc_url($appt, 'requisitos')
        . "\nWhatsApp: https://wa.me/" . preg_replace('/\D+/', '', (string) ($config['whatsapp'] ?? ''));

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//FluxusTerapia//Turnos//ES',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $appt['code'] . '@fluxusterapia.com',
        'DTSTAMP:' . $stamp(new DateTimeImmutable('now')),
        'DTSTART:' . $stamp($start),
        'DTEND:' . $stamp($end),
        'SUMMARY:' . $esc('Turno ' . $appt['therapy_name'] . ' · FluxusTerapia'),
        'LOCATION:' . $esc($loc['label'] === $loc['address'] ? $loc['address'] : $loc['name'] . ', ' . $loc['address']),
        'DESCRIPTION:' . $esc($desc),
        'STATUS:CONFIRMED',
        'BEGIN:VALARM',
        'ACTION:DISPLAY',
        'DESCRIPTION:' . $esc('Turno ' . $appt['therapy_name'] . ' en FluxusTerapia'),
        'TRIGGER:-PT2H',
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", array_map($fold, $lines)) . "\r\n";
}

/**
 * Correo HTML + texto con adjuntos (PDF; .ics como calendario). $inline son imágenes PNG que el HTML usa como
 * src="cid:<clave>" (muchos clientes bloquean data: URIs). Devuelve true si mail() lo aceptó.
 */
function turno_send_mail(string $to, string $subject, string $html, string $text, array $attachments, array $inline = []): bool
{
    global $config;
    $fromEmail = (string) ($config['mail_from'] ?? 'hola@fluxusterapia.com');
    $fromName = (string) ($config['mail_from_name'] ?? 'FluxusTerapia');
    $enc = static fn (string $v): string => preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;

    $mixed = 'fx_mix_' . bin2hex(random_bytes(8));
    $alt = 'fx_alt_' . bin2hex(random_bytes(8));
    $rel = 'fx_rel_' . bin2hex(random_bytes(8));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . $enc($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
        'X-Mailer: FluxusTerapia Turnos',
    ];
    $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n";
    if ($inline) {
        $related = "Content-Type: multipart/related; boundary=\"{$rel}\"\r\n\r\n--{$rel}\r\n" . $htmlPart;
        foreach ($inline as $cid => $png) {
            $related .= "--{$rel}\r\nContent-Type: image/png; name=\"{$cid}.png\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-ID: <{$cid}>\r\n"
                . "Content-Disposition: inline; filename=\"{$cid}.png\"\r\n\r\n"
                . chunk_split(base64_encode($png)) . "\r\n";
        }
        $htmlPart = $related . "--{$rel}--\r\n";
    }
    $body = "--{$mixed}\r\nContent-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n"
        . "--{$alt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--{$alt}\r\n" . $htmlPart
        . "--{$alt}--\r\n";
    foreach ($attachments as $name => $bytes) {
        $type = str_ends_with(strtolower($name), '.ics') ? 'text/calendar; charset=utf-8; method=PUBLISH' : 'application/pdf';
        $body .= "--{$mixed}\r\nContent-Type: {$type}; name=\"{$name}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
            . chunk_split(base64_encode($bytes)) . "\r\n";
    }
    $body .= "--{$mixed}--\r\n";

    if (!empty($config['mail_dump_dir'])) {
        $file = rtrim((string) $config['mail_dump_dir'], '/') . '/turno-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        file_put_contents($file, 'To: ' . $to . "\r\nSubject: " . $enc($subject) . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
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
    $date = format_date_es((string) $appt['date']);
    $time = format_time_es((string) $appt['time']);
    $loc = turno_location_of($appt);
    $reqUrl = turno_doc_url($appt, 'requisitos');
    $conUrl = turno_doc_url($appt, 'consentimiento');
    $signUrl = turno_page_url($appt, 'consentimiento.php');
    $payUrl = turno_page_url($appt, 'pay.php');
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $config['whatsapp']);
    $subject = 'Turno confirmado · ' . $appt['therapy_name'] . ' · ' . $date;
    $online = turno_is_online($appt);
    $signed = !empty($appt['consent_accepted_at']);
    $prep = turno_prep_lines($appt);
    $payOpen = turno_deposit_open($appt);
    $amount = money_ars((int) $appt['deposit_amount']);

    $inline = [];
    if ($payOpen) {
        try {
            $png = FluxusQr::png($payUrl, 6, 3);
        } catch (Throwable $ex) {
            $png = null;
        }
        if ($png !== null) {
            $inline['qr-pago'] = $png;
        }
    }

    $h2 = static fn (string $t): string => '<h2 style="margin:22px 0 8px;font-family:Georgia,serif;font-size:19px;color:#0f3d36">' . $t . '</h2>';
    $btn = static fn (string $url, string $label, string $bg): string => '<a href="' . $e($url) . '" style="display:inline-block;background:' . $bg
        . ';color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">' . $label . '</a>';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid rgba(15,61,54,.12)">'
        . '<tr><td style="background:#0f3d36;color:#fff;padding:20px 26px;font-family:Georgia,serif;font-size:24px">FluxusTerapia</td></tr>'
        . '<tr><td style="padding:26px;font-size:15px;line-height:1.6">'
        . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#2f8f6b;font-weight:700">Turno confirmado</p>'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px">Hola, ' . $e($appt['patient_name']) . '</h1>'
        . '<p style="margin:0 0 16px;color:#4f635a">Tu turno quedó confirmado. Te esperamos:</p>'
        . '<table role="presentation" width="100%" style="background:#eef3ef;border-radius:8px;margin:0 0 6px"><tr><td style="padding:14px 18px">'
        . '<strong style="font-size:17px">' . $e($appt['therapy_name']) . '</strong><br>'
        . 'Día: <strong>' . $e($date) . '</strong><br>'
        . 'Hora: <strong>' . $e($time) . '</strong> · duración aproximada ' . (int) $appt['duration_min'] . ' min<br>'
        . 'Lugar: <strong>' . $e($loc['label']) . '</strong><br>'
        . ($loc['notes'] !== '' ? $e($loc['notes']) . '<br>' : '')
        . ($loc['map_link'] !== '' ? '<a href="' . $e($loc['map_link']) . '" style="color:#0f3d36;font-weight:700">Cómo llegar (Google Maps)</a><br>' : '')
        . 'Código: <strong>' . $e($appt['code']) . '</strong>'
        . '</td></tr></table>';

    $html .= $h2('Consentimiento informado');
    if ($signed) {
        $html .= '<p style="margin:0 0 6px">Ya lo firmaste online el ' . $e(date('d/m/Y', strtotime((string) $appt['consent_accepted_at']))) . '. ¡Gracias!</p>';
    } else {
        $html .= '<p style="margin:0 0 12px">Antes de la sesión, leelo con calma y firmalo online con tu nombre y tu RUT o DNI. Si preferís, traelo impreso y firmado o lo firmás al llegar.</p>'
            . '<p style="margin:0 0 6px">' . $btn($signUrl, 'Firmar consentimiento online', '#0f3d36') . '</p>';
    }

    if ($prep) {
        $html .= $h2($online ? 'Para tu clase online' : 'Antes de venir') . '<ul style="margin:0;padding-left:20px">';
        foreach ($prep as $line) {
            $html .= '<li style="margin:0 0 4px">' . $e($line) . '</li>';
        }
        $html .= '</ul>';
    }

    if ($payOpen) {
        $html .= $h2('Seña (opcional)')
            . '<table role="presentation" width="100%" style="border:1px solid rgba(15,61,54,.18);border-radius:8px"><tr>'
            . (isset($inline['qr-pago'])
                ? '<td width="170" style="padding:12px;vertical-align:top"><img src="cid:qr-pago" width="160" height="160" alt="QR para pagar la seña" style="display:block;border:0"></td>'
                : '')
            . '<td style="padding:12px 14px;vertical-align:top">'
            . '<p style="margin:0 0 8px">Tu turno ya está confirmado. Si querés, podés dejar paga la seña de <strong>' . $e($amount) . '</strong> desde ahora'
            . (isset($inline['qr-pago']) ? ' escaneando el QR con el celular' : '') . ' (transferencia o Mercado Pago).</p>'
            . '<p style="margin:0 0 10px">' . $btn($payUrl, 'Pagar seña', '#2f8f6b') . '</p>'
            . '<p style="margin:0;font-size:12px;color:#4f635a;word-break:break-all">' . $e($payUrl) . '</p>'
            . '</td></tr></table>';
    }

    $html .= $h2('Te adjuntamos')
        . '<ul style="margin:0 0 18px;padding-left:20px">'
        . '<li>' . ($online ? '<strong>Indicaciones para tu clase online</strong>' : '<strong>Comprobante y requisitos para tu sesión</strong>') . '. <a href="' . $e($reqUrl) . '" style="color:#0f3d36">Ver PDF</a></li>'
        . '<li><strong>Consentimiento informado</strong> para imprimir. <a href="' . $e($conUrl) . '" style="color:#0f3d36">Ver PDF</a></li>'
        . '<li><strong>Agregá el turno a tu calendario</strong> (archivo adjunto Turno-' . $e($appt['code']) . '.ics).</li>'
        . '</ul>'
        . '<p style="margin:0 0 18px">' . $btn($wa, 'Consultas por WhatsApp', '#2f8f6b') . '</p>'
        . '<p style="margin:0;color:#4f635a">Si no podés venir, avisanos con al menos 24 horas de anticipación.<br><br>Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';

    $text = 'Hola, ' . $appt['patient_name'] . "\n\n"
        . "Tu turno quedó confirmado:\n"
        . $appt['therapy_name'] . "\nDía: " . $date . "\nHora: " . $time . "\nLugar: " . $loc['label'] . "\n"
        . ($loc['notes'] !== '' ? $loc['notes'] . "\n" : '')
        . ($loc['map_link'] !== '' ? 'Cómo llegar: ' . $loc['map_link'] . "\n" : '')
        . 'Código: ' . $appt['code'] . "\n\n"
        . "CONSENTIMIENTO INFORMADO\n"
        . ($signed
            ? 'Ya lo firmaste online el ' . date('d/m/Y', strtotime((string) $appt['consent_accepted_at'])) . ".\n\n"
            : "Firmalo online antes de la sesión (o traelo firmado / lo firmás al llegar):\n" . $signUrl . "\n\n");
    if ($prep) {
        $text .= ($online ? "PARA TU CLASE ONLINE\n" : "ANTES DE VENIR\n") . '- ' . implode("\n- ", $prep) . "\n\n";
    }
    if ($payOpen) {
        $text .= "SEÑA (OPCIONAL)\nTu turno ya está confirmado. Si querés, podés dejar paga la seña de " . $amount . " desde ahora:\n" . $payUrl . "\n\n";
    }
    $text .= "Te adjuntamos en PDF:\n"
        . ($online ? '- Indicaciones para tu clase online: ' : '- Comprobante y requisitos para tu sesión: ') . $reqUrl . "\n"
        . '- Consentimiento informado para imprimir: ' . $conUrl . "\n"
        . '- Agregá el turno a tu calendario (archivo adjunto Turno-' . $appt['code'] . ".ics)\n\n"
        . 'Consultas por WhatsApp: ' . $wa . "\n"
        . "Si no podés venir, avisanos con al menos 24 horas de anticipación.\n\nFluxusTerapia\n";

    $ok = turno_send_mail($to, $subject, $html, $text, [
        'Turno-' . $appt['code'] . '-requisitos.pdf' => turno_requisitos_pdf($appt),
        'Consentimiento-informado-' . $appt['code'] . '.pdf' => turno_consentimiento_pdf($appt),
        'Turno-' . $appt['code'] . '.ics' => turno_ics($appt),
    ], $inline);
    if (!$ok) {
        return 'failed';
    }
    db()->prepare('UPDATE appointments SET mail_sent_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $appointmentId]);
    return 'sent';
}
